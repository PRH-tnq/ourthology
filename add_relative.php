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
$myGroup = (int) person_row($pdo, (int) $me['person_id'])['family_group_id'];

$graph = fetch_family_graph($pdo, $myGroup);
$anchors = $graph['persons'];

$errors = [];
$successLink = null;
$first = $middle = $surname = '';
$anchorId = (string) $me['person_id'];
$direction = 'parent'; // new person is the anchor's: parent / child / partner
$relationKind = 'genetic';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $first        = trim((string) ($_POST['first_name'] ?? ''));
    $middle       = trim((string) ($_POST['middle_name'] ?? ''));
    $surname      = trim((string) ($_POST['surname'] ?? ''));
    $anchorId     = (string) ($_POST['anchor_id'] ?? '');
    $direction    = (string) ($_POST['direction'] ?? '');
    $relationKind = (string) ($_POST['relation_kind'] ?? 'genetic');

    if ($first === '') {
        $errors[] = 'First name is required.';
    }
    if (!in_array($direction, ['parent', 'child', 'partner'], true)) {
        $errors[] = 'Choose a relationship.';
    }
    if (!in_array($relationKind, ['genetic', 'step', 'adoptive'], true)) {
        $relationKind = 'genetic';
    }
    $anchorIdInt = filter_var($anchorId, FILTER_VALIDATE_INT);
    if ($anchorIdInt === false || !person_in_group($pdo, (int) $anchorIdInt, $myGroup)) {
        $errors[] = 'That anchor person is not in your family tree.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO persons (first_name, middle_name, surname, claimed_by_user_id, created_by_user_id, family_group_id)
                 VALUES (:first, :middle, :surname, NULL, :creator, :gid)'
            );
            $stmt->execute([
                'first'   => $first,
                'middle'  => $middle !== '' ? $middle : null,
                'surname' => $surname !== '' ? $surname : null,
                'creator' => $me['user_id'],
                'gid'     => $myGroup,
            ]);
            $newPersonId = (int) $pdo->lastInsertId();

            if ($direction === 'partner') {
                $a = min($newPersonId, (int) $anchorIdInt);
                $b = max($newPersonId, (int) $anchorIdInt);
                $pdo->prepare(
                    "INSERT INTO partnerships (person_a_id, person_b_id, kind, status, created_by_user_id)
                     VALUES (:a, :b, 'married', 'confirmed', :uid)"
                )->execute(['a' => $a, 'b' => $b, 'uid' => $me['user_id']]);
            } else {
                // "parent": new person is the anchor's parent -> parent_id = new, child_id = anchor
                // "child":  new person is the anchor's child  -> parent_id = anchor, child_id = new
                $parentId = $direction === 'parent' ? $newPersonId : (int) $anchorIdInt;
                $childId  = $direction === 'parent' ? (int) $anchorIdInt : $newPersonId;
                $pdo->prepare(
                    "INSERT INTO relationships (parent_id, child_id, relation_kind, status, created_by_user_id)
                     VALUES (:p, :c, :kind, 'confirmed', :uid)"
                )->execute(['p' => $parentId, 'c' => $childId, 'kind' => $relationKind, 'uid' => $me['user_id']]);
            }

            $token = create_claim_token($pdo, $newPersonId, (int) $me['user_id']);

            $pdo->commit();

            $successLink = claim_link_url($token);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('ourthology add_relative error: ' . $e->getMessage());
            $errors[] = 'Something went wrong adding that person. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Add a relative — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
</head>
<body>
  <div class="card" style="max-width:460px;">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <?php if ($successLink): ?>
      <div style="background:var(--paper-2);border-radius:8px;padding:14px;margin-top:16px;">
        <p style="margin:0 0 8px;font-weight:600;">Added — here's their invite link:</p>
        <p style="word-break:break-all;font-size:13px;background:#fff;border:1px solid var(--line);border-radius:6px;padding:8px;"><?= htmlspecialchars($successLink, ENT_QUOTES) ?></p>
        <p style="margin:8px 0 0;font-size:13px;color:var(--ink-faint);">Copy this and send it to them yourself (text, email, whatever) — it lets them set a password and claim this record as their own. It expires in 30 days; you can generate a fresh one later from your tree page.</p>
      </div>
      <p class="foot-link"><a href="/tree.php">Back to my tree</a> · <a href="/add_relative.php">Add another</a></p>
    <?php else: ?>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>

      <label for="anchor_id">Connected to</label>
      <select id="anchor_id" name="anchor_id">
        <?php foreach ($anchors as $a): ?>
          <option value="<?= (int) $a['id'] ?>" <?= (string) $a['id'] === $anchorId ? 'selected' : '' ?>>
            <?= htmlspecialchars(person_display_name($a), ENT_QUOTES) ?><?= (int) $a['id'] === (int) $me['person_id'] ? ' (you)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label for="direction">New person is that person's</label>
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

      <button type="submit" class="btn-primary">Add person</button>
    </form>
    <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
