<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/memory_tags.php';

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

    if ($id === false || !in_array($kind, ['relationship', 'partnership', 'memory_tag'], true) || !in_array($action, ['approve', 'reject', 'withdraw'], true)) {
        $errors[] = 'Invalid request.';
    } elseif ($kind === 'memory_tag') {
        // memory_tags has no family-group merge to do on approve (tagging
        // only ever happens within a family group that's already one
        // group — see includes/memory_tags.php) so this branch is simpler
        // than the relationships/partnerships one below: just a status
        // flip, or a straight delete either way it's declined/withdrawn.
        if ($action === 'withdraw') {
            $stmt = $pdo->prepare("SELECT id FROM memory_tags WHERE id = :id AND status = 'pending' AND created_by_user_id = :uid");
            $stmt->execute(['id' => $id, 'uid' => $myUserId]);
            if ($stmt->fetch() ?: null) {
                $pdo->prepare('DELETE FROM memory_tags WHERE id = :id')->execute(['id' => $id]);
                $notice = 'Tag request withdrawn.';
            } else {
                $errors[] = 'That request no longer exists (it may already have been handled).';
            }
        } else {
            $stmt = $pdo->prepare("SELECT id FROM memory_tags WHERE id = :id AND status = 'pending' AND approving_user_id = :uid");
            $stmt->execute(['id' => $id, 'uid' => $myUserId]);
            $row = $stmt->fetch() ?: null;

            if ($row === null) {
                $errors[] = 'That request no longer exists (it may already have been handled).';
            } elseif ($action === 'reject') {
                $pdo->prepare('DELETE FROM memory_tags WHERE id = :id')->execute(['id' => $id]);
                $notice = 'Tag declined.';
            } else {
                $note = trim((string) ($_POST['note'] ?? ''));
                $pdo->prepare("UPDATE memory_tags SET status = 'approved', resolved_at = NOW(), note = :note WHERE id = :id")
                    ->execute(['id' => $id, 'note' => $note !== '' ? $note : null]);
                $notice = 'Added to your timeline.';
            }
        }
    } else {
        $table = $kind === 'relationship' ? 'relationships' : 'partnerships';

        if ($action === 'withdraw') {
            // Withdrawing is for the person who SENT the request, not the
            // approver — ownership check happens in the query itself, not
            // just in the UI, exactly like the approve/reject path below.
            $stmt = $pdo->prepare("SELECT id FROM $table WHERE id = :id AND status = 'pending_approval' AND created_by_user_id = :uid");
            $stmt->execute(['id' => $id, 'uid' => $myUserId]);
            if ($stmt->fetch() ?: null) {
                $pdo->prepare("DELETE FROM $table WHERE id = :id")->execute(['id' => $id]);
                $notice = 'Request withdrawn.';
            } else {
                $errors[] = 'That request no longer exists (it may already have been handled).';
            }
        } else {
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
}

$pending = fetch_pending_for_user($pdo, $myUserId);
$outgoing = fetch_outgoing_pending_for_user($pdo, $myUserId);
$pendingTags = fetch_pending_memory_tags_for_user($pdo, $myUserId);
$outgoingTags = fetch_outgoing_memory_tags_for_user($pdo, $myUserId);
$incomingCount = count($pending['relationships']) + count($pending['partnerships']) + count($pendingTags);
$outgoingCount = count($outgoing['relationships']) + count($outgoing['partnerships']) + count($outgoingTags);

/** "Diary entry from 3 May 2024" / "the memory titled…" / a short snippet of the body — whatever names a memory best when there's no title. */
function ourthology_memory_label(array $row): string
{
    $title = trim((string) ($row['title'] ?? ''));
    if ($title !== '') {
        return '"' . $title . '"';
    }
    $body = trim((string) ($row['body'] ?? ''));
    if ($body !== '') {
        return '"' . mb_strimwidth($body, 0, 60, '…') . '"';
    }
    return 'a memory with no title';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pending requests — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
<style>
  .req-card { border:1px solid var(--line); border-radius:8px; padding:12px; margin-top:14px; background:#fff; }
  .req-when { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:var(--ink-faint); margin-bottom:6px; }
  .btn-small { width:auto; margin:0 8px 0 0; padding:6px 14px; }
  .btn-small.ghost { background:transparent; color:var(--accent); border:1px solid var(--accent); margin:0; }
  h3.section-title { margin:24px 0 4px; font-size:15px; }
  h3.section-title:first-of-type { margin-top:16px; }
</style>
</head>
<body>
  <div class="card" style="max-width:480px;">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <?php if ($notice): ?><p style="color:var(--accent);font-weight:600;"><?= htmlspecialchars($notice, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($errors): ?>
      <div class="error"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <h3 class="section-title">Waiting on you (<?= $incomingCount ?>)</h3>
    <?php if (!$incomingCount): ?>
      <p style="color:var(--ink-faint);font-size:14px;margin:4px 0 0;">Nothing needs your approval right now.</p>
    <?php endif; ?>

    <?php foreach ($pending['relationships'] as $r): ?>
      <div class="req-card">
        <span class="req-when"><?= htmlspecialchars(human_time_ago($r['created_at']), ENT_QUOTES) ?></span>
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
          <button type="submit" name="action" value="approve" class="btn-primary btn-small">Approve</button>
          <button type="submit" name="action" value="reject" class="btn-primary btn-small ghost">Decline</button>
        </form>
      </div>
    <?php endforeach; ?>

    <?php foreach ($pending['partnerships'] as $p): ?>
      <div class="req-card">
        <span class="req-when"><?= htmlspecialchars(human_time_ago($p['created_at']), ENT_QUOTES) ?></span>
        <p style="margin:0 0 8px;font-size:14px;">
          <strong><?= htmlspecialchars($p['created_by_email'], ENT_QUOTES) ?></strong> says
          <strong><?= htmlspecialchars($p['a_first'] . ' ' . $p['a_surname'], ENT_QUOTES) ?></strong> and
          <strong><?= htmlspecialchars($p['b_first'] . ' ' . $p['b_surname'], ENT_QUOTES) ?></strong> are partners.
        </p>
        <form method="post" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="partnership">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button type="submit" name="action" value="approve" class="btn-primary btn-small">Approve</button>
          <button type="submit" name="action" value="reject" class="btn-primary btn-small ghost">Decline</button>
        </form>
      </div>
    <?php endforeach; ?>

    <?php foreach ($pendingTags as $t): ?>
      <div class="req-card">
        <span class="req-when"><?= htmlspecialchars(human_time_ago($t['created_at']), ENT_QUOTES) ?></span>
        <p style="margin:0 0 8px;font-size:14px;">
          <strong><?= htmlspecialchars($t['created_by_email'], ENT_QUOTES) ?></strong> tagged you in
          <strong><?= htmlspecialchars($t['owner_first'] . ' ' . $t['owner_surname'], ENT_QUOTES) ?></strong>'s memory
          <?= htmlspecialchars(ourthology_memory_label($t), ENT_QUOTES) ?>. Approving adds it to your own timeline too.
        </p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="memory_tag">
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <label for="tag-note-<?= (int) $t['id'] ?>" style="font-size:12px;">Add a note about this memory <span style="text-transform:none;font-weight:400;">(optional — shown alongside it on every timeline it's on; you can also add or change this later)</span></label>
          <textarea id="tag-note-<?= (int) $t['id'] ?>" name="note" rows="2" style="width:100%;margin:4px 0 8px;padding:8px 10px;border:1px solid var(--line);border-radius:6px;font-size:13.5px;font-family:inherit;"></textarea>
          <button type="submit" name="action" value="approve" class="btn-primary btn-small">Approve</button>
          <button type="submit" name="action" value="reject" class="btn-primary btn-small ghost">Decline</button>
        </form>
      </div>
    <?php endforeach; ?>

    <h3 class="section-title">Sent by you, waiting on them (<?= $outgoingCount ?>)</h3>
    <?php if (!$outgoingCount): ?>
      <p style="color:var(--ink-faint);font-size:14px;margin:4px 0 0;">Nothing outstanding.</p>
    <?php endif; ?>

    <?php foreach ($outgoing['relationships'] as $r): ?>
      <div class="req-card">
        <span class="req-when"><?= htmlspecialchars(human_time_ago($r['created_at']), ENT_QUOTES) ?></span>
        <p style="margin:0 0 8px;font-size:14px;">
          You said <strong><?= htmlspecialchars($r['parent_first'] . ' ' . $r['parent_surname'], ENT_QUOTES) ?></strong>
          is the <?= htmlspecialchars($r['relation_kind'], ENT_QUOTES) ?> parent of
          <strong><?= htmlspecialchars($r['child_first'] . ' ' . $r['child_surname'], ENT_QUOTES) ?></strong> —
          waiting on <strong><?= htmlspecialchars($r['approving_email'], ENT_QUOTES) ?></strong> to approve.
        </p>
        <form method="post" style="display:inline;" onsubmit="return confirm('Withdraw this request?');">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="relationship">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button type="submit" name="action" value="withdraw" class="btn-primary btn-small ghost" style="margin:0;">Withdraw</button>
        </form>
      </div>
    <?php endforeach; ?>

    <?php foreach ($outgoing['partnerships'] as $p): ?>
      <div class="req-card">
        <span class="req-when"><?= htmlspecialchars(human_time_ago($p['created_at']), ENT_QUOTES) ?></span>
        <p style="margin:0 0 8px;font-size:14px;">
          You said <strong><?= htmlspecialchars($p['a_first'] . ' ' . $p['a_surname'], ENT_QUOTES) ?></strong> and
          <strong><?= htmlspecialchars($p['b_first'] . ' ' . $p['b_surname'], ENT_QUOTES) ?></strong> are partners —
          waiting on <strong><?= htmlspecialchars($p['approving_email'], ENT_QUOTES) ?></strong> to approve.
        </p>
        <form method="post" style="display:inline;" onsubmit="return confirm('Withdraw this request?');">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="partnership">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button type="submit" name="action" value="withdraw" class="btn-primary btn-small ghost" style="margin:0;">Withdraw</button>
        </form>
      </div>
    <?php endforeach; ?>

    <?php foreach ($outgoingTags as $t): ?>
      <div class="req-card">
        <span class="req-when"><?= htmlspecialchars(human_time_ago($t['created_at']), ENT_QUOTES) ?></span>
        <p style="margin:0 0 8px;font-size:14px;">
          You tagged <strong><?= htmlspecialchars($t['tagged_first'] . ' ' . $t['tagged_surname'], ENT_QUOTES) ?></strong> in
          <strong><?= htmlspecialchars($t['owner_first'] . ' ' . $t['owner_surname'], ENT_QUOTES) ?></strong>'s memory
          <?= htmlspecialchars(ourthology_memory_label($t), ENT_QUOTES) ?> —
          waiting on <strong><?= htmlspecialchars($t['approving_email'], ENT_QUOTES) ?></strong> to approve.
        </p>
        <form method="post" style="display:inline;" onsubmit="return confirm('Withdraw this tag?');">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="memory_tag">
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <button type="submit" name="action" value="withdraw" class="btn-primary btn-small ghost" style="margin:0;">Withdraw</button>
        </form>
      </div>
    <?php endforeach; ?>

    <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>
  </div>
</body>
</html>
