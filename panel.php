<?php
/**
 * Minimal Admin Panel (single file) for Telegram Game Bot
 * - PHP 8.1+, PDO
 * - Token-based login via /panel command
 * - Basic sections: Dashboard, Users, Submissions, Transfers, Shop, Countries, Factories, Questions, Settings, Broadcast
 * - This is a functional baseline you can extend; focuses on core flows
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();

date_default_timezone_set('Asia/Tehran');

// Reuse DB config by defining here (ensure to match bot.php)
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'telegram_game';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function now(): string { return date('Y-m-d H:i:s'); }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function isLoggedIn(): bool { return isset($_SESSION['admin_user_id']); }
function requireLogin(): void { if (!isLoggedIn()) { header('Location: panel.php?action=login'); exit; } }

function verifyToken(string $token): ?int {
    $stmt = db()->prepare('SELECT user_id FROM panel_tokens WHERE token = ? AND used_at IS NULL AND expires_at > ?');
    $stmt->execute([$token, now()]);
    $uid = $stmt->fetchColumn();
    if (!$uid) return null;
    db()->prepare('UPDATE panel_tokens SET used_at = ? WHERE token = ?')->execute([now(), $token]);
    return (int)$uid;
}

function nav(): void {
    echo '<nav style="padding:10px;background:#111;color:#fff;display:flex;gap:12px;flex-wrap:wrap">';
    echo '<a href="panel.php" style="color:#fff">داشبورد</a>';
    echo '<a href="panel.php?action=users" style="color:#fff">کاربران</a>';
    echo '<a href="panel.php?action=submissions" style="color:#fff">ارسالی‌ها</a>';
    echo '<a href="panel.php?action=transfers" style="color:#fff">انتقالات</a>';
    echo '<a href="panel.php?action=shop" style="color:#fff">فروشگاه</a>';
    echo '<a href="panel.php?action=factories" style="color:#fff">کارخانه‌ها</a>';
    echo '<a href="panel.php?action=countries" style="color:#fff">کشورها</a>';
    echo '<a href="panel.php?action=questions" style="color:#fff">سوالات</a>';
    echo '<a href="panel.php?action=settings" style="color:#fff">تنظیمات</a>';
    echo '<a href="panel.php?action=broadcast" style="color:#fff">پیام همگانی</a>';
    echo '<span style="flex:1 1 auto"></span>';
    if (isLoggedIn()) { echo '<a href="panel.php?action=logout" style="color:#fff">خروج</a>'; }
    echo '</nav>';
}

$action = $_GET['action'] ?? 'dashboard';

if ($action === 'login') {
    // Auto-login if token is provided via GET (one-click link)
    if (isset($_GET['token'])) {
        $token = trim((string)$_GET['token']);
        if ($token !== '') {
            $uid = verifyToken($token);
            if ($uid) { $_SESSION['admin_user_id'] = $uid; header('Location: panel.php'); exit; }
            $error = 'توکن نامعتبر یا منقضی شده است.';
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = trim($_POST['token'] ?? '');
        $uid = $token !== '' ? verifyToken($token) : null;
        if ($uid) {
            $_SESSION['admin_user_id'] = $uid;
            header('Location: panel.php');
            exit;
        }
        $error = 'توکن نامعتبر یا منقضی شده است.';
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>ورود مدیر</title></head><body style="font-family:sans-serif">';
    echo '<div style="max-width:480px;margin:60px auto;padding:24px;border:1px solid #ccc;border-radius:8px">';
    echo '<h3>ورود مدیر</h3>';
    if (!empty($error)) echo '<div style="color:#b00">'.h($error).'</div>';
    echo '<form method="post"><label>توکن یکبار مصرف:</label><input name="token" style="width:100%;padding:8px;margin:8px 0" /><button style="padding:8px 16px">ورود</button></form>';
    echo '</div></body></html>';
    exit;
}

if ($action === 'logout') {
    session_destroy();
    header('Location: panel.php?action=login');
    exit;
}

requireLogin();

// Dashboard
if ($action === 'dashboard' || $action === '') {
    $pdo = db();
    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $active24h = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE last_active_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
    $sumEarn = (int)$pdo->query("SELECT COALESCE(SUM(amount_points),0) FROM transactions WHERE type IN ('earn','reward')")->fetchColumn();
    $sumSpend = (int)$pdo->query("SELECT COALESCE(SUM(-amount_points),0) FROM transactions WHERE type = 'spend'")->fetchColumn();

    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>داشبورد</title></head><body style="font-family:sans-serif">';
    nav();
    echo '<div style="padding:16px">';
    echo '<h3>داشبورد</h3>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
    echo '<div style="padding:12px;border:1px solid #ddd;border-radius:8px">کاربران: <b>'.$totalUsers.'</b></div>';
    echo '<div style="padding:12px;border:1px solid #ddd;border-radius:8px">فعال ۲۴ساعت: <b>'.$active24h.'</b></div>';
    echo '<div style="padding:12px;border:1px solid #ddd;border-radius:8px">مجموع کسب امتیاز: <b>'.$sumEarn.'</b></div>';
    echo '<div style="padding:12px;border:1px solid #ddd;border-radius:8px">مجموع خرج امتیاز: <b>'.$sumSpend.'</b></div>';
    echo '</div>';
    echo '</div></body></html>';
    exit;
}

// Users list/profile (read-only minimal)
if ($action === 'users') {
    $pdo = db();
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $stmt = $pdo->prepare('SELECT u.*, c.name AS country FROM users u LEFT JOIN countries c ON c.id=u.country_id WHERE u.telegram_id = ? OR u.username = ?');
        $stmt->execute([$q, $q]);
    } else {
        $stmt = $pdo->query('SELECT u.*, c.name AS country FROM users u LEFT JOIN countries c ON c.id=u.country_id ORDER BY u.id DESC LIMIT 100');
    }
    $rows = $stmt->fetchAll();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>کاربران</title></head><body style="font-family:sans-serif">';
    nav();
    echo '<div style="padding:16px">';
    echo '<h3>کاربران</h3>';
    echo '<form method="get" style="margin-bottom:8px"><input type="hidden" name="action" value="users" /><input name="q" placeholder="آیدی عددی یا یوزرنیم" value="'.h($q).'"/><button>جستجو</button></form>';
    echo '<table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>تلگرام</th><th>یوزرنیم</th><th>کشور</th><th>امتیاز</th><th>ایجاد</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td>'.$r['id'].'</td><td>'.$r['telegram_id'].'</td><td>'.h((string)$r['username']).'</td><td>'.h((string)$r['country']).'</td><td>'.$r['points'].'</td><td>'.$r['created_at'].'</td></tr>';
    }
    echo '</table></div></body></html>';
    exit;
}

// Submissions review (approve/reject)
if ($action === 'submissions') {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $op = $_POST['op'] ?? '';
        if ($id > 0 && in_array($op, ['approve','reject'], true)) {
            $status = $op === 'approve' ? 'approved' : 'rejected';
            $pdo->prepare('UPDATE submissions SET status = ?, reviewed_at = ?, reviewed_by = ? WHERE id = ?')
                ->execute([$status, now(), $_SESSION['admin_user_id'], $id]);
        }
    }
    $rows = $pdo->query("SELECT s.*, u.telegram_id, c.name AS country FROM submissions s JOIN users u ON u.id=s.user_id LEFT JOIN countries c ON c.id=s.country_id WHERE s.status='pending' ORDER BY s.created_at ASC LIMIT 50")->fetchAll();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>ارسالی‌ها</title></head><body style="font-family:sans-serif">';
    nav();
    echo '<div style="padding:16px"><h3>ارسالی‌های در انتظار</h3>';
    if (!$rows) echo '<div>موردی نیست.</div>';
    foreach ($rows as $r) {
        echo '<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:8px 0">';
        echo '<div>نوع: <b>'.h($r['type']).'</b> | کشور: '.h((string)$r['country']).' | کاربر: '.$r['telegram_id'].'</div>';
        if ($r['text']) echo '<pre style="white-space:pre-wrap">'.h($r['text']).'</pre>';
        if ($r['photo_file_id']) echo '<div>Photo File ID: <code>'.h($r['photo_file_id']).'</code></div>';
        echo '<form method="post" style="display:flex;gap:8px"><input type="hidden" name="id" value="'.$r['id'].'" />';
        echo '<button name="op" value="approve">✅ تایید</button>';
        echo '<button name="op" value="reject">❌ رد</button>';
        echo '</form></div>';
    }
    echo '</div></body></html>';
    exit;
}

// Transfers approval
if ($action === 'transfers') {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $op = $_POST['op'] ?? '';
        if ($id && in_array($op, ['approve','reject'], true)) {
            $tr = $pdo->prepare('SELECT * FROM transfers WHERE id = ? AND status = "pending"');
            $tr->execute([$id]);
            $t = $tr->fetch();
            if ($t) {
                if ($op === 'approve') {
                    // Move item ownership (quantity=1 baseline)
                    $pdo->beginTransaction();
                    try {
                        // Get item id
                        $ui = $pdo->prepare('SELECT * FROM user_items WHERE id = ? FOR UPDATE');
                        $ui->execute([$t['user_item_id']]);
                        $row = $ui->fetch();
                        if ($row && (int)$row['user_id'] === (int)$t['from_user_id']) {
                            // Decrement from source
                            $pdo->prepare('DELETE FROM user_items WHERE id = ?')->execute([$t['user_item_id']]);
                            // Increment or insert to dest
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
    nav();
    echo '<div style="padding:16px"><h3>انتقالات در انتظار</h3>';
    if (!$rows) echo '<div>موردی نیست.</div>';
    foreach ($rows as $r) {
        echo '<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:8px 0">';
        echo 'آیتم: <b>'.h($r['item_name']).'</b> | از کاربر '.$r['from_tg'].' به '.$r['to_tg'];
        echo '<form method="post" style="display:inline-block;margin-right:12px"><input type="hidden" name="id" value="'.$r['id'].'" />';
        echo '<button name="op" value="approve">✅ تایید</button>';
        echo '<button name="op" value="reject">❌ رد</button>';
        echo '</form></div>';
    }
    echo '</div></body></html>';
    exit;
}

// Shop minimal management (categories/items listing only)
if ($action === 'shop') {
    $pdo = db();
    $cats = $pdo->query('SELECT * FROM shop_categories ORDER BY type, sort_order, name')->fetchAll();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>فروشگاه</title></head><body style="font-family:sans-serif">';
    nav();
    echo '<div style="padding:16px"><h3>دسته‌بندی‌ها</h3><table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>نام</th><th>نوع</th><th>فعال</th></tr>';
    foreach ($cats as $c) echo '<tr><td>'.$c['id'].'</td><td>'.h($c['name']).'</td><td>'.$c['type'].'</td><td>'.$c['is_active'].'</td></tr>';
    echo '</table>';
    $items = $pdo->query('SELECT i.*, c.name AS cat FROM items i JOIN shop_categories c ON c.id=i.category_id ORDER BY c.type, c.sort_order, i.sort_order, i.name')->fetchAll();
    echo '<h3 style="margin-top:16px">آیتم‌ها</h3><table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>نام</th><th>دسته</th><th>VIP</th><th>قیمت امتیاز</th><th>قیمت تومان</th><th>اعطا</th></tr>';
    foreach ($items as $it) echo '<tr><td>'.$it['id'].'</td><td>'.h($it['name']).'</td><td>'.h($it['cat']).'</td><td>'.$it['is_vip'].'</td><td>'.$it['price_points'].'</td><td>'.$it['price_toman'].'</td><td>'.$it['grant_type'].'</td></tr>';
    echo '</table></div></body></html>';
    exit;
}

if ($action === 'countries') {
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM countries ORDER BY name')->fetchAll();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>کشورها</title></head><body style="font-family:sans-serif">';
    nav();
    echo '<div style="padding:16px"><h3>کشورها</h3><table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>نام</th><th>نوع</th><th>فعال</th></tr>';
    foreach ($rows as $r) echo '<tr><td>'.$r['id'].'</td><td>'.h($r['name']).'</td><td>'.$r['type'].'</td><td>'.$r['is_active'].'</td></tr>';
    echo '</table></div></body></html>';
    exit;
}

if ($action === 'factories') {
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM factory_types ORDER BY name')->fetchAll();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>کارخانه‌ها</title></head><body style="font-family:sans-serif">';
    nav();
    echo '<div style="padding:16px"><h3>انواع کارخانه</h3><table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>نام</th><th>قیمت پایه</th><th>درآمد پایه</th><th>حداکثر سطح</th><th>فعال</th></tr>';
    foreach ($rows as $r) echo '<tr><td>'.$r['id'].'</td><td>'.h($r['name']).'</td><td>'.$r['base_price_points'].'</td><td>'.$r['base_income_points'].'</td><td>'.$r['max_level'].'</td><td>'.$r['is_active'].'</td></tr>';
    echo '</table></div></body></html>';
    exit;
}

if ($action === 'questions') {
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM questions ORDER BY id DESC LIMIT 100')->fetchAll();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>سوالات</title></head><body style="font-family:sans-serif">';
    nav();
    echo '<div style="padding:16px"><h3>سوالات</h3><table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>سوال</th><th>پاسخ</th><th>فعال</th></tr>';
    foreach ($rows as $r) echo '<tr><td>'.$r['id'].'</td><td>'.h($r['question_text']).'</td><td>'.h($r['correct_answer']).'</td><td>'.$r['is_active'].'</td></tr>';
    echo '</table></div></body></html>';
    exit;
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
    $rewardMin = (int)($pdo->prepare('SELECT `value` FROM settings WHERE `key`="reward_min"')->execute([]) || 0);
    $rewardMax = (int)($pdo->prepare('SELECT `value` FROM settings WHERE `key`="reward_max"')->execute([]) || 0);
    // Actually fetch values correctly
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key`="reward_min"'); $stmt->execute(); $rm = (int)($stmt->fetchColumn() ?: 100);
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key`="reward_max"'); $stmt->execute(); $rM = (int)($stmt->fetchColumn() ?: 1000);

    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>تنظیمات</title></head><body style="font-family:sans-serif">';
    nav();
    echo '<div style="padding:16px"><h3>تنظیمات</h3>';
    echo '<form method="post"><label>حداقل جایزه:</label><input name="reward_min" value="'.h((string)$rm).'" /> ';
    echo '<label>حداکثر جایزه:</label><input name="reward_max" value="'.h((string)$rM).'" /> ';
    echo '<button>ذخیره</button></form>';
    echo '</div></body></html>';
    exit;
}

if ($action === 'broadcast') {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Here only store the message; actual sending should be done by a separate job/bot
        $msg = trim($_POST['message'] ?? '');
        if ($msg !== '') {
            // Simple store in settings (or a new table in future)
            $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES ("last_broadcast", ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$msg]);
            $saved = true;
        }
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>پیام همگانی</title></head><body style="font-family:sans-serif">';
    nav();
    echo '<div style="padding:16px"><h3>پیام همگانی</h3>';
    if (!empty($saved)) echo '<div style="color:green">ذخیره شد.</div>';
    echo '<form method="post"><textarea name="message" style="width:100%;height:160px"></textarea><br/><button>ذخیره برای ارسال</button></form>';
    echo '</div></body></html>';
    exit;
}

// Fallback
header('HTTP/1.1 404 Not Found');
echo 'صفحه مورد نظر یافت نشد.';
