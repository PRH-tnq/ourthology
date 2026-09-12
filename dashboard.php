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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
</head>
<body>
  <div class="card">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <p style="margin-top:24px;">Welcome, <strong><?= htmlspecialchars($displayName, ENT_QUOTES) ?></strong>.</p>
    <p style="color:var(--ink-faint);font-size:14px;">
      Signed in as <?= htmlspecialchars($me['email'], ENT_QUOTES) ?> · person #<?= (int) $me['person_id'] ?>
    </p>
    <p style="margin-top:20px;"><a href="/tree.php" style="color:var(--accent);font-weight:600;">Go to my tree →</a></p>
    <p style="color:var(--ink-faint);font-size:14px;">
      Timeline/diary uploads move here in a later build phase — the tree (adding relatives, invite links, linking existing accounts) is live now.
    </p>

    <form method="post" action="/logout.php" style="margin-top:20px;">
      <button type="submit" class="btn-primary" style="background:transparent;color:var(--accent);border:1px solid var(--accent);">Log out</button>
    </form>
  </div>
</body>
</html>
