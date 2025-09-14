<?php
declare(strict_types=1);
require __DIR__ . '/../src/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $_SESSION['error'] = 'Неверный метод запроса.';
    header('Location: /index.php');
    exit;
}

$csrf = (string)($_POST['_csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
    $_SESSION['error'] = 'Проверка безопасности не пройдена. Обновите страницу и попробуйте ещё раз.';
    header('Location: /index.php');
    exit;
}

$login = trim((string)($_POST['login'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');

if ($login === '' || $pass === '') {
    $_SESSION['error'] = 'Заполните логин и пароль.';
    header('Location: /index.php');
    exit;
}

$cfgUser = $_ENV['ADMIN_LOGIN'] ?? 'admin';
$cfgPass = $_ENV['ADMIN_PASSWORD'] ?? null;         
$cfgHash = $_ENV['ADMIN_PASS_HASH'] ?? null;   

$loginOk = hash_equals($cfgUser, $login) && (
    ($cfgHash && password_verify($pass, $cfgHash)) ||
    ($cfgPass !== null && hash_equals($cfgPass, $pass))
);

if (!$loginOk) {
    $_SESSION['error'] = 'Неверный логин или пароль.';
    header('Location: /index.php');
    exit;
}

session_regenerate_id(true);
$_SESSION['uid'] = $login; 

$_SESSION['csrf'] = bin2hex(random_bytes(16));

header('Location: /admin.php');
exit;
