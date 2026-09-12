<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

require_login();
$me = current_user_with_person();
if ($me === null) {
    // Session pointed at a user row that's gone missing — treat as logged out.
    logout_user();
    header('Location: /login.php');
    exit;
}

$displayName = trim($me['first_name'] . ' ' . ($me['surname'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
</head>
<body>
  <div class="card">
    <div class="brand"><svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="#9A2A2A"/><path d="M16 22V14M16 14L11 9M16 14L21 9" stroke="#FBF8F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/><circle cx="16" cy="23" r="1.7" fill="#FBF8F1"/><circle cx="11" cy="8" r="1.7" fill="#FBF8F1"/><circle cx="21" cy="8" r="1.7" fill="#FBF8F1"/></svg><p class="wordmark">ourthology<span class="tld">.com</span></p></div>
    <p class="subtitle">an anthology of us.</p>

    <p style="margin-top:24px;">Welcome, <strong><?= htmlspecialchars($displayName, ENT_QUOTES) ?></strong>.</p>
    <p style="color:var(--ink-faint);font-size:14px;">
      Signed in as <?= htmlspecialchars($me['email'], ENT_QUOTES) ?> · person #<?= (int) $me['person_id'] ?>
    </p>
    <p style="margin-top:20px;">
      <a href="/tree.php" style="color:var(--accent);font-weight:600;">My tree →</a>
      &nbsp;·&nbsp;
      <a href="/timeline.php" style="color:var(--accent);font-weight:600;">My timeline →</a>
    </p>

    <form method="post" action="/logout.php" style="margin-top:20px;">
      <button type="submit" class="btn-primary" style="background:transparent;color:var(--accent);border:1px solid var(--accent);">Log out</button>
    </form>
  </div>
</body>
</html>
