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
$myUserId = (int) $me['user_id'];

$notice = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');
    $kind   = (string) ($_POST['kind'] ?? '');
    $id     = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);

    if ($id === false || !in_array($kind, ['relationship', 'partnership'], true) || !in_array($action, ['approve', 'reject'], true)) {
        $errors[] = 'Invalid request.';
    } else {
        $table = $kind === 'relationship' ? 'relationships' : 'partnerships';
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = :id AND status = 'pending_approval' AND approving_user_id = :uid");
        $stmt->execute(['id' => $id, 'uid' => $myUserId]);
        // PDOStatement::fetch() returns false (not null) when no row matches —
        // checking for null here would silently fall through to the approve
        // branch on a nonexistent/unauthorized request. Normalize to null.
        $row = $stmt->fetch() ?: null;

        if ($row === null) {
            $errors[] = 'That request no longer exists (it may already have been handled).';
        } elseif ($action === 'reject') {
            $pdo->prepare("DELETE FROM $table WHERE id = :id")->execute(['id' => $id]);
            $notice = 'Request declined.';
        } else {
            // approve: merge the two family groups, then mark confirmed
            $p1 = $kind === 'relationship' ? (int) $row['parent_id'] : (int) $row['person_a_id'];
            $p2 = $kind === 'relationship' ? (int) $row['child_id'] : (int) $row['person_b_id'];
            $g1 = (int) person_row($pdo, $p1)['family_group_id'];
            $g2 = (int) person_row($pdo, $p2)['family_group_id'];

            $pdo->beginTransaction();
            try {
                merge_family_groups($pdo, $g1, $g2);
                $pdo->prepare("UPDATE $table SET status = 'confirmed', resolved_at = NOW() WHERE id = :id")
                    ->execute(['id' => $id]);
                $pdo->commit();
                $notice = 'Confirmed — your trees are now linked.';
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('ourthology pending approve error: ' . $e->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
    }
}

$pending = fetch_pending_for_user($pdo, $myUserId);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pending requests — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
</head>
<body>
  <div class="card" style="max-width:480px;">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <?php if ($notice): ?><p style="color:var(--accent);font-weight:600;"><?= htmlspecialchars($notice, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($errors): ?>
      <div class="error"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if (!$pending['relationships'] && !$pending['partnerships']): ?>
      <p style="margin-top:16px;color:var(--ink-faint);">No pending requests.</p>
    <?php endif; ?>

    <?php foreach ($pending['relationships'] as $r): ?>
      <div style="border:1px solid var(--line);border-radius:8px;padding:12px;margin-top:14px;">
        <p style="margin:0 0 8px;font-size:14px;">
          <strong><?= htmlspecialchars($r['created_by_email'], ENT_QUOTES) ?></strong> says
          <strong><?= htmlspecialchars($r['parent_first'] . ' ' . $r['parent_surname'], ENT_QUOTES) ?></strong>
          is the <?= htmlspecialchars($r['relation_kind'], ENT_QUOTES) ?> parent of
          <strong><?= htmlspecialchars($r['child_first'] . ' ' . $r['child_surname'], ENT_QUOTES) ?></strong>.
        </p>
        <form method="post" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="relationship">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button type="submit" name="action" value="approve" class="btn-primary" style="width:auto;margin:0 8px 0 0;padding:6px 14px;">Approve</button>
          <button type="submit" name="action" value="reject" class="btn-primary" style="width:auto;margin:0;padding:6px 14px;background:transparent;color:var(--accent);border:1px solid var(--accent);">Decline</button>
        </form>
      </div>
    <?php endforeach; ?>

    <?php foreach ($pending['partnerships'] as $p): ?>
      <div style="border:1px solid var(--line);border-radius:8px;padding:12px;margin-top:14px;">
        <p style="margin:0 0 8px;font-size:14px;">
          <strong><?= htmlspecialchars($p['created_by_email'], ENT_QUOTES) ?></strong> says
          <strong><?= htmlspecialchars($p['a_first'] . ' ' . $p['a_surname'], ENT_QUOTES) ?></strong> and
          <strong><?= htmlspecialchars($p['b_first'] . ' ' . $p['b_surname'], ENT_QUOTES) ?></strong> are partners.
        </p>
        <form method="post" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="partnership">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button type="submit" name="action" value="approve" class="btn-primary" style="width:auto;margin:0 8px 0 0;padding:6px 14px;">Approve</button>
          <button type="submit" name="action" value="reject" class="btn-primary" style="width:auto;margin:0;padding:6px 14px;background:transparent;color:var(--accent);border:1px solid var(--accent);">Decline</button>
        </form>
      </div>
    <?php endforeach; ?>

    <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>
  </div>
</body>
</html>
