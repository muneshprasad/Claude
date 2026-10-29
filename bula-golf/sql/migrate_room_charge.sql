-- Run ONCE (phpMyAdmin > SQL) after migrate_hire_and_terms.sql.
-- "Charge to room" is now a per-course option, off until head office switches it on in Settings > Courses.
ALTER TABLE courses ADD COLUMN room_charge TINYINT(1) NOT NULL DEFAULT 0;
