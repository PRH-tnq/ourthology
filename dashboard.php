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
<link rel="stylesheet" href="/styles.css?v=20">
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
