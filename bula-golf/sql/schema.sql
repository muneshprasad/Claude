-- Bula Golf check-in schema (MySQL 5.7+ / MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,         -- short name used in the switcher and tabs
  full_name VARCHAR(150) NULL,            -- shown in the tablet header and on guest-facing screens
  logo MEDIUMTEXT NULL,                   -- data URL (png/jpeg/webp), resized in the browser
  logo_at DATETIME NULL,
  tennis TINYINT(1) NOT NULL DEFAULT 0,   -- course hires tennis racquets / tennis shoes
  shoes TINYINT(1) NOT NULL DEFAULT 0,    -- course hires golf shoes
  room_charge TINYINT(1) NOT NULL DEFAULT 0,   -- guests can have fees charged to their hotel room (head office switches this on)
  t9 SMALLINT NOT NULL DEFAULT 135,        -- expected minutes out for 9 holes (carts-out animation)
  t18 SMALLINT NOT NULL DEFAULT 270,       -- expected minutes out for 18 holes
  late9 SMALLINT NOT NULL DEFAULT 210,     -- a 9-hole cart still out after this many minutes is overdue
  late18 SMALLINT NOT NULL DEFAULT 360,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role ENUM('staff','supervisor','admin') NOT NULL DEFAULT 'staff',
  course_id INT NULL,                     -- NULL = head office: can switch between all courses
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS guests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mobile VARCHAR(30) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(150) NULL,
  country VARCHAR(80) NULL,
  hotel VARCHAR(150) NULL,
  room VARCHAR(30) NULL,
  dob DATE NULL,
  guest_type ENUM('guest','member') NOT NULL DEFAULT 'guest',
  marketing_optin TINYINT(1) NOT NULL DEFAULT 0,
  alert_note VARCHAR(255) NULL,           -- e.g. patron warning, shown to staff
  visits INT NOT NULL DEFAULT 0,
  last_visit DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mobile (mobile),
  KEY idx_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL DEFAULT 1,
  kind ENUM('cart','clubs','shoes','racquet') NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(150) NULL,          -- e.g. "Men's RH"
  fee DECIMAL(8,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  maintenance TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  gps_unit_id VARCHAR(80) NULL,           -- pairs the cart with the GPS unit
  gps_status VARCHAR(20) NULL,            -- ready|charging|offline|out_of_bounds (from GPS sync)
  gps_battery TINYINT NULL,
  gps_lat DECIMAL(9,6) NULL,
  gps_lng DECIMAL(9,6) NULL,
  gps_seen DATETIME NULL,
  UNIQUE KEY uq_course_kind_name (course_id, kind, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS checkins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL DEFAULT 1,
  main_guest_id INT NULL,
  holes TINYINT NOT NULL DEFAULT 18,
  fee_status ENUM('paid','room','unpaid','member') NOT NULL DEFAULT 'unpaid',
  tee_time VARCHAR(5) NULL,
  cart_id INT NULL,
  clubs_id INT NULL,
  hire_fee DECIMAL(8,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  bags VARCHAR(40) NULL,                  -- bag number(s), e.g. "12, 14"
  terms_version INT NOT NULL,
  staff_id INT NOT NULL,
  checked_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  returned_at DATETIME NULL,
  return_staff_id INT NULL,
  return_condition ENUM('ok','damaged','warning') NULL,
  return_note VARCHAR(500) NULL,
  return_photo MEDIUMTEXT NULL,           -- data URL (jpeg, resized in browser)
  KEY idx_open (returned_at),
  KEY idx_date (checked_in_at),
  KEY idx_course_date (course_id, checked_in_at),
  FOREIGN KEY (main_guest_id) REFERENCES guests(id),
  FOREIGN KEY (cart_id) REFERENCES assets(id),
  FOREIGN KEY (clubs_id) REFERENCES assets(id),
  FOREIGN KEY (staff_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS checkin_items (   -- extra hire on a check-in: rental shoes, tennis racquets
  id INT AUTO_INCREMENT PRIMARY KEY,
  checkin_id INT NOT NULL,
  asset_id INT NOT NULL,
  hire_fee DECIMAL(8,2) NOT NULL DEFAULT 0,
  KEY idx_asset (asset_id),
  KEY idx_checkin (checkin_id),
  FOREIGN KEY (checkin_id) REFERENCES checkins(id) ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES assets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS checkin_golfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  checkin_id INT NOT NULL,
  guest_id INT NOT NULL,
  is_main TINYINT(1) NOT NULL DEFAULT 0,
  signature MEDIUMTEXT NOT NULL,          -- PNG data URL
  signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  signed_ip VARCHAR(45) NULL,
  guardian_name VARCHAR(120) NULL,        -- set when golfer is under 18
  guest_type ENUM('guest','member') NOT NULL DEFAULT 'guest',   -- per check-in, so membership is per course
  member_id INT NULL,                     -- matched row in members, when found
  hotel VARCHAR(150) NULL,                -- as at check-in (guest record holds only the latest)
  country VARCHAR(80) NULL,
  FOREIGN KEY (checkin_id) REFERENCES checkins(id) ON DELETE CASCADE,
  FOREIGN KEY (guest_id) REFERENCES guests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-course member list uploaded by each course admin (names, numbers, expiry dates).
CREATE TABLE IF NOT EXISTS members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  member_no VARCHAR(40) NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  mobile VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  starts_on DATE NULL,                    -- optional: when the current membership year began
  expires_on DATE NULL,                   -- NULL = no expiry recorded
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_course_no (course_id, member_no),
  KEY idx_mobile (course_id, mobile),
  KEY idx_name (course_id, last_name, first_name),
  KEY idx_exp (course_id, expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS terms (
  version INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NULL,                     -- terms are per course (a course without its own uses the newest shared ones)
  body MEDIUMTEXT NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What each course's admin allows supervisors and staff to do (no rows = defaults: supervisors everything, staff nothing extra).
CREATE TABLE IF NOT EXISTS role_perms (
  course_id INT NOT NULL,
  role ENUM('supervisor','staff') NOT NULL,
  perm VARCHAR(30) NOT NULL,
  allowed TINYINT(1) NOT NULL,
  PRIMARY KEY (course_id, role, perm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  ip VARCHAR(45) NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_u (username, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO courses (id, name, full_name, tennis, shoes) VALUES (1, 'Natadola', 'Natadola Bay Golf Course', 0, 0), (2, 'Denarau', 'Denarau Golf & Racquet Club', 1, 1);

-- Placeholder terms: REPLACE with wording approved by your lawyer before going live.
INSERT INTO terms (course_id, body) VALUES (1,
'PLACEHOLDER TERMS - TO BE REPLACED WITH LEGALLY REVIEWED FIJI WORDING.\n\n1. I will use the golf cart and/or hired clubs with care and follow all course rules and staff instructions.\n2. I accept responsibility for any damage to the cart or hired equipment while in my care, other than fair wear and tear.\n3. I will return the cart and equipment at the end of my round.\n4. Hired clubs are for my use only and must not be shared.\n5. I play golf at my own risk and accept the course is not liable for injury or loss caused by my own actions.\n6. I understand my details are stored to speed up future check-ins.');

-- Denarau Golf & Racquet Club wording, transcribed from their paper slip. Have it checked before relying on it.
INSERT INTO terms (course_id, body) VALUES (2,
'CART EQUIPMENT RENTAL\nI agree that I am liable and responsible for all rental equipment and the golf cart whilst in my care.\n\nGOLF CART RENTAL AGREEMENT\n1. I will return the golf cart immediately following the completion of 18 holes of play in the same condition as when I received it.\n2. No more than 2 people and 2 golf bags will occupy or be carried in the golf cart at any one time.\n3. Only persons holding a current drivers licence will be permitted to drive the golf cart.\n4. The Denarau Golf & Racquet Club will not be liable for any damages to the cart, personal injury or property damage from or through my negligence.\n5. I acknowledge that I will be liable for any damages as a result of my negligent operation of the golf cart.\n6. I agree to pay the Denarau Golf & Racquet Club the cost of any repairs considered reasonably necessary by Denarau Golf & Racquet Club to the cart whilst in my care within 14 days upon receipt of an account.\n7. I agree to pay Denarau Golf & Racquet Club for any damages caused to either rental shoes or clubs whilst in my care.\n8. No sharing of golf sets is allowed.\n\nTENNIS RENTAL AGREEMENT\n1. I will return racquet and/or shoes immediately following the completion of play in the same condition as was received. Reasonable wear and tear accepted.\n2. I acknowledge that I will be liable for any damages to either racquet and/or shoes through negligent use.\n3. I agree to pay the Denarau Golf & Racquet Club for damages exceeding reasonable wear and tear.');
