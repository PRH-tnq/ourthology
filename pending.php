<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/memory_tags.php';
require_once __DIR__ . '/includes/entries.php'; // fetch_entry_media() — the memory preview below (Phase 32)
require_once __DIR__ . '/includes/postcards.php';

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
$myPersonId = (int) $me['person_id'];

$notice = null;
$errors = [];

// Phase 48: postcard.php (open/save/discard) redirects back here with
// one of these flashes -- $postcardNotice reuses this page's own
// $notice render slot below, and $openPostcard (resolved further down,
// once $myPersonId's ownership can be checked) drives the read pop-up.
$openPostcardRowId = $_SESSION['flash_open_postcard'] ?? null;
unset($_SESSION['flash_open_postcard']);
if (!empty($_SESSION['flash_postcard_notice'])) {
    $notice = (string) $_SESSION['flash_postcard_notice'];
}
unset($_SESSION['flash_postcard_notice']);

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
$pendingPostcards = fetch_pending_postcards_for_person($pdo, $myPersonId);
$outgoingPostcards = fetch_outgoing_postcards_for_person($pdo, $myPersonId);
$incomingCount = count($pending['relationships']) + count($pending['partnerships']) + count($pendingTags) + count($pendingPostcards);
$outgoingCount = count($outgoing['relationships']) + count($outgoing['partnerships']) + count($outgoingTags) + count($outgoingPostcards);

