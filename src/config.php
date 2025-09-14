<?php
declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_set_cookie_params([
        'httponly' => true,
        'secure'   => !empty($_SERVER['HTTPS']),
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
    session_start();
}

function csrf_token(): string {
    start_secure_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function asset_url(string $rel): string {
    $full = dirname(__DIR__) . '/public/' . ltrim($rel, '/');
    $v = @filemtime($full) ?: time();
    return '/' . ltrim($rel, '/') . '?v=' . $v;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn  = $_ENV['DB_DSN'] ?? null;
    if (!$dsn) {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = (int)($_ENV['DB_PORT'] ?? 5432);
        $name = $_ENV['DB_NAME'] ?? 'wordstat';
        $dsn  = "pgsql:host={$host};port={$port};dbname={$name}";
    }
    $user = $_ENV['DB_USER'] ?? 'postgres';
    $pass = $_ENV['DB_PASS'] ?? ($_ENV['DB_PASSWORD'] ?? '');

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5, 
        ]);
        @$pdo->exec("SET client_encoding TO 'UTF8'");
        return $pdo;
    } catch (Throwable $e) {
        throw new RuntimeException('DB connection failed');
    }
}

function require_auth(): void {
    start_secure_session();
    $isAuthed = !empty($_SESSION['uid']) || !empty($_SESSION['auth']);
    if (!$isAuthed) {
        header('Location: /index.php');
        exit;
    }
}

function view(string $title, string $body): void {
    $css = asset_url('assets/login.css');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo "<!DOCTYPE html>
<html lang=\"ru\">
<head>
  <meta charset=\"utf-8\">
  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
  <title>{$safeTitle}</title>
  <link rel=\"stylesheet\" href=\"{$css}\">
</head>
<body>
  <div class='card'>{$body}</div>
</body>
</html>";
}
