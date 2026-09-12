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

ourthology_start_session();

$flashNotice = $_SESSION['flash_edit_notice'] ?? null;
unset($_SESSION['flash_edit_notice']);

$personIdRaw = $_GET['person_id'] ?? $_POST['person_id'] ?? '';
$personIdFilter = filter_var($personIdRaw, FILTER_VALIDATE_INT);
$personId = ($personIdFilter !== false && person_in_group($pdo, (int) $personIdFilter, $myGroup)) ? (int) $personIdFilter : null;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($personId === null) {
        $errors[] = 'Choose a person first.';
    } elseif ($action === 'update_name') {
        $person = person_row($pdo, $personId);
        if ($person && $person['claimed_by_user_id']) {
            $errors[] = "This person has claimed their own record, so their name can't be changed here.";
        } else {
            $first = trim((string) ($_POST['first_name'] ?? ''));
            $middle = trim((string) ($_POST['middle_name'] ?? ''));
            $surname = trim((string) ($_POST['surname'] ?? ''));
            if ($first === '') {
                $errors[] = 'First name is required.';
            } else {
                $pdo->prepare('UPDATE persons SET first_name = :f, middle_name = :m, surname = :s WHERE id = :id')
                    ->execute([
                        'f'  => $first,
                        'm'  => $middle !== '' ? $middle : null,
                        's'  => $surname !== '' ? $surname : null,
                        'id' => $personId,
                    ]);
                $_SESSION['flash_edit_notice'] = 'Name updated.';
                header('Location: /edit_person.php?person_id=' . $personId);
                exit;
            }
        }
    } elseif ($action === 'update_kind') {
        $relId = filter_var($_POST['relationship_id'] ?? '', FILTER_VALIDATE_INT);
        $kind = (string) ($_POST['relation_kind'] ?? '');
        if ($relId === false || !in_array($kind, ['genetic', 'step', 'adoptive'], true)) {
            $errors[] = 'Invalid request.';
        } else {
            $stmt = $pdo->prepare('SELECT parent_id, child_id FROM relationships WHERE id = :id');
            $stmt->execute(['id' => (int) $relId]);
            $rel = $stmt->fetch();
            if (!$rel || !person_in_group($pdo, (int) $rel['parent_id'], $myGroup) || !person_in_group($pdo, (int) $rel['child_id'], $myGroup)) {
                $errors[] = 'That relationship is not in your family tree.';
            } else {
                $pdo->prepare("UPDATE relationships SET relation_kind = :k WHERE id = :id")
                    ->execute(['k' => $kind, 'id' => (int) $relId]);
                $_SESSION['flash_edit_notice'] = 'Relationship type updated.';
                header('Location: /edit_person.php?person_id=' . $personId);
                exit;
            }
        }
    } elseif ($action === 'remove_relationship') {
        $relId = filter_var($_POST['relationship_id'] ?? '', FILTER_VALIDATE_INT);
        if ($relId === false) {
            $errors[] = 'Invalid request.';
        } else {
            $stmt = $pdo->prepare('SELECT parent_id, child_id FROM relationships WHERE id = :id');
            $stmt->execute(['id' => (int) $relId]);
            $rel = $stmt->fetch();
            if (!$rel || !person_in_group($pdo, (int) $rel['parent_id'], $myGroup) || !person_in_group($pdo, (int) $rel['child_id'], $myGroup)) {
                $errors[] = 'That relationship is not in your family tree.';
            } else {
                $pdo->prepare('DELETE FROM relationships WHERE id = :id')->execute(['id' => (int) $relId]);
                $_SESSION['flash_edit_notice'] = 'Relationship removed. If this was recorded by mistake, use "Attach as a relative" below to add the correct one.';
                header('Location: /edit_person.php?person_id=' . $personId);
                exit;
            }
        }
    } elseif ($action === 'remove_partnership') {
        $partId = filter_var($_POST['partnership_id'] ?? '', FILTER_VALIDATE_INT);
        if ($partId === false) {
            $errors[] = 'Invalid request.';
        } else {
            $stmt = $pdo->prepare('SELECT person_a_id, person_b_id FROM partnerships WHERE id = :id');
            $stmt->execute(['id' => (int) $partId]);
            $part = $stmt->fetch();
            if (!$part || !person_in_group($pdo, (int) $part['person_a_id'], $myGroup) || !person_in_group($pdo, (int) $part['person_b_id'], $myGroup)) {
                $errors[] = 'That relationship is not in your family tree.';
            } else {
                $pdo->prepare('DELETE FROM partnerships WHERE id = :id')->execute(['id' => (int) $partId]);
                $_SESSION['flash_edit_notice'] = 'Relationship removed.';
                header('Location: /edit_person.php?person_id=' . $personId);
                exit;
            }
        }
    }
}