$openPostcard = $openPostcardRowId !== null
    ? fetch_postcard_recipient_row($pdo, (int) $openPostcardRowId, $myPersonId)
    : null;

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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css?v=25">
<style>
  .req-card { border:1px solid var(--line); border-radius:8px; padding:12px; margin-top:14px; background:#fff; }
  .req-when { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:var(--ink-faint); margin-bottom:6px; }
  .btn-small { width:auto; margin:0 8px 0 0; padding:6px 14px; }
  .btn-small.ghost { background:transparent; color:var(--accent); border:1px solid var(--accent); margin:0; }
  h3.section-title { margin:24px 0 4px; font-size:15px; }
  h3.section-title:first-of-type { margin-top:16px; }

  /* Phase 48: same postcard flip-card, byte-for-byte, as timeline.php's
     compose pop-up -- this page only ever shows it read-only (the
     recipient looking at what they were sent), never editable. */
  .postcard-overlay { position:fixed; inset:0; background:rgba(26,23,20,0.6); z-index:1000; display:flex; align-items:center; justify-content:center; padding:20px; overflow:auto; }
  .postcard-box { position:relative; width:min(96vw, 640px); max-height:94vh; overflow:auto; background:var(--paper); border:2px solid var(--accent); border-radius:16px; box-shadow:0 24px 60px -20px rgba(0,0,0,0.45); padding:22px 24px 26px; box-sizing:border-box; }
  .postcard-close { position:absolute; top:10px; right:12px; z-index:2; width:32px; height:32px; border-radius:50%; border:1px solid var(--line); background:#fff; color:var(--ink-soft); font-size:18px; line-height:1; cursor:pointer; }
  .postcard-close:hover { background:var(--paper-2); }
  .postcard-flip-scene { perspective:1600px; width:100%; aspect-ratio:3/2; margin:4px 0 14px; }
  .postcard-flip-inner { position:relative; width:100%; height:100%; transition:transform 0.7s cubic-bezier(.4,.2,.2,1); transform-style:preserve-3d; }
  .postcard-flip-inner.is-flipped { transform:rotateY(180deg); }
  .postcard-face { position:absolute; inset:0; backface-visibility:hidden; -webkit-backface-visibility:hidden; border:2px solid var(--accent); border-radius:12px; background:#fff; box-shadow:0 6px 18px -10px rgba(0,0,0,0.35); overflow:hidden; }
  .postcard-face-back { transform:rotateY(180deg); display:flex; flex-direction:column; }

  /* Phase 49: matches timeline.php's compose card byte-for-byte for
     every class shared between the two -- see that file for the
     rationale (matted photo front, two-column back). */
  /* Phase 50: a thick WHITE border on the front (like a printed photo
     tucked onto the card) and a diagonal striped "airmail" border on the
     back -- closer to Phil's reference postcard than Phase 49's plainer
     coloured-gradient mat. */
  .postcard-face-front { padding:16px; box-sizing:border-box; background:#fff; }
  .postcard-photo-mat { position:relative; width:100%; height:100%; border-radius:2px; overflow:hidden; background:#fff; }
  /* Phase 52: thicker stripes, closer to Phil's reference image than
     Phase 50's narrower 9px band. */
  .postcard-face-back {
    border-width:14px;
    border-style:solid;
    border-image-source: repeating-linear-gradient(-45deg,
      #9a2a2a 0, #9a2a2a 14px,
      #fff 14px, #fff 28px,
      #29456e 28px, #29456e 42px,
      #fff 42px, #fff 56px);
    border-image-slice:46;
    border-image-repeat:round;
    border-radius:0;
  }
  .postcard-read-photo { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }

  .postcard-back-toolbar { flex:0 0 auto; display:flex; align-items:center; padding:8px 10px; border-bottom:1px solid var(--line); background:var(--paper-2); }
  .postcard-back-content { flex:1 1 auto; display:flex; min-height:0; }
  .postcard-read-message { flex:1 1 58%; width:auto; min-width:0; box-sizing:border-box; padding:16px 18px; font-family:'Caveat',cursive; font-size:22px; line-height:1.5; color:#2b2620; overflow:auto; }
  .postcard-back-address { flex:0 0 40%; box-sizing:border-box; border-left:1px dashed var(--line); padding:14px 16px; display:flex; flex-direction:column; }
  /* Phase 52: a real postmark -- a branded postage-stamp graphic (the
     site's own brand mark) plus a cancellation-style circular postmark
     reading "OURTHOLOGY POST OFFICE" -- replacing the placeholder
     dashed box. */
  .postcard-stamp { position:static; align-self:flex-end; flex:0 0 auto; width:104px; aspect-ratio:118/84; margin-bottom:16px; }
  .postcard-stamp svg { display:block; width:100%; height:100%; }
  .postcard-address-lines { display:flex; flex-direction:column; gap:14px; margin-top:auto; }
  .postcard-address-line { border-bottom:1px solid var(--line); height:1px; }
  .postcard-address-line.is-filled { height:auto; border-bottom:1px solid var(--line); font-family:'Caveat',cursive; font-size:16px; color:#2b2620; padding-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .postcard-flip-btn { font-size:12.5px; font-weight:600; padding:6px 12px; border-radius:999px; border:1px solid var(--accent); color:var(--accent); background:#fff; cursor:pointer; font-family:inherit; }
  .postcard-flip-btn:hover { background:var(--paper-2); }
  .postcard-read-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; }
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

    <?php foreach ($pendingPostcards as $pc): ?>
      <div class="req-card">
        <span class="req-when"><?= htmlspecialchars(human_time_ago($pc['received_at']), ENT_QUOTES) ?></span>
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="display:block;width:56px;height:56px;border-radius:6px;overflow:hidden;border:1px solid var(--line);flex:none;">
            <img src="/postcard_media.php?id=<?= (int) $pc['postcard_id'] ?>&thumb=1" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;">
          </span>
          <p style="margin:0;font-size:14px;flex:1 1 auto;">
            A postcard from <strong><?= htmlspecialchars(person_display_name(['first_name' => $pc['sender_first'], 'surname' => $pc['sender_surname']]), ENT_QUOTES) ?></strong><?= $pc['status'] === 'read' ? ' <span style="color:var(--ink-faint);font-size:12px;">(already opened)</span>' : '' ?>
          </p>
        </div>
        <form method="post" action="/postcard.php" style="margin-top:8px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="open">
          <input type="hidden" name="recipient_row_id" value="<?= (int) $pc['recipient_row_id'] ?>">
          <button type="submit" class="btn-primary btn-small">Open postcard</button>
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

    <?php foreach ($outgoingPostcards as $pc): ?>
      <div class="req-card">
        <span class="req-when"><?= htmlspecialchars(human_time_ago($pc['sent_at']), ENT_QUOTES) ?></span>
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="display:block;width:56px;height:56px;border-radius:6px;overflow:hidden;border:1px solid var(--line);flex:none;">
            <img src="/postcard_media.php?id=<?= (int) $pc['postcard_id'] ?>&thumb=1" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;">
          </span>
          <p style="margin:0;font-size:14px;flex:1 1 auto;">
            Postcard sent to <strong><?= htmlspecialchars(person_display_name(['first_name' => $pc['recipient_first'], 'surname' => $pc['recipient_surname']]), ENT_QUOTES) ?></strong>
            <?= $pc['status'] === 'read' ? ' <span style="color:var(--ink-faint);font-size:12px;">(opened, not yet decided)</span>' : ' <span style="color:var(--ink-faint);font-size:12px;">(not yet opened)</span>' ?>
          </p>
        </div>
      </div>
    <?php endforeach; ?>

    <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>
  </div>
  <?php if ($openPostcard): ?>
  <div class="postcard-overlay" id="postcardReadOverlay">
    <div class="postcard-box">
      <button type="button" class="postcard-close" id="postcardReadClose" aria-label="Close">×</button>
      <h3 style="margin:0 0 14px;">A postcard from <?= htmlspecialchars(person_display_name(["first_name" => $openPostcard['sender_first'], "surname" => $openPostcard['sender_surname']]), ENT_QUOTES) ?></h3>
      <div class="postcard-flip-scene">
        <div class="postcard-flip-inner">
          <div class="postcard-face postcard-face-front">
            <div class="postcard-photo-mat">
              <img class="postcard-read-photo" src="/postcard_media.php?id=<?= (int) $openPostcard['postcard_id'] ?>" alt="">
            </div>
            <button type="button" class="postcard-flip-btn" style="position:absolute;top:8px;right:8px;z-index:1;">Read the message →</button>
          </div>
          <div class="postcard-face postcard-face-back">
            <div class="postcard-back-toolbar">
              <button type="button" class="postcard-flip-btn">← Back to photo</button>
            </div>
            <div class="postcard-back-content">
              <div class="postcard-read-message"><?= $openPostcard['message'] !== '' ? nl2br(htmlspecialchars($openPostcard['message'], ENT_QUOTES)) : '<span style="color:var(--ink-faint);">(no message)</span>' ?></div>
              <div class="postcard-back-address">
                <div class="postcard-stamp" aria-hidden="true">
                <svg viewBox="-2 -6 118 84" aria-hidden="true">
                        <defs>
                          <path id="pmArc" d="M 53 36 A 25 25 0 0 1 103 36"/>
                        </defs>
                        <rect x="2" y="3" width="54" height="64" rx="2" fill="#9A2A2A" stroke="#FBF8F1" stroke-width="2.5" stroke-dasharray="3.6 3.2"/>
                        <g transform="translate(15,13) scale(0.92)">
                          <path d="M16 7 C10 8 6.3 12.6 7.4 17.2 C11.2 16.5 14.7 12.6 16 7 Z" fill="#FBF8F1"/>
                          <path d="M16 7 C22 8 25.7 12.6 24.6 17.2 C20.8 16.5 17.3 12.6 16 7 Z" fill="#FBF8F1"/>
                          <line x1="16" y1="7.2" x2="16" y2="17" stroke="#9A2A2A" stroke-width="1.1" stroke-linecap="round"/>
                          <line x1="16" y1="17" x2="16" y2="23.2" stroke="#FBF8F1" stroke-width="2.4" stroke-linecap="round"/>
                          <line x1="16" y1="23.2" x2="12.6" y2="26.6" stroke="#FBF8F1" stroke-width="1.8" stroke-linecap="round"/>
                          <line x1="16" y1="23.2" x2="19.4" y2="26.6" stroke="#FBF8F1" stroke-width="1.8" stroke-linecap="round"/>
                        </g>
                        <text x="29" y="60" text-anchor="middle" font-family="Georgia, 'Times New Roman', serif" font-size="7" fill="#FBF8F1" letter-spacing="0.3">OURTHOLOGY</text>
                        <g opacity="0.74">
                          <circle cx="78" cy="36" r="25" fill="none" stroke="#29456e" stroke-width="1.6"/>
                          <circle cx="78" cy="36" r="19" fill="none" stroke="#29456e" stroke-width="1"/>
                          <text font-family="Georgia, 'Times New Roman', serif" font-size="5.2" fill="#29456e" letter-spacing="0.3">
                            <textPath href="#pmArc" startOffset="50%" text-anchor="middle">OURTHOLOGY P.O.</textPath>
                          </text>
                          <text x="78" y="39" text-anchor="middle" font-family="Georgia, 'Times New Roman', serif" font-size="6.2" fill="#29456e" letter-spacing="0.4"><?= htmlspecialchars(date('d M Y', strtotime($openPostcard['received_at'])), ENT_QUOTES) ?></text>
                          <line x1="78" y1="7" x2="78" y2="1" stroke="#29456e" stroke-width="1.2" stroke-linecap="round"/>
                          <line x1="60" y1="13" x2="57" y2="8" stroke="#29456e" stroke-width="1.2" stroke-linecap="round"/>
                          <line x1="96" y1="13" x2="99" y2="8" stroke="#29456e" stroke-width="1.2" stroke-linecap="round"/>
                        </g>
                      </svg>
              </div>
                <div class="postcard-address-lines">
                  <span class="postcard-address-line is-filled">To: <?= htmlspecialchars(person_display_name($me), ENT_QUOTES) ?></span>
                  <span class="postcard-address-line is-filled">From: <?= htmlspecialchars(person_display_name(["first_name" => $openPostcard['sender_first'], "surname" => $openPostcard['sender_surname']]), ENT_QUOTES) ?></span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php if (in_array($openPostcard['status'], ['pending', 'read'], true)): ?>
      <div class="postcard-read-footer">
        <form method="post" action="/postcard.php" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="discard">
          <input type="hidden" name="recipient_row_id" value="<?= (int) $openPostcard['recipient_row_id'] ?>">
          <button type="submit" class="btn-primary btn-small ghost">Discard</button>
        </form>
        <form method="post" action="/postcard.php" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="recipient_row_id" value="<?= (int) $openPostcard['recipient_row_id'] ?>">
          <button type="submit" class="btn-primary btn-small">Save to my timeline</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
    (function () {
      var overlay = document.getElementById("postcardReadOverlay");
      if (!overlay) return;
      function close() {
        overlay.remove();
        document.removeEventListener("keydown", onEsc);
      }
      function onEsc(evt) {
        if (evt.key === "Escape") close();
      }
      overlay.addEventListener("click", function (evt) {
        if (evt.target === overlay) close();
      });
      document.getElementById("postcardReadClose").addEventListener("click", close);
      document.addEventListener("keydown", onEsc);
      var inner = overlay.querySelector(".postcard-flip-inner");
      overlay.querySelectorAll(".postcard-flip-btn").forEach(function (btn) {
        btn.addEventListener("click", function () { inner.classList.toggle("is-flipped"); });
      });
    })();
  </script>
  <?php endif; ?>
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
