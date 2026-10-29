-- Run ONCE (phpMyAdmin > SQL). Records each emailed copy of a signed agreement, so admins can see what was sent and resend.
CREATE TABLE IF NOT EXISTS email_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  checkin_id INT NOT NULL,
  golfer_id INT NOT NULL,                 -- checkin_golfers.id
  to_email VARCHAR(150) NOT NULL,
  status ENUM('sent','failed') NOT NULL,
  detail VARCHAR(255) NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_checkin (checkin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email settings: course_id 0 is the system default (head office); each course can use that or its own account (own address / SMTP).
CREATE TABLE IF NOT EXISTS email_settings (
  course_id INT NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 1,          -- courses: email guests a PDF copy of what they sign
  use_system TINYINT(1) NOT NULL DEFAULT 1,       -- courses: 1 = send through the system email, 0 = the course's own
  method ENUM('mail','smtp') NOT NULL DEFAULT 'mail',
  from_name VARCHAR(100) NULL,
  from_email VARCHAR(150) NULL,
  reply_to VARCHAR(150) NULL,
  smtp_host VARCHAR(150) NULL,
  smtp_port INT NULL,
  smtp_secure ENUM('none','ssl','tls') NOT NULL DEFAULT 'tls',
  smtp_user VARCHAR(150) NULL,
  smtp_pass TEXT NULL,                            -- stored encrypted, never sent back to the browser
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A promotional banner printed at the foot of each emailed PDF (JPEG or PNG data URL, set by the course admin).
ALTER TABLE courses ADD COLUMN promo MEDIUMTEXT NULL, ADD COLUMN promo_at DATETIME NULL;
