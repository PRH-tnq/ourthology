<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Phase 40: if we got here via a "please log in first" bounce from a
// page that needed auth (e.g. a pending-approval notification email
// clicked while logged out), ?next carries where to send the user once
// they're in -- validated so it can only ever be a same-site relative
// path, never used to redirect anywhere off ourthology.com.
$next = ourthology_safe_redirect_target($_GET['next'] ?? $_POST['next'] ?? null);

if (current_user_id() !== null) {
    header('Location: ' . ($next ?? '/timeline.php'));
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
            header('Location: ' . ($next ?? '/timeline.php'));
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
<link rel="stylesheet" href="/styles.css?v=21">
</head>
<body>
  <div class="card">
    <div class="brand" style="display:flex;align-items:center;gap:14px;margin:0 0 22px;">
      <svg class="brand-mark" width="44" height="44" viewBox="0 0 32 32" aria-hidden="true" style="flex:none;display:block;">
        <circle cx="16" cy="16" r="15" fill="#9A2A2A"/>
        <path d="M16 7 C10 8 6.3 12.6 7.4 17.2 C11.2 16.5 14.7 12.6 16 7 Z" fill="#FBF8F1"/>
        <path d="M16 7 C22 8 25.7 12.6 24.6 17.2 C20.8 16.5 17.3 12.6 16 7 Z" fill="#FBF8F1"/>
        <line x1="16" y1="7.2" x2="16" y2="17" stroke="#9A2A2A" stroke-width="1" stroke-linecap="round"/>
        <line x1="16" y1="17" x2="16" y2="23.2" stroke="#FBF8F1" stroke-width="2.2" stroke-linecap="round"/>
        <line x1="16" y1="23.2" x2="12.6" y2="26.6" stroke="#FBF8F1" stroke-width="1.6" stroke-linecap="round"/>
        <line x1="16" y1="23.2" x2="19.4" y2="26.6" stroke="#FBF8F1" stroke-width="1.6" stroke-linecap="round"/>
      </svg>
      <div class="brand-text" style="display:flex;flex-direction:column;">
        <p class="wordmark" style="margin:0;">ourthology<span class="tld">.com</span></p>
        <p class="subtitle" style="margin:3px 0 0;">an anthology of us.</p>
      </div>
    </div>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <?php if ($next !== null): ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
      <?php endif; ?>
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
