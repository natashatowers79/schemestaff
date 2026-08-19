<?php
/**
 * Scheme Staff — form intake.
 *
 * Receives the JSON that script.js builds, stores it in MySQL, and writes any
 * attached documents to disk outside the web root. The request/response contract
 * is deliberately identical to the Google Apps Script it replaces — a payload of
 * {formType, fields, files} in, {ok: true} or {ok: false, error} out — so the
 * only change needed on the website is SUBMIT_URL.
 *
 * script.js posts as text/plain, which keeps the request "simple" in CORS terms
 * and avoids a preflight round trip. The response still needs an explicit
 * Access-Control-Allow-Origin header before the browser will let the page read it.
 */

declare(strict_types=1);

// A plain variable rather than a const: constant expressions have restrictions
// that vary by PHP version, and there is nothing to gain by testing them here.
$privateDir = __DIR__ . '/../private';

require $privateDir . '/db.php';
require $privateDir . '/fieldmap.php';

$config = require $privateDir . '/config.php';

/* ── Response helpers ── */

function respond(array $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $clientMessage, string $logDetail = '', int $status = 400): never {
    if ($logDetail !== '') {
        error_log('[schemestaff] ' . $logDetail);
    }
    respond(['ok' => false, 'error' => $clientMessage], $status);
}

/* ── CORS ── */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $config['allowed_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('This endpoint accepts POST requests only.', '', 405);
}

// An unrecognised Origin is refused outright. A browser would already have
// blocked the response, but this stops anything else posting straight at us.
if ($origin !== '' && !in_array($origin, $config['allowed_origins'], true)) {
    fail('Submissions are not accepted from this address.', 'rejected origin: ' . $origin, 403);
}

/* ── Parse ── */

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    fail('Your submission arrived empty. Please try again.');
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    fail('Your submission could not be read. Please try again.', 'json decode failed: ' . json_last_error_msg());
}

$formType = (string) ($data['formType'] ?? '');
$fields   = is_array($data['fields'] ?? null) ? $data['fields'] : [];
$files    = is_array($data['files'] ?? null) ? $data['files'] : [];

// array_key_exists, not isset() — isset() accepts only variables, and calling it
// on a constant array is a fatal error rather than a false.
if (!array_key_exists($formType, FORM_TABLES)) {
    fail('Unrecognised form.', 'unknown formType: ' . $formType);
}
if ($fields === []) {
    fail('Your submission arrived without any answers. Please try again.');
}

// Defence in depth: script.js already refuses to transmit password fields, but
// never store one if a future change lets it through.
foreach (array_keys($fields) as $label) {
    if (stripos((string) $label, 'password') !== false) {
        unset($fields[$label]);
    }
}

/* ── Validate attachments before touching the database ── */

$totalBytes = 0;
$prepared   = [];

foreach ($files as $file) {
    if (!is_array($file) || !isset($file['base64'], $file['filename'])) {
        fail('One of your attachments could not be read. Please try again.');
    }

    $binary = base64_decode((string) $file['base64'], true);
    if ($binary === false) {
        fail('One of your attachments could not be read. Please try again.', 'base64 decode failed');
    }

    $extension = strtolower(pathinfo((string) $file['filename'], PATHINFO_EXTENSION));
    if (!in_array($extension, $config['allowed_extensions'], true)) {
        fail(
            'Attachments must be PDF, Word documents or images. "' . basename((string) $file['filename']) . '" was not accepted.',
            'rejected extension: ' . $extension
        );
    }

    $totalBytes += strlen($binary);
    if ($totalBytes > $config['max_upload_bytes']) {
        fail('Your uploaded files are too large in total. Please use smaller files and try again.');
    }

    $prepared[] = [
        'field'     => (string) ($file['field'] ?? 'Attachment'),
        'original'  => basename((string) $file['filename']),
        'mime'      => (string) ($file['mimeType'] ?? ''),
        'extension' => $extension,
        'binary'    => $binary,
    ];
}

/* ── Store ── */

try {
    $pdo = db_connect($config['db']);
} catch (Throwable $e) {
    fail('We could not save your submission just now. Please try again shortly.',
         'db connect failed: ' . $e->getMessage(), 503);
}

$written = [];

