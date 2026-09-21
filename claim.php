<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/peripheral.php';

ourthology_start_session();
$pdo = ourthology_pdo();

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$claim = null;

if ($token !== '') {
    $stmt = $pdo->prepare(
        "SELECT ct.person_id, ct.expires_at, ct.used_at, p.first_name, p.surname, p.claimed_by_user_id, p.died, p.deceased_year_unknown,
                cu.first_name AS added_by_first, cu.surname AS added_by_surname
         FROM claim_tokens ct
         JOIN persons p ON p.id = ct.person_id
         JOIN users u ON u.id = ct.created_by_user_id
         JOIN persons cu ON cu.id = u.person_id
         WHERE ct.token = :token"
    );
    $stmt->execute(['token' => $token]);
    $claim = $stmt->fetch() ?: null;
}

if ($claim === null) {
    $errors[] = 'This invite link is invalid.';
} elseif ($claim['used_at'] !== null || $claim['claimed_by_user_id'] !== null) {
    $errors[] = 'This record has already been claimed.';
} elseif (!empty($claim['died']) || !empty($claim['deceased_year_unknown'])) {
    // Phase 30: a death date can be added to a person's record any time
    // after their invite link was generated — even a still-valid,
    // unexpired link must stop working the moment that happens, since a
    // profile recorded as deceased is never claimable, by anyone. Phase
    // 84: recorded as deceased with the year still unknown counts exactly
    // the same as a full date of death here.
    $errors[] = "This record can't be claimed — it's recorded as belonging to someone who has passed away.";
} elseif (strtotime($claim['expires_at']) < time()) {
    $errors[] = 'This invite link has expired — ask whoever added you for a fresh one.';
}

$alreadyLoggedIn = current_user_id() !== null;
$email = '';

if (!$errors && !$alreadyLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Re-check under the transaction that nobody claimed it — or
            // recorded a death for this person — in the meantime. Locking
            // the persons row too (not just claim_tokens) closes the gap
            // where someone adds a date of death in the moments between
            // this page loading and this form being submitted.
            $lock = $pdo->prepare(
                'SELECT ct.used_at, p.died, p.deceased_year_unknown
                 FROM claim_tokens ct JOIN persons p ON p.id = ct.person_id
                 WHERE ct.token = :token FOR UPDATE'
            );
            $lock->execute(['token' => $token]);
            $row = $lock->fetch();
            if (!$row || $row['used_at'] !== null) {
                throw new RuntimeException('already_used');
            }
            if (!empty($row['died']) || !empty($row['deceased_year_unknown'])) {
                throw new RuntimeException('deceased');
            }

            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, person_id) VALUES (:email, :hash, :pid)');
            $stmt->execute([
                'email' => $email,
                'hash'  => password_hash($password, PASSWORD_DEFAULT),
                'pid'   => $claim['person_id'],
            ]);
            $newUserId = (int) $pdo->lastInsertId();

            $pdo->prepare('UPDATE persons SET claimed_by_user_id = :uid WHERE id = :pid')
                ->execute(['uid' => $newUserId, 'pid' => $claim['person_id']]);

            // Phase 58: this person might be one half of a peripheral-tree
            // pair (includes/peripheral.php) -- if so, claiming this side
            // claims the OTHER side too, in the same transaction, so a
            // single invite link (always for the in-law's original/master
            // node -- see peripheral_tree.php) gives access to both
            // without a second claim step. A no-op for the overwhelming
            // majority of claims, which have no peripheral_tree_links row
            // at all.
            $linkStmt = $pdo->prepare(
                'SELECT master_person_id, peripheral_person_id FROM peripheral_tree_links
                 WHERE master_person_id = :pid1 OR peripheral_person_id = :pid2'
            );
            $linkStmt->execute(['pid1' => $claim['person_id'], 'pid2' => $claim['person_id']]);
            $link = $linkStmt->fetch();
            if ($link) {
                $counterpartId = (int) $link['master_person_id'] === (int) $claim['person_id']
                    ? (int) $link['peripheral_person_id']
                    : (int) $link['master_person_id'];
                $pdo->prepare('UPDATE persons SET claimed_by_user_id = :uid WHERE id = :pid AND claimed_by_user_id IS NULL')
                    ->execute(['uid' => $newUserId, 'pid' => $counterpartId]);
            }

            $pdo->prepare('UPDATE claim_tokens SET used_at = NOW() WHERE token = :token')
                ->execute(['token' => $token]);

            $pdo->commit();

            login_user($newUserId);
            header('Location: /timeline.php');
            exit;
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage() === 'deceased'
                ? "This record can't be claimed — it's recorded as belonging to someone who has passed away."
                : 'This record has already been claimed.';
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000') {
                $errors[] = 'That email is already registered to another account.';
            } else {
                error_log('ourthology claim error: ' . $e->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
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
<title>Claim your record — ourthology.com</title>
<link rel="stylesheet" href="/styles.css?v=26">
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
      <!-- Phase 45: "Sign up" is no longer an equal-weight option next to
           a failed claim -- that was the single most likely way someone
           who was genuinely invited (an expired/mistyped link) ended up
           silently founding their own disconnected family tree instead. -->
      <p class="foot-link">Already have an account? <a href="/login.php">Log in</a>.</p>
      <p class="foot-link" style="font-size:12px;">Not being invited to an existing family? <a href="/signup.php">Start a new family tree</a> instead.</p>

    <?php elseif ($alreadyLoggedIn): ?>
      <p>You're already logged in, so you can't claim another record from this session.</p>
      <p class="foot-link"><a href="/logout.php">Log out</a> first if this record is actually you.</p>

    <?php else: ?>
      <p style="margin-top:16px;">
        Claiming the record for <strong><?= htmlspecialchars(trim($claim['first_name'] . ' ' . $claim['surname']), ENT_QUOTES) ?></strong>,
        added by <?= htmlspecialchars(trim($claim['added_by_first'] . ' ' . $claim['added_by_surname']), ENT_QUOTES) ?>.
      </p>
      <p style="font-size:13px;color:var(--ink-faint);">If this is you, set a password below to activate your account and take over this record.</p>

      <form method="post" novalidate>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
        <?= csrf_field() ?>

        <label for="email">Your email</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" minlength="10" required>

        <label for="confirm_password">Confirm password</label>
        <input type="password" id="confirm_password" name="confirm_password" minlength="10" required>

        <button type="submit" class="btn-primary">Claim this record</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
