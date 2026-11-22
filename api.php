<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// NCP Finderからのアクセスを許可
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === 'https://flarum.ballota.jp') {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

function reply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin !== 'https://flarum.ballota.jp') {
        reply(403, ['error' => 'Forbidden']);
    }
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    reply(405, ['error' => 'Method not allowed']);
}

if ($origin !== '' &&
    $origin !== 'https://flarum.ballota.jp') {
    reply(403, ['error' => 'Forbidden']);
}

$action = $_GET['action'] ?? '';
if (!in_array($action, ['send', 'receive'], true)) {
    reply(404, ['error' => 'Unknown action']);
}

$raw = file_get_contents('php://input', false, null, 0, 4097);
if ($raw === false || strlen($raw) > 4096) {
    reply(400, ['error' => 'Invalid request size']);
}

$input = json_decode($raw, true);
if (!is_array($input)) {
    reply(400, ['error' => 'Invalid JSON']);
}

try {
    $dbPath = dirname(__DIR__) . '/gps_data/gps.sqlite';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000');

    $db->exec('CREATE TABLE IF NOT EXISTS locations (
        code TEXT PRIMARY KEY,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        altitude REAL,
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS attempts (
        ip TEXT NOT NULL,
        action TEXT NOT NULL,
        window_start INTEGER NOT NULL,
        count INTEGER NOT NULL,
        PRIMARY KEY (ip, action)
    )');

    $now = time();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // 同時アクセスに対応
    $db->beginTransaction();

    // 期限切れデータを整理
    $stmt = $db->prepare(
        'DELETE FROM locations WHERE expires_at <= ?'
    );
    $stmt->execute([$now]);

    $db->prepare(
        'DELETE FROM attempts WHERE window_start <= ?'
    )->execute([$now - 600]);

    // 10分間あたりのIP別リクエスト制限
    $limit = $action === 'send' ? 20 : 5;
    $stmt = $db->prepare(
        'SELECT count FROM attempts
         WHERE ip = ? AND action = ?'
    );
    $stmt->execute([$ip, $action]);
    $count = $stmt->fetchColumn();

    if ($count !== false && (int)$count >= $limit) {
        $db->commit();
        reply(429, ['error' => 'Too many requests']);
    }

    $db->prepare(
        'INSERT INTO attempts (ip, action, window_start, count)
         VALUES (?, ?, ?, 1)
         ON CONFLICT(ip, action)
         DO UPDATE SET count = count + 1'
    )->execute([$ip, $action, $now]);

    if ($action === 'send') {
        $lat = filter_var(
            $input['latitude'] ?? null,
            FILTER_VALIDATE_FLOAT
        );
        $lon = filter_var(
            $input['longitude'] ?? null,
            FILTER_VALIDATE_FLOAT
        );
        $alt = $input['altitude'] ?? null;

        if ($lat === false || $lon === false ||
            $lat < -90 || $lat > 90 ||
            $lon < -180 || $lon > 180) {
            $db->commit();
            reply(400, ['error' => 'Invalid coordinates']);
        }

        if ($alt !== null) {
            $alt = filter_var($alt, FILTER_VALIDATE_FLOAT);
            if ($alt === false || abs($alt) > 100000) {
                $db->commit();
                reply(400, ['error' => 'Invalid altitude']);
            }
        }

        $stmt = $db->prepare(
            'INSERT OR IGNORE INTO locations
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $code = null;
        for ($i = 0; $i < 20; $i++) {
            $candidate = sprintf('%06d', random_int(0, 999999));
            $stmt->execute([
                $candidate, $lat, $lon, $alt,
                $now, $now + 600
            ]);
            if ($stmt->rowCount() === 1) {
                $code = $candidate;
                break;
            }
        }

        if ($code === null) {
            throw new RuntimeException('No available code');
        }

        $db->commit();
        reply(200, [
            'status' => 'ok',
            'code' => $code,
            'expires_in' => 600
        ]);
    }

    // GPSデータ取得
    $code = $input['code'] ?? '';
    if (!is_string($code) ||
        !preg_match('/^\d{6}$/D', $code)) {
        $db->commit();
        reply(400, ['error' => 'Invalid code']);
    }

    $stmt = $db->prepare(
        'SELECT latitude, longitude, altitude
         FROM locations
         WHERE code = ? AND expires_at > ?'
    );
    $stmt->execute([$code, $now]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($location === false) {
        $db->commit();
        reply(404, ['error' => 'Not found or expired']);
    }

    // 取得成功後に削除
    $db->prepare(
        'DELETE FROM locations WHERE code = ?'
    )->execute([$code]);

    $db->commit();

    reply(200, [
        'status' => 'ok',
        'latitude' => (float)$location['latitude'],
        'longitude' => (float)$location['longitude'],
        'altitude' => $location['altitude'] === null
            ? null : (float)$location['altitude']
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('GPS API error: ' . $e->getMessage());
    reply(500, ['error' => 'Internal server error']);
}