try {
    $pdo->beginTransaction();

    $submissionId = insert_submission($pdo, $formType, $data);

    $projection = project_fields($formType, $fields);
    if ($projection !== []) {
        insert_projection($pdo, FORM_TABLES[$formType], $submissionId, $projection);
    }

    // Files are written only once the rows are safely in the transaction, and
    // are deleted again if the commit fails — so disk and database stay in step.
    $folder = sprintf('%s/%s/%d', $config['upload_dir'], date('Y/m'), $submissionId);
    if ($prepared !== [] && !is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
        throw new RuntimeException('could not create upload folder: ' . $folder);
    }

    foreach ($prepared as $file) {
        $storedName = bin2hex(random_bytes(16)) . '.' . $file['extension'];
        $storedPath = $folder . '/' . $storedName;

        if (file_put_contents($storedPath, $file['binary']) === false) {
            throw new RuntimeException('could not write ' . $storedPath);
        }
        chmod($storedPath, 0600);
        $written[] = $storedPath;

        insert_upload($pdo, $submissionId, [
            'field'    => $file['field'],
            'original' => $file['original'],
            'stored'   => $storedPath,
            'mime'     => $file['mime'],
            'bytes'    => strlen($file['binary']),
            'sha256'   => hash('sha256', $file['binary']),
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($written as $path) {
        @unlink($path);
    }
    fail('We could not save your submission just now. Please try again shortly.',
         'submission failed: ' . $e->getMessage(), 500);
}

/* ── Email ──────────────────────────────────────────────────────────────────
 *
 * Both messages are sent with @ and after the data is safely committed. Mail is
 * the least reliable part of any shared host, and a submission that is already
 * in the database must never be reported as failed because a mail server sulked.
 *
 * The From address must stay on schemestaff.co.za whoever the recipient is: the
 * domain's SPF record authorises this server to send as schemestaff.co.za and
 * nothing else. Sending as, say, an @me.com address would fail SPF and DMARC and
 * be spam-filed or rejected.
 */

$from = $config['from_email'] ?? 'no-reply@schemestaff.co.za';

/** Plain-text mail headers. Values are sanitised to prevent header injection. */
function mail_headers(string $from, ?string $replyTo = null): string {
    $strip   = fn(?string $v): string => str_replace(["\r", "\n"], '', (string) $v);
    $headers = [
        'From: Scheme Staff <' . $strip($from) . '>',
        'Content-Type: text/plain; charset=utf-8',
        'MIME-Version: 1.0',
    ];
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $strip($replyTo);
    }
    return implode("\r\n", $headers);
}

/** The address the person filling in the form gave us, if we can find one. */
function submitter_email(array $projection, array $fields): ?string {
    foreach (['email', 'contact_email', 'login_email'] as $column) {
        $candidate = $projection[$column] ?? null;
        if ($candidate && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }
    }
    // Fall back to scanning the raw labels, in case the mapping missed it.
    foreach ($fields as $label => $value) {
        if (stripos((string) $label, 'email') !== false
            && is_string($value)
            && filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
            return trim($value);
        }
    }
    return null;
}

/** What the submitter should see this submission called. */
const FORM_DESCRIPTIONS = [
    'Candidates'            => 'your candidate registration',
    'Employers'             => 'your employer registration',
    'Job postings'          => 'your job posting',
    'Availability postings' => 'your availability posting',
    'Contact messages'      => 'your message',
];

$submitterEmail = submitter_email($projection ?? [], $fields);
// array_key_exists rather than ?? — the null coalescing operator does not reliably
// suppress the undefined-key warning on a constant array.
$description = array_key_exists($formType, FORM_DESCRIPTIONS)
    ? FORM_DESCRIPTIONS[$formType]
    : 'your submission';

// 1. Tell Scheme Staff something came in.
if (!empty($config['notify_email'])) {
    $summary = '';
    foreach ($fields as $label => $value) {
        if (is_string($value) && trim($value) !== '') {
            $summary .= "  {$label}: {$value}\n";
        }
    }

    @mail(
        $config['notify_email'],
        'Scheme Staff — new ' . $formType . ' submission (#' . $submissionId . ')',
        "A new submission has been received.\n\n"
            . "Form:       {$formType}\n"
            . "Reference:  {$submissionId}\n"
            . 'Received:   ' . date('Y-m-d H:i') . "\n"
            . 'Attachments: ' . count($prepared) . "\n\n"
            . "Submitted values\n"
            . "----------------\n" . $summary . "\n"
            . "This is stored in the database — nothing here needs keeping.\n",
        mail_headers($from, $submitterEmail)
    );
}

// 2. Reassure the person who filled the form in. Under POPIA it is good practice
//    to tell someone what has been collected and who is holding it.
if ($submitterEmail !== null) {
    @mail(
        $submitterEmail,
        'Scheme Staff — we have received ' . $description,
        "Thank you — we have received {$description}.\n\n"
            . "Your reference is {$submissionId}. Please quote it if you get in touch.\n\n"
            . "What happens next\n"
            . "-----------------\n"
            . "A member of the Scheme Staff team will review your submission and be\n"
            . "in touch. We do not share your details with anyone outside Scheme Staff\n"
            . "without your agreement.\n\n"
            . "If you did not submit this, or you would like your information removed,\n"
            . "reply to this email and we will delete it.\n\n"
            . "Scheme Staff — Property Recruitment\n"
            . "https://schemestaff.co.za\n",
        mail_headers($from, $config['notify_email'] ?: null)
    );
}

respond(['ok' => true, 'reference' => $submissionId]);
