<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/memory_tags.php';
require_once __DIR__ . '/includes/entries.php'; // fetch_entry_media() — the memory preview below (Phase 32)

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    $next = ourthology_safe_redirect_target($_SERVER['REQUEST_URI'] ?? null);
    header('Location: /login.php' . ($next !== null ? '?next=' . rawurlencode($next) : ''));
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

// Phase 32: a memory-tag request is a judgment call ("does this actually
// belong on my timeline too?"), so both lists below get the tagged
// memory's own media attached — same shape as fetch_entries_for_person()
// uses, just per-entry rather than batched, since there are normally only
// a handful of these at once. can_view_media() (includes/media.php) was
// widened alongside this so /media.php?id=... actually serves these
// images to a reviewer whose tag is still pending, not just once approved.
foreach ($pendingTags as &$t) {
    $t['media'] = fetch_entry_media($pdo, (int) $t['entry_id']);
}
unset($t);
foreach ($outgoingTags as &$t) {
    $t['media'] = fetch_entry_media($pdo, (int) $t['entry_id']);
}
unset($t);

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

/**
 * The full memory preview shown under a tag request's summary line — the
 * complete body text (not the 60-character label snippet above) plus a
 * thumbnail for every attached file, so whoever is asked to approve or
 * decline can actually see what they're being asked about instead of
 * judging a memory blind. Images link to the full-size original; other
 * file types (video, pdf, doc, txt — see MEDIA_ALLOWED in
 * includes/media.php) show as a small labelled icon tile that opens the
 * file directly, since there's no useful inline preview for those here.
 */
function ourthology_pending_memory_preview_html(array $row): string
{
    $html = '';
    $body = trim((string) ($row['body'] ?? ''));
    if ($body !== '') {
        $html .= '<p style="margin:0 0 10px;font-size:13.5px;line-height:1.5;white-space:pre-wrap;color:var(--ink);">'
            . nl2br(htmlspecialchars($body, ENT_QUOTES)) . '</p>';
    }
    $media = $row['media'] ?? [];
    if ($media) {
        $html .= '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 10px;">';
        foreach ($media as $m) {
            $url = '/media.php?id=' . (int) $m['id'];
            $isImage = str_starts_with((string) $m['mime_type'], 'image/');
            if ($isImage) {
                $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener" '
                    . 'style="display:block;width:72px;height:72px;border-radius:6px;overflow:hidden;border:1px solid var(--line);flex:none;">'
                    . '<img src="' . htmlspecialchars($url . '&thumb=1', ENT_QUOTES) . '" alt="" loading="lazy" '
                    . 'style="width:100%;height:100%;object-fit:cover;display:block;"></a>';
            } else {
                $kind = str_starts_with((string) $m['mime_type'], 'video/') ? 'Video' : 'File';
                $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener" '
                    . 'style="display:flex;align-items:center;justify-content:center;text-align:center;width:72px;height:72px;'
                    . 'border-radius:6px;border:1px solid var(--line);background:var(--paper);flex:none;font-size:11px;'
                    . 'color:var(--ink-faint);padding:4px;box-sizing:border-box;">' . htmlspecialchars($kind, ENT_QUOTES) . '</a>';
            }
        }
        $html .= '</div>';
    }
    return $html;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pending requests — ourthology.com</title>
<link rel="stylesheet" href="/styles.css?v=20">
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

    <?php if ($notice): ?><p style="color:var(--accent);font-weight:600;"><?= htmlspecialchars($notice, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($errors): ?>
      <div class="error"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <h3 class="section-title" id="waiting-on-you">Waiting on you (<?= $incomingCount ?>)</h3>
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
        <?= ourthology_pending_memory_preview_html($t) ?>
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
        <?= ourthology_pending_memory_preview_html($t) ?>
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
  <?php if (($_GET['goto'] ?? '') === 'waiting-on-you'): ?>
  <script>
    // Phase 40: a pending-approval notification email links here with
    // ?goto=waiting-on-you rather than a plain #waiting-on-you fragment,
    // because a logged-out click has to go through /login.php first --
    // fragments never reach the server, so they don't survive that
    // round trip, but this query param does (see require_login() /
    // login.php's ?next= handling). Scrolls straight to the section
    // once the page has actually loaded.
    document.getElementById('waiting-on-you')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  </script>
  <?php endif; ?>
</body>
</html>
