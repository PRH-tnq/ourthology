<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/account_deletion.php';

// Phase 59: "I may want to delete my profile and leave the site... under
// account settings give me an option to remove my profile, deleting all
// my data and my peripheral tree if there are no unclaimed profiles on
// there. Ensure there are multiple stages of confirmation of that action
// before it is actioned." This page IS those multiple stages: a
// read-only summary of exactly what's about to happen (step 1), then a
// second screen (step 2) that requires typing your own email, typing the
// word DELETE, re-entering your password, and ticking an "I understand"
// box -- ALL re-checked server-side on submit, on top of the ordinary
// CSRF check every POST on this app already gets. Nothing is deleted
// until every one of those checks passes on the same request.

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Location: /login.php');
    exit;
}
$pdo = ourthology_pdo();
$userId = (int) $me['user_id'];

$step = (($_GET['step'] ?? $_POST['step'] ?? '') === '2') ? 2 : 1;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $confirmEmail = trim((string) ($_POST['confirm_email'] ?? ''));
    $confirmPhrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $confirmUnderstand = ($_POST['confirm_understand'] ?? '') === '1';

    if (strcasecmp($confirmEmail, (string) $me['email']) !== 0) {
        $errors[] = 'The email you typed doesn\'t match your account email.';
    }
    if ($confirmPhrase !== 'DELETE') {
        $errors[] = 'Type DELETE (all capitals) to confirm.';
    }
    if (!$confirmUnderstand) {
        $errors[] = 'Check the box confirming you understand this can\'t be undone.';
    }
    $pwStmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :uid');
    $pwStmt->execute(['uid' => $userId]);
    $storedHash = (string) ($pwStmt->fetchColumn() ?: '');
    if ($confirmPassword === '' || $storedHash === '' || !password_verify($confirmPassword, $storedHash)) {
        $errors[] = 'Incorrect password.';
    }

    if (!$errors) {
        try {
            // Phase 91: files are only removed once the transaction has
            // actually committed, so a failure part-way through really
            // does leave "nothing changed" -- rows AND files.
            $filesToDelete = [];
            $pdo->beginTransaction();
            ourthology_delete_own_account($pdo, $userId, $filesToDelete);
            $pdo->commit();
            foreach ($filesToDelete as $path) {
                delete_media_file($path);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('ourthology delete_account error: ' . $e->getMessage());
            $errors[] = 'Something went wrong deleting your account. Nothing was changed — please try again.';
        }

        if (!$errors) {
            logout_user();
            header('Location: /login.php?deleted=1');
            exit;
        }
    }

    $step = 2; // re-show the confirmation form (with the field values cleared) on any failure
}

