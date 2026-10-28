-- Bula Golf check-in schema (MySQL 5.7+ / MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role ENUM('staff','manager') NOT NULL DEFAULT 'staff',
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
  kind ENUM('cart','clubs') NOT NULL,
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
  UNIQUE KEY uq_kind_name (kind, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS checkins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  main_guest_id INT NULL,
  holes TINYINT NOT NULL DEFAULT 18,
  fee_status ENUM('paid','room','unpaid') NOT NULL DEFAULT 'unpaid',
  tee_time VARCHAR(5) NULL,
  cart_id INT NULL,
  clubs_id INT NULL,
  hire_fee DECIMAL(8,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
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
  FOREIGN KEY (main_guest_id) REFERENCES guests(id),
  FOREIGN KEY (cart_id) REFERENCES assets(id),
  FOREIGN KEY (clubs_id) REFERENCES assets(id),
  FOREIGN KEY (staff_id) REFERENCES users(id)
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
  FOREIGN KEY (checkin_id) REFERENCES checkins(id) ON DELETE CASCADE,
  FOREIGN KEY (guest_id) REFERENCES guests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS terms (
  version INT AUTO_INCREMENT PRIMARY KEY,
  body MEDIUMTEXT NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  ip VARCHAR(45) NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_u (username, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Placeholder terms: REPLACE with wording approved by your lawyer before going live.
INSERT INTO terms (body) VALUES (
'PLACEHOLDER TERMS - TO BE REPLACED WITH LEGALLY REVIEWED FIJI WORDING.\n\n1. I will use the golf cart and/or hired clubs with care and follow all course rules and staff instructions.\n2. I accept responsibility for any damage to the cart or hired equipment while in my care, other than fair wear and tear.\n3. I will return the cart and equipment at the end of my round.\n4. Hired clubs are for my use only and must not be shared.\n5. I play golf at my own risk and accept the course is not liable for injury or loss caused by my own actions.\n6. I understand my details are stored to speed up future check-ins.');
