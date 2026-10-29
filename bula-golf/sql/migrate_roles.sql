-- Run ONCE (phpMyAdmin > SQL), after the other migrations.
-- Three roles: admin (head office, or one course's admin), supervisor, staff.
-- Existing "manager" logins become admins (a manager with no course stays head office).
SET NAMES utf8mb4;

ALTER TABLE users MODIFY role ENUM('staff','manager','supervisor','admin') NOT NULL DEFAULT 'staff';
UPDATE users SET role = 'admin' WHERE role = 'manager';
ALTER TABLE users MODIFY role ENUM('staff','supervisor','admin') NOT NULL DEFAULT 'staff';

-- What each course's admin has allowed for supervisors and staff (no rows = defaults: supervisors everything, staff nothing extra).
CREATE TABLE IF NOT EXISTS role_perms (
  course_id INT NOT NULL,
  role ENUM('supervisor','staff') NOT NULL,
  perm VARCHAR(30) NOT NULL,
  allowed TINYINT(1) NOT NULL,
  PRIMARY KEY (course_id, role, perm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
