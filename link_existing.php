<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Location: /login.php');
    exit;
}
$pdo = ourthology_pdo();
$myPersonId = (int) $me['person_id'];
$myGroup = (int) person_row($pdo, $myPersonId)['family_group_id'];

$errors = [];
$success = null;
$email = '';
$direction = 'parent'; // that person is MY: parent / child / partner
$relationKind = 'genetic';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email        = trim((string) ($_POST['email'] ?? ''));
    $direction    = (string) ($_POST['direction'] ?? '');
    $relationKind = (string) ($_POST['relation_kind'] ?? 'genetic');

    if (!in_array($direction, ['parent', 'child', 'partner'], true)) {
        $errors[] = 'Choose a relationship.';
    }
    if (!in_array($relationKind, ['genetic', 'step', 'adoptive'], true)) {
        $relationKind = 'genetic';
    }

    $target = null;
    if (!$errors) {
        $stmt = $pdo->prepare('SELECT u.id AS user_id, p.id AS person_id, p.family_group_id, p.first_name, p.surname
                                FROM users u JOIN persons p ON p.id = u.person_id WHERE u.email = :email');
        $stmt->execute(['email' => $email]);
        $target = $stmt->fetch() ?: null;

        if ($target === null) {
            $errors[] = 'No account found with that email — if they have not signed up yet, add them from your tree page instead so you can send them an invite link.';
        } elseif ((int) $target['user_id'] === (int) $me['user_id']) {
            $errors[] = "That's your own account.";
        } elseif ((int) $target['family_group_id'] === $myGroup) {
            $errors[] = "You're already connected to that person.";
        }
    }

    if (!$errors) {
        try {
            $targetPersonId = (int) $target['person_id'];

            if ($direction === 'partner') {
                $a = min($myPersonId, $targetPersonId);
                $b = max($myPersonId, $targetPersonId);
                $pdo->prepare(
                    "INSERT INTO partnerships (person_a_id, person_b_id, kind, status, created_by_user_id, approving_user_id)
                     VALUES (:a, :b, 'married', 'pending_approval', :uid, :approver)"
                )->execute(['a' => $a, 'b' => $b, 'uid' => $me['user_id'], 'approver' => $target['user_id']]);
            } else {
                // "parent": that person is MY parent -> parent_id = target, child_id = me
                // "child":  that person is MY child  -> parent_id = me, child_id = target
                $parentId = $direction === 'parent' ? $targetPersonId : $myPersonId;
                $childId  = $direction === 'parent' ? $myPersonId : $targetPersonId;
                $pdo->prepare(
                    "INSERT INTO relationships (parent_id, child_id, relation_kind, status, created_by_user_id, approving_user_id)
                     VALUES (:p, :c, :kind, 'pending_approval', :uid, :approver)"
                )->execute(['p' => $parentId, 'c' => $childId, 'kind' => $relationKind, 'uid' => $me['user_id'], 'approver' => $target['user_id']]);
            }

            $success = trim($target['first_name'] . ' ' . $target['surname']);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'A link between you and that person already exists (confirmed or pending).';
            } else {
                error_log('ourthology link_existing error: ' . $e->getMessage());
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
<title>Link to an existing person — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
</head>
<body>
  <div class="card" style="max-width:440px;">
    <div class="brand"><svg class="brand-mark" width="26" height="26" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="#9A2A2A"/><path d="M16 22V14M16 14L11 9M16 14L21 9" stroke="#FBF8F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/><circle cx="16" cy="23" r="1.7" fill="#FBF8F1"/><circle cx="11" cy="8" r="1.7" fill="#FBF8F1"/><circle cx="21" cy="8" r="1.7" fill="#FBF8F1"/></svg><p class="wordmark">ourthology<span class="tld">.com</span></p></div>
    <p class="subtitle">an anthology of us.</p>

    <?php if ($success): ?>
      <p style="margin-top:16px;">Request sent to <strong><?= htmlspecialchars($success, ENT_QUOTES) ?></strong> — they'll see it as a pending request next time they log in, and it'll only join your trees together once they approve it.</p>
      <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>
    <?php else: ?>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p style="font-size:13px;color:var(--ink-faint);margin-top:0;">If they already have their own ourthology account, link to it directly here. They'll need to approve the link before your trees join together — this isn't for adding someone new, that's done from your tree page instead.</p>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <label for="email">Their email</label>
      <input type="email" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required>

      <label for="direction">That person is my</label>
      <select id="direction" name="direction">
        <option value="parent" <?= $direction === 'parent' ? 'selected' : '' ?>>Parent</option>
        <option value="child" <?= $direction === 'child' ? 'selected' : '' ?>>Child</option>
        <option value="partner" <?= $direction === 'partner' ? 'selected' : '' ?>>Partner / spouse</option>
      </select>

      <label for="relation_kind">Relationship type (ignored for partner)</label>
      <select id="relation_kind" name="relation_kind">
        <option value="genetic" <?= $relationKind === 'genetic' ? 'selected' : '' ?>>Genetic</option>
        <option value="step" <?= $relationKind === 'step' ? 'selected' : '' ?>>Step</option>
        <option value="adoptive" <?= $relationKind === 'adoptive' ? 'selected' : '' ?>>Adoptive</option>
      </select>

      <button type="submit" class="btn-primary">Send link request</button>
    </form>
    <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