$graph = fetch_family_graph($pdo, $myGroup);
$personsById = [];
foreach ($graph['persons'] as $p) {
    $personsById[(int) $p['id']] = $p;
}

$person = $personId !== null ? ($personsById[$personId] ?? null) : null;

$rels = [];
$parts = [];
if ($person !== null) {
    $relStmt = $pdo->prepare(
        "SELECT r.id, r.parent_id, r.child_id, r.relation_kind,
                pp.first_name AS parent_first, pp.surname AS parent_surname,
                pc.first_name AS child_first, pc.surname AS child_surname
         FROM relationships r
         JOIN persons pp ON pp.id = r.parent_id
         JOIN persons pc ON pc.id = r.child_id
         WHERE r.status = 'confirmed' AND (r.parent_id = :pid OR r.child_id = :pid2)
         ORDER BY r.id"
    );
    $relStmt->execute(['pid' => $personId, 'pid2' => $personId]);
    $rels = $relStmt->fetchAll();

    $partStmt = $pdo->prepare(
        "SELECT p.id, p.person_a_id, p.person_b_id,
                pa.first_name AS a_first, pa.surname AS a_surname,
                pb.first_name AS b_first, pb.surname AS b_surname
         FROM partnerships p
         JOIN persons pa ON pa.id = p.person_a_id
         JOIN persons pb ON pb.id = p.person_b_id
         WHERE p.status = 'confirmed' AND (p.person_a_id = :pid OR p.person_b_id = :pid2)
         ORDER BY p.id"
    );
    $partStmt->execute(['pid' => $personId, 'pid2' => $personId]);
    $parts = $partStmt->fetchAll();
}

$confirmRel = filter_var($_GET['confirm_rel'] ?? '', FILTER_VALIDATE_INT);
$confirmRel = $confirmRel === false ? null : (int) $confirmRel;
$confirmPart = filter_var($_GET['confirm_part'] ?? '', FILTER_VALIDATE_INT);
$confirmPart = $confirmPart === false ? null : (int) $confirmPart;

