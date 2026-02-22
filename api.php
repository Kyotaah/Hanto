<?php
header('Content-Type: application/json');

// ---------- 🔐 RENDER POSTGRESQL CREDENTIALS ----------
define('DB_HOST', 'dpg-d6cs0si4d50c73abld8g-a');
define('DB_PORT', 5432);
define('DB_NAME', 'fordkey');
define('DB_USER', 'fordkey_user');
define('DB_PASS', 'hMxLH6jBmLvM6uworyRynomtRie1GUbT');
define('API_SECRET', 'RebKyoGangGangLolEGG!!');
// -------------------------------------------------------

try {
    $dsn = "pgsql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'DB connection failed']));
}

// Check if 'generated_by' column exists (PostgreSQL information_schema)
$stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name='hanto_keys' AND column_name='generated_by'");
$stmt->execute();
$has_generated_by = $stmt->fetchColumn() ? true : false;

$input_raw = file_get_contents('php://input');
if (!$input_raw) die(json_encode(['success' => false, 'error' => 'Empty request body']));
$input = json_decode($input_raw, true);
if (!$input) die(json_encode(['success' => false, 'error' => 'Invalid JSON']));

if (($input['secret'] ?? '') !== API_SECRET) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

$action = $input['action'] ?? '';

// ----- Helper functions -----
function generateKey($length = 24) {
    $prefix = "HANTO_";
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    $randomLength = $length - strlen($prefix);
    $random = '';
    for ($i = 0; $i < $randomLength; $i++) {
        $random .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $prefix . $random;
}

function computeExpiration($years, $months, $weeks, $days, $hours, $minutes) {
    $timestamp = time();
    $timestamp += $years * 31536000;
    $timestamp += $months * 2592000;
    $timestamp += $weeks * 604800;
    $timestamp += $days * 86400;
    $timestamp += $hours * 3600;
    $timestamp += $minutes * 60;
    return date('Y-m-d H:i:s', $timestamp);
}

switch ($action) {
    case 'generate':
        $years = (int)($input['years'] ?? 0);
        $months = (int)($input['months'] ?? 0);
        $weeks = (int)($input['weeks'] ?? 0);
        $days = (int)($input['days'] ?? 0);
        $hours = (int)($input['hours'] ?? 0);
        $minutes = (int)($input['minutes'] ?? 0);
        $max_uses = $input['max_uses'] ?? null;
        $is_troll = (bool)($input['troll'] ?? false);
        $generated_by = $input['generated_by'] ?? null;

        if ($years + $months + $weeks + $days + $hours + $minutes == 0) {
            $hours = 24;
        }

        $expires_at = ($years + $months + $weeks + $days + $hours + $minutes > 0)
            ? computeExpiration($years, $months, $weeks, $days, $hours, $minutes)
            : null;

        $key = generateKey();

        try {
            if ($has_generated_by) {
                $stmt = $pdo->prepare("INSERT INTO hanto_keys (key_code, created_at, expires_at, max_uses, is_troll, generated_by) VALUES (?, NOW(), ?, ?, ?, ?)");
                $stmt->execute([$key, $expires_at, $max_uses, $is_troll ? 1 : 0, $generated_by]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO hanto_keys (key_code, created_at, expires_at, max_uses, is_troll) VALUES (?, NOW(), ?, ?, ?)");
                $stmt->execute([$key, $expires_at, $max_uses, $is_troll ? 1 : 0]);
            }
            echo json_encode(['success' => true, 'key' => $key]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    case 'bulk_generate':
        $count = (int)($input['count'] ?? 1);
        if ($count < 1 || $count > 50) {
            echo json_encode(['success' => false, 'error' => 'Count must be 1-50']);
            break;
        }
        $years = (int)($input['years'] ?? 0);
        $months = (int)($input['months'] ?? 0);
        $weeks = (int)($input['weeks'] ?? 0);
        $days = (int)($input['days'] ?? 0);
        $hours = (int)($input['hours'] ?? 0);
        $minutes = (int)($input['minutes'] ?? 0);
        $max_uses = $input['max_uses'] ?? null;
        $is_troll = (bool)($input['troll'] ?? false);
        $generated_by = $input['generated_by'] ?? null;

        $expires_at = null;
        if ($years + $months + $weeks + $days + $hours + $minutes > 0) {
            $expires_at = computeExpiration($years, $months, $weeks, $days, $hours, $minutes);
        }

        $keys = [];
        $pdo->beginTransaction();
        try {
            for ($i = 0; $i < $count; $i++) {
                $key = generateKey();
                if ($has_generated_by) {
                    $stmt = $pdo->prepare("INSERT INTO hanto_keys (key_code, created_at, expires_at, max_uses, is_troll, generated_by) VALUES (?, NOW(), ?, ?, ?, ?)");
                    $stmt->execute([$key, $expires_at, $max_uses, $is_troll ? 1 : 0, $generated_by]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO hanto_keys (key_code, created_at, expires_at, max_uses, is_troll) VALUES (?, NOW(), ?, ?, ?)");
                    $stmt->execute([$key, $expires_at, $max_uses, $is_troll ? 1 : 0]);
                }
                $keys[] = $key;
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'keys' => $keys, 'count' => count($keys)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Bulk insert failed: ' . $e->getMessage()]);
        }
        break;

    case 'check':
        $key = $input['key'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM hanto_keys WHERE key_code = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'key_data' => $row]);
        break;

    case 'delete':
        $key = $input['key'] ?? '';
        try {
            $stmt = $pdo->prepare("DELETE FROM hanto_keys WHERE key_code = ?");
            $stmt->execute([$key]);
            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Delete failed: ' . $e->getMessage()]);
        }
        break;

    case 'delete_all':
        $confirm = $input['confirm'] ?? false;
        if ($confirm !== true) {
            echo json_encode(['success' => false, 'error' => 'Confirmation required']);
            break;
        }
        try {
            // Delete all rows and get count
            $stmt = $pdo->query("DELETE FROM hanto_keys");
            $count = $stmt->rowCount();
            // Reset the sequence (auto-increment)
            $pdo->query("ALTER SEQUENCE hanto_keys_id_seq RESTART WITH 1");
            echo json_encode(['success' => true, 'deleted' => $count]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Delete all failed: ' . $e->getMessage()]);
        }
        break;

    case 'lock':
        $key = $input['key'] ?? '';
        try {
            $stmt = $pdo->prepare("UPDATE hanto_keys SET locked = 1 WHERE key_code = ?");
            $stmt->execute([$key]);
            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Lock failed: ' . $e->getMessage()]);
        }
        break;

    case 'unlock':
        $key = $input['key'] ?? '';
        try {
            $stmt = $pdo->prepare("UPDATE hanto_keys SET locked = 0 WHERE key_code = ?");
            $stmt->execute([$key]);
            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Unlock failed: ' . $e->getMessage()]);
        }
        break;

    case 'unbind':
        $key = $input['key'] ?? '';
        try {
            $stmt = $pdo->prepare("UPDATE hanto_keys SET hwid = NULL WHERE key_code = ?");
            $stmt->execute([$key]);
            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Unbind failed: ' . $e->getMessage()]);
        }
        break;

    case 'extend':
        $key = $input['key'] ?? '';
        $hours = (int)($input['hours'] ?? 0);
        if ($hours <= 0) {
            echo json_encode(['success' => false, 'error' => 'Hours must be positive']);
            break;
        }
        try {
            $stmt = $pdo->prepare("SELECT expires_at FROM hanto_keys WHERE key_code = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Key not found']);
                break;
            }
            $current = $row['expires_at'];
            $new_expiry = $current
                ? date('Y-m-d H:i:s', strtotime($current) + ($hours * 3600))
                : date('Y-m-d H:i:s', time() + ($hours * 3600));
            $stmt = $pdo->prepare("UPDATE hanto_keys SET expires_at = ? WHERE key_code = ?");
            $stmt->execute([$new_expiry, $key]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Extend failed: ' . $e->getMessage()]);
        }
        break;

    case 'decrease':
        $key = $input['key'] ?? '';
        $hours = (int)($input['hours'] ?? 0);
        if ($hours <= 0) {
            echo json_encode(['success' => false, 'error' => 'Hours must be positive']);
            break;
        }
        try {
            $stmt = $pdo->prepare("SELECT expires_at FROM hanto_keys WHERE key_code = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Key not found']);
                break;
            }
            $current = $row['expires_at'];
            if (!$current) {
                echo json_encode(['success' => false, 'error' => 'Key never expires – cannot decrease']);
                break;
            }
            $new_expiry = date('Y-m-d H:i:s', strtotime($current) - ($hours * 3600));
            $stmt = $pdo->prepare("UPDATE hanto_keys SET expires_at = ? WHERE key_code = ?");
            $stmt->execute([$new_expiry, $key]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Decrease failed: ' . $e->getMessage()]);
        }
        break;

    case 'rename':
        $old_key = $input['old_key'] ?? '';
        $new_key = $input['new_key'] ?? '';
        try {
            $stmt = $pdo->prepare("SELECT id FROM hanto_keys WHERE key_code = ?");
            $stmt->execute([$new_key]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'New key already exists']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE hanto_keys SET key_code = ? WHERE key_code = ?");
            $stmt->execute([$new_key, $old_key]);
            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Rename failed: ' . $e->getMessage()]);
        }
        break;

    case 'search_by_prefix':
    $prefix = $input['prefix'] ?? '';
    if (strlen($prefix) < 2) {
        echo json_encode(['success' => false, 'error' => 'Prefix too short (min 2 chars)']);
        break;
    }
    // Use ILIKE for case‑insensitive search
    $stmt = $pdo->prepare("SELECT key_code FROM hanto_keys WHERE key_code ILIKE ? ORDER BY created_at DESC LIMIT 25");
    $stmt->execute([$prefix . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['success' => true, 'keys' => $rows]);
    break;

    case 'list':
        $stmt = $pdo->query("SELECT key_code, hwid, expires_at, uses, max_uses, locked, is_troll, created_at FROM hanto_keys ORDER BY created_at DESC LIMIT 20");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'keys' => $rows]);
        break;

    case 'list_all':
        $stmt = $pdo->query("SELECT key_code, hwid, created_at, expires_at, uses, max_uses, locked, is_troll FROM hanto_keys ORDER BY created_at DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'keys' => $rows]);
        break;

    case 'stats':
        $stats = [];
        $stmt = $pdo->query("SELECT COUNT(*) FROM hanto_keys");
        $stats['total'] = $stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM hanto_keys WHERE hwid IS NOT NULL");
        $stats['bound'] = $stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM hanto_keys WHERE (expires_at IS NULL OR expires_at > NOW()) AND locked = 0 AND is_troll = 0");
        $stats['active'] = $stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM hanto_keys WHERE expires_at IS NOT NULL AND expires_at <= NOW()");
        $stats['expired'] = $stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM hanto_keys WHERE locked = 1");
        $stats['locked'] = $stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM hanto_keys WHERE is_troll = 1");
        $stats['troll'] = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;

    case 'purge':
        try {
            $stmt = $pdo->prepare("DELETE FROM hanto_keys WHERE expires_at IS NOT NULL AND expires_at <= NOW()");
            $stmt->execute();
            echo json_encode(['success' => true, 'purged' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Purge failed: ' . $e->getMessage()]);
        }
        break;

    case 'export':
        $stmt = $pdo->query("SELECT key_code, hwid, created_at, expires_at, uses, max_uses, locked, is_troll FROM hanto_keys");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'export' => $rows]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
