<?php
/**
 * Scheme Staff — database connection and write helpers.
 *
 * Everything here uses prepared statements. Column names are never taken from
 * user input — they come only from the fixed mapping in fieldmap.php, and are
 * additionally whitelisted against the live table structure before use.
 */

/**
 * Truncate to fit a VARCHAR without splitting a multi-byte character — a broken
 * character would be rejected outright by a utf8mb4 column. mbstring is present
 * on almost every PHP build, but falling back keeps a missing extension from
 * taking the whole endpoint down.
 */
function clip(?string $value, int $length): string {
    $value = $value ?? '';
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $length)
        : substr($value, 0, $length);
}

function db_connect(array $cfg): PDO {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        $cfg['name']
    );

    return new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/** Column names that actually exist on a table, used to filter the projection. */
function table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    // $table is never user input — it comes from FORM_TABLES only.
    $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
    return $cache[$table] = array_column($rows, 'Field');
}

/** Store the complete submission exactly as received. Returns the new id. */
function insert_submission(PDO $pdo, string $formType, array $payload): int {
    $stmt = $pdo->prepare(
        'INSERT INTO submissions (form_type, submitted_at, remote_ip, user_agent, payload)
         VALUES (:form_type, NOW(), :ip, :ua, :payload)'
    );
    $stmt->execute([
        ':form_type' => $formType,
        ':ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        ':ua'        => clip($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
        ':payload'   => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    return (int) $pdo->lastInsertId();
}

/** Write the projected columns into the form's own table. */
function insert_projection(PDO $pdo, string $table, int $submissionId, array $row): void {
    $valid = table_columns($pdo, $table);
    $row   = array_intersect_key($row, array_flip($valid));

    $row['submission_id'] = $submissionId;
    $columns = array_keys($row);

    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(fn($c) => "`{$c}`", $columns)),
        implode(', ', array_map(fn($c) => ":{$c}", $columns))
    );

    $stmt = $pdo->prepare($sql);
    foreach ($row as $column => $value) {
        $stmt->bindValue(":{$column}", $value);
    }
    $stmt->execute();
}

/** Record where an uploaded document was written. */
function insert_upload(PDO $pdo, int $submissionId, array $file): void {
    $stmt = $pdo->prepare(
        'INSERT INTO uploads
            (submission_id, field_label, original_name, stored_path, mime_type, bytes, sha256, created_at)
         VALUES (:sid, :label, :original, :stored, :mime, :bytes, :sha, NOW())'
    );
    $stmt->execute([
        ':sid'      => $submissionId,
        ':label'    => clip($file['field'], 190),
        ':original' => clip($file['original'], 255),
        ':stored'   => $file['stored'],
        ':mime'     => clip($file['mime'] ?? '', 120),
        ':bytes'    => $file['bytes'],
        ':sha'      => $file['sha256'],
    ]);
}
