<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim((string) ($_POST['host'] ?? ''));
    $port = (int) ($_POST['port'] ?? 3306);
    $database = trim((string) ($_POST['database'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $editorPassword = (string) ($_POST['editor_password'] ?? '');
    $editorPasswordConfirm = (string) ($_POST['editor_password_confirm'] ?? '');

    if ($host === '' || $port < 1 || $database === '' || $username === '' ||
        $editorPassword === '' || $editorPassword !== $editorPasswordConfirm) {
        $message = 'Please fill in all connection fields.';
        if ($editorPassword !== '' && $editorPassword !== $editorPasswordConfirm) {
            $message = 'The editor passwords do not match.';
        }
    } else {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS fielddesk_records (
                    store_name VARCHAR(32) NOT NULL,
                    record_id VARCHAR(64) NOT NULL,
                    record_data LONGTEXT NOT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (store_name, record_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            $outside = dirname(__DIR__) . '/fielddesk-config.php';
            $inside = __DIR__ . '/fielddesk-config.php';
            $target = @is_writable(dirname($outside)) ? $outside : $inside;
            $contents = "<?php\nreturn " . var_export([
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'editor_password_hash' => password_hash($editorPassword, PASSWORD_DEFAULT),
            ], true) . ";\n";
            if (@file_put_contents($target, $contents, LOCK_EX) === false) {
                throw new RuntimeException('The server cannot write the configuration file.');
            }
            @chmod($target, 0600);
            $success = 'Database connected and Fielddesk tables initialized.';
        } catch (Throwable $error) {
            $message = 'Connection failed: ' . $error->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fielddesk · Database Setup</title>
  <style>
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f5f6f4; font-family: system-ui, sans-serif; color: #17212b; }
    form { width: min(520px, calc(100vw - 32px)); background: #fff; border: 1px solid #e3e6e5; border-radius: 12px; padding: 24px; box-sizing: border-box; }
    h1 { margin: 0 0 6px; font-size: 24px; } p { color: #78838d; font-size: 13px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 18px 0; }
    label { display: grid; gap: 6px; font-size: 12px; font-weight: 700; }
    input { width: 100%; box-sizing: border-box; height: 40px; border: 1px solid #dfe3e2; border-radius: 7px; padding: 0 10px; }
    .wide { grid-column: 1 / -1; }
    button { height: 40px; border: 0; border-radius: 7px; background: #e97c4b; color: #fff; font-weight: 750; cursor: pointer; }
    .message { padding: 10px 12px; border-radius: 7px; background: #fae9e7; color: #963c36; }
    .success { background: #e8f4ee; color: #2e8062; }
  </style>
</head>
<body>
  <form method="post">
    <h1>Fielddesk database setup</h1>
    <p>Enter the MySQL details supplied by your host. The credentials are written to a server-only configuration file, not to the HTML or JavaScript.</p>
    <?php if (!empty($message)): ?><p class="message"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if (!empty($success)): ?><p class="message success"><?= htmlspecialchars($success) ?></p><?php endif; ?>
    <div class="grid">
      <label class="wide">MySQL hostname<input name="host" value="<?= htmlspecialchars($_POST['host'] ?? '') ?>" placeholder="sql305.example.com" required></label>
      <label>Port<input name="port" type="number" value="<?= htmlspecialchars($_POST['port'] ?? '3306') ?>" required></label>
      <label>Database name<input name="database" value="<?= htmlspecialchars($_POST['database'] ?? '') ?>" required></label>
      <label class="wide">MySQL username<input name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required></label>
      <label class="wide">MySQL password<input name="password" type="password" autocomplete="new-password" required></label>
      <label class="wide">Editor login password<input name="editor_password" type="password" autocomplete="new-password" minlength="10" required></label>
      <label class="wide">Confirm editor password<input name="editor_password_confirm" type="password" autocomplete="new-password" minlength="10" required></label>
    </div>
    <button type="submit">Test connection and save</button>
  </form>
</body>
</html>