$kindLabels = ['genetic' => 'genetic', 'step' => 'step', 'adoptive' => 'adoptive'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit person — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
<style>
  .nav { display:flex; gap:10px 16px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin: 18px 0 4px; }
  .nav-links { display:flex; gap:10px; flex-wrap:wrap; }
  .nav a { font-size:13px; padding:7px 12px; border-radius:999px; border:1px solid var(--line); color:var(--ink-soft); text-decoration:none; background:#fff; }
  .whoami { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12.5px; color:var(--ink-faint); }
  .whoami strong { color:var(--ink-soft); font-weight:600; }
  .whoami form { display:inline; }
  .whoami .linklet { font-size:12.5px; }
  select { padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; background:#fff; color:var(--ink); }
  .notice { background:var(--paper-2); border-radius:8px; padding:10px 12px; font-size:14px; margin-top:16px; }
  .rel-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 0; border-bottom:1px solid var(--line); font-size:14px; flex-wrap:wrap; }
  .rel-desc { flex:1 1 auto; min-width:220px; }
  .rel-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .rel-actions form { display:inline-flex; align-items:center; gap:6px; }
  .btn-danger { font-size:12px; background:transparent; border:1px solid var(--error); color:var(--error); border-radius:999px; padding:5px 10px; cursor:pointer; }
  .btn-danger:hover { background:var(--error-bg); }
  .btn-small { font-size:12px; background:#fff; border:1px solid var(--line); color:var(--ink-soft); border-radius:999px; padding:5px 10px; cursor:pointer; }
  .confirm-box { background:var(--error-bg); border-radius:8px; padding:12px 14px; margin:10px 0; font-size:14px; }
  .confirm-box .actions { display:flex; gap:10px; margin-top:10px; }
  select.kind-select { font-size:12px; padding:4px 6px; }
</style>
</head>
<body>
  <div class="card" style="max-width:560px;">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <div class="nav">
      <div class="nav-links">
        <a href="/timeline.php">My timeline</a>
        <a href="/tree.php">My tree</a>
        <a href="/add_relative.php">+ Add a relative</a>
      </div>
      <div class="whoami">
        Signed in as <strong><?= htmlspecialchars($me['email'], ENT_QUOTES) ?></strong>
        <form method="post" action="/logout.php"><button type="submit" class="linklet">Log out</button></form>
      </div>
    </div>

    <?php if ($flashNotice): ?>
      <div class="notice"><?= htmlspecialchars($flashNotice, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p style="font-size:13px;color:var(--ink-faint);margin-top:12px;">
      Fix a person's name or details, or correct a relationship that was recorded wrong (for example, someone who should show up as a sibling but was accidentally added as a parent).
    </p>

    <form method="get" style="margin-top:12px;">
      <label for="person_id">Person</label>
      <select id="person_id" name="person_id" onchange="this.form.submit()">
        <option value="">Choose someone…</option>
        <?php foreach ($graph['persons'] as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= $personId === (int) $p['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars(person_display_name($p), ENT_QUOTES) ?><?= (int) $p['id'] === $myPersonId ? ' (you)' : '' ?><?= $p['claimed_by_user_id'] ? '' : ' (unclaimed)' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn-small" style="margin-top:8px;">Go</button></noscript>
    </form>

    <?php if ($person !== null): ?>

      <h3 style="margin:24px 0 4px;">Name</h3>
      <?php if ($person['claimed_by_user_id']): ?>
        <p style="font-size:14px;"><?= htmlspecialchars(person_display_name($person), ENT_QUOTES) ?> — this person has claimed their own record, so their name can only be changed by them.</p>
      <?php else: ?>
        <form method="post" class="row-2" style="margin-top:8px;">
          <?= csrf_field() ?>
          <input type="hidden" name="person_id" value="<?= $personId ?>">
          <input type="hidden" name="action" value="update_name">
          <div>
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($person['first_name'], ENT_QUOTES) ?>" maxlength="60" required>
          </div>
          <div>
            <label for="surname">Surname</label>
            <input type="text" id="surname" name="surname" value="<?= htmlspecialchars((string) $person['surname'], ENT_QUOTES) ?>" maxlength="60">
          </div>
          <div style="grid-column:1 / -1;">
            <label for="middle_name">Middle name <span style="text-transform:none;font-weight:400;">(optional)</span></label>
            <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars((string) $person['middle_name'], ENT_QUOTES) ?>" maxlength="60">
            <button type="submit" class="btn-primary" style="margin-top:14px;">Save name</button>
          </div>
        </form>
      <?php endif; ?>

      <h3 style="margin:24px 0 4px;">Relationships</h3>
      <?php if (!$rels && !$parts): ?>
        <p style="font-size:14px;color:var(--ink-faint);">Not connected to anyone yet.</p>
      <?php endif; ?>

      <?php foreach ($rels as $r): ?>
        <?php
          $isParentSide = (int) $r['parent_id'] === $personId;
          $parentName = trim($r['parent_first'] . ' ' . $r['parent_surname']);
          $childName = trim($r['child_first'] . ' ' . $r['child_surname']);
          $otherName = $isParentSide ? $childName : $parentName;
          $desc = $isParentSide
            ? '<strong>' . htmlspecialchars($otherName, ENT_QUOTES) . '</strong> is their child'
            : '<strong>' . htmlspecialchars($otherName, ENT_QUOTES) . '</strong> is their parent';
        ?>
        <div class="rel-row">
          <div class="rel-desc"><?= $desc ?> <span style="color:var(--ink-faint);">(<?= htmlspecialchars($kindLabels[$r['relation_kind']] ?? $r['relation_kind'], ENT_QUOTES) ?>)</span></div>
          <div class="rel-actions">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="person_id" value="<?= $personId ?>">
              <input type="hidden" name="action" value="update_kind">
              <input type="hidden" name="relationship_id" value="<?= (int) $r['id'] ?>">
              <select name="relation_kind" class="kind-select" onchange="this.form.submitBtn.disabled=false">
                <option value="genetic" <?= $r['relation_kind'] === 'genetic' ? 'selected' : '' ?>>genetic</option>
                <option value="step" <?= $r['relation_kind'] === 'step' ? 'selected' : '' ?>>step</option>
                <option value="adoptive" <?= $r['relation_kind'] === 'adoptive' ? 'selected' : '' ?>>adoptive</option>
              </select>
              <button type="submit" name="submitBtn" class="btn-small">Save</button>
            </form>
            <a href="/edit_person.php?person_id=<?= $personId ?>&confirm_rel=<?= (int) $r['id'] ?>" class="btn-danger">Remove</a>
          </div>
        </div>
        <?php if ($confirmRel === (int) $r['id']): ?>
          <div class="confirm-box">
            Remove this relationship — <?= htmlspecialchars($parentName, ENT_QUOTES) ?> as parent of <?= htmlspecialchars($childName, ENT_QUOTES) ?>? This can't be undone (you'd need to add it again from scratch).
            <div class="actions">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="person_id" value="<?= $personId ?>">
                <input type="hidden" name="action" value="remove_relationship">
                <input type="hidden" name="relationship_id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn-danger">Yes, remove it</button>
              </form>
              <a href="/edit_person.php?person_id=<?= $personId ?>" class="btn-small" style="text-decoration:none;display:inline-block;">Cancel</a>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php foreach ($parts as $p): ?>
        <?php
          $aName = trim($p['a_first'] . ' ' . $p['a_surname']);
          $bName = trim($p['b_first'] . ' ' . $p['b_surname']);
          $otherName = (int) $p['person_a_id'] === $personId ? $bName : $aName;
        ?>
        <div class="rel-row">
          <div class="rel-desc"><strong><?= htmlspecialchars($otherName, ENT_QUOTES) ?></strong> is their spouse / partner</div>
          <div class="rel-actions">
            <a href="/edit_person.php?person_id=<?= $personId ?>&confirm_part=<?= (int) $p['id'] ?>" class="btn-danger">Remove</a>
          </div>
        </div>
        <?php if ($confirmPart === (int) $p['id']): ?>
          <div class="confirm-box">
            Remove the partnership between <?= htmlspecialchars($aName, ENT_QUOTES) ?> and <?= htmlspecialchars($bName, ENT_QUOTES) ?>? This can't be undone (you'd need to add it again from scratch).
            <div class="actions">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="person_id" value="<?= $personId ?>">
                <input type="hidden" name="action" value="remove_partnership">
                <input type="hidden" name="partnership_id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="btn-danger">Yes, remove it</button>
              </form>
              <a href="/edit_person.php?person_id=<?= $personId ?>" class="btn-small" style="text-decoration:none;display:inline-block;">Cancel</a>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>

      <p class="foot-link" style="margin-top:20px;">
        <a href="/add_relative.php?existing_person_id=<?= $personId ?>">Attach <?= htmlspecialchars(person_display_name($person), ENT_QUOTES) ?> as a relative of someone else</a>
      </p>
      <p style="font-size:12px;color:var(--ink-faint);margin-top:-12px;">Use this after removing a wrong relationship above, to record the correct one — grandparent, sibling, cousin, and the rest are all available, not just parent/child.</p>

    <?php endif; ?>

    <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>
  </div>
</body>
</html>
