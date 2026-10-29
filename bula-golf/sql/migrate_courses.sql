-- Run ONCE on an existing install (phpMyAdmin > SQL) to add Natadola + Denarau.
-- Everything already in the system is assigned to Natadola (course 1); move carts/clubs
-- to Denarau afterwards in Settings, or add Denarau's own fleet there.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO courses (id, name) VALUES (1, 'Natadola'), (2, 'Denarau');

ALTER TABLE users    ADD COLUMN course_id INT NULL AFTER role;                  -- NULL = head office (sees every course)
ALTER TABLE assets   ADD COLUMN course_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE checkins ADD COLUMN course_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE assets   DROP INDEX uq_kind_name, ADD UNIQUE KEY uq_course_kind_name (course_id, kind, name);
ALTER TABLE checkins ADD KEY idx_course_date (course_id, checked_in_at);
