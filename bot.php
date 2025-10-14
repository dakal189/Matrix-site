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
const BOT_TOKEN = 'YOUR_TELEGRAM_BOT_TOKEN_HERE'; // e.g., 123456:ABC...

// Admin numeric user ID and username
const ADMIN_ID = 123456789; // Change to your Telegram numeric ID
const ADMIN_USERNAME = 'ADMIN_USERNAME'; // without @

// Group chat ID for Q&A (negative for supergroups)
const GROUP_CHAT_ID = -1001234567890; // e.g., -100xxxxxxxxxx

// Maintenance mode (true = users see maintenance message; admin bypasses)
const MAINTENANCE = false;

// Database connection
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'telegram_game';
const DB_USER = 'root';
const DB_PASS = '';

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

function sendMessage(int|string $chatId, string $text, array $extra = []): void {
    $params = array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $extra);
    tg('sendMessage', $params);
}

function sendPhoto(int|string $chatId, string $fileIdOrUrl, array $extra = []): void {
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

function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): void {
    $params = array_merge([
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $extra);
    tg('editMessageText', $params);
}

function deleteMessage(int|string $chatId, int $messageId): void {
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
            $u = $pdo->prepare('UPDATE users SET country_id = ? WHERE id = ?');
            $u->execute([$countryId, $user['id']]);
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
        sendMessage($user['telegram_id'], "شما هیچ کارخانه‌ای ندارید.\nبرای خرید، به <b>فروشگاه عادی</b> بروید و مجوز کارخانه را تهیه کنید.");
        return;
    }

    foreach ($rows as $r) {
        $name = $r['name'];
        $level = (int)$r['level'];
        $income = calcIncome((int)$r['base_income_points'], $level);
        $basePrice = (int)$r['base_price_points'];
        $maxLevel = (int)$r['max_level'];
        $upgradingUntil = $r['upgrading_until'] ? strtotime($r['upgrading_until']) : null;
        $cooldownUntil = $r['cooldown_until'] ? strtotime($r['cooldown_until']) : null;

        $text = "🏭 <b>{$name}</b> - سطح <b>{$level}</b>\n" .
                "درآمد: <b>{$income}</b> امتیاز";

        $buttons = [];
        if ($upgradingUntil && $upgradingUntil > time()) {
            $remainingMin = (int)ceil(($upgradingUntil - time())/60);
            $buttons[] = [['text' => '⏳ ارتقا در حال انجام (' . $remainingMin . ' دقیقه)', 'callback_data' => 'NOOP']];
        } elseif ($level >= $maxLevel) {
            $buttons[] = [['text' => '🔝 حداکثر سطح', 'callback_data' => 'NOOP']];
        } else {
            $nextCost = calcUpgradeCost($basePrice, $level);
            $buttons[] = [['text' => "⬆️ ارتقا به سطح " . ($level+1) . " (" . $nextCost . " امتیاز)", 'callback_data' => 'FACTORY_UPGRADE|' . $r['uf_id'] ]];
        }

        if ($cooldownUntil && $cooldownUntil > time()) {
            $remainingH = (int)ceil(($cooldownUntil - time())/3600);
            $buttons[] = [['text' => '🕒 استراحت تا ' . $remainingH . ' ساعت', 'callback_data' => 'NOOP']];
        } else {
            $buttons[] = [['text' => '💰 دریافت درآمد: ' . $income, 'callback_data' => 'FACTORY_COLLECT|' . $r['uf_id'] ]];
        }

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
        $priceTxt = $isVip ? ((int)$it['price_toman'] . ' تومان') : ((int)$it['price_points'] . ' امتیاز');
        $text = '<b>' . htmlspecialchars($name) . '</b>\n' . htmlspecialchars($desc) . "\nقیمت: <b>{$priceTxt}</b>";
        if ($isVip) {
            $cb = 'VIP_BUY|' . $it['id'];
            $btn = [ ['text' => '🛒 خرید VIP', 'callback_data' => $cb] ];
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
    $price = (int)$it['price_points'];
    try {
        tx($pdo);
        $u = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
        $u->execute([$user['id']]);
        $usr = $u->fetch();
        if ((int)$usr['points'] < $price) { rollback($pdo); answerCallback($cb['id'], 'امتیاز کافی نیست.'); return; }
        $pdo->prepare('UPDATE users SET points = points - ? WHERE id = ?')->execute([$price, $user['id']]);
        $pdo->prepare('INSERT INTO transactions (user_id, type, amount_points, description, created_at) VALUES (?,?,?,?,?)')
            ->execute([$user['id'], 'spend', -$price, 'Buy item: ' . $it['name'], now()]);
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
            $pdo->prepare('INSERT INTO user_items (user_id, item_id, quantity, created_at) VALUES (?,?,1,?)')
                ->execute([$user['id'], $itemId, now()]);
        }
        commit($pdo);
    } catch (Throwable $e) { rollback($pdo); answerCallback($cb['id'], 'خرید ناموفق بود.'); return; }
    answerCallback($cb['id'], 'خرید موفق بود.');
    sendMessage($user['telegram_id'], '✅ آیتم با موفقیت خریداری شد.');
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
    if (!$rows) { sendMessage($user['telegram_id'], 'بسته‌ای موجود نیست.'); return; }
    foreach ($rows as $r) {
        $text = '<b>' . htmlspecialchars($r['name']) . '</b> - ' . (int)$r['points'] . ' امتیاز\n' . 'قیمت: <b>' . (int)$r['price_toman'] . '</b> تومان';
        $btn = [ ['text' => '🛒 خرید VIP', 'callback_data' => 'VIP_BUY_POINTS|' . $r['id']] ];
        sendMessage($user['telegram_id'], $text, [ 'reply_markup' => ['inline_keyboard' => [ $btn ] ] ]);
    }
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
function listEvents(array $user): void {
    $stmt = db()->query('SELECT title, description FROM events WHERE is_active = 1 ORDER BY id DESC LIMIT 20');
    $rows = $stmt->fetchAll();
    if (!$rows) { sendMessage($user['telegram_id'], 'ایونت فعالی وجود ندارد.'); return; }
    foreach ($rows as $e) {
        $text = '💎 <b>' . htmlspecialchars($e['title']) . '</b>\n' . htmlspecialchars((string)$e['description']);
        sendMessage($user['telegram_id'], $text);
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
            listEvents($user);
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
            case 'VIP_BUY':
                handleVipBuy($user, (int)($parts[1] ?? 0), null, $cb);
                break;
            case 'VIP_BUY_POINTS':
                handleVipBuy($user, null, (int)($parts[1] ?? 0), $cb);
                break;
            case 'TRANSFER':
                startTransfer($user, (int)($parts[1] ?? 0), $cb);
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
        cronAskQuestion();
    } elseif ($cmd === 'cron:cleanup') {
        cronCleanupQuestions();
    } else {
        echo "Usage:\n";
        echo "  php bot.php cron:ask      # Post a new question if none active\n";
        echo "  php bot.php cron:cleanup  # Delete expired unanswered questions\n";
    }
    exit(0);
}

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
