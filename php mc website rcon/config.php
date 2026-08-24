<?php
session_start();

$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    die("Configuration file not found.");
}
$config = json_decode(file_get_contents($config_file), true);
if (!is_array($config)) {
    die("Invalid configuration file.");
}

$allowed_domains = $config['allowed_domains'] ?? [];
$http_host = $_SERVER['HTTP_HOST'] ?? '';
$host_clean = preg_replace('/:\d+$/', '', $http_host);

if (!in_array($host_clean, $allowed_domains)) {
    header("HTTP/1.1 403 Forbidden");
    die("Access Denied: Unauthorized Domain.");
}

$db = new PDO('sqlite:' . __DIR__ . '/db.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    otp TEXT,
    otp_expiry INTEGER
)");

$db->exec("CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    price INTEGER,
    image TEXT,
    command TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    items TEXT,
    total_price INTEGER,
    receipt_img TEXT,
    status TEXT DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    subject TEXT,
    message TEXT,
    reply TEXT DEFAULT NULL,
    status TEXT DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS settings (
    key TEXT UNIQUE,
    value TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
    ip TEXT UNIQUE,
    attempts INTEGER DEFAULT 0,
    blocked_until INTEGER DEFAULT 0
)");

$db->exec("CREATE TABLE IF NOT EXISTS otp_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    ip TEXT,
    last_requested INTEGER
)");

function init_setting($key, $default) {
    global $db;
    $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute([$key, $default]);
}

init_setting('admin_user', 'admin');
init_setting('admin_pass', password_hash('admin', PASSWORD_BCRYPT));
init_setting('card_number', '6037-9999-9999-9999');
init_setting('rcon_host', '127.0.0.1');
init_setting('rcon_port', '25575');
init_setting('rcon_pass', 'secret');

function get_setting($key) {
    global $db;
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    return $stmt->fetchColumn();
}

function update_setting($key, $value) {
    global $db;
    $stmt = $db->prepare("UPDATE settings SET value = ? WHERE key = ?");
    $stmt->execute([$value, $key]);
}

function check_ip_block() {
    global $db;
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $db->prepare("SELECT * FROM login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['blocked_until'] > time()) {
        die("Your IP is temporarily blocked due to multiple failed login attempts.");
    }
}

function register_failed_attempt() {
    global $db;
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $db->prepare("INSERT INTO login_attempts (ip, attempts, blocked_until) VALUES (?, 1, 0) ON CONFLICT(ip) DO UPDATE SET attempts = attempts + 1");
    $stmt->execute([$ip]);
    
    $stmt = $db->prepare("SELECT attempts FROM login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
    $attempts = $stmt->fetchColumn();
    
    if ($attempts >= 3) {
        $blocked_until = time() + 600;
        $stmt = $db->prepare("UPDATE login_attempts SET blocked_until = ?, attempts = 0 WHERE ip = ?");
        $stmt->execute([$blocked_until, $ip]);
    }
}

function clear_failed_attempts() {
    global $db;
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
}