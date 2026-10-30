-- Golf attendant role, guest types, payments upfront, club-set numbers, range balls.
ALTER TABLE users MODIFY role ENUM('staff','attendant','supervisor','admin') NOT NULL DEFAULT 'staff';
ALTER TABLE role_perms MODIFY role ENUM('supervisor','attendant','staff') NOT NULL;

-- Payments are taken up front: how the guest paid.
ALTER TABLE checkins ADD COLUMN pay_method VARCHAR(10) NULL AFTER fee_status;
ALTER TABLE checkins ADD COLUMN range_total DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER green_total;

-- Guest types (rate categories) are now editable per course; rates are keyed by the type's code.
ALTER TABLE rates MODIFY category VARCHAR(30) NOT NULL;
ALTER TABLE checkin_golfers MODIFY rate_cat VARCHAR(30) NULL;
CREATE TABLE IF NOT EXISTS guest_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  code VARCHAR(30) NOT NULL,
  label VARCHAR(60) NOT NULL,
  proof TINYINT(1) NOT NULL DEFAULT 0,        -- staff must confirm they saw a card / ID (seniors, PGA members...)
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_gt (course_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Club sets can have a different price for 9 holes (blank = same as 18).
ALTER TABLE assets ADD COLUMN fee9 DECIMAL(8,2) NULL AFTER fee;

-- Range balls: what a course sells, and the orders the golf attendant must issue.
CREATE TABLE IF NOT EXISTS range_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  name VARCHAR(60) NOT NULL,
  price DECIMAL(8,2) NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS range_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  checkin_id INT NULL,
  guest_name VARCHAR(160) NULL,
  product_name VARCHAR(60) NOT NULL,
  qty INT NOT NULL DEFAULT 1,
  amount DECIMAL(8,2) NOT NULL DEFAULT 0,
  status ENUM('pending','issued') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_at DATETIME NULL,
  issued_by INT NULL,
  KEY k_pending (course_id, status),
  FOREIGN KEY (checkin_id) REFERENCES checkins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Free bucket for verified members: one product is flagged, and each member can claim it once per day.
ALTER TABLE range_products ADD COLUMN member_free TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
ALTER TABLE range_orders ADD COLUMN free TINYINT(1) NOT NULL DEFAULT 0 AFTER amount;
ALTER TABLE range_orders ADD COLUMN member_id INT NULL AFTER free;
ALTER TABLE range_orders ADD COLUMN free_day DATE NULL AFTER member_id;
ALTER TABLE range_orders ADD UNIQUE KEY uq_free_claim (course_id, member_id, free_day);

-- The four existing categories for every course (each course admin can add more, e.g. Senior, PGA member, Junior).
INSERT IGNORE INTO guest_types (course_id, code, label, proof, sort)
  SELECT c.id, t.code, t.label, 0, t.sort FROM courses c JOIN (
    SELECT 'member' code, 'Member' label, 1 sort UNION ALL SELECT 'resort', 'Resort / hotel guest', 2
    UNION ALL SELECT 'resident', 'Fiji resident', 3 UNION ALL SELECT 'visitor', 'Visitor', 4) t;
