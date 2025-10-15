<?php
/**
 * Telegram Game Bot - Monolithic Implementation (PHP 8.1+)
 *
 * Features:
 * - Secure PDO (MySQL) with prepared statements
 * - Webhook JSON processing
 * - New user onboarding and country selection (free/VIP)
 * - Main menu and flows (Factories, Play, Shops, Events, Countries, Points & Forces, Contact Admin)
 * - Factories: list/upgrade/collect with cooldowns and upgrade timers
 * - Play: sabotage/security/scenario submissions (text/photo) queued for admin review
 * - Shops: normal (points) and VIP (Toman) with deep-link to admin
 * - My Points & Forces: owned items, transfer requests pending admin approval
 * - Group Q&A: CLI cron to post questions and webhook to validate replies and award points
 * - Maintenance mode and simple settings
 *
 * IMPORTANT: Fill the CONFIG section before use. Deploy this file to your webhook endpoint.
 */

declare(strict_types=1);

// ===============================
// CONFIGURATION (EDIT THESE)
// ===============================
// Telegram bot token
const BOT_TOKEN = '8259992553:AAFGEeyyozoxHVHAbR_7gpSjULrL79M0Lho';

// Admin numeric user ID and username
const ADMIN_ID = 5641303137;
const ADMIN_USERNAME = 'Dakal1'; // without @

// Group chat ID for Q&A (negative for supergroups)
const GROUP_CHAT_ID = -1002798392543;

// Optional log channel/chat for purchase logs (0 = disabled)
const LOG_CHANNEL_ID = -1002798392543; // same channel for Q&A and purchase logs

// Maintenance mode (true = users see maintenance message; admin bypasses)
const MAINTENANCE = false;

// Database connection
const DB_HOST = 'localhost';
const DB_PORT = 3306;
const DB_NAME = 'dakallli_Test2';
const DB_USER = 'dakallli_Test2';
const DB_PASS = 'hosyarww123';

// Timezone
date_default_timezone_set('Asia/Tehran');

// ===============================
// BOOTSTRAP
// ===============================
ini_set('display_errors', '0');
error_reporting(E_ALL);

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

