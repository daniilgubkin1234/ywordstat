<?php
declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

function asset_url(string $rel): string {
    $full = dirname(__DIR__) . '/public/' . ltrim($rel, '/');
    $v = @filemtime($full) ?: time();
    return '/' . ltrim($rel, '/') . '?v=' . $v;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $port = $_ENV['DB_PORT'] ?? '5432';
    $db   = $_ENV['DB_NAME'] ?? 'wordstat';
    $user = $_ENV['DB_USER'] ?? 'postgres';
    $pass = $_ENV['DB_PASSWORD'] ?? '';
    $dsn  = "pgsql:host={$host};port={$port};dbname={$db}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET client_encoding TO 'UTF8'");
    return $pdo;
}

function require_auth(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $login = $_ENV['ADMIN_LOGIN'] ?? 'admin';
    $pass  = $_ENV['ADMIN_PASSWORD'] ?? 'admin123';

    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        if (($_POST['username'] ?? '') === $login && ($_POST['password'] ?? '') === $pass) {
            $_SESSION['auth'] = true;
            header('Location: /admin.php');
            exit;
        }
        $_SESSION['error'] = 'Неверный логин или пароль';
        header('Location: /index.php');
        exit;
    }
    if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
        if (!($_SESSION['auth'] ?? false)) {
            header('Location: /index.php');
            exit;
        }
    }
}

function view(string $title, string $body): void {
    $css = asset_url('assets/login.css');
    echo "<!DOCTYPE html>
<html lang=\"ru\">
<head>
  <meta charset=\"utf-8\">
  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
  <title>{$title}</title>
  <link rel=\"stylesheet\" href=\"{$css}\">
</head>
<body>
  <div class='card'>{$body}</div>
</body>
</html>";
}
