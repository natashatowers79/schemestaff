-- Scheme Staff — database schema
--
-- Run once against an empty MySQL database (DirectAdmin → MySQL Databases →
-- create the database and user, then import this file via phpMyAdmin).
--
-- Design note: every submission is stored twice. `submissions.payload` keeps the
-- complete form exactly as it arrived, so nothing is ever lost when a form gains
-- or renames a field. The per-form tables below are a *projection* of the fields
-- the matching engine needs to query on. If a label changes on the website and
-- the mapping in private/fieldmap.php isn't updated, the projected column goes
-- null but the raw payload still has the answer — recoverable, not lost.

SET NAMES utf8mb4;

-- ── EVERY SUBMISSION, RAW ──────────────────────────────────────────────────

CREATE TABLE submissions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_type     VARCHAR(64)     NOT NULL,
  submitted_at  DATETIME        NOT NULL,
  remote_ip     VARCHAR(45)     NULL,
  user_agent    VARCHAR(255)    NULL,
  payload       JSON            NOT NULL,
  PRIMARY KEY (id),
  KEY idx_type_date (form_type, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CANDIDATES ─────────────────────────────────────────────────────────────

CREATE TABLE candidates (
  submission_id       BIGINT UNSIGNED NOT NULL,
  full_name           VARCHAR(160)  NULL,
  email               VARCHAR(190)  NULL,
  phone               VARCHAR(40)   NULL,
  id_number           VARCHAR(40)   NULL,
  date_of_birth       DATE          NULL,
  address             TEXT          NULL,
  education           VARCHAR(120)  NULL,
  current_title       VARCHAR(160)  NULL,
  current_employer    VARCHAR(160)  NULL,
  years_experience    VARCHAR(40)   NULL,
  reason_for_leaving  TEXT          NULL,
  employment_types    TEXT          NULL,   -- multi-select, semicolon separated
  work_arrangements   TEXT          NULL,
  open_to             TEXT          NULL,
  own_transport       TEXT          NULL,
  primary_role        VARCHAR(120)  NULL,
  skills              TEXT          NULL,
  specialisations     TEXT          NULL,
  ppra_number         VARCHAR(60)   NULL,
  current_salary      VARCHAR(60)   NULL,
  salary_expectation  VARCHAR(60)   NULL,
  available_from      DATE          NULL,
  recurring_availability TEXT       NULL,
  notice_period       VARCHAR(40)   NULL,
  notice_period_type  VARCHAR(40)   NULL,
  availability_status VARCHAR(60)   NULL,
  locations           TEXT          NULL,
  travel_radius       VARCHAR(40)   NULL,
  willing_to_relocate VARCHAR(20)   NULL,
  login_email         VARCHAR(190)  NULL,
  PRIMARY KEY (submission_id),
  KEY idx_email (email),
  KEY idx_status (availability_status),
  KEY idx_role (primary_role),
  CONSTRAINT fk_candidates_submission
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── EMPLOYERS ──────────────────────────────────────────────────────────────

CREATE TABLE employers (
  submission_id      BIGINT UNSIGNED NOT NULL,
  company_name       VARCHAR(190) NULL,
  cipc_number        VARCHAR(60)  NULL,
  vat_number         VARCHAR(60)  NULL,
  website            VARCHAR(255) NULL,
  facebook           VARCHAR(255) NULL,
  linkedin           VARCHAR(255) NULL,
  contact_name       VARCHAR(160) NULL,
  contact_title      VARCHAR(120) NULL,
  contact_email      VARCHAR(190) NULL,
  contact_phone      VARCHAR(40)  NULL,
  address            TEXT         NULL,
  st_units           VARCHAR(40)  NULL,
  hoa_properties     VARCHAR(40)  NULL,
  shareblock_units   VARCHAR(40)  NULL,
  portfolio_type     TEXT         NULL,
  ppra_number        VARCHAR(60)  NULL,
  roles_hired        TEXT         NULL,
  rate_ranges        TEXT         NULL,
  subscription       VARCHAR(80)  NULL,
  login_email        VARCHAR(190) NULL,
  PRIMARY KEY (submission_id),
  KEY idx_company (company_name),
  KEY idx_contact_email (contact_email),
  CONSTRAINT fk_employers_submission
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── JOB POSTINGS ───────────────────────────────────────────────────────────

CREATE TABLE job_postings (
  submission_id        BIGINT UNSIGNED NOT NULL,
  job_title            VARCHAR(160) NULL,
  province             VARCHAR(80)  NULL,
  location             VARCHAR(160) NULL,
  min_experience       VARCHAR(60)  NULL,
  employment_type      VARCHAR(60)  NULL,
  work_arrangement     VARCHAR(60)  NULL,
  hours                VARCHAR(60)  NULL,
  schedule_details     TEXT         NULL,
  start_date           DATE         NULL,
  end_date             DATE         NULL,
  rate_offered         VARCHAR(80)  NULL,
  salary_band          VARCHAR(80)  NULL,
  requirements         TEXT         NULL,
  skills_required      TEXT         NULL,
  software_required    TEXT         NULL,
  legislation_required TEXT         NULL,
  role_description     TEXT         NULL,
  certifications       TEXT         NULL,
  -- Hard filters. Nicole's rule: an unregistered candidate must never be
  -- matched to a posting where ppra_required is set.
  ppra_required        TINYINT(1)   NOT NULL DEFAULT 0,
  credit_required      TINYINT(1)   NOT NULL DEFAULT 0,
  csos_required        TINYINT(1)   NOT NULL DEFAULT 0,
  offer_environment    TEXT         NULL,
  offer_growth         TEXT         NULL,
  offer_benefits       TEXT         NULL,
  status               ENUM('open','filled','closed') NOT NULL DEFAULT 'open',
  PRIMARY KEY (submission_id),
  KEY idx_open (status, province),
  KEY idx_ppra (ppra_required),
  CONSTRAINT fk_jobs_submission
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AVAILABILITY POSTINGS ──────────────────────────────────────────────────

CREATE TABLE availability_postings (
  submission_id      BIGINT UNSIGNED NOT NULL,
  available_from     DATE         NULL,
  available_to       DATE         NULL,
  work_type          VARCHAR(60)  NULL,
  recurring_schedule TEXT         NULL,
  locations          TEXT         NULL,
  travel_radius      VARCHAR(40)  NULL,
  travel_further     VARCHAR(20)  NULL,
  preferred_roles    TEXT         NULL,
  open_to_other      VARCHAR(60)  NULL,
  rate_expectation   VARCHAR(80)  NULL,
  negotiable         VARCHAR(40)  NULL,
  status             ENUM('open','placed','withdrawn') NOT NULL DEFAULT 'open',
  PRIMARY KEY (submission_id),
  KEY idx_avail (status, available_from),
  CONSTRAINT fk_avail_submission
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CONTACT MESSAGES ───────────────────────────────────────────────────────

CREATE TABLE contact_messages (
  submission_id BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(160) NULL,
  email         VARCHAR(190) NULL,
  phone         VARCHAR(40)  NULL,
  i_am          VARCHAR(120) NULL,
  message       TEXT         NULL,
  handled       TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (submission_id),
  CONSTRAINT fk_contact_submission
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── UPLOADED DOCUMENTS ─────────────────────────────────────────────────────
--
-- Files themselves live OUTSIDE public_html (see private/uploads/). This table
-- only records where they went. Never expose stored_path over the web directly —
-- serve documents through an authenticated script when the portal exists.

CREATE TABLE uploads (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  field_label   VARCHAR(190) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path   VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(120) NULL,
  bytes         INT UNSIGNED NOT NULL,
  sha256        CHAR(64)     NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_submission (submission_id),
  CONSTRAINT fk_uploads_submission
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
