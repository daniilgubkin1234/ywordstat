<?php
declare(strict_types=1);
require __DIR__ . '/../src/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$err = $_SESSION['error'] ?? null;
unset($_SESSION['error']);

ob_start();
?>
<h1>Вход</h1>
<p class="muted">Введите логин и пароль администратора.</p>

<form action="/login.php" method="post" autocomplete="on">
  <input type="hidden" name="action" value="login">

  <label>Логин</label>
  <input type="text" name="username" placeholder="admin" required
         autocomplete="username" autofocus>

  <label>Пароль</label>
  <input type="password" name="password" placeholder="••••••••" required
         autocomplete="current-password">

  <div class="row">
    <button class="btn" type="submit">Войти</button>
    <a class="btn" href="/">На главную</a>
  </div>

  <?php if ($err): ?>
    <div class="err"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>
</form>
<?php
$body = ob_get_clean();
view('Вход', $body);
