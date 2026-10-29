-- Run ONCE (phpMyAdmin > SQL), after migrate_email.sql.
-- Adds: green-fee rate card, scheduled report emails, overdue-cart alerts, background-job bookkeeping, and offline check-in support.
SET NAMES utf8mb4;

-- Green-fee rate card per course: one amount per (holes, golfer type).
CREATE TABLE IF NOT EXISTS rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  holes TINYINT NOT NULL,                              -- 9 or 18
  category ENUM('member','resort','resident','visitor') NOT NULL,
  amount DECIMAL(8,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_rate (course_id, holes, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE checkins ADD COLUMN green_total DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER hire_fee;
ALTER TABLE checkin_golfers ADD COLUMN green_fee DECIMAL(8,2) NOT NULL DEFAULT 0, ADD COLUMN rate_cat VARCHAR(20) NULL;

-- Offline support: a reference the tablet makes up, so a check-in sent twice (after a dropped connection) is only recorded once.
ALTER TABLE checkins ADD COLUMN client_ref VARCHAR(40) NULL, ADD UNIQUE KEY uq_client_ref (client_ref);

-- Returning a golf set: staff confirm it is complete or say what is missing.
ALTER TABLE checkins ADD COLUMN clubs_missing VARCHAR(255) NULL, ADD COLUMN clubs_checked TINYINT(1) NOT NULL DEFAULT 0;

-- Photos taken when something comes back damaged or a golf set is incomplete (several per return).
CREATE TABLE IF NOT EXISTS return_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  checkin_id INT NOT NULL,
  kind ENUM('damage','clubs') NOT NULL,        -- cart/equipment damage, or a golf set returned damaged or incomplete
  photo MEDIUMTEXT NOT NULL,                  -- JPEG data URL, resized on the tablet
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_checkin (checkin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Scheduled report emails (weekly / monthly), per course.
CREATE TABLE IF NOT EXISTS report_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  report_type VARCHAR(20) NOT NULL,                    -- e.g. fleet (cart usage), cashup, playmix
  frequency ENUM('weekly','monthly') NOT NULL,
  recipients TEXT NOT NULL,                            -- comma separated email addresses
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_sent DATE NULL,
  UNIQUE KEY uq_sched (course_id, report_type, frequency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Overdue-cart alerts, per course (email, and an optional webhook for SMS/chat services).
CREATE TABLE IF NOT EXISTS alert_settings (
  course_id INT NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  recipients TEXT NULL,                                -- comma separated email addresses
  webhook_url VARCHAR(300) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS alert_log (
  checkin_id INT NOT NULL PRIMARY KEY,                 -- one alert per overdue check-in
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Last-run stamps for the background jobs (these run when the app is in use, so no cron is required).
CREATE TABLE IF NOT EXISTS app_state (
  k VARCHAR(40) NOT NULL PRIMARY KEY,
  v VARCHAR(100) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
