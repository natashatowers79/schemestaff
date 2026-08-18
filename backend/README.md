# Scheme Staff — backend

PHP intake endpoint for the website's forms. Replaces the Google Apps Script /
Google Sheets route: submissions go into MySQL, uploaded documents go to disk
outside the web root.

Runs on the Axxess hosting at `api.schemestaff.co.za` (PHP 8.3, MySQL, Apache).
The website itself stays on GitHub Pages — only the form submissions come here.

## Layout

```
backend/
├── schema.sql              run once to create the tables
├── public_html/            → copy into api.schemestaff.co.za/public_html/
│   ├── submit.php          the endpoint
│   └── .htaccess           HTTPS redirect + security headers
└── private/                → copy to a `private` folder ALONGSIDE public_html
    ├── config.example.php  template; copy to config.php and fill in
    ├── config.php          real credentials — never committed, never in public_html
    ├── db.php
    ├── fieldmap.php        form label → database column mapping
    └── uploads/            candidate documents (created automatically)
```

On the server the two folders sit side by side:

```
/domains/api.schemestaff.co.za/
├── public_html/     ← reachable from the web
└── private/         ← NOT reachable from the web
```

That split is the whole security model. Anything in `private/` — the database
password, every uploaded CV and ID document — cannot be fetched over the web,
whatever URL somebody guesses.

## Setup

**1. Create the database.** DirectAdmin → MySQL Databases → create a database and
a user, and give the user full rights on it. DirectAdmin prefixes both names with
your account, so you'll end up with something like `kbqjsoyz_schemestaff`. Save
the password somewhere safe — it's shown only once.

**2. Create the tables.** DirectAdmin → phpMyAdmin → select the new database →
Import → choose `schema.sql` → Go. You should end up with seven tables.

**3. Upload the files.** In File Manager:

- everything in `public_html/` goes into `domains/api.schemestaff.co.za/public_html/`
- create a folder `private` inside `domains/api.schemestaff.co.za/` (the same
  level as `public_html`, **not** inside it) and put everything from `private/`
  there

**4. Write the config.** Copy `private/config.example.php` to `private/config.php`
and fill in the database name, user and password from step 1.

**5. Test.** From a terminal:

```
curl -i -X POST https://api.schemestaff.co.za/submit.php \
  -H 'Origin: https://schemestaff.co.za' \
  -H 'Content-Type: text/plain' \
  -d '{"formType":"Contact messages","fields":{"Your name":"Test","Email address":"test@example.com","Message":"Testing"},"files":[]}'
```

Expect `{"ok":true,"reference":1}`. Check the row landed in `contact_messages`
via phpMyAdmin, then delete it.

**6. Go live.** Set `SUBMIT_URL` in `../script.js` to
`https://api.schemestaff.co.za/submit.php`, commit and push. Until then the site
stays in preview mode and stores nothing.

Do step 6 last. Pointing the live site at a backend that isn't finished means
real registrations failing in front of real people.

## How it fits together

`script.js` builds `{formType, fields, files}` and POSTs it as `text/plain` —
which keeps it a "simple" CORS request and avoids a preflight. `submit.php`
returns `{ok: true}` or `{ok: false, error}`, exactly the contract the Apps
Script used, so nothing else on the website changes.

Every submission is stored **twice**:

- `submissions.payload` — the complete form as it arrived, as JSON
- a row in the form's own table (`candidates`, `job_postings`, …) holding just
  the fields matching needs to query

That redundancy is deliberate. The per-form columns are populated by mapping
label text in `fieldmap.php`, and label text changes. When it does, the mapped
column goes null — but the raw payload is still complete, so the data is
recoverable rather than lost. **If you rename a field on a form, update
`fieldmap.php` to match.**

## Things to know

- **Passwords are never stored.** `script.js` strips them and `submit.php` strips
  them again. Real authentication arrives with the member portal.
- **Uploads are capped at 40MB per submission**, and limited to PDF, Word and
  image files. The server's own ceiling is 64MB; base64 encoding inflates
  payloads by about a third, hence the gap.
- **Files are stored under random names** with the original recorded in the
  database, so a candidate called `cv.pdf` can't overwrite another.
- **Uploads and the database commit together.** If the database write fails the
  files are deleted again, so the two never drift apart.
- **`max_execution_time` is 30 seconds** on this hosting. Fine for form intake.
  Anything slower — AI matching in particular — must run as a scheduled job that
  writes results to the database, not inside a web request.
- **Nothing here serves documents back out.** When the portal is built, CVs must
  be delivered through an authenticated script that checks who is asking, not by
  linking to a file path.
