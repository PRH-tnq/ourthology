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

// Phase 95: a correct login now comes back here once (?check=1) before
// going on, to confirm the browser actually kept the session cookie. Still
// logged out at this point means it didn't -- say so plainly, rather than
// the silent "straight back to an empty login page" Pamela's iPad was
// giving -- and log which cookies arrived (names only) to pin down why.
$cookieDropped = ($_GET['check'] ?? '') === '1';
if ($cookieDropped) {
    error_log(sprintf(
        'ourthology login did not stick: cookies sent [%s]; user agent: %s',
        ourthology_cookie_names_summary(),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 300)
    ));
}

// Phase 59: delete_account.php lands here (already logged out) once an
// account deletion completes — a plain query flag rather than a session
// flash, since logout_user() clears the whole session right before this
// redirect.
$accountDeleted = ($_GET['deleted'] ?? '') === '1';

$errors = [];
$email  = '';

// Very light brute-force throttle: track failed attempts in-session.
// Not a substitute for real rate limiting, but cheap and better than nothing
// for a v1 with no infra for it yet.
ourthology_start_session();
$_SESSION['login_fail_count'] = $_SESSION['login_fail_count'] ?? 0;
// Phase 96: the lock now actually lifts after a while. It used to last as
// long as the browser session did, which on an iPad (where Safari tabs stay
// open for weeks) could mean locked out for weeks despite the "wait a few
// minutes" message. Counted from the most recent failed attempt.
const LOGIN_MAX_FAILS = 8;
const LOGIN_LOCK_SECONDS = 15 * 60;
$lastFail = (int) ($_SESSION['login_fail_last'] ?? 0);
if ($lastFail > 0 && time() - $lastFail >= LOGIN_LOCK_SECONDS) {
    $_SESSION['login_fail_count'] = 0;
    unset($_SESSION['login_fail_last']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($_SESSION['login_fail_count'] >= LOGIN_MAX_FAILS) {
        $minutes = max(1, (int) ceil(($lastFail + LOGIN_LOCK_SECONDS - time()) / 60));
        $errors[] = 'Too many failed attempts — please wait ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' and try again.';
    } else {
        $stmt = ourthology_pdo()->prepare('SELECT id, password_hash, status FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
            $_SESSION['login_fail_count'] = 0;
            unset($_SESSION['login_fail_last']);
            login_user((int) $user['id']);
            ourthology_pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
                ->execute(['id' => $user['id']]);
            // Phase 95: via ?check=1 (see the top of this file), which
            // forwards straight on to $next once it sees the session.
            header('Location: /login.php?check=1' . ($next !== null ? '&next=' . rawurlencode($next) : ''));
            exit;
        }

        $_SESSION['login_fail_count']++;
        $_SESSION['login_fail_last'] = time();
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
<link rel="stylesheet" href="/styles.css?v=28">
<style>
  .cookie-help { text-align:left; line-height:1.45; }
  .cookie-help p { margin:6px 0 4px; }
  .cookie-help ul { margin:4px 0 0; padding-left:18px; }
  .cookie-help li { margin:4px 0; }
</style>
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

    <?php if ($accountDeleted): ?>
      <p class="foot-link" style="margin-top:0;">Your account has been deleted. Take care.</p>
    <?php endif; ?>

    <?php if ($cookieDropped && !$errors): ?>
      <div class="error cookie-help" role="alert">
        <b>Your password was right, but this browser didn't keep you signed in.</b>
        <p>That's usually old saved data for this website on this device. To clear it:</p>
        <ul>
          <li><b>iPad or iPhone:</b> open <i>Settings</i> &rarr; <i>Apps</i> &rarr; <i>Safari</i> &rarr; <i>Advanced</i> &rarr; <i>Website Data</i> (on older devices: <i>Settings</i> &rarr; <i>Safari</i> &rarr; <i>Advanced</i> &rarr; <i>Website Data</i>), search for &ldquo;ourthology&rdquo;, swipe it away, then come back and log in again. While you're in <i>Settings</i> &rarr; <i>Safari</i>, check <i>Block All Cookies</i> is switched off.</li>
          <li><b>Computer:</b> clear this site's cookies in your browser's settings, or try a private window.</li>
          <li>If you opened this page from a link in an email or a social app, open <b>ourthology.com</b> in Safari or Chrome itself instead.</li>
        </ul>
      </div>
    <?php endif; ?>

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

    <!-- Phase 45: this used to read "New here? Create an account" -- the
         exact words someone who was actually invited would click, sending
         them into their own disconnected family tree instead of the one
         they were meant to join. See roadmap-ideas.md.
         Phase 46: Phil asked for the "start a new family tree" option to
         be much more prominent than Phase 45 left it -- now a real
         secondary button instead of a small footnote link, while the
         "invited by a family member?" line above it still steers a real
         invitee toward their personal link first. -->
    <p class="foot-link">Invited by a family member? You need their personal link — ask them to resend it from their tree page.</p>
    <a href="/signup.php" class="btn-secondary">Start a new family tree</a>
  </div>
</body>
</html>
