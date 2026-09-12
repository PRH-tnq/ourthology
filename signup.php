<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Already logged in? No need to sign up again.
if (current_user_id() !== null) {
    header('Location: /timeline.php');
    exit;
}

$errors = [];
$first  = '';
$middle = '';
$surname = '';
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $first    = trim((string) ($_POST['first_name'] ?? ''));
    $middle   = trim((string) ($_POST['middle_name'] ?? ''));
    $surname  = trim((string) ($_POST['surname'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    if ($first === '') {
        $errors[] = 'First name is required.';
    }
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
        $pdo = ourthology_pdo();
        try {
            $pdo->beginTransaction();

            // 1) Create the person record (family_group_id is filled in
            // once we know this person's own id — a fresh signup starts
            // its own, brand-new family group).
            $stmt = $pdo->prepare(
                'INSERT INTO persons (first_name, middle_name, surname, family_group_id)
                 VALUES (:first, :middle, :surname, 0)'
            );
            $stmt->execute([
                'first'   => $first,
                'middle'  => $middle !== '' ? $middle : null,
                'surname' => $surname !== '' ? $surname : null,
            ]);
            $personId = (int) $pdo->lastInsertId();

            $pdo->prepare('UPDATE persons SET family_group_id = :gid WHERE id = :id')
                ->execute(['gid' => $personId, 'id' => $personId]);

            // 2) Create the login, linked to that person.
            $stmt = $pdo->prepare(
                'INSERT INTO users (email, password_hash, person_id) VALUES (:email, :hash, :person_id)'
            );
            $stmt->execute([
                'email'     => $email,
                'hash'      => password_hash($password, PASSWORD_DEFAULT),
                'person_id' => $personId,
            ]);
            $userId = (int) $pdo->lastInsertId();

            // 3) Tie the person record back to the user who claimed it.
            // (Native prepared statements — EMULATE_PREPARES is off — don't
            // allow reusing one named placeholder twice, hence uid1/uid2.)
            $pdo->prepare('UPDATE persons SET claimed_by_user_id = :uid1, created_by_user_id = :uid2 WHERE id = :id')
                ->execute(['uid1' => $userId, 'uid2' => $userId, 'id' => $personId]);

            $pdo->commit();

            login_user($userId);
            header('Location: /timeline.php');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000') {
                $errors[] = 'That email is already registered — try logging in instead.';
            } else {
                error_log('ourthology signup error: ' . $e->getMessage());
                $errors[] = 'Something went wrong creating your account. Please try again.';
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
<title>Sign up — ourthology.com</title>
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
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="row-2">
        <div>
          <label for="first_name">First name</label>
          <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($first, ENT_QUOTES) ?>" maxlength="60" required>
        </div>
        <div>
          <label for="surname">Surname</label>
          <input type="text" id="surname" name="surname" value="<?= htmlspecialchars($surname, ENT_QUOTES) ?>" maxlength="60">
        </div>
      </div>
      <label for="middle_name">Middle name <span style="text-transform:none;font-weight:400;">(optional)</span></label>
      <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($middle, ENT_QUOTES) ?>" maxlength="60">

      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required>

      <label for="password">Password</label>
      <input type="password" id="password" name="password" minlength="10" required>

      <label for="confirm_password">Confirm password</label>
      <input type="password" id="confirm_password" name="confirm_password" minlength="10" required>

      <button type="submit" class="btn-primary">Create account</button>
    </form>

    <p class="foot-link">Already have an account? <a href="/login.php">Log in</a></p>
  </div>
</body>
</html>
