-- MySQL schema for Telegram Game Bot
-- Requirements: MySQL 8.0+, utf8mb4
-- Safe re-runs: IF NOT EXISTS used where possible

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Core entities
CREATE TABLE IF NOT EXISTS countries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  type ENUM('free','vip') NOT NULL DEFAULT 'free',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  telegram_id BIGINT UNSIGNED NOT NULL UNIQUE,
  username VARCHAR(64) NULL,
  first_name VARCHAR(128) NULL,
  last_name VARCHAR(128) NULL,
  language_code VARCHAR(8) NULL,
  country_id INT UNSIGNED NULL,
  points BIGINT NOT NULL DEFAULT 0,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_banned TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active_at DATETIME NULL,
  CONSTRAINT fk_users_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_user_country (country_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Factory definitions and ownership
CREATE TABLE IF NOT EXISTS factory_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  base_price_points INT UNSIGNED NOT NULL,
  base_income_points INT UNSIGNED NOT NULL,
  max_level INT UNSIGNED NOT NULL DEFAULT 10,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_factories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  factory_type_id INT UNSIGNED NOT NULL,
  level INT UNSIGNED NOT NULL DEFAULT 1,
  upgrading_until DATETIME NULL,
  last_collected_at DATETIME NULL,
  cooldown_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_uf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_uf_ft FOREIGN KEY (factory_type_id) REFERENCES factory_types(id) ON DELETE RESTRICT,
  UNIQUE KEY uniq_user_factory_type (user_id, factory_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shops
CREATE TABLE IF NOT EXISTS shop_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  type ENUM('normal','vip') NOT NULL DEFAULT 'normal',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  is_vip TINYINT(1) NOT NULL DEFAULT 0,
  price_points INT UNSIGNED NULL,
  price_toman INT UNSIGNED NULL,
  grant_type ENUM('force','factory','points') NOT NULL DEFAULT 'force',
  grant_factory_type_id INT UNSIGNED NULL,
  grant_points INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_items_cat FOREIGN KEY (category_id) REFERENCES shop_categories(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_grant_ft FOREIGN KEY (grant_factory_type_id) REFERENCES factory_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ui_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ui_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('earn','spend','admin_adjust','reward') NOT NULL,
  amount_points BIGINT NOT NULL,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_tx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Play submissions
CREATE TABLE IF NOT EXISTS submissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  country_id INT UNSIGNED NULL,
  type ENUM('sabotage','security','scenario') NOT NULL,
  text TEXT NULL,
  photo_file_id VARCHAR(255) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by INT UNSIGNED NULL,
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transfers (requires admin approval)
CREATE TABLE IF NOT EXISTS transfers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_item_id BIGINT UNSIGNED NOT NULL,
  from_user_id INT UNSIGNED NOT NULL,
  to_user_id INT UNSIGNED NOT NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  decided_by INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  CONSTRAINT fk_tr_ui FOREIGN KEY (user_item_id) REFERENCES user_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_tr_from FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tr_to FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events listing
CREATE TABLE IF NOT EXISTS events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VIP point packages (for Buy Points)
CREATE TABLE IF NOT EXISTS point_packages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  points INT UNSIGNED NOT NULL,
  price_toman INT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group Q&A
CREATE TABLE IF NOT EXISTS questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_text TEXT NOT NULL,
  correct_answer VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_id INT UNSIGNED NOT NULL,
  group_chat_id BIGINT NOT NULL,
  message_id BIGINT NOT NULL,
  posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  answered_by BIGINT NULL,
  answered_at DATETIME NULL,
  deleted_at DATETIME NULL,
  reward_points INT UNSIGNED NULL,
  CONSTRAINT fk_qp_q FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
  INDEX idx_qp_group_active (group_chat_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VIP purchase request log (optional)
CREATE TABLE IF NOT EXISTS vip_purchase_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NULL,
  point_package_id INT UNSIGNED NULL,
  status ENUM('requested','contacted','completed','cancelled') NOT NULL DEFAULT 'requested',
  message_text TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vpr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vpr_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL,
  CONSTRAINT fk_vpr_pkg FOREIGN KEY (point_package_id) REFERENCES point_packages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key-value settings
CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(64) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed data
INSERT IGNORE INTO countries (id, name, type) VALUES
  (1, 'آلمان', 'free'),
  (2, 'فرانسه', 'vip'),
  (3, 'ایتالیا', 'free'),
  (4, 'ژاپن', 'vip'),
  (5, 'ایران', 'free');

INSERT IGNORE INTO factory_types (id, name, base_price_points, base_income_points, max_level, description) VALUES
  (1, 'کارخانه فولاد', 1000, 200, 10, 'تولید فولاد با ظرفیت پایه'),
  (2, 'کارخانه الکترونیک', 2000, 400, 12, 'مونتاژ قطعات الکترونیکی');

INSERT IGNORE INTO shop_categories (id, name, type, sort_order) VALUES
  (1, 'نیروها', 'normal', 1),
  (2, 'کارخانه‌ها', 'normal', 2),
  (3, 'VIP آیتم‌ها', 'vip', 1);

INSERT IGNORE INTO items (id, category_id, name, description, is_vip, price_points, price_toman, grant_type, grant_factory_type_id, grant_points, sort_order) VALUES
  (1, 1, 'جاسوس', 'نیروی ویژه برای ماموریت‌ها', 0, 500, NULL, 'force', NULL, NULL, 1),
  (2, 2, 'مجوز کارخانه فولاد', 'خرید کارخانه فولاد سطح ۱', 0, 1000, NULL, 'factory', 1, NULL, 1),
  (3, 3, 'VIP سپر امنیتی', 'آیتم VIP ویژه', 1, NULL, 150000, 'force', NULL, NULL, 1),
  (4, 1, 'بسته امتیاز داخلی', 'دریافت 1000 امتیاز (عادی)', 0, 0, NULL, 'points', NULL, 1000, 2);

INSERT IGNORE INTO events (id, title, description) VALUES
  (1, 'ایونت هالووین', 'در طول ایونت، پاداش‌ها ۲ برابر است');

INSERT IGNORE INTO point_packages (id, name, points, price_toman, sort_order) VALUES
  (1, '۱٬۰۰۰ امتیاز', 1000, 50000, 1),
  (2, '۵٬۰۰۰ امتیاز', 5000, 200000, 2),
  (3, '۱۰٬۰۰۰ امتیاز', 10000, 350000, 3);

INSERT IGNORE INTO questions (id, question_text, correct_answer) VALUES
  (1, 'پایتخت ایران کدام است؟', 'تهران'),
  (2, '۲+۲ چند می‌شود؟', '4');

INSERT INTO settings (`key`, `value`) VALUES
  ('reward_min', '100'),
  ('reward_max', '1000')
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);
