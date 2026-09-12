<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (current_user_id() !== null) {
    header('Location: /timeline.php');
    exit;
}

$errors = [];
$email  = '';

// Very light brute-force throttle: track failed attempts in-session.
// Not a substitute for real rate limiting, but cheap and better than nothing
// for a v1 with no infra for it yet.
ourthology_start_session();
$_SESSION['login_fail_count'] = $_SESSION['login_fail_count'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($_SESSION['login_fail_count'] >= 8) {
        $errors[] = 'Too many failed attempts — please wait a few minutes and try again.';
    } else {
        $stmt = ourthology_pdo()->prepare('SELECT id, password_hash, status FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
            $_SESSION['login_fail_count'] = 0;
            login_user((int) $user['id']);
            ourthology_pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
                ->execute(['id' => $user['id']]);
            header('Location: /timeline.php');
            exit;
        }

        $_SESSION['login_fail_count']++;
        // Deliberately generic — never reveal whether the email exists.
        $errors[] = 'Incorrect email or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
</head>
<body>
  <div class="card">
    <div class="brand"><svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="#9A2A2A"/><path d="M16 22V14M16 14L11 9M16 14L21 9" stroke="#FBF8F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/><circle cx="16" cy="23" r="1.7" fill="#FBF8F1"/><circle cx="11" cy="8" r="1.7" fill="#FBF8F1"/><circle cx="21" cy="8" r="1.7" fill="#FBF8F1"/></svg><p class="wordmark">ourthology<span class="tld">.com</span></p></div>
    <p class="subtitle">an anthology of us.</p>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required autofocus>

      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>

      <button type="submit" class="btn-primary">Log in</button>
    </form>

    <p class="foot-link">New here? <a href="/signup.php">Create an account</a></p>
  </div>
</body>
</html>
