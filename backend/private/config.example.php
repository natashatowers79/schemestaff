<?php
/**
 * Scheme Staff — configuration template.
 *
 * Copy this to config.php in the same folder and fill in the real values.
 * config.php is gitignored and must NEVER be committed or placed inside
 * public_html — it holds the database password.
 */

return [

    // From DirectAdmin → MySQL Databases. DirectAdmin prefixes both the database
    // and the username with your account name, e.g. "kbqjsoyz_schemestaff".
    'db' => [
        'host'     => 'localhost',
        'name'     => 'ACCOUNT_schemestaff',
        'user'     => 'ACCOUNT_schemestaff',
        'password' => '',
    ],

    // Which origins may POST to this endpoint. The live site is served from the
    // apex domain by GitHub Pages, so it is a different origin to this API and
    // the browser will send a CORS preflight. Keep this list tight — anything
    // listed here can submit forms.
    'allowed_origins' => [
        'https://schemestaff.co.za',
        'https://www.schemestaff.co.za',
    ],

    // Where uploaded CVs and certificates are written. Must sit OUTSIDE
    // public_html so documents can never be fetched directly over the web.
    'upload_dir' => __DIR__ . '/uploads',

    // Total bytes of attachments allowed per submission. The server's own
    // post_max_size (64M) is the hard ceiling; base64 inflates payloads by
    // roughly a third, so keep this comfortably below it.
    'max_upload_bytes' => 40 * 1024 * 1024,

    // Extensions accepted for uploads. Anything else is rejected outright.
    'allowed_extensions' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'],

    // Address notified whenever a form is submitted. Leave empty to skip.
    'notify_email' => '',

    // The From address on both the notification and the confirmation sent to the
    // person who filled the form in. MUST stay on schemestaff.co.za — the domain's
    // SPF record authorises this server to send as schemestaff.co.za and nothing
    // else, so any other domain here would fail SPF/DMARC and be spam-filed.
    'from_email' => 'no-reply@schemestaff.co.za',
];
