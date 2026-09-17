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
// Phase 45: server-enforced confirmation that this really is meant to
// start a brand-new, disconnected family tree -- see roadmap-ideas.md
// ("Signup without a claim link creates an invisible orphan island").
$confirmNewTree = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $first    = trim((string) ($_POST['first_name'] ?? ''));
    $middle   = trim((string) ($_POST['middle_name'] ?? ''));
    $surname  = trim((string) ($_POST['surname'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');
    $confirmNewTree = isset($_POST['confirm_new_tree']);

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
    if (!$confirmNewTree) {
        $errors[] = 'Please confirm you understand this starts a brand-new family tree before continuing.';
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
<title>Start a new family tree — ourthology.com</title>
<link rel="stylesheet" href="/styles.css?v=25">
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

    <div class="notice">
      This creates a brand-new, separate family archive on ourthology.com —
      not connected to anyone already here. If a family member already added
      you to their tree, use the personal invite link they sent you instead
      (ask them to resend it if you've lost it) — don't create an account here,
      or you'll end up in your own disconnected tree instead of theirs.
    </div>

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

      <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--ink);margin:16px 0 4px;">
        <input type="checkbox" id="confirm_new_tree" name="confirm_new_tree" value="1" style="margin-top:2px;" <?= $confirmNewTree ? 'checked' : '' ?> required>
        <span>I understand this starts a brand-new family tree, separate from any existing one on ourthology.com.</span>
      </label>

      <button type="submit" class="btn-primary">Start my new family tree</button>
    </form>

    <p class="foot-link">Already have an account? <a href="/login.php">Log in</a></p>
  </div>
</body>
</html>
