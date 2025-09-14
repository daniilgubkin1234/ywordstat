<?php
declare(strict_types=1);
require __DIR__ . '/../src/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$err = $_SESSION['error'] ?? null;
unset($_SESSION['error']);

ob_start();
?>
<h1>Вход</h1>
<p class="muted">Введите логин и пароль администратора.</p>

<form action="/login.php" method="post" autocomplete="on">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="action" value="login">

  <div class="row">
    <label>Логин</label>
    <input name="login" required autocomplete="username">
  </div>

  <div class="row">
    <label>Пароль</label>
    <input type="password" name="password" required autocomplete="current-password">
  </div>

  <div class="row">
    <button class="btn" type="submit">Войти</button>
    <a class="btn" href="/">На главную</a>
  </div>

  <?php if ($err): ?>
    <div class="err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
</form>
<?php
$body = ob_get_clean();
view('Вход', $body);
