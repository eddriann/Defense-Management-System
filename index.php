<?php
require_once __DIR__ . '/includes/csrf.php';
start_app_session();
$message = $_GET['redirect'] ?? '';
if ($message === '0') {
  $message = "Invalid Login";
} else {
  $message = '';
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sign in | Defense Management System</title>
  <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>&amp;rev=style-20261115-2">
  <link rel="stylesheet" href="assets/css/login.css?v=11&amp;rev=style-20261115-2">
  <link rel="icon" type="image/png" href="assets/logo/favicon.png?v=20261115">
</head>

<body class="login-page">
  <main class="login-layout">
    <section class="login-welcome" aria-label="Defense Management System">
      <img class="welcome-logo" src="assets/logo/logo.jpg" alt="Computer Arts and Technological College, Inc.">
      <div class="welcome-copy">
        <h1>Defense Management System</h1>
        <p>Thesis and Capstone Portal</p>
      </div>
      <p class="login-footer">Computer Arts and Technological College, Inc.</p>
    </section>
    <form class="login-card card shadow-lg" action="auth/login.php" method="post">
      <h2>Welcome back</h2>
      <p class="login-intro">Sign in to access your defense workspace.</p>
      <?php if ($message !== ''): ?>
        <span class="login-error" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
      <?= csrf_field() ?>
      <label for="username">Username
        <input type="text" name="username" id="username" autocomplete="username" required />
      </label>
      <label for="password">Password
        <input type="password" name="password" id="password" autocomplete="current-password" required />
      </label>
      <input type="submit" value="Sign in" />
    </form>
  </main>
</body>

</html>