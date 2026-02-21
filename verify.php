<?php
header('Content-Type: application/json');

// ---------- 🔐 RENDER POSTGRESQL CREDENTIALS ----------
define('DB_HOST', 'dpg-d6cs0si4d50c73abld8g-a');
define('DB_PORT', 5432);
define('DB_NAME', 'fordkey');
define('DB_USER', 'fordkey_user');
define('DB_PASS', 'hMxLH6jBmLvM6uworyRynomtRie1GUbT');
define('ALLOWED_SCRIPT', 'Hanto');
define('MAX_ATTEMPTS_PER_IP', 10);
define('ATTEMPT_WINDOW', 900); // seconds
define('CREATOR_ID', 1740176503); // Your Roblox User ID
// -------------------------------------------------------

try {
    $dsn = "pgsql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'DB connection failed']));
}

// Define dummy blacklist functions if they don't exist in your DB
if (!function_exists('isBlacklisted')) {
    function isBlacklisted($pdo, $hwid) { return false; }
}
if (!function_exists('isHwidBanned')) {
    function isHwidBanned($pdo, $hwid) { return false; }
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    die(json_encode(['success' => false, 'message' => 'Invalid JSON']));
}

$key = trim($input['key'] ?? '');
$hwid = trim($input['hwid'] ?? '');
$script = trim($input['script'] ?? '');
$user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;

if (empty($key) || empty($hwid) || empty($script)) {
    die(json_encode(['success' => false, 'message' => 'Missing parameters']));
}
if ($script !== ALLOWED_SCRIPT) {
    die(json_encode(['success' => false, 'message' => 'Invalid script']));
}

// ---- CREATOR IMMUNITY ----
$is_creator = ($user_id === CREATOR_ID);
if ($is_creator) {
    // Skip bans, blacklists, rate limits, and HWID binding
} else {
    // ---- NORMAL USER VALIDATION ----
    // Rate limiting
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', $ip);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hanto_attempts WHERE ip = ? AND timestamp > NOW() - INTERVAL '? SECOND'");
    // Note: PostgreSQL uses INTERVAL syntax, but we must bind the interval value properly.
    // Using a placeholder for the whole interval is tricky; we can use a fixed interval.
    // Simpler: calculate cutoff in PHP and use >=.
    $cutoff = date('Y-m-d H:i:s', time() - ATTEMPT_WINDOW);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hanto_attempts WHERE ip = ? AND timestamp > ?");
    $stmt->execute([$ip, $cutoff]);
    if ($stmt->fetchColumn() >= MAX_ATTEMPTS_PER_IP) {
        die(json_encode(['success' => false, 'message' => 'Too many attempts. Try later.']));
    }

    $stmt = $pdo->prepare("INSERT INTO hanto_attempts (ip, timestamp) VALUES (?, NOW())");
    $stmt->execute([$ip]);

    // ---- Bans & Blacklists (using dummy functions) ----
    if (isBlacklisted($pdo, $hwid)) {
        die(json_encode(['success' => false, 'message' => '❌ Your HWID is blacklisted']));
    }
    if (isHwidBanned($pdo, $hwid)) {
        die(json_encode(['success' => false, 'message' => '❌ This HWID is banned']));
    }
}

// ---- Key lookup ----
$stmt = $pdo->prepare("SELECT * FROM hanto_keys WHERE key_code = ?");
$stmt->execute([$key]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die(json_encode(['success' => false, 'message' => 'Invalid key']));
}

// ---- Troll key rejection ----
if ($row['is_troll'] == 1) {
    die(json_encode(['success' => false, 'message' => '❌ This key is fake (troll)']));
}

// ---- Locked / Expired / Max uses ----
if ($row['locked']) {
    die(json_encode(['success' => false, 'message' => 'Key is locked']));
}
if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
    die(json_encode(['success' => false, 'message' => 'Key expired']));
}
if ($row['max_uses'] !== null && $row['uses'] >= $row['max_uses']) {
    die(json_encode(['success' => false, 'message' => 'Key max uses reached']));
}

// ---- HWID binding ----
if ($is_creator) {
    // Creator: just update last_used, do NOT bind HWID
    $stmt = $pdo->prepare("UPDATE hanto_keys SET last_used = NOW(), uses = uses + 1 WHERE id = ?");
    $stmt->execute([$row['id']]);
    $message = 'Access granted (Creator)';
} else {
    // Normal user: enforce HWID binding
    if ($row['hwid'] === null) {
        $stmt = $pdo->prepare("UPDATE hanto_keys SET hwid = ?, last_used = NOW(), uses = uses + 1 WHERE id = ?");
        $stmt->execute([$hwid, $row['id']]);
        $message = 'Key activated and bound to this device';
    } else {
        if ($row['hwid'] !== $hwid) {
            die(json_encode(['success' => false, 'message' => 'Key already used on another device']));
        }
        $stmt = $pdo->prepare("UPDATE hanto_keys SET last_used = NOW(), uses = uses + 1 WHERE id = ?");
        $stmt->execute([$row['id']]);
        $message = 'Access granted';
    }
}

echo json_encode([
    'success' => true,
    'message' => $message,
    'is_troll' => $row['is_troll']
]);
?>