// Ensure database schema and minimal seeds exist (idempotent)
function ensureSchema(): void {
    $pdo = db();
    $stmts = [];
    $stmts[] = "CREATE TABLE IF NOT EXISTS countries (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL UNIQUE,
      type ENUM('free','vip') NOT NULL DEFAULT 'free',
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS users (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS user_states (
      user_id INT UNSIGNED PRIMARY KEY,
      state VARCHAR(64) NOT NULL,
      meta JSON NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS factory_types (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      base_price_points INT UNSIGNED NOT NULL,
      base_income_points INT UNSIGNED NOT NULL,
      max_level INT UNSIGNED NOT NULL DEFAULT 10,
      description TEXT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS user_factories (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS shop_categories (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      type ENUM('normal','vip') NOT NULL DEFAULT 'normal',
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS items (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS user_items (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      item_id INT UNSIGNED NOT NULL,
      quantity INT UNSIGNED NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_ui_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ui_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS transactions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      type ENUM('earn','spend','admin_adjust','reward') NOT NULL,
      amount_points BIGINT NOT NULL,
      description VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      INDEX idx_tx_user_created (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS submissions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS transfers (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS events (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(150) NOT NULL,
      description TEXT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS point_packages (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      points INT UNSIGNED NOT NULL,
      price_toman INT UNSIGNED NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS questions (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      question_text TEXT NOT NULL,
      correct_answer VARCHAR(255) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS question_posts (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS vip_purchase_requests (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS settings (
      `key` VARCHAR(64) PRIMARY KEY,
      `value` VARCHAR(255) NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $stmts[] = "CREATE TABLE IF NOT EXISTS panel_tokens (
      token VARCHAR(128) PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NOT NULL,
      used_at DATETIME NULL,
      CONSTRAINT fk_pt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      INDEX idx_pt_user (user_id),
      INDEX idx_pt_exp (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    foreach ($stmts as $sql) { $pdo->exec($sql); }

    // Seed minimal data (idempotent)
    $pdo->exec("INSERT IGNORE INTO settings(`key`,`value`) VALUES ('reward_min','100'),('reward_max','1000')");
    $pdo->exec("INSERT IGNORE INTO countries (id,name,type) VALUES (1,'آلمان','free'),(2,'فرانسه','vip'),(3,'ایتالیا','free')");
    $pdo->exec("INSERT IGNORE INTO shop_categories (id,name,type,sort_order) VALUES (1,'نیروها','normal',1),(2,'کارخانه‌ها','normal',2),(3,'VIP آیتم‌ها','vip',1)");
    $pdo->exec("INSERT IGNORE INTO factory_types (id,name,base_price_points,base_income_points,max_level) VALUES (1,'کارخانه فولاد',1000,200,10)");
    $pdo->exec("INSERT IGNORE INTO items (id,category_id,name,is_vip,price_points,price_toman,grant_type,grant_factory_type_id,grant_points,sort_order) VALUES
      (1,1,'جاسوس',0,500,NULL,'force',NULL,NULL,1),
      (2,2,'مجوز کارخانه فولاد',0,1000,NULL,'factory',1,NULL,1),
      (3,3,'VIP سپر امنیتی',1,NULL,150000,'force',NULL,NULL,1)");

    // Schema extensions for new features (idempotent)
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS money BIGINT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE items ADD COLUMN IF NOT EXISTS price_money BIGINT UNSIGNED NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE items ADD COLUMN IF NOT EXISTS per_user_limit INT UNSIGNED NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE items ADD COLUMN IF NOT EXISTS per_user_daily_limit INT UNSIGNED NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE items ADD COLUMN IF NOT EXISTS pack_size INT UNSIGNED NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE countries ADD COLUMN IF NOT EXISTS starter_money BIGINT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE countries ADD COLUMN IF NOT EXISTS starter_points BIGINT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE countries ADD COLUMN IF NOT EXISTS settings JSON NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE factory_types ADD COLUMN IF NOT EXISTS base_price INT UNSIGNED NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE factory_types ADD COLUMN IF NOT EXISTS price_currency ENUM('money','points') NOT NULL DEFAULT 'money'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE factory_types ADD COLUMN IF NOT EXISTS base_income INT UNSIGNED NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE factory_types ADD COLUMN IF NOT EXISTS income_currency ENUM('money','points') NOT NULL DEFAULT 'money'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE factory_types ADD COLUMN IF NOT EXISTS payout_interval_hours INT UNSIGNED NOT NULL DEFAULT 24"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE factory_types ADD COLUMN IF NOT EXISTS upgrade_base_minutes INT UNSIGNED NOT NULL DEFAULT 60"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS amount_money BIGINT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

    // Country-item blocks
    $pdo->exec("CREATE TABLE IF NOT EXISTS country_item_blocks (
      country_id INT UNSIGNED NOT NULL,
      item_id INT UNSIGNED NOT NULL,
      blocked TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (country_id, item_id),
      CONSTRAINT fk_cib_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE,
      CONSTRAINT fk_cib_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Item purchases log (for per-day limits)
    $pdo->exec("CREATE TABLE IF NOT EXISTS item_purchases (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      item_id INT UNSIGNED NOT NULL,
      quantity INT UNSIGNED NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_ip_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ip_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
      INDEX idx_ip_user_day (user_id, item_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Point purchases (receipts)
    $pdo->exec("CREATE TABLE IF NOT EXISTS point_purchases (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      package_id INT UNSIGNED NOT NULL,
      code VARCHAR(32) NOT NULL,
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      photo_file_id VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      decided_at DATETIME NULL,
      decided_by INT UNSIGNED NULL,
      CONSTRAINT fk_pp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_pp_pkg FOREIGN KEY (package_id) REFERENCES point_packages(id) ON DELETE RESTRICT,
      UNIQUE KEY uniq_pp_code (code),
      INDEX idx_pp_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Missions
    $pdo->exec("CREATE TABLE IF NOT EXISTS missions (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      type ENUM('buy_shop') NOT NULL,
      shop_type ENUM('normal','vip') NULL,
      required_count INT UNSIGNED NOT NULL,
      reward_type ENUM('money','points') NOT NULL,
      reward_amount INT UNSIGNED NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_missions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      mission_id INT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      progress_count INT UNSIGNED NOT NULL DEFAULT 0,
      claimed_at DATETIME NULL,
      UNIQUE KEY uniq_um (mission_id, user_id),
      CONSTRAINT fk_um_m FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE,
      CONSTRAINT fk_um_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tg(string $method, array $params = []): array {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'description' => $err];
    }
    curl_close($ch);
    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['ok' => false, 'description' => 'Invalid JSON'];
}

function sendMessage($chatId, string $text, array $extra = []): void {
    $params = array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $extra);
    tg('sendMessage', $params);
}

function sendPhoto($chatId, string $fileIdOrUrl, array $extra = []): void {
    $params = array_merge([
        'chat_id' => $chatId,
        'photo' => $fileIdOrUrl,
        'parse_mode' => 'HTML',
    ], $extra);
    tg('sendPhoto', $params);
}

function answerCallback(string $callbackId, string $text, bool $alert = false): void {
    tg('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert,
    ]);
}

function editMessageText($chatId, int $messageId, string $text, array $extra = []): void {
    $params = array_merge([
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $extra);
    tg('editMessageText', $params);
}

function deleteMessage($chatId, int $messageId): void {
    tg('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
    ]);
}

// ===============================
// HELPERS
// ===============================
function normalizeAnswer(string $s): string {
    $s = trim(mb_strtolower($s, 'UTF-8'));
    // Persian digits to Latin
    $persianDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $latinDigits = ['0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($persianDigits, $latinDigits, $s);
    return $s;
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function tx(PDO $pdo): void { $pdo->beginTransaction(); }
function commit(PDO $pdo): void { if ($pdo->inTransaction()) $pdo->commit(); }
function rollback(PDO $pdo): void { if ($pdo->inTransaction()) $pdo->rollBack(); }

function getSetting(string $key, ?string $default = null): ?string {
    $stmt = db()->prepare('SELECT value FROM settings WHERE `key` = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string)$val : $default;
}

function logToChannel(string $text, ?string $photoFileId = null): void {
    if (!defined('LOG_CHANNEL_ID') || LOG_CHANNEL_ID === 0) return;
    if ($photoFileId) {
        sendPhoto(LOG_CHANNEL_ID, $photoFileId, ['caption' => $text]);
    } else {
        sendMessage(LOG_CHANNEL_ID, $text);
    }
}

// Country feature settings
function getCountrySettings(int $countryId): array {
    if ($countryId <= 0) return [
        'allow_factory_purchase' => true,
        'allow_shop_normal' => true,
        'allow_shop_vip' => true,
    ];
    $stmt = db()->prepare('SELECT settings FROM countries WHERE id = ?');
    $stmt->execute([$countryId]);
    $json = (string)($stmt->fetchColumn() ?: '');
    $arr = $json ? json_decode($json, true) : [];
    if (!is_array($arr)) $arr = [];
    $arr += [
        'allow_factory_purchase' => true,
        'allow_shop_normal' => true,
        'allow_shop_vip' => true,
    ];
    return $arr;
}

function isItemBlockedForCountry(?int $countryId, int $itemId): bool {
    if (!$countryId) return false;
    $stmt = db()->prepare('SELECT blocked FROM country_item_blocks WHERE country_id = ? AND item_id = ?');
    $stmt->execute([$countryId, $itemId]);
    $v = $stmt->fetchColumn();
    return (int)$v === 1;
}

// Missions progress updater (buy_shop)
function updateBuyShopMissions(int $userId, string $shopType): void {
    $stmt = db()->prepare("SELECT id, required_count FROM missions WHERE is_active = 1 AND type = 'buy_shop' AND (shop_type = ? OR shop_type IS NULL)");
    $stmt->execute([$shopType]);
    $missions = $stmt->fetchAll();
    if (!$missions) return;
    $pdo = db();
    foreach ($missions as $m) {
        $pdo->prepare('INSERT INTO user_missions (mission_id, user_id, progress_count) VALUES (?,?,1) ON DUPLICATE KEY UPDATE progress_count = progress_count + 1')
            ->execute([$m['id'], $userId]);
    }
}

// Factory helpers for flexible schema
function ftBasePrice(array $ft): int { return isset($ft['base_price']) ? (int)$ft['base_price'] : (int)($ft['base_price_points'] ?? 0); }
function ftPriceCurrency(array $ft): string { return $ft['price_currency'] ?? 'points'; }
function ftBaseIncome(array $ft): int { return isset($ft['base_income']) ? (int)$ft['base_income'] : (int)($ft['base_income_points'] ?? 0); }
function ftIncomeCurrency(array $ft): string { return $ft['income_currency'] ?? 'points'; }
function ftPayoutIntervalHours(array $ft): int { return (int)($ft['payout_interval_hours'] ?? 24); }
function ftUpgradeBaseMinutes(array $ft): int { return (int)($ft['upgrade_base_minutes'] ?? 60); }

// ===============================
// USER MODEL
// ===============================
function ensureUser(array $from): array {
    $pdo = db();
    $telegramId = (int)$from['id'];
    $username = $from['username'] ?? null;
    $firstName = $from['first_name'] ?? null;
    $lastName = $from['last_name'] ?? null;
    $lang = $from['language_code'] ?? null;

    $stmt = $pdo->prepare('SELECT * FROM users WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
    $user = $stmt->fetch();
    if (!$user) {
        $stmt = $pdo->prepare('INSERT INTO users (telegram_id, username, first_name, last_name, language_code, created_at, last_active_at) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$telegramId, $username, $firstName, $lastName, $lang, now(), now()]);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $user = $stmt->fetch();
    } else {
        $stmt = $pdo->prepare('UPDATE users SET username = ?, first_name = ?, last_name = ?, language_code = ?, last_active_at = ? WHERE id = ?');
        $stmt->execute([$username, $firstName, $lastName, $lang, now(), $user['id']]);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $user = $stmt->fetch();
    }
    return $user;
}

function isAdmin(array $user): bool {
    return (int)$user['is_admin'] === 1 || (int)$user['telegram_id'] === ADMIN_ID;
}

function hasCountry(array $user): bool {
    return !empty($user['country_id']);
}

function getUserCountryName(?int $countryId): ?string {
    if (!$countryId) return null;
    $stmt = db()->prepare('SELECT name FROM countries WHERE id = ?');
    $stmt->execute([$countryId]);
    $name = $stmt->fetchColumn();
    return $name ? (string)$name : null;
}

// ===============================
// KEYBOARDS
// ===============================
function mainMenu(array $user): array {
    if (!hasCountry($user)) {
        $keyboard = [
            [['text' => '🏳️ انتخاب کشور']],
            [['text' => '☎️ ارتباط با ادمین']],
        ];
    } else {
        $keyboard = [
            [ ['text' => '🏭 کارخانه‌ها'], ['text' => '🎮 بازی کردن'] ],
            [ ['text' => '💱 خرید امتیاز'], ['text' => '💲 فروشگاه عادی'], ['text' => '🛒 فروشگاه VIP'] ],
            [ ['text' => '💎 لیست ایونت‌ها'], ['text' => '🪪 لیست کشورهای فعال'] ],
            [ ['text' => '💳 امتیاز و نیروهای من'], ['text' => '☎️ ارتباط با ادمین'] ],
        ];
    }
    return [
        'keyboard' => $keyboard,
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'is_persistent' => true,
    ];
}

function inlineUrlButton(string $text, string $url): array {
    return [['text' => $text, 'url' => $url]];
}

// ===============================
// COUNTRY SELECTION
// ===============================
function countrySelectionKeyboard(): array {
    $pdo = db();
    $stmt = $pdo->query('SELECT c.id, c.name, c.type, c.is_active,
        (SELECT COUNT(1) FROM users u WHERE u.country_id = c.id) AS taken
        FROM countries c WHERE c.is_active = 1 ORDER BY c.name ASC LIMIT 100');
    $rows = $stmt->fetchAll();

    $inline = [];
    foreach ($rows as $row) {
        $name = $row['name'];
        $isVip = $row['type'] === 'vip';
        $taken = (int)$row['taken'] > 0;
        if ($isVip) {
            $text = '💎 ' . $name . ' (VIP)';
            $callback = 'COUNTRY_VIP|' . $row['id'];
            $inline[] = [ ['text' => $text, 'callback_data' => $callback] ];
        } else {
            if ($taken) {
                $text = '❌ ' . $name;
                $inline[] = [ ['text' => $text, 'callback_data' => 'NOOP'] ];
            } else {
                $text = '✅ ' . $name;
                $callback = 'COUNTRY_FREE_TAKE|' . $row['id'];
                $inline[] = [ ['text' => $text, 'callback_data' => $callback] ];
            }
        }
    }
    return ['inline_keyboard' => $inline];
}

function handleCountryCallback(array $user, string $action, int $countryId, array $cb): void {
    if ($action === 'COUNTRY_VIP') {
        $stmt = db()->prepare('SELECT name FROM countries WHERE id = ?');
        $stmt->execute([$countryId]);
        $name = (string)($stmt->fetchColumn() ?: '');
        $text = "برای دریافت کشور VIP <b>{$name}</b> با ادمین در تماس باشید." . "\n\n" .
            "روی دکمه زیر بزنید و پیام زیر را برای ادمین ارسال کنید:" . "\n" .
            "<code>سلام. درخواست کشور VIP دارم: {$name}</code>";
        $url = 'https://t.me/' . ADMIN_USERNAME;
        answerCallback($cb['id'], 'راهنمای دریافت کشور VIP ارسال شد.');
        sendMessage($user['telegram_id'], $text, [ 'reply_markup' => [ 'inline_keyboard' => [ inlineUrlButton('ارتباط با ادمین', $url) ] ] ]);
        return;
    }
    if ($action === 'COUNTRY_FREE_TAKE') {
        if (hasCountry($user)) {
            answerCallback($cb['id'], 'شما قبلاً کشوری دارید.');
            return;
        }
        $pdo = db();
        try {
            tx($pdo);
            // Ensure country still available
            $q = $pdo->prepare('SELECT COUNT(1) FROM users WHERE country_id = ?');
            $q->execute([$countryId]);
            $taken = (int)$q->fetchColumn() > 0;
            if ($taken) {
                rollback($pdo);
                answerCallback($cb['id'], 'این کشور به تازگی توسط کاربر دیگری گرفته شد.');
                return;
            }
            // Assign to user
            // Starter pack
            $c = $pdo->prepare('SELECT starter_money, starter_points FROM countries WHERE id = ?');
            $c->execute([$countryId]);
            $cinfo = $c->fetch();
            $starterMoney = (int)($cinfo['starter_money'] ?? 0);
            $starterPoints = (int)($cinfo['starter_points'] ?? 0);
            $u = $pdo->prepare('UPDATE users SET country_id = ?, money = money + ?, points = points + ? WHERE id = ?');
            $u->execute([$countryId, $starterMoney, $starterPoints, $user['id']]);
            commit($pdo);
        } catch (Throwable $e) {
            rollback($pdo);
            answerCallback($cb['id'], 'خطا در تخصیص کشور.');
            return;
        }
        $name = getUserCountryName($countryId) ?? 'کشور شما';
        answerCallback($cb['id'], 'کشور با موفقیت ثبت شد.');
        sendMessage($user['telegram_id'], 'کشور شما: <b>' . htmlspecialchars($name) . '</b> ثبت شد. منوی اصلی کامل فعال شد.', [
            'reply_markup' => mainMenu(['country_id' => $countryId] + $user)
        ]);
        return;
    }
}

// ===============================
// FACTORIES
// ===============================
function calcUpgradeCost(int $basePrice, int $currentLevel): int {
    // Cost to go from currentLevel -> currentLevel+1
    // cost = basePrice * 2^(currentLevel)
    return (int)round($basePrice * (2 ** $currentLevel));
}

function calcIncome(int $baseIncome, int $level): int {
    // Each level adds +50% to base income
    $multiplier = 1.0 + 0.5 * (max(1, $level) - 1);
    return (int)round($baseIncome * $multiplier);
}

function upgradeDurationMinutes(int $levelNext): int {
    // Level 1 upgrade time = 30 minutes; each next +15 minutes
    // If upgrading to level 2 => levelNext=2 => 30 + 15*(2-1) = 45
    return 30 + 15 * max(0, $levelNext - 1);
}

function listUserFactories(array $user): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT uf.id AS uf_id, uf.level, uf.upgrading_until, uf.cooldown_until, ft.*
        FROM user_factories uf JOIN factory_types ft ON ft.id = uf.factory_type_id
        WHERE uf.user_id = ? ORDER BY ft.name');
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $kb = [ [ ['text'=>'🛒 خرید کارخانه','callback_data'=>'FACTORY_BUY_LIST'] ] ];
        sendMessage($user['telegram_id'], "شما هیچ کارخانه‌ای ندارید.", [ 'reply_markup' => ['inline_keyboard'=>$kb] ]);
        return;
    }

    foreach ($rows as $r) {
        $name = $r['name'];
        $level = (int)$r['level'];
        $income = calcIncome(ftBaseIncome($r), $level);
        $basePrice = ftBasePrice($r);
        $maxLevel = (int)$r['max_level'];
        $upgradingUntil = $r['upgrading_until'] ? strtotime($r['upgrading_until']) : null;
        $cooldownUntil = $r['cooldown_until'] ? strtotime($r['cooldown_until']) : null;

        $incomeUnit = (ftIncomeCurrency($r) === 'money') ? 'پول بازی' : 'امتیاز';
        $text = "🏭 <b>{$name}</b> - سطح <b>{$level}</b>\n" .
                "درآمد: <b>{$income}</b> {$incomeUnit}";

        $buttons = [];
        if ($upgradingUntil && $upgradingUntil > time()) {
            $remainingMin = (int)ceil(($upgradingUntil - time())/60);
            $buttons[] = [['text' => '⏳ ارتقا در حال انجام (' . $remainingMin . ' دقیقه)', 'callback_data' => 'NOOP']];
        } elseif ($level >= $maxLevel) {
            $buttons[] = [['text' => '🔝 حداکثر سطح', 'callback_data' => 'NOOP']];
        } else {
            $nextCost = calcUpgradeCost($basePrice, $level);
            $costUnit = (ftPriceCurrency($r) === 'money') ? 'پول' : 'امتیاز';
            $buttons[] = [['text' => "⬆️ ارتقا به سطح " . ($level+1) . " (" . $nextCost . " " . $costUnit . ")", 'callback_data' => 'FACTORY_UPGRADE|' . $r['uf_id'] ]];
        }

        if ($cooldownUntil && $cooldownUntil > time()) {
            $remainingH = (int)ceil(($cooldownUntil - time())/3600);
            $buttons[] = [['text' => '🕒 استراحت تا ' . $remainingH . ' ساعت', 'callback_data' => 'NOOP']];
        } else {
            $buttons[] = [['text' => '💰 دریافت درآمد: ' . $income, 'callback_data' => 'FACTORY_COLLECT|' . $r['uf_id'] ]];
        }

        $buttons[] = [['text'=>'🛒 خرید کارخانه','callback_data'=>'FACTORY_BUY_LIST']];
        sendMessage($user['telegram_id'], $text, [ 'reply_markup' => ['inline_keyboard' => $buttons] ]);
    }
}

function handleFactoryAction(array $user, string $action, int $userFactoryId, array $cb): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT uf.*, ft.name, ft.base_price_points, ft.base_income_points, ft.max_level FROM user_factories uf JOIN factory_types ft ON ft.id = uf.factory_type_id WHERE uf.id = ? AND uf.user_id = ?');
    $stmt->execute([$userFactoryId, $user['id']]);
    $f = $stmt->fetch();
    if (!$f) { answerCallback($cb['id'], 'کارخانه یافت نشد.'); return; }

    if ($action === 'FACTORY_UPGRADE') {
        if ($f['upgrading_until'] && strtotime($f['upgrading_until']) > time()) {
            answerCallback($cb['id'], 'در حال ارتقا است.'); return;
        }
        if ($f['level'] >= $f['max_level']) { answerCallback($cb['id'], 'به حداکثر سطح رسیده‌اید.'); return; }
        $nextCost = calcUpgradeCost((int)$f['base_price_points'], (int)$f['level']);
        $durationMin = upgradeDurationMinutes((int)$f['level'] + 1);
        $text = 'آیا برای ارتقا به سطح ' . ((int)$f['level'] + 1) . ' با هزینه ' . $nextCost . ' امتیاز موافقید؟\nزمان ارتقا: ' . $durationMin . ' دقیقه';
        $buttons = [ [ ['text' => '✅ تایید', 'callback_data' => 'FACTORY_UPGRADE_CONFIRM|' . $userFactoryId], ['text' => '❌ انصراف', 'callback_data' => 'NOOP'] ] ];
        answerCallback($cb['id'], 'هزینه ارتقا نمایش داده شد.');
        editMessageText($cb['message']['chat']['id'], $cb['message']['message_id'], $text, [ 'reply_markup' => ['inline_keyboard' => $buttons] ]);
        return;
    }

    if ($action === 'FACTORY_UPGRADE_CONFIRM') {
        if ($f['upgrading_until'] && strtotime($f['upgrading_until']) > time()) {
            answerCallback($cb['id'], 'در حال ارتقا است.'); return;
        }
        if ($f['level'] >= $f['max_level']) { answerCallback($cb['id'], 'به حداکثر سطح رسیده‌اید.'); return; }
        $nextCost = calcUpgradeCost((int)$f['base_price_points'], (int)$f['level']);
        try {
            tx($pdo);
            // Lock user row
            $u = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $u->execute([$user['id']]);
            $usr = $u->fetch();
            if ((int)$usr['points'] < $nextCost) {
                rollback($pdo);
                answerCallback($cb['id'], 'امتیاز کافی ندارید.'); return;
            }
            $newPoints = (int)$usr['points'] - $nextCost;
            $upgradingUntil = date('Y-m-d H:i:s', time() + upgradeDurationMinutes((int)$f['level'] + 1) * 60);
            $pdo->prepare('UPDATE users SET points = ? WHERE id = ?')->execute([$newPoints, $user['id']]);
            $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
                ->execute([$user['id'], 'spend', -$nextCost, 'Factory upgrade', now()]);
            $pdo->prepare('UPDATE user_factories SET upgrading_until = ? WHERE id = ?')->execute([$upgradingUntil, $f['id']]);
            commit($pdo);
        } catch (Throwable $e) { rollback($pdo); answerCallback($cb['id'], 'ارتقا ناموفق بود.'); return; }
        answerCallback($cb['id'], 'ارتقا آغاز شد.');
        editMessageText($cb['message']['chat']['id'], $cb['message']['message_id'], '⏳ ارتقا آغاز شد. لطفاً تا اتمام زمان منتظر بمانید.');
        return;
    }

    if ($action === 'FACTORY_COLLECT') {
        $cooldownUntil = $f['cooldown_until'] ? strtotime($f['cooldown_until']) : null;
        if ($cooldownUntil && $cooldownUntil > time()) { answerCallback($cb['id'], 'کارخانه در استراحت است.'); return; }
        $upgradingUntil = $f['upgrading_until'] ? strtotime($f['upgrading_until']) : null;
        if ($upgradingUntil && $upgradingUntil > time()) { answerCallback($cb['id'], 'ارتقا در حال انجام است.'); return; }
        // If upgrading time passed, apply level increment
        if ($upgradingUntil && $upgradingUntil <= time()) {
            $pdo->prepare('UPDATE user_factories SET level = level + 1, upgrading_until = NULL WHERE id = ?')->execute([$f['id']]);
            $f['level'] = (int)$f['level'] + 1;
        }
        $income = calcIncome((int)$f['base_income_points'], (int)$f['level']);
        try {
            tx($pdo);
            $u = $pdo->prepare('SELECT points FROM users WHERE id = ? FOR UPDATE');
            $u->execute([$user['id']]);
            $points = (int)$u->fetchColumn();
            $newPoints = $points + $income;
            $pdo->prepare('UPDATE users SET points = ? WHERE id = ?')->execute([$newPoints, $user['id']]);
            $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
                ->execute([$user['id'], 'earn', $income, 'Factory income', now()]);
            $pdo->prepare('UPDATE user_factories SET last_collected_at = ?, cooldown_until = ? WHERE id = ?')
                ->execute([now(), date('Y-m-d H:i:s', time() + 24*3600), $f['id']]);
            commit($pdo);
        } catch (Throwable $e) { rollback($pdo); answerCallback($cb['id'], 'خطا در دریافت درآمد.'); return; }
        answerCallback($cb['id'], 'درآمد اضافه شد.');
        editMessageText($cb['message']['chat']['id'], $cb['message']['message_id'], '✅ ' . $income . ' امتیاز به حساب شما اضافه شد. کارخانه تا ۲۴ ساعت در استراحت است.');
        // Log
        $country = getUserCountryName((int)($user['country_id'] ?? 0)) ?? 'بدون کشور';
        $log = '📈 دریافت درآمد کارخانه\nکاربر: '.$user['telegram_id'].' (@'.($user['username'] ?? '-').')\nکشور: '.$country.'\nمبلغ: '.$income.' امتیاز';
        logToChannel($log, null);
        return;
    }
}

// ===============================
// PLAY SUBMISSIONS
// ===============================
function setUserState(int $userId, string $state, array $meta = []): void {
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_states (
      user_id INT UNSIGNED PRIMARY KEY,
      state VARCHAR(64) NOT NULL,
      meta JSON NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $stmt = $pdo->prepare('INSERT INTO user_states (user_id, state, meta, updated_at) VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE state=VALUES(state), meta=VALUES(meta), updated_at=VALUES(updated_at)');
    $stmt->execute([$userId, $state, json_encode($meta, JSON_UNESCAPED_UNICODE), now()]);
}

function clearUserState(int $userId): void {
    $stmt = db()->prepare('DELETE FROM user_states WHERE user_id = ?');
    $stmt->execute([$userId]);
}

function getUserState(int $userId): ?array {
    // Ensure table exists defensively (covers older DBs without user_states)
    db()->exec('CREATE TABLE IF NOT EXISTS user_states (
      user_id INT UNSIGNED PRIMARY KEY,
      state VARCHAR(64) NOT NULL,
      meta JSON NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $stmt = db()->prepare('SELECT state, meta FROM user_states WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $meta = $row['meta'] ? json_decode((string)$row['meta'], true) : [];
    return ['state' => $row['state'], 'meta' => is_array($meta) ? $meta : []];
}

function promptPlayType(array $user): void {
    $buttons = [
        [ ['text' => '💣 رول خرابکاری', 'callback_data' => 'PLAY|sabotage'], ['text' => '🛡️ رول امنیتی', 'callback_data' => 'PLAY|security'] ],
        [ ['text' => '📜 سناریو', 'callback_data' => 'PLAY|scenario'] ]
    ];
    sendMessage($user['telegram_id'], 'نوع رول خود را انتخاب کنید و سپس متن و در صورت تمایل یک عکس ارسال کنید.', [ 'reply_markup' => [ 'inline_keyboard' => $buttons ] ]);
}

function handlePlayCallback(array $user, string $type, array $cb): void {
    if (!in_array($type, ['sabotage','security','scenario'], true)) { answerCallback($cb['id'], 'نوع نامعتبر'); return; }
    setUserState((int)$user['id'], 'await_submission', ['type' => $type]);
    answerCallback($cb['id'], 'لطفاً متن و در صورت تمایل عکس را ارسال کنید.');
    sendMessage($user['telegram_id'], 'لطفاً متن و در صورت تمایل یک عکس ارسال کنید. ارسال شما برای بازبینی ذخیره خواهد شد.');
}

function saveSubmission(array $user, string $type, ?string $text, ?string $photoFileId): void {
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO submissions (user_id, country_id, type, text, photo_file_id, status, created_at) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$user['id'], $user['country_id'] ?: null, $type, $text, $photoFileId, 'pending', now()]);
    clearUserState((int)$user['id']);
    sendMessage($user['telegram_id'], 'ارسال شما ثبت شد و پس از بررسی نتیجه اطلاع‌رسانی می‌شود.');
}

// ===============================
// SHOPS
// ===============================
function listShopCategories(array $user, string $shopType): void {
    // Country-based gating
    $settings = getCountrySettings((int)($user['country_id'] ?? 0));
    if ($shopType === 'normal' && empty($settings['allow_shop_normal'])) { sendMessage($user['telegram_id'], 'این بخش برای کشور شما غیرفعال است.'); return; }
    if ($shopType === 'vip' && empty($settings['allow_shop_vip'])) { sendMessage($user['telegram_id'], 'این بخش برای کشور شما غیرفعال است.'); return; }

    $stmt = db()->prepare('SELECT id, name FROM shop_categories WHERE type = ? AND is_active = 1 ORDER BY sort_order, name');
    $stmt->execute([$shopType]);
    $rows = $stmt->fetchAll();
    if (!$rows) { sendMessage($user['telegram_id'], 'دسته‌بندی‌ای یافت نشد.'); return; }
    $inline = [];
    foreach ($rows as $r) {
        $inline[] = [ ['text' => $r['name'], 'callback_data' => 'SHOP_CAT|' . $shopType . '|' . $r['id']] ];
    }
    sendMessage($user['telegram_id'], 'یک دسته‌بندی را انتخاب کنید:', [ 'reply_markup' => ['inline_keyboard' => $inline] ]);
}

function listShopItems(array $user, string $shopType, int $categoryId): void {
    $stmt = db()->prepare('SELECT i.* FROM items i JOIN shop_categories c ON c.id = i.category_id WHERE i.category_id = ? AND i.is_active = 1 AND c.type = ? ORDER BY i.sort_order, i.name');
    $stmt->execute([$categoryId, $shopType]);
    $rows = $stmt->fetchAll();
    if (!$rows) { sendMessage($user['telegram_id'], 'در این دسته آیتمی موجود نیست.'); return; }
    foreach ($rows as $it) {
        $name = $it['name'];
        $desc = $it['description'] ?: '';
        $isVip = (int)$it['is_vip'] === 1 || $shopType === 'vip';
        // Normal shop uses game money; VIP uses points
        // skip blocked items per country
        if (isItemBlockedForCountry((int)($user['country_id'] ?? 0), (int)$it['id'])) continue;
        $priceTxt = $isVip ? ((int)$it['price_points'] . ' امتیاز') : ((int)($it['price_money'] ?? 0) . ' پول بازی');
        $text = '<b>' . htmlspecialchars($name) . '</b>\n' . htmlspecialchars($desc) . "\nقیمت: <b>{$priceTxt}</b>";
        if ($isVip) {
            $cb = 'BUY_VIP_ITEM|' . $it['id'];
            $btn = [ ['text' => '🛒 خرید با امتیاز VIP', 'callback_data' => $cb] ];
        } else {
            $cb = 'BUY_ITEM|' . $it['id'];
            $btn = [ ['text' => '🛒 خرید', 'callback_data' => $cb] ];
        }
        sendMessage($user['telegram_id'], $text, [ 'reply_markup' => ['inline_keyboard' => [ $btn ] ] ]);
    }
}

function handleBuyItem(array $user, int $itemId, array $cb): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT i.*, c.type AS shop_type FROM items i JOIN shop_categories c ON c.id = i.category_id WHERE i.id = ? AND i.is_active = 1');
    $stmt->execute([$itemId]);
    $it = $stmt->fetch();
    if (!$it) { answerCallback($cb['id'], 'آیتم یافت نشد.'); return; }
    $isVip = (int)$it['is_vip'] === 1 || $it['shop_type'] === 'vip';
    if ($isVip) { answerCallback($cb['id'], 'این آیتم VIP است.'); return; }
    $price = (int)($it['price_money'] ?? 0);
    try {
        tx($pdo);
        $u = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
        $u->execute([$user['id']]);
        $usr = $u->fetch();
        // Limits check
        $limit = (int)($it['per_user_limit'] ?? 0);
        if ($limit > 0) {
            $owned = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM user_items WHERE user_id = ? AND item_id = ?');
            $owned->execute([$user['id'], $itemId]);
            $cur = (int)$owned->fetchColumn();
            if ($cur >= $limit) { rollback($pdo); answerCallback($cb['id'], 'به حداکثر خرید مجاز برای این آیتم رسیده‌اید.'); return; }
        }
        $daily = (int)($it['per_user_daily_limit'] ?? 0);
        if ($daily > 0) {
            $dailyQ = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM item_purchases WHERE user_id = ? AND item_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
            $dailyQ->execute([$user['id'], $itemId]);
            $d = (int)$dailyQ->fetchColumn();
            if ($d >= $daily) { rollback($pdo); answerCallback($cb['id'], 'به محدودیت روزانه خرید این آیتم رسیده‌اید.'); return; }
        }

        if ((int)$usr['money'] < $price) { rollback($pdo); answerCallback($cb['id'], 'پول بازی کافی نیست.'); return; }
        $pdo->prepare('UPDATE users SET money = money - ? WHERE id = ?')->execute([$price, $user['id']]);
        $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, amount_money, description, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$user['id'], 'spend', 0, -$price, 'Buy item: ' . $it['name'], now()]);
        if ($it['grant_type'] === 'points' && (int)$it['grant_points'] > 0) {
            $gain = (int)$it['grant_points'];
            $pdo->prepare('UPDATE users SET points = points + ? WHERE id = ?')->execute([$gain, $user['id']]);
            $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
                ->execute([$user['id'], 'earn', $gain, 'Item grant points: ' . $it['name'], now()]);
        } elseif ($it['grant_type'] === 'factory' && $it['grant_factory_type_id']) {
            // Add factory if not owned
            $exists = $pdo->prepare('SELECT id FROM user_factories WHERE user_id = ? AND factory_type_id = ?');
            $exists->execute([$user['id'], $it['grant_factory_type_id']]);
            if (!$exists->fetch()) {
                $pdo->prepare('INSERT INTO user_factories (user_id, factory_type_id, level, created_at) VALUES (?,?,1,?)')
                    ->execute([$user['id'], $it['grant_factory_type_id'], now()]);
            }
        } else {
            // force or other => add to user_items
            $pack = max(1, (int)($it['pack_size'] ?? 1));
            $exists = $pdo->prepare('SELECT id, quantity FROM user_items WHERE user_id = ? AND item_id = ?');
            $exists->execute([$user['id'], $itemId]);
            $row = $exists->fetch();
            if ($row) {
                $pdo->prepare('UPDATE user_items SET quantity = quantity + ? WHERE id = ?')->execute([$pack, $row['id']]);
            } else {
                $pdo->prepare('INSERT INTO user_items (user_id, item_id, quantity, created_at) VALUES (?,?,?,?)')
                    ->execute([$user['id'], $itemId, $pack, now()]);
            }
        }
        // Log purchase for daily limits
        $pdo->prepare('INSERT INTO item_purchases (user_id, item_id, quantity, created_at) VALUES (?,?,?,?)')
            ->execute([$user['id'], $itemId, max(1, (int)($it['pack_size'] ?? 1)), now()]);
        // Missions
        updateBuyShopMissions((int)$user['id'], 'normal');
        commit($pdo);
    } catch (Throwable $e) { rollback($pdo); answerCallback($cb['id'], 'خرید ناموفق بود.'); return; }
    answerCallback($cb['id'], 'خرید موفق بود.');
    sendMessage($user['telegram_id'], '✅ آیتم با موفقیت خریداری شد.');
    // Log
    $country = getUserCountryName((int)($user['country_id'] ?? 0)) ?? 'بدون کشور';
    $pack = max(1, (int)($it['pack_size'] ?? 1));
    $log = 'خرید عادی:\nکاربر: ' . $user['telegram_id'] . ' (@' . ($user['username'] ?? '-') . ')\n'
         . 'آیتم: ' . $it['name'] . ' × ' . $pack . "\n" . 'مبلغ: ' . $price . ' پول بازی' . "\n" . 'کشور: ' . $country;
    logToChannel($log, null);
}

function handleBuyVipItem(array $user, int $itemId, array $cb): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT i.*, c.type AS shop_type FROM items i JOIN shop_categories c ON c.id = i.category_id WHERE i.id = ? AND i.is_active = 1');
    $stmt->execute([$itemId]);
    $it = $stmt->fetch();
    if (!$it) { answerCallback($cb['id'], 'آیتم یافت نشد.'); return; }
    $isVip = (int)$it['is_vip'] === 1 || $it['shop_type'] === 'vip';
    if (!$isVip) { answerCallback($cb['id'], 'این آیتم VIP نیست.'); return; }
    $price = (int)($it['price_points'] ?? 0);
    try {
        tx($pdo);
        $u = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
        $u->execute([$user['id']]);
        $usr = $u->fetch();
        // Limits check (VIP uses same fields)
        $limit = (int)($it['per_user_limit'] ?? 0);
        if ($limit > 0) {
            $owned = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM user_items WHERE user_id = ? AND item_id = ?');
            $owned->execute([$user['id'], $itemId]);
            $cur = (int)$owned->fetchColumn();
            if ($cur >= $limit) { rollback($pdo); answerCallback($cb['id'], 'به حداکثر خرید مجاز این آیتم رسیده‌اید.'); return; }
        }
        $daily = (int)($it['per_user_daily_limit'] ?? 0);
        if ($daily > 0) {
            $dailyQ = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM item_purchases WHERE user_id = ? AND item_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
            $dailyQ->execute([$user['id'], $itemId]);
            $d = (int)$dailyQ->fetchColumn();
            if ($d >= $daily) { rollback($pdo); answerCallback($cb['id'], 'به محدودیت روزانه خرید این آیتم رسیده‌اید.'); return; }
        }
        if ((int)$usr['points'] < $price) { rollback($pdo); answerCallback($cb['id'], 'امتیاز کافی نیست.'); return; }
        $pdo->prepare('UPDATE users SET points = points - ? WHERE id = ?')->execute([$price, $user['id']]);
        $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
            ->execute([$user['id'], 'spend', -$price, 'Buy VIP item: ' . $it['name'], now()]);
        // grant
        if ($it['grant_type'] === 'points' && (int)$it['grant_points'] > 0) {
            $gain = (int)$it['grant_points'];
            $pdo->prepare('UPDATE users SET points = points + ? WHERE id = ?')->execute([$gain, $user['id']]);
            $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
                ->execute([$user['id'], 'earn', $gain, 'VIP item grant points: ' . $it['name'], now()]);
        } elseif ($it['grant_type'] === 'factory' && $it['grant_factory_type_id']) {
            $exists = $pdo->prepare('SELECT id FROM user_factories WHERE user_id = ? AND factory_type_id = ?');
            $exists->execute([$user['id'], $it['grant_factory_type_id']]);
            if (!$exists->fetch()) {
                $pdo->prepare('INSERT INTO user_factories (user_id, factory_type_id, level, created_at) VALUES (?,?,1,?)')
                    ->execute([$user['id'], $it['grant_factory_type_id'], now()]);
            }
        } else {
            $pack = max(1, (int)($it['pack_size'] ?? 1));
            $exists = $pdo->prepare('SELECT id, quantity FROM user_items WHERE user_id = ? AND item_id = ?');
            $exists->execute([$user['id'], $itemId]);
            $row = $exists->fetch();
            if ($row) {
                $pdo->prepare('UPDATE user_items SET quantity = quantity + ? WHERE id = ?')->execute([$pack, $row['id']]);
            } else {
                $pdo->prepare('INSERT INTO user_items (user_id, item_id, quantity, created_at) VALUES (?,?,?,?)')
                    ->execute([$user['id'], $itemId, $pack, now()]);
            }
        }
        // Missions
        updateBuyShopMissions((int)$user['id'], 'vip');
        commit($pdo);
    } catch (Throwable $e) { rollback($pdo); answerCallback($cb['id'], 'خرید ناموفق بود.'); return; }
    answerCallback($cb['id'], 'خرید VIP موفق بود.');
    sendMessage($user['telegram_id'], '✅ آیتم VIP با موفقیت خریداری شد.');
    // Log
    $country = getUserCountryName((int)($user['country_id'] ?? 0)) ?? 'بدون کشور';
    $pack = max(1, (int)($it['pack_size'] ?? 1));
    $log = 'خرید VIP:\nکاربر: ' . $user['telegram_id'] . ' (@' . ($user['username'] ?? '-') . ')\n'
         . 'آیتم: ' . $it['name'] . ' × ' . $pack . "\n" . 'مبلغ: ' . $price . ' امتیاز' . "\n" . 'کشور: ' . $country;
    logToChannel($log, null);
}

function handleVipBuy(array $user, ?int $itemId, ?int $pkgId, array $cb): void {
    $pdo = db();
    $text = '';
    if ($itemId) {
        $stmt = $pdo->prepare('SELECT i.name, i.price_toman FROM items i WHERE i.id = ?');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();
        if (!$row) { answerCallback($cb['id'], 'آیتم یافت نشد.'); return; }
        $country = getUserCountryName((int)$user['country_id']) ?? 'بدون کشور';
        $text = "سلام. درخواست خرید آیتم VIP زیر را دارم:\nنام آیتم: {$row['name']}\nبرای کشور: {$country}\nقیمت: {$row['price_toman']} تومان";
    } elseif ($pkgId) {
        $stmt = $pdo->prepare('SELECT name, price_toman, points FROM point_packages WHERE id = ?');
        $stmt->execute([$pkgId]);
        $row = $stmt->fetch();
        if (!$row) { answerCallback($cb['id'], 'بسته یافت نشد.'); return; }
        $country = getUserCountryName((int)$user['country_id']) ?? 'بدون کشور';
        $text = "سلام. درخواست خرید بسته امتیاز VIP را دارم:\nنام بسته: {$row['name']} ({$row['points']} امتیاز)\nبرای کشور: {$country}\nقیمت: {$row['price_toman']} تومان";
    } else {
        answerCallback($cb['id'], 'درخواست نامعتبر'); return;
    }

    // Log request
    $pdo->prepare('INSERT INTO vip_purchase_requests (user_id, item_id, point_package_id, status, message_text, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$user['id'], $itemId, $pkgId, 'requested', $text, now()]);

    answerCallback($cb['id'], 'راهنمای خرید VIP ارسال شد.');
    sendMessage($user['telegram_id'], 'برای تکمیل خرید VIP با ادمین در ارتباط باشید. پیام زیر را ارسال کنید:\n\n<code>' . htmlspecialchars($text) . '</code>', [
        'reply_markup' => ['inline_keyboard' => [ inlineUrlButton('ارتباط با ادمین', 'https://t.me/' . ADMIN_USERNAME) ] ]
    ]);
}

// ===============================
// MY POINTS & FORCES
// ===============================
function showMyPointsAndForces(array $user): void {
    $pdo = db();
    $points = (int)$user['points'];
    $text = 'امتیاز فعلی شما: <b>' . $points . '</b>';
    sendMessage($user['telegram_id'], $text);

    $stmt = $pdo->prepare('SELECT ui.id AS ui_id, i.name FROM user_items ui JOIN items i ON i.id = ui.item_id WHERE ui.user_id = ? ORDER BY ui.created_at DESC LIMIT 100');
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
    if (!$rows) { sendMessage($user['telegram_id'], 'آیتمی در اختیار ندارید.'); return; }

    foreach ($rows as $r) {
        $btn = [ ['text' => '🔄 انتقال', 'callback_data' => 'TRANSFER|' . $r['ui_id']] ];
        sendMessage($user['telegram_id'], 'آیتم: <b>' . htmlspecialchars($r['name']) . '</b>', [ 'reply_markup' => ['inline_keyboard' => [ $btn ] ] ]);
    }
}

function startTransfer(array $user, int $userItemId, array $cb): void {
    // Verify ownership
    $stmt = db()->prepare('SELECT ui.id FROM user_items ui WHERE ui.id = ? AND ui.user_id = ?');
    $stmt->execute([$userItemId, $user['id']]);
    if (!$stmt->fetch()) { answerCallback($cb['id'], 'آیتم یافت نشد.'); return; }
    setUserState((int)$user['id'], 'await_transfer_to', ['user_item_id' => $userItemId]);
    answerCallback($cb['id'], 'شناسه عددی کاربر مقصد را ارسال کنید.');
    sendMessage($user['telegram_id'], 'لطفاً شناسه عددی کاربر مقصد را ارسال کنید. انتقال پس از تایید ادمین انجام می‌شود.');
}

function handleTransferTarget(array $user, string $text): void {
    $state = getUserState((int)$user['id']);
    if (!$state || $state['state'] !== 'await_transfer_to') return;
    $toTelegramId = (int)trim($text);
    if ($toTelegramId <= 0) { sendMessage($user['telegram_id'], 'شناسه نامعتبر است.'); return; }
    $pdo = db();
    // Ensure both users exist
    $stmt = $pdo->prepare('SELECT id FROM users WHERE telegram_id = ?');
    $stmt->execute([$toTelegramId]);
    $toUserId = (int)($stmt->fetchColumn() ?: 0);
    if ($toUserId <= 0) { sendMessage($user['telegram_id'], 'کاربر مقصد در ربات یافت نشد.'); return; }

    $userItemId = (int)$state['meta']['user_item_id'];
    $stmt = $pdo->prepare('SELECT id FROM user_items WHERE id = ? AND user_id = ?');
    $stmt->execute([$userItemId, $user['id']]);
    if (!$stmt->fetch()) { sendMessage($user['telegram_id'], 'آیتم متعلق به شما نیست.'); clearUserState((int)$user['id']); return; }

    $pdo->prepare('INSERT INTO transfers (user_item_id, from_user_id, to_user_id, status, created_at) VALUES (?,?,?,?,?)')
        ->execute([$userItemId, $user['id'], $toUserId, 'pending', now()]);
    clearUserState((int)$user['id']);
    sendMessage($user['telegram_id'], 'درخواست انتقال ثبت شد و پس از تایید ادمین انجام می‌شود.');
}

// ===============================
// BUY POINTS (VIP PACKAGES)
// ===============================
function listPointPackages(array $user): void {
    $stmt = db()->query('SELECT id, name, points, price_toman FROM point_packages WHERE is_active = 1 ORDER BY sort_order, id');
    $rows = $stmt->fetchAll();
    if (!$rows) { sendMessage($user['telegram_id'], 'فعلاً بسته‌ای تعریف نشده است.'); return; }
    foreach ($rows as $r) {
        $text = '<b>' . htmlspecialchars($r['name']) . '</b> - ' . (int)$r['points'] . ' امتیاز\n' . 'قیمت: <b>' . (int)$r['price_toman'] . '</b> تومان';
        $btn = [ ['text' => '🧾 خرید و ارسال رسید', 'callback_data' => 'BUY_POINTS|' . $r['id']] ];
        sendMessage($user['telegram_id'], $text, [ 'reply_markup' => ['inline_keyboard' => [ $btn ] ] ]);
    }
}

function startPointsPurchase(array $user, int $packageId, array $cb): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, name, points, price_toman FROM point_packages WHERE id = ? AND is_active = 1');
    $stmt->execute([$packageId]);
    $pkg = $stmt->fetch();
    if (!$pkg) { answerCallback($cb['id'], 'بسته یافت نشد.'); return; }
    $code = strtoupper(bin2hex(random_bytes(4)));
    $pdo->prepare('INSERT INTO point_purchases (user_id, package_id, code, status, created_at) VALUES (?,?,?,?,?)')
        ->execute([$user['id'], $pkg['id'], $code, 'pending', now()]);
    answerCallback($cb['id'], 'کد خرید صادر شد.');
    sendMessage($user['telegram_id'], 'کد خرید شما: <b>'.$code.'</b>
لطفاً عکس رسید پرداخت را ارسال کنید.', []);
    setUserState((int)$user['id'], 'await_points_receipt', ['purchase_code' => $code, 'package_id' => (int)$pkg['id']]);
}

// ===============================
// COUNTRIES LIST
// ===============================
function listActiveCountries(array $user): void {
    $stmt = db()->query('SELECT c.id, c.name, c.type, (SELECT COUNT(1) FROM users u WHERE u.country_id = c.id) AS taken FROM countries c WHERE c.is_active = 1 ORDER BY c.name');
    $rows = $stmt->fetchAll();
    $lines = [];
    foreach ($rows as $r) {
        $status = ($r['type'] === 'vip') ? 'VIP' : ((int)$r['taken'] > 0 ? '❌' : '✅');
        $lines[] = $r['name'] . ' - ' . $status;
    }
    $txt = "کشورهای فعال:\n" . implode("\n", $lines);
    sendMessage($user['telegram_id'], $txt);
}

// ===============================
// EVENTS LIST
// ===============================
function showMissionsForUser(array $user): void {
    $missions = db()->query("SELECT * FROM missions WHERE is_active = 1 ORDER BY id DESC LIMIT 50")->fetchAll();
    if (!$missions) { sendMessage($user['telegram_id'], 'ایونت/ماموریت فعالی وجود ندارد.'); return; }
    foreach ($missions as $m) {
        $um = db()->prepare('SELECT progress_count, claimed_at FROM user_missions WHERE mission_id = ? AND user_id = ?');
        $um->execute([$m['id'], $user['id']]);
        $row = $um->fetch();
        $progress = (int)($row['progress_count'] ?? 0);
        $claimed = !empty($row['claimed_at']);
        $need = (int)$m['required_count'];
        $shop = ($m['shop_type'] === 'vip') ? 'خرید از شاپ VIP' : 'خرید از شاپ عادی';
        $reward = (string)$m['reward_amount'] . ' ' . (($m['reward_type'] === 'money') ? 'پول بازی' : 'امتیاز');
        $text = '🎯 ' . htmlspecialchars($m['name']) . "\n" . $shop . ' × ' . $need . "\nپیشرفت: <b>{$progress}/{$need}</b>\nجایزه: <b>{$reward}</b>';
        $btns = [];
        if (!$claimed && $progress >= $need) {
            $btns[] = [ ['text'=>'🎁 دریافت جایزه','callback_data'=>'MISSION_CLAIM|'.$m['id']] ];
        }
        sendMessage($user['telegram_id'], $text, [ 'reply_markup' => ['inline_keyboard' => $btns] ]);
    }
}

// ===============================
// CONTACT ADMIN
// ===============================
function contactAdmin(array $user): void {
    $url = 'https://t.me/' . ADMIN_USERNAME;
    sendMessage($user['telegram_id'], 'برای ارتباط با ادمین روی دکمه زیر بزنید:', [
        'reply_markup' => [ 'inline_keyboard' => [ inlineUrlButton('گفتگو با ادمین', $url) ] ]
    ]);
}

// ===============================
// ADMIN IN-CHAT PANEL (INLINE)
// ===============================
function adminShowHome(array $user, ?int $messageId = null): void {
    $kb = [
        [ ['text' => '📦 بسته‌های امتیاز', 'callback_data' => 'ADMIN|PPKGS'], ['text' => '🧾 خریدهای امتیاز', 'callback_data' => 'ADMIN|PP'] ],
        [ ['text' => '🌍 کشورها', 'callback_data' => 'ADMIN|COUNTRIES'], ['text' => '🛍️ فروشگاه', 'callback_data' => 'ADMIN|SHOP'] ],
        [ ['text' => '🏭 کارخانه‌ها', 'callback_data' => 'ADMIN|FACTORIES'], ['text' => '📝 ارسالی‌ها', 'callback_data' => 'ADMIN|SUBMISSIONS'] ],
        [ ['text' => '🔄 انتقال‌ها', 'callback_data' => 'ADMIN|TRANSFERS'], ['text' => '❓ سوالات', 'callback_data' => 'ADMIN|QUESTIONS'] ],
        [ ['text' => '📣 Broadcast', 'callback_data' => 'ADMIN|BROADCAST'] ],
    ];
    $text = "پنل مدیریت";
    if ($messageId) {
        editMessageText($user['telegram_id'], $messageId, $text, ['reply_markup' => ['inline_keyboard' => $kb]]);
    } else {
        sendMessage($user['telegram_id'], $text, ['reply_markup' => ['inline_keyboard' => $kb]]);
    }
}

function adminParsePipe(string $text): array {
    $parts = array_map('trim', explode('|', $text));
    return $parts;
}

function adminListShop(array $user, ?int $messageId = null): void {
    $rows = db()->query('SELECT * FROM shop_categories ORDER BY type, sort_order, name')->fetchAll();
    $lines = [];
    foreach ($rows as $r) { $lines[] = ($r['is_active']?'✅':'⛔️')." ".$r['id'].' - '.($r['type']).' - '.$r['name']; }
    $kb = [ [ ['text'=>'➕ افزودن دسته','callback_data'=>'ADMIN|CAT_ADD'] ] , [ ['text'=>'⬅️ بازگشت','callback_data'=>'ADMIN|HOME'] ] ];
    $text = 'دسته‌ها:\n'.( $lines ? implode("\n", $lines) : '—' ) . "\nبرای مدیریت، از دکمه‌های آیتم استفاده کنید.";
    if ($messageId) editMessageText($user['telegram_id'], $messageId, $text, ['reply_markup'=>['inline_keyboard'=>$kb]]);
    else sendMessage($user['telegram_id'], $text, ['reply_markup'=>['inline_keyboard'=>$kb]]);
    // For brevity, also send per-category controls (first 5)
    foreach (array_slice($rows,0,5) as $r) {
        $kb2 = [ [ ['text'=>'✏️ ویرایش','callback_data'=>'ADMIN|CAT_EDIT|'.$r['id']], ['text'=>$r['is_active']?'⛔️ غیرفعال':'✅ فعال','callback_data'=>'ADMIN|CAT_TOG|'.$r['id']], ['text'=>'🗑 حذف','callback_data'=>'ADMIN|CAT_DEL|'.$r['id']] ], [ ['text'=>'➕ افزودن آیتم','callback_data'=>'ADMIN|ITEM_ADD|'.$r['id']], ['text'=>'📦 آیتم‌ها','callback_data'=>'ADMIN|ITEMS|'.$r['id']] ] ];
        sendMessage($user['telegram_id'], 'دسته: '.$r['name'].' ('.$r['type'].')', ['reply_markup'=>['inline_keyboard'=>$kb2]]);
    }
}

function adminListItems(array $user, int $catId): void {
    $stmt = db()->prepare('SELECT i.* FROM items i WHERE i.category_id = ? ORDER BY i.sort_order, i.name');
    $stmt->execute([$catId]);
    $items = $stmt->fetchAll();
    if (!$items) { sendMessage($user['telegram_id'], 'آیتمی ندارد.'); return; }
    foreach ($items as $it) {
        $kb = [ [ ['text'=>'✏️ ویرایش','callback_data'=>'ADMIN|ITEM_EDIT|'.$it['id']], ['text'=>$it['is_active']?'⛔️ غیرفعال':'✅ فعال','callback_data'=>'ADMIN|ITEM_TOG|'.$it['id']], ['text'=>'🗑 حذف','callback_data'=>'ADMIN|ITEM_DEL|'.$it['id']] ] ];
        sendMessage($user['telegram_id'], 'آیتم: '.htmlspecialchars($it['name']).' #'.$it['id'], ['reply_markup'=>['inline_keyboard'=>$kb]]);
    }
}

function adminListFactories(array $user, ?int $messageId = null): void {
    $rows = db()->query('SELECT * FROM factory_types ORDER BY name')->fetchAll();
    $kb = [ [ ['text'=>'➕ افزودن کارخانه','callback_data'=>'ADMIN|FT_ADD'] ], [ ['text'=>'⬅️ بازگشت','callback_data'=>'ADMIN|HOME'] ] ];
    $txt = 'کارخانه‌ها: '.count($rows);
    if ($messageId) editMessageText($user['telegram_id'], $messageId, $txt, ['reply_markup'=>['inline_keyboard'=>$kb]]);
    else sendMessage($user['telegram_id'], $txt, ['reply_markup'=>['inline_keyboard'=>$kb]]);
    foreach ($rows as $r) {
        $kb2 = [ [ ['text'=>'✏️ ویرایش','callback_data'=>'ADMIN|FT_EDIT|'.$r['id']], ['text'=>$r['is_active']?'⛔️ غیرفعال':'✅ فعال','callback_data'=>'ADMIN|FT_TOG|'.$r['id']], ['text'=>'🗑 حذف','callback_data'=>'ADMIN|FT_DEL|'.$r['id']] ] ];
        sendMessage($user['telegram_id'], '🏭 '.htmlspecialchars($r['name']).' #'.$r['id'], ['reply_markup'=>['inline_keyboard'=>$kb2]]);
    }
}

function adminListMissions(array $user, ?int $messageId = null): void {
    $rows = db()->query('SELECT * FROM missions ORDER BY id DESC')->fetchAll();
    $kb = [ [ ['text'=>'➕ افزودن ماموریت','callback_data'=>'ADMIN|MS_ADD'] ], [ ['text'=>'⬅️ بازگشت','callback_data'=>'ADMIN|HOME'] ] ];
    $txt = 'ماموریت‌ها: '.count($rows);
    if ($messageId) editMessageText($user['telegram_id'], $messageId, $txt, ['reply_markup'=>['inline_keyboard'=>$kb]]);
    else sendMessage($user['telegram_id'], $txt, ['reply_markup'=>['inline_keyboard'=>$kb]]);
    foreach ($rows as $r) {
        $kb2 = [ [ ['text'=>'✏️ ویرایش','callback_data'=>'ADMIN|MS_EDIT|'.$r['id']], ['text'=>$r['is_active']?'⛔️ غیرفعال':'✅ فعال','callback_data'=>'ADMIN|MS_TOG|'.$r['id']], ['text'=>'🗑 حذف','callback_data'=>'ADMIN|MS_DEL|'.$r['id']] ] ];
        sendMessage($user['telegram_id'], '🎯 '.htmlspecialchars($r['name']).' #'.$r['id'], ['reply_markup'=>['inline_keyboard'=>$kb2]]);
    }
}
function adminListPointPackages(array $user, ?int $messageId = null): void {
    $rows = db()->query('SELECT id,name,points,price_toman,is_active FROM point_packages ORDER BY sort_order,id')->fetchAll();
    $lines = [];
    foreach ($rows as $r) { $lines[] = ($r['is_active']?'✅':'⛔️') . ' ' . $r['id'] . ' - ' . $r['name'] . ' (' . (int)$r['points'] . ')'; }
    $text = "بسته‌های امتیاز:\n" . ( $lines ? implode("\n", $lines) : '—' );
    $kb = [
        [ ['text' => '➕ افزودن بسته', 'callback_data' => 'ADMIN|PPKGS_ADD'] ],
        [ ['text' => '⬅️ بازگشت', 'callback_data' => 'ADMIN|HOME'] ],
    ];
    if ($messageId) editMessageText($user['telegram_id'], $messageId, $text, ['reply_markup'=>['inline_keyboard'=>$kb]]);
    else sendMessage($user['telegram_id'], $text, ['reply_markup'=>['inline_keyboard'=>$kb]]);
    foreach (array_slice($rows,0,10) as $r) {
        $kb2 = [ [
            ['text'=>'✏️ ویرایش','callback_data'=>'ADMIN|PPKG_EDIT|'.$r['id']],
            ['text'=>$r['is_active']?'⛔️ غیرفعال':'✅ فعال','callback_data'=>'ADMIN|PPKG_TOG|'.$r['id']],
            ['text'=>'🗑 حذف','callback_data'=>'ADMIN|PPKG_DEL|'.$r['id']]
        ] ];
        sendMessage($user['telegram_id'], 'بسته: '.htmlspecialchars($r['name']).' #'.$r['id'], ['reply_markup'=>['inline_keyboard'=>$kb2]]);
    }
}

function adminListPointPurchases(array $user, ?int $messageId = null): void {
    $rows = db()->query('SELECT pp.id,pp.code,pp.photo_file_id,u.telegram_id,u.username,c.name AS country FROM point_purchases pp JOIN users u ON u.id=pp.user_id LEFT JOIN countries c ON c.id=u.country_id WHERE pp.status="pending" ORDER BY pp.created_at ASC LIMIT 5')->fetchAll();
    if (!$rows) {
        $kb = [ [ ['text' => '⬅️ بازگشت', 'callback_data' => 'ADMIN|HOME'] ] ];
        if ($messageId) editMessageText($user['telegram_id'], $messageId, 'درخواستی موجود نیست.', ['reply_markup'=>['inline_keyboard'=>$kb]]);
        else sendMessage($user['telegram_id'], 'درخواستی موجود نیست.', ['reply_markup'=>['inline_keyboard'=>$kb]]);
        return;
    }
    foreach ($rows as $r) {
        $caption = 'کد: ' . $r['code'] . "\n" . 'کاربر: ' . $r['telegram_id'] . ' (@' . ($r['username']?:'-') . ')' . "\n" . 'کشور: ' . ($r['country']?:'-');
        $kb = [ [ ['text'=>'✅ تایید','callback_data'=>'ADMIN|PP|APPROVE|'.$r['id']], ['text'=>'❌ رد','callback_data'=>'ADMIN|PP|REJECT|'.$r['id']] ], [ ['text'=>'⬅️ بازگشت','callback_data'=>'ADMIN|HOME'] ] ];
        if ($r['photo_file_id']) sendPhoto($user['telegram_id'], $r['photo_file_id'], ['caption'=>$caption, 'reply_markup'=>['inline_keyboard'=>$kb]]);
        else sendMessage($user['telegram_id'], $caption, ['reply_markup'=>['inline_keyboard'=>$kb]]);
    }
}

function countrySettingsGet(int $countryId): array {
    $stmt = db()->prepare('SELECT settings FROM countries WHERE id = ?');
    $stmt->execute([$countryId]);
    $json = (string)($stmt->fetchColumn() ?: '');
    $arr = $json ? json_decode($json, true) : [];
    if (!is_array($arr)) $arr = [];
    $arr += [ 'allow_factory_purchase' => true, 'allow_shop_normal' => true, 'allow_shop_vip' => true ];
    return $arr;
}

function countrySettingsSave(int $countryId, array $settings): void {
    db()->prepare('UPDATE countries SET settings = ? WHERE id = ?')->execute([json_encode($settings, JSON_UNESCAPED_UNICODE), $countryId]);
}

function adminListCountries(array $user, ?int $messageId = null): void {
    $rows = db()->query('SELECT id,name,type,starter_money,starter_points FROM countries ORDER BY name')->fetchAll();
    $lines = [];
    foreach ($rows as $r) { $lines[] = $r['id'] . ' - ' . $r['name'] . ' (' . $r['type'] . ') SM:' . (int)$r['starter_money'] . ' SP:' . (int)$r['starter_points']; }
    $text = 'کشورها:\n' . ( $lines ? implode("\n", $lines) : '—' );
    $kb = [ [ ['text'=>'➕ افزودن کشور','callback_data'=>'ADMIN|COUNTRY_ADD'] ], [ ['text'=>'🛠 تنظیمات کشور','callback_data'=>'ADMIN|COUNTRY_CFG_PROMPT'] ], [ ['text'=>'⬅️ بازگشت','callback_data'=>'ADMIN|HOME'] ] ];
    if ($messageId) editMessageText($user['telegram_id'], $messageId, $text, ['reply_markup'=>['inline_keyboard'=>$kb]]);
    else sendMessage($user['telegram_id'], $text, ['reply_markup'=>['inline_keyboard'=>$kb]]);
}

function adminStartAddPackage(array $user): void {
    setUserState((int)$user['id'], 'admin_pkg_add', ['step'=>1, 'pkg'=>[]]);
    sendMessage($user['telegram_id'], 'افزودن بسته جدید - مرحله 1\nنام بسته را ارسال کنید:');
}

function adminHandleAddPackage(array $user, string $text): void {
    $state = getUserState((int)$user['id']);
    if (!$state || $state['state'] !== 'admin_pkg_add') return;
    $step = (int)($state['meta']['step'] ?? 1);
    $pkg = (array)($state['meta']['pkg'] ?? []);
    if ($step === 1) {
        if ($text === '') { sendMessage($user['telegram_id'], 'نام معتبر نیست.'); return; }
        $pkg['name'] = $text;
        setUserState((int)$user['id'], 'admin_pkg_add', ['step'=>2, 'pkg'=>$pkg]);
        sendMessage($user['telegram_id'], 'مرحله 2\nتعداد امتیاز (عدد) را ارسال کنید:');
        return;
    }
    if ($step === 2) {
        if (!ctype_digit($text)) { sendMessage($user['telegram_id'], 'فقط عدد.'); return; }
        $pkg['points'] = (int)$text;
        setUserState((int)$user['id'], 'admin_pkg_add', ['step'=>3, 'pkg'=>$pkg]);
        sendMessage($user['telegram_id'], 'مرحله 3\nقیمت (تومان) را ارسال کنید:');
        return;
    }
    if ($step === 3) {
        if (!ctype_digit($text)) { sendMessage($user['telegram_id'], 'فقط عدد.'); return; }
        $pkg['price_toman'] = (int)$text;
        setUserState((int)$user['id'], 'admin_pkg_add', ['step'=>4, 'pkg'=>$pkg]);
        sendMessage($user['telegram_id'], 'مرحله 4\nترتیب نمایش (عدد - اختیاری، خالی=0):');
        return;
    }
    if ($step === 4) {
        $pkg['sort_order'] = $text === '' ? 0 : (int)$text;
        setUserState((int)$user['id'], 'admin_pkg_add', ['step'=>5, 'pkg'=>$pkg]);
        sendMessage($user['telegram_id'], 'مرحله 5\nفعال باشد؟ (1 بله / 0 خیر)');
        return;
    }
    if ($step === 5) {
        if (!in_array($text, ['0','1'], true)) { sendMessage($user['telegram_id'], '1 یا 0.'); return; }
        $pkg['is_active'] = (int)$text;
        $stmt = db()->prepare('INSERT INTO point_packages (name, points, price_toman, is_active, sort_order, created_at) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$pkg['name'], $pkg['points'], $pkg['price_toman'], $pkg['is_active'], (int)$pkg['sort_order'], now()]);
        clearUserState((int)$user['id']);
        sendMessage($user['telegram_id'], '✅ بسته ثبت شد.');
        adminListPointPackages($user, null);
        return;
    }
}

function adminStartEditPackage(array $user, int $pkgId): void {
    setUserState((int)$user['id'], 'admin_pkg_edit', ['pkg_id'=>$pkgId]);
    sendMessage($user['telegram_id'], 'ویرایش بسته #'.$pkgId.'\nلطفاً به فرمت زیر ارسال کنید:\nنام|امتیاز|قیمت_تومان|ترتیب|فعال(1/0)\nبرای بدون تغییر از - استفاده کنید.');
}

function adminHandleEditPackage(array $user, string $text): void {
    $state = getUserState((int)$user['id']);
    if (!$state || $state['state'] !== 'admin_pkg_edit') return;
    $pkgId = (int)$state['meta']['pkg_id'];
    $parts = adminParsePipe($text);
    if (count($parts) < 5) { sendMessage($user['telegram_id'], 'فرمت نامعتبر.'); return; }
    list($name,$points,$price,$sort,$active) = $parts;
    $row = db()->prepare('SELECT * FROM point_packages WHERE id = ?');
    $row->execute([$pkgId]);
    $cur = $row->fetch();
    if (!$cur) { clearUserState((int)$user['id']); sendMessage($user['telegram_id'], 'بسته یافت نشد.'); return; }
    $name = ($name==='-') ? $cur['name'] : $name;
    $points = ($points==='-') ? $cur['points'] : (int)$points;
    $price = ($price==='-') ? $cur['price_toman'] : (int)$price;
    $sort = ($sort==='-') ? $cur['sort_order'] : (int)$sort;
    $active = ($active==='-') ? $cur['is_active'] : (int)$active;
    db()->prepare('UPDATE point_packages SET name=?, points=?, price_toman=?, sort_order=?, is_active=? WHERE id=?')->execute([$name,$points,$price,$sort,$active,$pkgId]);
    clearUserState((int)$user['id']);
    sendMessage($user['telegram_id'], '✅ بسته بروزرسانی شد.');
    adminListPointPackages($user, null);
}

function adminStartAddCountry(array $user): void {
    setUserState((int)$user['id'], 'admin_country_add', ['step'=>1, 'c'=>[]]);
    sendMessage($user['telegram_id'], 'افزودن کشور - مرحله 1\nنام کشور را ارسال کنید:');
}

function adminHandleAddCountry(array $user, string $text): void {
    $state = getUserState((int)$user['id']);
    if (!$state || $state['state'] !== 'admin_country_add') return;
    $step = (int)($state['meta']['step'] ?? 1);
    $c = (array)($state['meta']['c'] ?? []);
    if ($step === 1) {
        if ($text==='') { sendMessage($user['telegram_id'], 'نام معتبر نیست.'); return; }
        $c['name'] = $text;
        setUserState((int)$user['id'], 'admin_country_add', ['step'=>2,'c'=>$c]);
        sendMessage($user['telegram_id'], 'مرحله 2\nنوع کشور (free/vip) را ارسال کنید:');
        return;
    }
    if ($step === 2) {
        $t = strtolower($text);
        if (!in_array($t, ['free','vip'], true)) { sendMessage($user['telegram_id'], 'فقط free یا vip'); return; }
        $c['type'] = $t;
        setUserState((int)$user['id'], 'admin_country_add', ['step'=>3,'c'=>$c]);
        sendMessage($user['telegram_id'], 'مرحله 3\nاستارتر پول بازی (عدد):');
        return;
    }
    if ($step === 3) {
        if ($text!=='' && !ctype_digit($text)) { sendMessage($user['telegram_id'], 'فقط عدد.'); return; }
        $c['starter_money'] = ($text==='')?0:(int)$text;
        setUserState((int)$user['id'], 'admin_country_add', ['step'=>4,'c'=>$c]);
        sendMessage($user['telegram_id'], 'مرحله 4\nاستارتر امتیاز (عدد):');
        return;
    }
    if ($step === 4) {
        if ($text!=='' && !ctype_digit($text)) { sendMessage($user['telegram_id'], 'فقط عدد.'); return; }
        $c['starter_points'] = ($text==='')?0:(int)$text;
        db()->prepare('INSERT INTO countries (name,type,is_active,created_at,starter_money,starter_points) VALUES (?,?,1,?,?,?)')
            ->execute([$c['name'],$c['type'], now(), $c['starter_money'],$c['starter_points']]);
        clearUserState((int)$user['id']);
        sendMessage($user['telegram_id'], '✅ کشور ایجاد شد.');
        adminListCountries($user, null);
        return;
    }
}

function adminCountryConfigPanel(array $user, int $countryId): void {
    $stmt = db()->prepare('SELECT id,name,type,starter_money,starter_points,settings FROM countries WHERE id = ?');
    $stmt->execute([$countryId]);
    $c = $stmt->fetch();
    if (!$c) { sendMessage($user['telegram_id'], 'کشور یافت نشد.'); return; }
    $s = $c['settings'] ? json_decode((string)$c['settings'], true) : [];
    if (!is_array($s)) $s = [];
    $s += ['allow_shop_normal'=>true,'allow_shop_vip'=>true,'allow_factory_purchase'=>true];
    $text = 'کشور: '.$c['name'].' ('.$c['type'].")\nSM: ".$c['starter_money'].' SP: '.$c['starter_points'];
    $kb = [
        [ ['text'=>($s['allow_shop_normal']?'✅':'⛔️').' عادی','callback_data'=>'ADMIN|COUNTRY_TOG|'.$c['id'].'|allow_shop_normal'], ['text'=>($s['allow_shop_vip']?'✅':'⛔️').' VIP','callback_data'=>'ADMIN|COUNTRY_TOG|'.$c['id'].'|allow_shop_vip'] ],
        [ ['text'=>($s['allow_factory_purchase']?'✅':'⛔️').' خرید کارخانه','callback_data'=>'ADMIN|COUNTRY_TOG|'.$c['id'].'|allow_factory_purchase'] ],
        [ ['text'=>'✏️ استارتر پول','callback_data'=>'ADMIN|COUNTRY_SET_SM|'.$c['id']], ['text'=>'✏️ استارتر امتیاز','callback_data'=>'ADMIN|COUNTRY_SET_SP|'.$c['id']] ],
        [ ['text'=>'⬅️ بازگشت','callback_data'=>'ADMIN|COUNTRIES'] ]
    ];
    sendMessage($user['telegram_id'], $text, ['reply_markup'=>['inline_keyboard'=>$kb]]);
}
// ===============================
// ADMIN PANEL (SINGLE-FILE, VIA GET action=...)
// ===============================
function panel_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function panel_is_logged_in(): bool { return isset($_SESSION['admin_user_id']); }
function panel_require_login(): void { if (!panel_is_logged_in()) { header('Location: ' . ($_SERVER['SCRIPT_NAME'] ?? 'bot.php') . '?action=login'); exit; } }

function panel_verify_token(string $token): ?int {
    $stmt = db()->prepare('SELECT user_id FROM panel_tokens WHERE token = ? AND used_at IS NULL AND expires_at > ?');
    $stmt->execute([$token, now()]);
    $uid = $stmt->fetchColumn();
    if (!$uid) return null;
    db()->prepare('UPDATE panel_tokens SET used_at = ? WHERE token = ?')->execute([now(), $token]);
    return (int)$uid;
}

function panel_nav(): void {
    echo '<nav style="padding:10px;background:#111;color:#fff;display:flex;gap:12px;flex-wrap:wrap">';
    $base = ($_SERVER['SCRIPT_NAME'] ?? 'bot.php');
    echo '<a href="'.$base.'" style="color:#fff">داشبورد</a>';
    echo '<a href="'.$base.'?action=users" style="color:#fff">کاربران</a>';
    echo '<a href="'.$base.'?action=submissions" style="color:#fff">ارسالی‌ها</a>';
    echo '<a href="'.$base.'?action=transfers" style="color:#fff">انتقالات</a>';
    echo '<a href="'.$base.'?action=shop" style="color:#fff">فروشگاه</a>';
    echo '<a href="'.$base.'?action=point_purchases" style="color:#fff">خرید امتیاز</a>';
    echo '<a href="'.$base.'?action=factories" style="color:#fff">کارخانه‌ها</a>';
    echo '<a href="'.$base.'?action=countries" style="color:#fff">کشورها</a>';
    echo '<a href="'.$base.'?action=questions" style="color:#fff">سوالات</a>';
    echo '<a href="'.$base.'?action=settings" style="color:#fff">تنظیمات</a>';
    echo '<a href="'.$base.'?action=broadcast" style="color:#fff">پیام همگانی</a>';
    echo '<span style="flex:1 1 auto"></span>';
    if (panel_is_logged_in()) { echo '<a href="'.$base.'?action=logout" style="color:#fff">خروج</a>'; }
    echo '</nav>';
}

function serveAdminPanel(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $action = $_GET['action'] ?? 'dashboard';

    if ($action === 'login') {
        // one-click login via GET token
        if (isset($_GET['token'])) {
            $token = trim((string)$_GET['token']);
            if ($token !== '') {
                $uid = panel_verify_token($token);
                if ($uid) { $_SESSION['admin_user_id'] = $uid; header('Location: ' . ($_SERVER['SCRIPT_NAME'] ?? 'bot.php')); exit; }
                $error = 'توکن نامعتبر یا منقضی شده است.';
            }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = trim((string)($_POST['token'] ?? ''));
            $uid = $token !== '' ? panel_verify_token($token) : null;
            if ($uid) { $_SESSION['admin_user_id'] = $uid; header('Location: ' . ($_SERVER['SCRIPT_NAME'] ?? 'bot.php')); exit; }
            $error = 'توکن نامعتبر یا منقضی شده است.';
        }
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>ورود مدیر</title></head><body style="font-family:sans-serif">';
        echo '<div style="max-width:480px;margin:60px auto;padding:24px;border:1px solid #ccc;border-radius:8px">';
        echo '<h3>ورود مدیر</h3>';
        if (!empty($error)) echo '<div style="color:#b00">'.panel_h($error).'</div>';
        echo '<form method="post"><label>توکن یکبار مصرف:</label><input name="token" style="width:100%;padding:8px;margin:8px 0" /><button style="padding:8px 16px">ورود</button></form>';
        echo '</div></body></html>';
        return;
    }

    if ($action === 'logout') { session_destroy(); header('Location: ' . ($_SERVER['SCRIPT_NAME'] ?? 'bot.php') . '?action=login'); return; }

    panel_require_login();

    // Dashboard
    if ($action === 'dashboard' || $action === '') {
        $pdo = db();
        $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $active24h = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE last_active_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
        $sumEarn = (int)$pdo->query("SELECT COALESCE(SUM(amount_points),0) FROM transactions WHERE type IN ('earn','reward')")->fetchColumn();
        $sumSpend = (int)$pdo->query("SELECT COALESCE(SUM(-amount_points),0) FROM transactions WHERE type = 'spend'")->fetchColumn();
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>داشبورد</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px">';
        echo '<h3>داشبورد</h3>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
        echo '<div style="padding:12px;border:1px solid #ddd;border-radius:8px">کاربران: <b>'.$totalUsers.'</b></div>';
        echo '<div style="padding:12px;border:1px solid #ddd;border-radius:8px">فعال ۲۴ساعت: <b>'.$active24h.'</b></div>';
        echo '<div style="padding:12px;border:1px solid #ddd;border-radius:8px">مجموع کسب امتیاز: <b>'.$sumEarn.'</b></div>';
        echo '<div style="padding:12px;border:1px solid #ddd;border-radius:8px">مجموع خرج امتیاز: <b>'.$sumSpend.'</b></div>';
        echo '</div></div></body></html>';
        return;
    }

    // Users
    if ($action === 'users') {
        $pdo = db();
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $stmt = $pdo->prepare('SELECT u.*, c.name AS country FROM users u LEFT JOIN countries c ON c.id=u.country_id WHERE u.telegram_id = ? OR u.username = ?');
            $stmt->execute([$q, $q]);
        } else {
            $stmt = $pdo->query('SELECT u.*, c.name AS country FROM users u LEFT JOIN countries c ON c.id=u.country_id ORDER BY u.id DESC LIMIT 100');
        }
        $rows = $stmt->fetchAll();
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>کاربران</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px"><h3>کاربران</h3>';
        $base = ($_SERVER['SCRIPT_NAME'] ?? 'bot.php');
        echo '<form method="get" style="margin-bottom:8px"><input type="hidden" name="action" value="users" /><input name="q" placeholder="آیدی عددی یا یوزرنیم" value="'.panel_h($q).'"/><button>جستجو</button></form>';
        echo '<table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>تلگرام</th><th>یوزرنیم</th><th>کشور</th><th>امتیاز</th><th>ایجاد</th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>'.$r['id'].'</td><td>'.$r['telegram_id'].'</td><td>'.panel_h((string)$r['username']).'</td><td>'.panel_h((string)$r['country']).'</td><td>'.$r['points'].'</td><td>'.$r['created_at'].'</td></tr>';
        }
        echo '</table></div></body></html>';
        return;
    }

    // Submissions
    if ($action === 'submissions') {
        $pdo = db();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = (int)($_POST['id'] ?? 0);
            $op = (string)($_POST['op'] ?? '');
            if ($id > 0 && in_array($op, ['approve','reject'], true)) {
                $status = $op === 'approve' ? 'approved' : 'rejected';
                $pdo->prepare('UPDATE submissions SET status = ?, reviewed_at = ?, reviewed_by = ? WHERE id = ?')
                    ->execute([$status, now(), $_SESSION['admin_user_id'], $id]);
                // Notify user
                $tu = $pdo->prepare('SELECT u.telegram_id FROM submissions s JOIN users u ON u.id=s.user_id WHERE s.id = ?');
                $tu->execute([$id]);
                $tid = (int)($tu->fetchColumn() ?: 0);
                if ($tid) sendMessage($tid, $status==='approved'?'✅ ارسالی شما تایید شد.':'❌ ارسالی شما رد شد.');
            }
        }
        $rows = $pdo->query("SELECT s.*, u.telegram_id, c.name AS country FROM submissions s JOIN users u ON u.id=s.user_id LEFT JOIN countries c ON c.id=s.country_id WHERE s.status='pending' ORDER BY s.created_at ASC LIMIT 50")->fetchAll();
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>ارسالی‌ها</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px"><h3>ارسالی‌های در انتظار</h3>';
        if (!$rows) echo '<div>موردی نیست.</div>';
        foreach ($rows as $r) {
            echo '<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:8px 0">';
            echo '<div>نوع: <b>'.panel_h($r['type']).'</b> | کشور: '.panel_h((string)$r['country']).' | کاربر: '.$r['telegram_id'].'</div>';
            if ($r['text']) echo '<pre style="white-space:pre-wrap">'.panel_h($r['text']).'</pre>';
            if ($r['photo_file_id']) echo '<div>Photo File ID: <code>'.panel_h($r['photo_file_id']).'</code></div>';
            echo '<form method="post" style="display:flex;gap:8px"><input type="hidden" name="id" value="'.$r['id'].'" />';
            echo '<button name="op" value="approve">✅ تایید</button>';
            echo '<button name="op" value="reject">❌ رد</button>';
            echo '</form></div>';
        }
        echo '</div></body></html>';
        return;
    }

    // Points purchases moderation
    if ($action === 'point_purchases') {
        $pdo = db();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = (int)($_POST['id'] ?? 0);
            $op = (string)($_POST['op'] ?? '');
            if ($id > 0 && in_array($op, ['approve','reject'], true)) {
                $pp = $pdo->prepare('SELECT pp.*, u.telegram_id, u.id AS uid FROM point_purchases pp JOIN users u ON u.id=pp.user_id WHERE pp.id = ? AND pp.status = "pending"');
                $pp->execute([$id]);
                $row = $pp->fetch();
                if ($row) {
                    if ($op === 'approve') {
                        $pkg = $pdo->prepare('SELECT points FROM point_packages WHERE id = ?');
                        $pkg->execute([$row['package_id']]);
                        $points = (int)($pkg->fetchColumn() ?: 0);
                        $pdo->beginTransaction();
                        try {
                            $pdo->prepare('UPDATE users SET points = points + ? WHERE id = ?')->execute([$points, $row['uid']]);
                            $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
                                ->execute([$row['uid'], 'earn', $points, 'Points purchase code '.$row['code'], now()]);
                            $pdo->prepare('UPDATE point_purchases SET status = "approved", decided_at = ?, decided_by = ? WHERE id = ?')
                                ->execute([now(), $_SESSION['admin_user_id'], $id]);
                            $pdo->commit();
                        } catch (Throwable $e) { $pdo->rollBack(); }
                        sendMessage($row['telegram_id'], '✅ خرید امتیاز تایید شد. کد: '.$row['code']);
                    } else {
                        $pdo->prepare('UPDATE point_purchases SET status = "rejected", decided_at = ?, decided_by = ? WHERE id = ?')
                            ->execute([now(), $_SESSION['admin_user_id'], $id]);
                        sendMessage($row['telegram_id'], '❌ خرید امتیاز رد شد. کد: '.$row['code']);
                    }
                }
            }
        }
        $rows = $pdo->query('SELECT pp.*, u.telegram_id, u.username, c.name AS country FROM point_purchases pp JOIN users u ON u.id=pp.user_id LEFT JOIN countries c ON c.id=u.country_id WHERE pp.status = "pending" ORDER BY pp.created_at ASC LIMIT 50')->fetchAll();
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>خرید امتیاز</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px"><h3>درخواست‌های خرید امتیاز</h3>';
        if (!$rows) echo '<div>موردی نیست.</div>';
        foreach ($rows as $r) {
            echo '<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:8px 0">';
            echo 'بازیکن: '.$r['telegram_id'].' (@'.panel_h((string)$r['username']).') | کشور: '.panel_h((string)$r['country']).' | کد: <b>'.$r['code'].'</b>';
            if ($r['photo_file_id']) echo '<div>رسید: <code>'.panel_h($r['photo_file_id']).'</code></div>';
            echo '<form method="post" style="display:inline-block;margin-top:8px"><input type="hidden" name="id" value="'.$r['id'].'" />';
            echo '<button name="op" value="approve">✅ تایید</button> ';
            echo '<button name="op" value="reject">❌ رد</button>';
            echo '</form></div>';
        }
        echo '</div></body></html>';
        return;
    }

    // Transfers
    if ($action === 'transfers') {
        $pdo = db();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = (int)($_POST['id'] ?? 0);
            $op = (string)($_POST['op'] ?? '');
            if ($id && in_array($op, ['approve','reject'], true)) {
                $tr = $pdo->prepare('SELECT * FROM transfers WHERE id = ? AND status = "pending"');
                $tr->execute([$id]);
                $t = $tr->fetch();
                if ($t) {
                    if ($op === 'approve') {
                        $pdo->beginTransaction();
                        try {
                            $ui = $pdo->prepare('SELECT * FROM user_items WHERE id = ? FOR UPDATE');
                            $ui->execute([$t['user_item_id']]);
                            $row = $ui->fetch();
                            if ($row && (int)$row['user_id'] === (int)$t['from_user_id']) {
                                $pdo->prepare('DELETE FROM user_items WHERE id = ?')->execute([$t['user_item_id']]);
                                $ex = $pdo->prepare('SELECT id, quantity FROM user_items WHERE user_id = ? AND item_id = ? FOR UPDATE');
                                $ex->execute([$t['to_user_id'], $row['item_id']]);
                                $dest = $ex->fetch();
                                if ($dest) {
                                    $pdo->prepare('UPDATE user_items SET quantity = quantity + 1 WHERE id = ?')->execute([$dest['id']]);
                                } else {
                                    $pdo->prepare('INSERT INTO user_items (user_id, item_id, quantity, created_at) VALUES (?,?,1,?)')
                                        ->execute([$t['to_user_id'], $row['item_id'], now()]);
                                }
                                $pdo->prepare('UPDATE transfers SET status = "approved", decided_at = ?, decided_by = ? WHERE id = ?')
                                    ->execute([now(), $_SESSION['admin_user_id'], $id]);
                            }
                            $pdo->commit();
                        } catch (Throwable $e) { $pdo->rollBack(); }
                    } else {
                        $pdo->prepare('UPDATE transfers SET status = "rejected", decided_at = ?, decided_by = ? WHERE id = ?')
                            ->execute([now(), $_SESSION['admin_user_id'], $id]);
                    }
                }
            }
        }
        $rows = $pdo->query('SELECT t.*, u1.telegram_id AS from_tg, u2.telegram_id AS to_tg, i.name AS item_name
            FROM transfers t JOIN users u1 ON u1.id=t.from_user_id JOIN users u2 ON u2.id=t.to_user_id
            JOIN user_items ui ON ui.id=t.user_item_id JOIN items i ON i.id=ui.item_id
            WHERE t.status = "pending" ORDER BY t.created_at ASC LIMIT 50')->fetchAll();
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>انتقالات</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px"><h3>انتقالات در انتظار</h3>';
        if (!$rows) echo '<div>موردی نیست.</div>';
        foreach ($rows as $r) {
            echo '<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:8px 0">';
            echo 'آیتم: <b>'.panel_h($r['item_name']).'</b> | از کاربر '.$r['from_tg'].' به '.$r['to_tg'];
            echo '<form method="post" style="display:inline-block;margin-right:12px"><input type="hidden" name="id" value="'.$r['id'].'" />';
            echo '<button name="op" value="approve">✅ تایید</button>';
            echo '<button name="op" value="reject">❌ رد</button>';
            echo '</form></div>';
        }
        echo '</div></body></html>';
        return;
    }

    if ($action === 'shop') {
        $pdo = db();
        $cats = $pdo->query('SELECT * FROM shop_categories ORDER BY type, sort_order, name')->fetchAll();
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>فروشگاه</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px"><h3>دسته‌بندی‌ها</h3><table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>نام</th><th>نوع</th><th>فعال</th></tr>';
        foreach ($cats as $c) echo '<tr><td>'.$c['id'].'</td><td>'.panel_h($c['name']).'</td><td>'.$c['type'].'</td><td>'.$c['is_active'].'</td></tr>';
        echo '</table>';
        $items = $pdo->query('SELECT i.*, c.name AS cat FROM items i JOIN shop_categories c ON c.id=i.category_id ORDER BY c.type, c.sort_order, i.sort_order, i.name')->fetchAll();
        echo '<h3 style="margin-top:16px">آیتم‌ها</h3><table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>نام</th><th>دسته</th><th>VIP</th><th>قیمت امتیاز</th><th>قیمت تومان</th><th>اعطا</th></tr>';
        foreach ($items as $it) echo '<tr><td>'.$it['id'].'</td><td>'.panel_h($it['name']).'</td><td>'.panel_h($it['cat']).'</td><td>'.$it['is_vip'].'</td><td>'.$it['price_points'].'</td><td>'.$it['price_toman'].'</td><td>'.$it['grant_type'].'</td></tr>';
        echo '</table></div></body></html>';
        return;
    }

    if ($action === 'countries') {
        $pdo = db();
        $rows = $pdo->query('SELECT * FROM countries ORDER BY name')->fetchAll();
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>کشورها</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px"><h3>کشورها</h3><table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>نام</th><th>نوع</th><th>فعال</th></tr>';
        foreach ($rows as $r) echo '<tr><td>'.$r['id'].'</td><td>'.panel_h($r['name']).'</td><td>'.$r['type'].'</td><td>'.$r['is_active'].'</td></tr>';
        echo '</table></div></body></html>';
        return;
    }

    if ($action === 'factories') {
        $pdo = db();
        $rows = $pdo->query('SELECT * FROM factory_types ORDER BY name')->fetchAll();
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>کارخانه‌ها</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px"><h3>انواع کارخانه</h3><table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>نام</th><th>قیمت پایه</th><th>درآمد پایه</th><th>حداکثر سطح</th><th>فعال</th></tr>';
        foreach ($rows as $r) echo '<tr><td>'.$r['id'].'</td><td>'.panel_h($r['name']).'</td><td>'.$r['base_price_points'].'</td><td>'.$r['base_income_points'].'</td><td>'.$r['max_level'].'</td><td>'.$r['is_active'].'</td></tr>';
        echo '</table></div></body></html>';
        return;
    }

    if ($action === 'questions') {
        $pdo = db();
        $rows = $pdo->query('SELECT * FROM questions ORDER BY id DESC LIMIT 100')->fetchAll();
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>سوالات</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px"><h3>سوالات</h3><table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>سوال</th><th>پاسخ</th><th>فعال</th></tr>';
        foreach ($rows as $r) echo '<tr><td>'.$r['id'].'</td><td>'.panel_h($r['question_text']).'</td><td>'.panel_h($r['correct_answer']).'</td><td>'.$r['is_active'].'</td></tr>';
        echo '</table></div></body></html>';
        return;
    }

    if ($action === 'settings') {
        $pdo = db();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach (['reward_min','reward_max'] as $k) {
                if (isset($_POST[$k])) {
                    $v = trim((string)$_POST[$k]);
                    $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$k, $v]);
                }
            }
        }
        $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key`="reward_min"'); $stmt->execute(); $rm = (int)($stmt->fetchColumn() ?: 100);
        $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key`="reward_max"'); $stmt->execute(); $rM = (int)($stmt->fetchColumn() ?: 1000);
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>تنظیمات</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px"><h3>تنظیمات</h3>';
        echo '<form method="post"><label>حداقل جایزه:</label><input name="reward_min" value="'.panel_h((string)$rm).'" /> ';
        echo '<label>حداکثر جایزه:</label><input name="reward_max" value="'.panel_h((string)$rM).'" /> ';
        echo '<button>ذخیره</button></form>';
        echo '</div></body></html>';
        return;
    }

    if ($action === 'broadcast') {
        $pdo = db();
        $saved = false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $msg = trim((string)($_POST['message'] ?? ''));
            if ($msg !== '') {
                $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES ("last_broadcast", ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$msg]);
                $saved = true;
            }
        }
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>پیام همگانی</title></head><body style="font-family:sans-serif">';
        panel_nav();
        echo '<div style="padding:16px"><h3>پیام همگانی</h3>';
        if ($saved) echo '<div style="color:green">ذخیره شد.</div>';
        echo '<form method="post"><textarea name="message" style="width:100%;height:160px"></textarea><br/><button>ذخیره برای ارسال</button></form>';
        echo '</div></body></html>';
        return;
    }

    header('HTTP/1.1 404 Not Found');
    echo 'صفحه مورد نظر یافت نشد.';
}

// ===============================
// GROUP Q&A (CRON + WEBHOOK)
// ===============================
function cronAskQuestion(): void {
    // Skip if there is an active question not expired
    $stmt = db()->prepare('SELECT COUNT(1) FROM question_posts WHERE group_chat_id = ? AND answered_by IS NULL AND deleted_at IS NULL AND expires_at > ?');
    $stmt->execute([GROUP_CHAT_ID, now()]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo "Active question exists.\n";
        return;
    }
    // Pick random active question
    $q = db()->query('SELECT id, question_text FROM questions WHERE is_active = 1 ORDER BY RAND() LIMIT 1');
    $row = $q->fetch();
    if (!$row) { echo "No questions found.\n"; return; }
    $res = tg('sendMessage', [
        'chat_id' => GROUP_CHAT_ID,
        'text' => '❓ ' . $row['question_text'],
    ]);
    if (!($res['ok'] ?? false)) { echo "Failed to post.\n"; return; }
    $messageId = (int)($res['result']['message_id'] ?? 0);
    $expiresAt = date('Y-m-d H:i:s', time() + 10*60);
    db()->prepare('INSERT INTO question_posts (question_id, group_chat_id, message_id, posted_at, expires_at) VALUES (?,?,?,?,?)')
        ->execute([$row['id'], GROUP_CHAT_ID, $messageId, now(), $expiresAt]);
    echo "Posted question #{$row['id']} as message {$messageId}.\n";
}

function cronCleanupQuestions(): void {
    $stmt = db()->prepare('SELECT id, message_id FROM question_posts WHERE group_chat_id = ? AND answered_by IS NULL AND deleted_at IS NULL AND expires_at <= ?');
    $stmt->execute([GROUP_CHAT_ID, now()]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        deleteMessage(GROUP_CHAT_ID, (int)$r['message_id']);
        db()->prepare('UPDATE question_posts SET deleted_at = ? WHERE id = ?')->execute([now(), $r['id']]);
        echo "Deleted expired question msg {$r['message_id']}\n";
    }
}

function handleGroupReply(array $message): void {
    if (!isset($message['chat']) || (int)$message['chat']['id'] !== GROUP_CHAT_ID) return; // only our group
    if (empty($message['reply_to_message'])) return; // must be a reply
    $replyToMsgId = (int)$message['reply_to_message']['message_id'];
    $stmt = db()->prepare('SELECT qp.id, q.id AS q_id, q.correct_answer FROM question_posts qp JOIN questions q ON q.id = qp.question_id WHERE qp.group_chat_id = ? AND qp.message_id = ? AND qp.answered_by IS NULL AND qp.deleted_at IS NULL AND qp.expires_at > ?');
    $stmt->execute([GROUP_CHAT_ID, $replyToMsgId, now()]);
    $row = $stmt->fetch();
    if (!$row) return;

    $from = $message['from'] ?? [];
    if (!$from) return;
    $user = ensureUser($from);
    if (!$user['country_id']) return; // only users with a country can answer

    $text = $message['text'] ?? ($message['caption'] ?? '');
    if ($text === '') return;

    $ans = normalizeAnswer($text);
    $correct = normalizeAnswer((string)$row['correct_answer']);
    if ($ans !== $correct) return; // wrong answer: ignore

    // Correct answer: reward
    $min = (int)(getSetting('reward_min', '100'));
    $max = (int)(getSetting('reward_max', '1000'));
    if ($min < 0) $min = 0; if ($max < $min) $max = $min;
    $reward = random_int($min, $max);

    $pdo = db();
    try {
        tx($pdo);
        $pdo->prepare('UPDATE users SET points = points + ? WHERE id = ?')->execute([$reward, $user['id']]);
        $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
            ->execute([$user['id'], 'reward', $reward, 'Group Q&A reward', now()]);
        $pdo->prepare('UPDATE question_posts SET answered_by = ?, answered_at = ?, reward_points = ? WHERE id = ?')
            ->execute([$user['telegram_id'], now(), $reward, $row['id']]);
        commit($pdo);
    } catch (Throwable $e) { rollback($pdo); return; }

    // Congratulate in DM and delete question
    sendMessage($user['telegram_id'], '🎉 پاسخ صحیح! ' . $reward . ' امتیاز به شما تعلق گرفت.');
    // Delete question message
    deleteMessage(GROUP_CHAT_ID, $replyToMsgId);
    db()->prepare('UPDATE question_posts SET deleted_at = ? WHERE id = ?')->execute([now(), $row['id']]);
        // Log
        $country = getUserCountryName((int)($user['country_id'] ?? 0)) ?? 'بدون کشور';
        $log = '✅ پاسخ صحیح Q&A\nکاربر: '.$user['telegram_id'].' (@'.($user['username'] ?? '-').')\nکشور: '.$country.'\nجایزه: '.$reward.' امتیاز';
        logToChannel($log, null);
}

// ===============================
// UPDATE ROUTER
// ===============================
function handleMessage(array $message): void {
    $from = $message['from'] ?? null;
    if (!$from) return;
    $user = ensureUser($from);

    if (MAINTENANCE && !isAdmin($user)) {
        sendMessage($user['telegram_id'], 'ربات موقتاً در حال تعمیر است. لطفاً بعداً مراجعه کنید.');
        return;
    }

    $text = trim((string)($message['text'] ?? ''));
    $hasPhoto = isset($message['photo']) && is_array($message['photo']) && count($message['photo']) > 0;
    $caption = trim((string)($message['caption'] ?? ''));

    // Handle states first (play submission, transfer)
    $state = getUserState((int)$user['id']);
    if ($state) {
        if ($state['state'] === 'await_submission') {
            $type = (string)$state['meta']['type'];
            $photoId = null;
            if ($hasPhoto) {
                $photos = $message['photo'];
                $largest = end($photos);
                $photoId = (string)$largest['file_id'];
            }
            $content = $text !== '' ? $text : ($caption !== '' ? $caption : null);
            if (!$content && !$photoId) {
                sendMessage($user['telegram_id'], 'لطفاً متن یا عکس ارسال کنید.');
                return;
            }
            saveSubmission($user, $type, $content, $photoId);
            return;
        } elseif ($state['state'] === 'await_transfer_to') {
            if ($text !== '') {
                handleTransferTarget($user, $text);
                return;
            }
        } elseif ($state['state'] === 'await_points_receipt') {
            if (!$hasPhoto) { sendMessage($user['telegram_id'], 'لطفاً فقط عکس رسید را ارسال کنید.'); return; }
            $photos = $message['photo'];
            $largest = end($photos);
            $photoId = (string)$largest['file_id'];
            $code = (string)$state['meta']['purchase_code'];
            $pdo = db();
            $stmt = $pdo->prepare('UPDATE point_purchases SET photo_file_id = ? WHERE code = ? AND user_id = ? AND status = "pending"');
            $stmt->execute([$photoId, $code, $user['id']]);
            clearUserState((int)$user['id']);
            sendMessage($user['telegram_id'], 'لطفاً منتظر بمانید. درخواست شما ثبت شد. کد خرید: <b>'.$code.'</b>');
            // Notify admin channel if configured
            $country = getUserCountryName((int)($user['country_id'] ?? 0)) ?? 'بدون کشور';
            $log = 'خرید امتیاز در انتظار:
کاربر: '.$user['telegram_id'].' (@'.($user['username'] ?? '-').')
کشور: '.$country.'
کد: '.$code;
            logToChannel($log, $photoId);
            return;
        } elseif ($state['state'] === 'await_broadcast') {
            if ($text === '') { sendMessage($user['telegram_id'], 'لطفاً متن پیام را ارسال کنید.'); return; }
            // Simple broadcast: to all users
            $stmt = db()->query('SELECT telegram_id FROM users');
            while ($row = $stmt->fetch()) {
                sendMessage((int)$row['telegram_id'], $text);
                usleep(100000); // 0.1s to respect rate
            }
            clearUserState((int)$user['id']);
            sendMessage($user['telegram_id'], 'پیام همگانی ارسال شد.');
            return;
        } elseif ($state['state'] === 'admin_pkg_add') {
            adminHandleAddPackage($user, $text);
            return;
        } elseif ($state['state'] === 'admin_pkg_edit') {
            adminHandleEditPackage($user, $text);
            return;
        } elseif ($state['state'] === 'admin_country_add') {
            adminHandleAddCountry($user, $text);
            return;
        } elseif ($state['state'] === 'admin_country_cfg_id') {
            if (!ctype_digit($text)) { sendMessage($user['telegram_id'],'فقط عدد شناسه کشور.'); return; }
            clearUserState((int)$user['id']);
            adminCountryConfigPanel($user, (int)$text);
            return;
        } elseif ($state['state'] === 'admin_country_set_sm') {
            $cid = (int)($state['meta']['country_id'] ?? 0);
            if (!ctype_digit($text)) { sendMessage($user['telegram_id'],'فقط عدد.'); return; }
            db()->prepare('UPDATE countries SET starter_money = ? WHERE id = ?')->execute([(int)$text, $cid]);
            clearUserState((int)$user['id']);
            sendMessage($user['telegram_id'], 'ذخیره شد.');
            adminCountryConfigPanel($user, $cid);
            return;
        } elseif ($state['state'] === 'admin_country_set_sp') {
            $cid = (int)($state['meta']['country_id'] ?? 0);
            if (!ctype_digit($text)) { sendMessage($user['telegram_id'],'فقط عدد.'); return; }
            db()->prepare('UPDATE countries SET starter_points = ? WHERE id = ?')->execute([(int)$text, $cid]);
            clearUserState((int)$user['id']);
            sendMessage($user['telegram_id'], 'ذخیره شد.');
            adminCountryConfigPanel($user, $cid);
            return;
        }
    }

    // Commands / Menu
    if ($text === '/start') {
        $name = htmlspecialchars($user['first_name'] ?: 'کاربر');
        $welcome = "سلام {$name}!\nبه ربات بازی خوش آمدید.";
        sendMessage($user['telegram_id'], $welcome, [ 'reply_markup' => mainMenu($user) ]);
        if (!hasCountry($user)) {
            sendMessage($user['telegram_id'], 'برای شروع، کشور خود را انتخاب کنید.', [ 'reply_markup' => countrySelectionKeyboard() ]);
        }
        return;
    }

    if ($text === '/panel') {
        if (!isAdmin($user)) { sendMessage($user['telegram_id'], 'دسترسی به پنل مدیریت ندارید.'); return; }
        adminShowHome($user);
        return;
    }

    switch ($text) {
        case '🏳️ انتخاب کشور':
            sendMessage($user['telegram_id'], 'کشور خود را انتخاب کنید:', [ 'reply_markup' => countrySelectionKeyboard() ]);
            return;
        case '🏭 کارخانه‌ها':
            listUserFactories($user);
            return;
        case '🎮 بازی کردن':
            promptPlayType($user);
            return;
        case '💲 فروشگاه عادی':
            listShopCategories($user, 'normal');
            return;
        case '🛒 فروشگاه VIP':
            listShopCategories($user, 'vip');
            return;
        case '💱 خرید امتیاز':
            listPointPackages($user);
            return;
        case '💎 لیست ایونت‌ها':
            showMissionsForUser($user);
            return;
        case '🪪 لیست کشورهای فعال':
            listActiveCountries($user);
            return;
        case '💳 امتیاز و نیروهای من':
            // Refresh user to get latest points
            $user = ensureUser($from);
            showMyPointsAndForces($user);
            return;
        case '☎️ ارتباط با ادمین':
            contactAdmin($user);
            return;
    }

    // Unknown text fallback
    if ($text !== '' || $hasPhoto) {
        sendMessage($user['telegram_id'], 'درخواست نامعتبر. از منوی زیر استفاده کنید.', [ 'reply_markup' => mainMenu($user) ]);
    }
}

function handleCallbackQuery(array $cb): void {
    $from = $cb['from'] ?? null;
    if (!$from) return;
    $user = ensureUser($from);

    $data = (string)($cb['data'] ?? '');
    if ($data === '') return;

    // pattern: ACTION|arg1|arg2
    $parts = explode('|', $data);
    $action = $parts[0] ?? '';

    if (MAINTENANCE && !isAdmin($user)) {
        answerCallback($cb['id'], 'در حال تعمیر.'); return;
    }

    try {
        switch ($action) {
            case 'ADMIN':
                if (!isAdmin($user)) { answerCallback($cb['id'], ''); return; }
                $sub = (string)($parts[1] ?? '');
                if ($sub === 'HOME' || $sub === '') {
                    adminShowHome($user, $cb['message']['message_id'] ?? null);
                    answerCallback($cb['id'], '');
                    break;
                }
                if ($sub === 'PPKGS') {
                    adminListPointPackages($user, $cb['message']['message_id'] ?? null);
                    answerCallback($cb['id'], '');
                    break;
                }
                if ($sub === 'PPKGS_ADD') { adminStartAddPackage($user); answerCallback($cb['id'], ''); break; }
                if ($sub === 'PPKG_EDIT') { adminStartEditPackage($user, (int)($parts[2] ?? 0)); answerCallback($cb['id'], ''); break; }
                if ($sub === 'PPKG_TOG') { db()->prepare('UPDATE point_packages SET is_active = 1 - is_active WHERE id = ?')->execute([(int)($parts[2] ?? 0)]); adminListPointPackages($user, $cb['message']['message_id'] ?? null); answerCallback($cb['id'], ''); break; }
                if ($sub === 'PPKG_DEL') { db()->prepare('DELETE FROM point_packages WHERE id = ?')->execute([(int)($parts[2] ?? 0)]); adminListPointPackages($user, $cb['message']['message_id'] ?? null); answerCallback($cb['id'], ''); break; }
                if ($sub === 'PP') {
                    adminListPointPurchases($user, $cb['message']['message_id'] ?? null);
                    answerCallback($cb['id'], '');
                    break;
                }
                if ($sub === 'SHOP') {
                    adminListShop($user, $cb['message']['message_id'] ?? null);
                    answerCallback($cb['id'], '');
                    break;
                }
                if ($sub === 'FACTORIES') {
                    adminListFactories($user, $cb['message']['message_id'] ?? null);
                    answerCallback($cb['id'], '');
                    break;
                }
                if ($sub === 'QUESTIONS') {
                    // Minimal: just count
                    $cnt = (int)db()->query('SELECT COUNT(*) FROM questions')->fetchColumn();
                    editMessageText($user['telegram_id'], $cb['message']['message_id'], 'تعداد سوالات: '.$cnt, ['reply_markup'=>['inline_keyboard'=>[[['text'=>'⬅️ بازگشت','callback_data'=>'ADMIN|HOME']]]]]);
                    answerCallback($cb['id'], '');
                    break;
                }
                if ($sub === 'SUBMISSIONS') {
                    // Shortcut to pending count
                    $cnt = (int)db()->query("SELECT COUNT(*) FROM submissions WHERE status='pending'")->fetchColumn();
                    editMessageText($user['telegram_id'], $cb['message']['message_id'], 'ارسالی‌های در انتظار: '.$cnt, ['reply_markup'=>['inline_keyboard'=>[[['text'=>'⬅️ بازگشت','callback_data'=>'ADMIN|HOME']]]]]);
                    answerCallback($cb['id'], '');
                    break;
                }
                if ($sub === 'TRANSFERS') {
                    $cnt = (int)db()->query("SELECT COUNT(*) FROM transfers WHERE status='pending'")->fetchColumn();
                    editMessageText($user['telegram_id'], $cb['message']['message_id'], 'انتقالات در انتظار: '.$cnt, ['reply_markup'=>['inline_keyboard'=>[[['text'=>'⬅️ بازگشت','callback_data'=>'ADMIN|HOME']]]]]);
                    answerCallback($cb['id'], '');
                    break;
                }
                if ($sub === 'BROADCAST') {
                    editMessageText($user['telegram_id'], $cb['message']['message_id'], 'متن پیام همگانی را به صورت معمول ارسال کنید.', ['reply_markup'=>['inline_keyboard'=>[[['text'=>'⬅️ بازگشت','callback_data'=>'ADMIN|HOME']]]]]);
                    setUserState((int)$user['id'], 'await_broadcast');
                    answerCallback($cb['id'], '');
                    break;
                }
                if ($sub === 'PP' && ($parts[2] ?? '') === 'APPROVE') {
                    $ppId = (int)($parts[3] ?? 0);
                    $pdo = db();
                    $q = $pdo->prepare('SELECT pp.*, u.id AS uid, u.telegram_id, u.username, c.name AS country, pkg.points AS pkg_points, pkg.name AS pkg_name FROM point_purchases pp JOIN users u ON u.id=pp.user_id LEFT JOIN countries c ON c.id=u.country_id JOIN point_packages pkg ON pkg.id=pp.package_id WHERE pp.id = ? AND pp.status = "pending"');
                    $q->execute([$ppId]);
                    $row = $q->fetch();
                    if ($row) {
                        try {
                            tx($pdo);
                            $points = (int)$row['pkg_points'];
                            $pdo->prepare('UPDATE users SET points = points + ? WHERE id = ?')->execute([$points, (int)$row['uid']]);
                            $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
                                ->execute([(int)$row['uid'], 'earn', $points, 'Points purchase code '.$row['code'], now()]);
                            $pdo->prepare('UPDATE point_purchases SET status = "approved", decided_at = ?, decided_by = ? WHERE id = ?')
                                ->execute([now(), (int)$user['id'], $ppId]);
                            commit($pdo);
                        } catch (Throwable $e) { rollback($pdo); }
                        // Notify user
                        sendMessage((int)$row['telegram_id'], '✅ خرید امتیاز تایید شد. کد: '.$row['code'].' | +'.$row['pkg_points'].' امتیاز');
                        // Log to channel
                        $log = '✅ تایید خرید امتیاز\n'
                             . 'کاربر: '.$row['telegram_id'].' (@'.($row['username']??'-').')\n'
                             . 'کشور: '.(($row['country']??'-')?:'-').'\n'
                             . 'بسته: '.$row['pkg_name'].' ('.$row['pkg_points'].' امتیاز)\n'
                             . 'کد: '.$row['code'];
                        $photo = $row['photo_file_id'] ?: null;
                        logToChannel($log, $photo);
                    }
                    adminListPointPurchases($user, $cb['message']['message_id'] ?? null);
                    answerCallback($cb['id'], '');
                    break;
                }
                if ($sub === 'PP' && ($parts[2] ?? '') === 'REJECT') {
                    $ppId = (int)($parts[3] ?? 0);
                    $pdo = db();
                    $q = $pdo->prepare('SELECT pp.*, u.id AS uid, u.telegram_id, u.username, c.name AS country, pkg.points AS pkg_points, pkg.name AS pkg_name FROM point_purchases pp JOIN users u ON u.id=pp.user_id LEFT JOIN countries c ON c.id=u.country_id JOIN point_packages pkg ON pkg.id=pp.package_id WHERE pp.id = ? AND pp.status = "pending"');
                    $q->execute([$ppId]);
                    $row = $q->fetch();
                    if ($row) {
                        $pdo->prepare('UPDATE point_purchases SET status = "rejected", decided_at = ?, decided_by = ? WHERE id = ?')
                            ->execute([now(), (int)$user['id'], $ppId]);
                        // Notify user
                        sendMessage((int)$row['telegram_id'], '❌ خرید امتیاز رد شد. کد: '.$row['code']);
                        // Log to channel
                        $log = '❌ رد خرید امتیاز\n'
                             . 'کاربر: '.$row['telegram_id'].' (@'.($row['username']??'-').')\n'
                             . 'کشور: '.(($row['country']??'-')?:'-').'\n'
                             . 'بسته: '.$row['pkg_name'].' ('.$row['pkg_points'].' امتیاز)\n'
                             . 'کد: '.$row['code'];
                        $photo = $row['photo_file_id'] ?: null;
                        logToChannel($log, $photo);
                    }
                    adminListPointPurchases($user, $cb['message']['message_id'] ?? null);
                    answerCallback($cb['id'], '');
                    break;
                }
                // Countries list placeholder
                if ($sub === 'COUNTRIES') { adminListCountries($user, $cb['message']['message_id'] ?? null); answerCallback($cb['id'], ''); break; }
                if ($sub === 'COUNTRY_ADD') { adminStartAddCountry($user); answerCallback($cb['id'], ''); break; }
                if ($sub === 'COUNTRY_CFG_PROMPT') { sendMessage($user['telegram_id'], 'شناسه کشور را ارسال کنید:'); setUserState((int)$user['id'], 'admin_country_cfg_id'); answerCallback($cb['id'], ''); break; }
                if ($sub === 'COUNTRY_TOG') { $cid=(int)($parts[2]??0); $key=(string)($parts[3]??''); $stmt=db()->prepare('SELECT settings FROM countries WHERE id = ?'); $stmt->execute([$cid]); $cur=$stmt->fetchColumn(); $s=$cur?json_decode((string)$cur,true):[]; if(!is_array($s))$s=[]; $s[$key]=empty($s[$key]); db()->prepare('UPDATE countries SET settings = ? WHERE id = ?')->execute([json_encode($s,JSON_UNESCAPED_UNICODE),$cid]); adminCountryConfigPanel($user,$cid); answerCallback($cb['id'], ''); break; }
                if ($sub === 'COUNTRY_SET_SM') { $cid=(int)($parts[2]??0); setUserState((int)$user['id'],'admin_country_set_sm',['country_id'=>$cid]); sendMessage($user['telegram_id'],'مقدار جدید استارتر پول بازی را ارسال کنید:'); answerCallback($cb['id'], ''); break; }
                if ($sub === 'COUNTRY_SET_SP') { $cid=(int)($parts[2]??0); setUserState((int)$user['id'],'admin_country_set_sp',['country_id'=>$cid]); sendMessage($user['telegram_id'],'مقدار جدید استارتر امتیاز را ارسال کنید:'); answerCallback($cb['id'], ''); break; }
                // Not implemented actions fallback
                answerCallback($cb['id'], '');
                break;
            case 'NOOP':
                answerCallback($cb['id'], '');
                break;
            case 'COUNTRY_VIP':
            case 'COUNTRY_FREE_TAKE':
                handleCountryCallback($user, $action, (int)($parts[1] ?? 0), $cb);
                break;
            case 'FACTORY_UPGRADE':
            case 'FACTORY_UPGRADE_CONFIRM':
            case 'FACTORY_COLLECT':
                handleFactoryAction($user, $action, (int)($parts[1] ?? 0), $cb);
                break;
            case 'FACTORY_BUY_LIST':
                // Show purchasable factories list based on country settings and currency
                $rows = db()->query('SELECT * FROM factory_types WHERE is_active = 1 ORDER BY name')->fetchAll();
                if (!$rows) { answerCallback($cb['id'], 'موردی نیست'); break; }
                foreach ($rows as $ft) {
                    $price = ftBasePrice($ft);
                    $unit = (ftPriceCurrency($ft) === 'money') ? 'پول بازی' : 'امتیاز';
                    $btn = [ [ ['text'=>'🛒 خرید - '.$price.' '.$unit, 'callback_data'=>'FACTORY_BUY|'.$ft['id'] ] ] ];
                    sendMessage($user['telegram_id'], '🏭 '.htmlspecialchars($ft['name'])."\nقیمت پایه: <b>{$price}</b> {$unit}", [ 'reply_markup'=>['inline_keyboard'=>$btn] ]);
                }
                answerCallback($cb['id'], '');
                break;
            case 'FACTORY_BUY':
                // Attempt to purchase factory type
                $ftId = (int)($parts[1] ?? 0);
                $stmt = db()->prepare('SELECT * FROM factory_types WHERE id = ? AND is_active = 1');
                $stmt->execute([$ftId]);
                $ft = $stmt->fetch();
                if (!$ft) { answerCallback($cb['id'], 'یافت نشد'); break; }
                $price = ftBasePrice($ft);
                $cur = ftPriceCurrency($ft);
                $pdo = db();
                try {
                    tx($pdo);
                    $u = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
                    $u->execute([$user['id']]);
                    $usr = $u->fetch();
                    // already owned?
                    $ex = $pdo->prepare('SELECT id FROM user_factories WHERE user_id = ? AND factory_type_id = ?');
                    $ex->execute([$user['id'], $ftId]);
                    if ($ex->fetch()) { rollback($pdo); answerCallback($cb['id'], 'قبلاً خریداری شده است.'); break; }
                    if ($cur === 'money') {
                        if ((int)$usr['money'] < $price) { rollback($pdo); answerCallback($cb['id'], 'پول بازی کافی نیست.'); break; }
                        $pdo->prepare('UPDATE users SET money = money - ? WHERE id = ?')->execute([$price, $user['id']]);
                        $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, amount_money, description, created_at) VALUES (?,?,?,?,?,?)')
                            ->execute([$user['id'], 'spend', 0, -$price, 'Buy factory: '.$ft['name'], now()]);
                    } else {
                        if ((int)$usr['points'] < $price) { rollback($pdo); answerCallback($cb['id'], 'امتیاز کافی نیست.'); break; }
                        $pdo->prepare('UPDATE users SET points = points - ? WHERE id = ?')->execute([$price, $user['id']]);
                        $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
                            ->execute([$user['id'], 'spend', -$price, 'Buy factory: '.$ft['name'], now()]);
                    }
                    $pdo->prepare('INSERT INTO user_factories (user_id, factory_type_id, level, created_at) VALUES (?,?,1,?)')
                        ->execute([$user['id'], $ftId, now()]);
                    commit($pdo);
                } catch (Throwable $e) { rollback($pdo); answerCallback($cb['id'], 'خرید ناموفق بود.'); break; }
                answerCallback($cb['id'], 'خرید انجام شد.');
                sendMessage($user['telegram_id'], '✅ کارخانه با موفقیت خریداری شد.');
                break;
            case 'PLAY':
                handlePlayCallback($user, (string)($parts[1] ?? ''), $cb);
                break;
            case 'SHOP_CAT':
                listShopItems($user, (string)($parts[1] ?? ''), (int)($parts[2] ?? 0));
                answerCallback($cb['id'], '');
                break;
            case 'BUY_ITEM':
                handleBuyItem($user, (int)($parts[1] ?? 0), $cb);
                break;
            case 'BUY_VIP_ITEM':
                handleBuyVipItem($user, (int)($parts[1] ?? 0), $cb);
                break;
            case 'VIP_BUY':
                handleVipBuy($user, (int)($parts[1] ?? 0), null, $cb);
                break;
            case 'VIP_BUY_POINTS':
                handleVipBuy($user, null, (int)($parts[1] ?? 0), $cb);
                break;
            case 'BUY_POINTS':
                startPointsPurchase($user, (int)($parts[1] ?? 0), $cb);
                break;
            case 'TRANSFER':
                startTransfer($user, (int)($parts[1] ?? 0), $cb);
                break;
            case 'MISSION_CLAIM':
                $missionId = (int)($parts[1] ?? 0);
                $pdo = db();
                // Check eligibility
                $m = $pdo->prepare('SELECT * FROM missions WHERE id = ? AND is_active = 1');
                $m->execute([$missionId]);
                $mission = $m->fetch();
                if (!$mission) { answerCallback($cb['id'], 'نامعتبر'); break; }
                $um = $pdo->prepare('SELECT progress_count, claimed_at FROM user_missions WHERE mission_id = ? AND user_id = ?');
                $um->execute([$missionId, $user['id']]);
                $row = $um->fetch();
                if (!$row || (int)$row['progress_count'] < (int)$mission['required_count'] || !empty($row['claimed_at'])) { answerCallback($cb['id'], 'نامعتبر'); break; }
                try {
                    tx($pdo);
                    if ($mission['reward_type'] === 'money') {
                        $pdo->prepare('UPDATE users SET money = money + ? WHERE id = ?')->execute([(int)$mission['reward_amount'], $user['id']]);
                        $pdo->prepare('INSERT INTO transactions (user_id, type, amount_money, description, created_at) VALUES (?,?,?,?,?)')
                            ->execute([$user['id'], 'reward', (int)$mission['reward_amount'], 'Mission reward: '.$mission['name'], now()]);
                    } else {
                        $pdo->prepare('UPDATE users SET points = points + ? WHERE id = ?')->execute([(int)$mission['reward_amount'], $user['id']]);
                        $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
                            ->execute([$user['id'], 'reward', (int)$mission['reward_amount'], 'Mission reward: '.$mission['name'], now()]);
                    }
                    $pdo->prepare('UPDATE user_missions SET claimed_at = ? WHERE mission_id = ? AND user_id = ?')
                        ->execute([now(), $missionId, $user['id']]);
                    commit($pdo);
                } catch (Throwable $e) { rollback($pdo); answerCallback($cb['id'], 'خطا'); break; }
                answerCallback($cb['id'], 'جایزه دریافت شد.');
                sendMessage($user['telegram_id'], '🎁 جایزه ماموریت به حساب شما واریز شد.');
                break;
            default:
                answerCallback($cb['id'], 'درخواست نامعتبر');
        }
    } catch (Throwable $e) {
        answerCallback($cb['id'], 'خطای غیرمنتظره');
    }
}

// ===============================
// ENTRYPOINT
// ===============================
if (php_sapi_name() === 'cli') {
    $cmd = $argv[1] ?? '';
    if ($cmd === 'cron:ask') {
        ensureSchema();
        cronAskQuestion();
    } elseif ($cmd === 'cron:cleanup') {
        ensureSchema();
        cronCleanupQuestions();
    } else {
        echo "Usage:\n";
        echo "  php bot.php cron:ask      # Post a new question if none active\n";
        echo "  php bot.php cron:cleanup  # Delete expired unanswered questions\n";
    }
    exit(0);
}

// Admin panel GET handling (before webhook)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['action']) || isset($_GET['panel']))) {
    serveAdminPanel();
    exit;
}

// Initialize schema before handling panel/webhook
ensureSchema();

// Webhook handler
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') { http_response_code(200); exit; }
$update = json_decode($raw, true);
if (!is_array($update)) { http_response_code(200); exit; }

// Message types
if (isset($update['message'])) {
    $msg = $update['message'];
    // If this is a group reply, handle Q&A first
    if (($msg['chat']['type'] ?? '') !== 'private') {
        handleGroupReply($msg);
    } else {
        handleMessage($msg);
    }
}
if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
}

http_response_code(200);
