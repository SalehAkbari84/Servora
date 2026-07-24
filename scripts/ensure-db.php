<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>
//
// One-off helper: read DB_* from backend/.env and run
// `CREATE DATABASE IF NOT EXISTS` so the user doesn't have to log into
// MySQL just to make an empty schema. Kept in a separate .php file so
// setup.bat can call it cleanly without fighting cmd's quote/escape
// rules around PHP's `->` operator.
//
// Usage:  php scripts/ensure-db.php
// Exit:   0 on success (db exists or was created), 1 on connection failure.

$envPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . '.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "ensure-db: backend/.env not found at {$envPath}\n");
    exit(1);
}

// Minimal .env parser — Laravel's hasn't booted yet (composer install
// might not have run). Handles KEY=value, KEY="value", and # comments.
$env = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (!str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $v = trim($v);
    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
        $v = substr($v, 1, -1);
    }
    $env[trim($k)] = $v;
}

$host = $env['DB_HOST']     ?? '127.0.0.1';
$port = $env['DB_PORT']     ?? '3306';
$db   = $env['DB_DATABASE'] ?? 'servora';
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';

try {
    // Connect WITHOUT specifying a database — we may need to create it.
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $safe = str_replace('`', '', $db);  // never trust user input for identifiers
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safe}` "
             . "CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci");
    echo "ensure-db: OK  ({$safe})\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ensure-db: ERROR  " . $e->getMessage() . "\n");
    fwrite(STDERR, "  Check DB_HOST, DB_USERNAME, DB_PASSWORD in backend/.env\n");
    exit(1);
}
