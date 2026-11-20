<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$configPaths = [
    dirname(__DIR__) . '/fielddesk-config.php',
    __DIR__ . '/fielddesk-config.php',
];
$config = null;
foreach ($configPaths as $path) {
    if (is_readable($path)) {
        $config = require $path;
        break;
    }
}

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_array($config)) {
    respond(503, ['error' => 'Database is not configured.', 'setup' => true]);
}

$required = ['host', 'port', 'database', 'username', 'password'];
foreach ($required as $key) {
    if (!isset($config[$key])) {
        respond(503, ['error' => 'Database configuration is incomplete.', 'setup' => true]);
    }
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        (int) $config['port'],
        $config['database']
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $error) {
    error_log('Fielddesk database connection failed: ' . $error->getMessage());
    respond(503, ['error' => 'Database connection failed. Check the server configuration.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST' && $method !== 'DELETE') {
    respond(405, ['error' => 'Method not allowed.']);
}

$validStores = ['events', 'cameras', 'crew', 'channels', 'atem', 'cables', 'songs'];

try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS fielddesk_records (
            store_name VARCHAR(32) NOT NULL,
            record_id VARCHAR(64) NOT NULL,
            record_data LONGTEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (store_name, record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    if ($method === 'GET') {
        $stores = array_fill_keys($validStores, []);
        $statement = $pdo->query('SELECT store_name, record_id, record_data FROM fielddesk_records');
        foreach ($statement as $row) {
            if (!in_array($row['store_name'], $validStores, true)) {
                continue;
            }
            $record = json_decode($row['record_data'], true);
            if (!is_array($record)) {
                continue;
            }
            $record['id'] = $row['record_id'];
            if ($row['store_name'] === 'events' && empty($_SESSION['fielddesk_editor'])) {
                unset($record['multiviewUrl']);
            }
            $stores[$row['store_name']][] = $record;
        }
        respond(200, [
            'format' => 'fielddesk-backup',
            'version' => 1,
            'stores' => $stores,
            'authenticated' => !empty($_SESSION['fielddesk_editor']),
        ]);
    }

    if ($method === 'DELETE') {
        if (empty($_SESSION['fielddesk_editor'])) {
            respond(401, ['error' => 'Editor login required.', 'loginRequired' => true]);
        }
        $storeName = $_GET['store'] ?? '';
        $recordId = $_GET['id'] ?? '';
    } else {
        $body = file_get_contents('php://input');
        $payload = json_decode($body === false ? '' : $body, true);
        if (!is_array($payload)) {
            respond(400, ['error' => 'Invalid JSON request.']);
        }
        $action = $payload['action'] ?? '';
        if ($action === 'session') {
            respond(200, ['authenticated' => !empty($_SESSION['fielddesk_editor'])]);
        }
        if ($action === 'login') {
            $password = $payload['password'] ?? '';
            $hash = $config['editor_password_hash'] ?? '';
            if (!is_string($password) || !is_string($hash) || $hash === '' || !password_verify($password, $hash)) {
                respond(401, ['error' => 'Incorrect editor password.']);
            }
            session_regenerate_id(true);
            $_SESSION['fielddesk_editor'] = true;
            respond(200, ['authenticated' => true]);
        }
        if ($action === 'logout') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $cookie = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
            }
            session_destroy();
            respond(200, ['authenticated' => false]);
        }

        if (empty($_SESSION['fielddesk_editor'])) {
            respond(401, ['error' => 'Editor login required.', 'loginRequired' => true]);
        }
        if ($action === 'delete') {
            $storeName = $payload['store'] ?? '';
            $recordId = $payload['id'] ?? '';
        } elseif ($action === 'put') {
            $storeName = $payload['store'] ?? '';
            $record = $payload['record'] ?? null;
            if (!is_string($storeName) || !in_array($storeName, $validStores, true) ||
                !is_array($record) || !isset($record['id']) || !is_string($record['id']) || $record['id'] === '') {
                respond(400, ['error' => 'Invalid record.']);
            }
            $recordId = $record['id'];
            $recordJson = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($recordJson === false) {
                respond(400, ['error' => 'Record could not be encoded.']);
            }
            $statement = $pdo->prepare(
                'INSERT INTO fielddesk_records (store_name, record_id, record_data)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE record_data = VALUES(record_data)'
            );
            $statement->execute([$storeName, $recordId, $recordJson]);
            respond(200, ['ok' => true]);
        } elseif ($action === 'merge') {
            $incoming = $payload['stores'] ?? null;
            if (!is_array($incoming)) {
                respond(400, ['error' => 'Invalid backup.']);
            }
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'INSERT INTO fielddesk_records (store_name, record_id, record_data)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE record_data = VALUES(record_data)'
            );
            foreach ($validStores as $storeName) {
                if (!isset($incoming[$storeName]) || !is_array($incoming[$storeName])) {
                    continue;
                }
                foreach ($incoming[$storeName] as $record) {
                    if (!is_array($record) || !isset($record['id']) || !is_string($record['id']) || $record['id'] === '') {
                        continue;
                    }
                    $recordJson = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($recordJson !== false) {
                        $statement->execute([$storeName, $record['id'], $recordJson]);
                    }
                }
            }
            $pdo->commit();
            respond(200, ['ok' => true]);
        } else {
            respond(400, ['error' => 'Unknown action.']);
        }
    }

    if (!is_string($storeName) || !in_array($storeName, $validStores, true) ||
        !is_string($recordId) || $recordId === '') {
        respond(400, ['error' => 'Invalid delete request.']);
    }
    $statement = $pdo->prepare('DELETE FROM fielddesk_records WHERE store_name = ? AND record_id = ?');
    $statement->execute([$storeName, $recordId]);
    respond(200, ['ok' => true]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Fielddesk API error: ' . $error->getMessage());
    respond(500, ['error' => 'The database operation failed.']);
}
