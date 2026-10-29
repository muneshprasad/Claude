-- Run ONCE on an install that already ran migrate_courses.sql (phpMyAdmin > SQL).
-- Adds: per-course terms, shoes + tennis racquet hire, bag numbers, member lists, and Denarau's terms wording.
SET NAMES utf8mb4;

-- Per-course hire features: Natadola has no tennis; Denarau hires shoes and tennis gear.
ALTER TABLE courses ADD COLUMN full_name VARCHAR(150) NULL AFTER name;
UPDATE courses SET full_name = 'Natadola Bay Golf Course' WHERE id = 1;
UPDATE courses SET full_name = 'Denarau Golf & Racquet Club' WHERE id = 2;
ALTER TABLE courses ADD COLUMN logo MEDIUMTEXT NULL, ADD COLUMN logo_at DATETIME NULL;
ALTER TABLE courses ADD COLUMN tennis TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN shoes TINYINT(1) NOT NULL DEFAULT 0;
UPDATE courses SET tennis = 1, shoes = 1 WHERE id = 2;

ALTER TABLE assets MODIFY kind ENUM('cart','clubs','shoes','racquet') NOT NULL;
-- "member" = no green fee (only allowed when every golfer is a verified, current member)
ALTER TABLE checkins MODIFY fee_status ENUM('paid','room','unpaid','member') NOT NULL DEFAULT 'unpaid';
ALTER TABLE checkins ADD COLUMN bags VARCHAR(40) NULL AFTER notes;

CREATE TABLE IF NOT EXISTS checkin_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  checkin_id INT NOT NULL,
  asset_id INT NOT NULL,
  hire_fee DECIMAL(8,2) NOT NULL DEFAULT 0,
  KEY idx_asset (asset_id),
  KEY idx_checkin (checkin_id),
  FOREIGN KEY (checkin_id) REFERENCES checkins(id) ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES assets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Member / visitor is now recorded per check-in, so a Natadola member is not treated as a Denarau member.
ALTER TABLE checkin_golfers ADD COLUMN guest_type ENUM('guest','member') NOT NULL DEFAULT 'guest';
UPDATE checkin_golfers cg JOIN guests g ON g.id = cg.guest_id SET cg.guest_type = g.guest_type;

-- Per-course member lists (uploaded by each course admin).
CREATE TABLE IF NOT EXISTS members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  member_no VARCHAR(40) NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  mobile VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  expires_on DATE NULL,                   -- NULL = no expiry recorded
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_course_no (course_id, member_no),
  KEY idx_mobile (course_id, mobile),
  KEY idx_name (course_id, last_name, first_name),
  KEY idx_exp (course_id, expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE checkin_golfers ADD COLUMN member_id INT NULL;

ALTER TABLE terms ADD COLUMN course_id INT NULL AFTER version;
UPDATE terms SET course_id = 1 WHERE course_id IS NULL;

-- Denarau Golf & Racquet Club wording, transcribed from their paper slip. Have it checked before relying on it.
INSERT INTO terms (course_id, body) VALUES (2,
'CART EQUIPMENT RENTAL\nI agree that I am liable and responsible for all rental equipment and the golf cart whilst in my care.\n\nGOLF CART RENTAL AGREEMENT\n1. I will return the golf cart immediately following the completion of 18 holes of play in the same condition as when I received it.\n2. No more than 2 people and 2 golf bags will occupy or be carried in the golf cart at any one time.\n3. Only persons holding a current drivers licence will be permitted to drive the golf cart.\n4. The Denarau Golf & Racquet Club will not be liable for any damages to the cart, personal injury or property damage from or through my negligence.\n5. I acknowledge that I will be liable for any damages as a result of my negligent operation of the golf cart.\n6. I agree to pay the Denarau Golf & Racquet Club the cost of any repairs considered reasonably necessary by Denarau Golf & Racquet Club to the cart whilst in my care within 14 days upon receipt of an account.\n7. I agree to pay Denarau Golf & Racquet Club for any damages caused to either rental shoes or clubs whilst in my care.\n8. No sharing of golf sets is allowed.\n\nTENNIS RENTAL AGREEMENT\n1. I will return racquet and/or shoes immediately following the completion of play in the same condition as was received. Reasonable wear and tear accepted.\n2. I acknowledge that I will be liable for any damages to either racquet and/or shoes through negligent use.\n3. I agree to pay the Denarau Golf & Racquet Club for damages exceeding reasonable wear and tear.');