$preview = ourthology_account_deletion_preview($pdo, $userId);
$homeCounts = $preview['home_counts'] ?? ['memories' => 0, 'relationships' => 0, 'partnerships' => 0, 'postcards' => 0, 'letters' => 0, 'cards' => 0];
$hasPeripheral = $preview['peripheral'] !== null;
$peripheralQualifies = $preview['peripheral_qualifies_for_group_wipe'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Delete my account — ourthology.com</title>
<link rel="stylesheet" href="/styles.css?v=27">
<style>
  .danger-box { background:var(--error-bg); border:1px solid var(--error); border-radius:10px; padding:14px 16px; margin:16px 0; }
  .danger-box h3 { margin:0 0 6px; color:var(--error); }
  .summary-list { margin:10px 0; padding-left:0; list-style:none; font-size:14px; }
  .summary-list li { padding:4px 0; border-bottom:1px solid var(--paper-2); }
  .summary-list li:last-child { border-bottom:none; }
  .btn-danger-lg { display:inline-block; background:var(--error); color:#fff; border:none; border-radius:8px; padding:10px 18px; font-size:14px; font-weight:700; cursor:pointer; text-decoration:none; }
  .btn-danger-lg:hover { opacity:0.9; }
  .btn-danger-lg:disabled { opacity:0.5; cursor:not-allowed; }
</style>
</head>
<body>
  <div class="card" style="max-width:520px;">
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

    <?php if ($step === 1): ?>
      <h2 style="margin-top:0;">Delete my account</h2>
      <p style="font-size:14px;color:var(--ink-soft);">This permanently removes your profile from ourthology.com. Before you continue, here's exactly what that means:</p>

      <div class="danger-box">
        <h3>Your profile</h3>
        <ul class="summary-list">
          <li>Your name, dates, and profile photo — <strong>deleted</strong></li>
          <li><?= $homeCounts['memories'] ?> memor<?= $homeCounts['memories'] === 1 ? 'y' : 'ies' ?> on your timeline — <strong>deleted</strong></li>
          <li><?= $homeCounts['relationships'] ?> relationship<?= $homeCounts['relationships'] === 1 ? '' : 's' ?> and <?= $homeCounts['partnerships'] ?> partnership<?= $homeCounts['partnerships'] === 1 ? '' : 's' ?> — <strong>removed</strong> (everyone else's own records stay exactly as they are)</li>
          <li><?= $homeCounts['postcards'] ?> postcard<?= $homeCounts['postcards'] === 1 ? '' : 's' ?>, <?= $homeCounts['letters'] ?> letter<?= $homeCounts['letters'] === 1 ? '' : 's' ?> and <?= $homeCounts['cards'] ?> greeting card<?= $homeCounts['cards'] === 1 ? '' : 's' ?> you sent or received — <strong>deleted</strong></li>
        </ul>
      </div>

      <?php if ($hasPeripheral): ?>
        <div class="danger-box">
          <h3>Your peripheral tree</h3>
          <?php if ($peripheralQualifies): ?>
            <p style="font-size:13px;margin:0;">Nobody else has claimed a profile on the separate family tree you started (<?= $preview['peripheral_group_person_count'] ?> <?= $preview['peripheral_group_person_count'] === 1 ? 'person' : 'people' ?> on it) — so that <strong>entire tree is deleted too</strong>, along with every memory, relationship, postcard, and letter on it.</p>
          <?php else: ?>
            <p style="font-size:13px;margin:0;">Someone else has claimed a profile on that tree, so it's kept — only your own "you" node on it is removed. Everyone else's records there are untouched.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <p style="font-size:13px;color:var(--ink-faint);">This can't be undone. Nothing is deleted on this screen — the next step asks you to confirm it directly.</p>

      <p style="margin-top:20px;">
        <a href="/delete_account.php?step=2" class="btn-danger-lg">Continue to delete my account</a>
      </p>
      <p class="foot-link"><a href="/edit_person.php">Cancel — take me back</a></p>

    <?php else: /* step 2 */ ?>
      <h2 style="margin-top:0;">Confirm account deletion</h2>
      <p style="font-size:14px;color:var(--ink-soft);">This is the last step. Once you submit this form, your profile and its data are deleted immediately and can't be recovered.</p>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="2">

        <label for="confirm_email">Type your account email (<?= htmlspecialchars((string) $me['email'], ENT_QUOTES) ?>) to confirm</label>
        <input type="email" id="confirm_email" name="confirm_email" required autocomplete="off">

        <label for="confirm_phrase" style="margin-top:12px;">Type DELETE (all capitals) to confirm</label>
        <input type="text" id="confirm_phrase" name="confirm_phrase" required autocomplete="off">

        <label for="confirm_password" style="margin-top:12px;">Re-enter your password</label>
        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="current-password">

        <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--ink);margin:14px 0 4px;">
          <input type="checkbox" name="confirm_understand" value="1" style="margin-top:2px;" required>
          <span>I understand this permanently deletes my profile and data, and can't be undone.</span>
        </label>

        <button type="submit" class="btn-danger-lg" style="margin-top:14px;width:100%;">Permanently delete my account</button>
      </form>
      <p class="foot-link"><a href="/delete_account.php">Back</a> · <a href="/edit_person.php">Cancel — take me back</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
