<?php
// test_search.php – directly test search_by_prefix
header('Content-Type: application/json');

define('DB_HOST', 'dpg-d6cs0si4d50c73abld8g-a');
define('DB_PORT', 5432);
define('DB_NAME', 'fordkey');
define('DB_USER', 'fordkey_user');
define('DB_PASS', 'hMxLH6jBmLvM6uworyRynomtRie1GUbT');
define('API_SECRET', 'RebKyoGangGangLolEGG!!');

try {
    $dsn = "pgsql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'DB connection failed']));
}

$prefix = $_GET['prefix'] ?? 'HA';
$secret = $_GET['secret'] ?? '';
if ($secret !== API_SECRET) {
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

$stmt = $pdo->prepare("SELECT key_code FROM hanto_keys WHERE key_code ILIKE ? ORDER BY created_at DESC LIMIT 25");
$stmt->execute([$prefix . '%']);
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(['success' => true, 'keys' => $rows]);
