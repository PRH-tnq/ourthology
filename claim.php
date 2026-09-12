<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';

ourthology_start_session();
$pdo = ourthology_pdo();

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$claim = null;

if ($token !== '') {
    $stmt = $pdo->prepare(
        "SELECT ct.person_id, ct.expires_at, ct.used_at, p.first_name, p.surname, p.claimed_by_user_id,
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

            // Re-check under the transaction that nobody claimed it in the meantime.
            $lock = $pdo->prepare('SELECT used_at FROM claim_tokens WHERE token = :token FOR UPDATE');
            $lock->execute(['token' => $token]);
            $row = $lock->fetch();
            if (!$row || $row['used_at'] !== null) {
                throw new RuntimeException('already_used');
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

            $pdo->prepare('UPDATE claim_tokens SET used_at = NOW() WHERE token = :token')
                ->execute(['token' => $token]);

            $pdo->commit();

            login_user($newUserId);
            header('Location: /timeline.php');
            exit;
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors[] = 'This record has already been claimed.';
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
<link rel="stylesheet" href="/styles.css">
</head>
<body>
  <div class="card">
    <div class="brand"><svg class="brand-mark" width="26" height="26" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="#9A2A2A"/><path d="M16 22V14M16 14L11 9M16 14L21 9" stroke="#FBF8F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/><circle cx="16" cy="23" r="1.7" fill="#FBF8F1"/><circle cx="11" cy="8" r="1.7" fill="#FBF8F1"/><circle cx="21" cy="8" r="1.7" fill="#FBF8F1"/></svg><p class="wordmark">ourthology<span class="tld">.com</span></p></div>
    <p class="subtitle">an anthology of us.</p>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
      <p class="foot-link"><a href="/login.php">Log in</a> · <a href="/signup.php">Sign up</a></p>

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
