<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/entries.php';
require_once __DIR__ . '/includes/media.php';

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Location: /login.php');
    exit;
}
$pdo = ourthology_pdo();
$myPersonId = (int) $me['person_id'];
$myPerson = person_row($pdo, $myPersonId);
$myGroup = (int) $myPerson['family_group_id'];

$targetId = filter_var($_GET['person_id'] ?? $myPersonId, FILTER_VALIDATE_INT);
if ($targetId === false) {
    $targetId = $myPersonId;
}
$target = person_row($pdo, (int) $targetId);

if ($target === null) {
    http_response_code(404);
    exit('No such person.');
}

$isOwner = (int) $target['id'] === $myPersonId;
$sameGroup = (int) $target['family_group_id'] === $myGroup;

if (!$isOwner && !$sameGroup) {
    http_response_code(403);
    exit("You don't have access to this person's timeline.");
}

$notice = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete_entry') {
        $entryId = filter_var($_POST['entry_id'] ?? '', FILTER_VALIDATE_INT);
        if ($entryId !== false) {
            // Ownership check happens in the query itself, not just in the UI —
            // this only matches (and only deletes) a row that both has this id
            // AND belongs to me.
            $stmt = $pdo->prepare('SELECT id FROM timeline_entries WHERE id = :id AND person_id = :pid');
            $stmt->execute(['id' => $entryId, 'pid' => $myPersonId]);
            if ($stmt->fetch() ?: null) {
                $mediaStmt = $pdo->prepare('SELECT file_path FROM media WHERE timeline_entry_id = :eid');
                $mediaStmt->execute(['eid' => $entryId]);
                foreach ($mediaStmt->fetchAll() as $m) {
                    delete_media_file($m['file_path']);
                }
                $pdo->prepare('DELETE FROM timeline_entries WHERE id = :id')->execute(['id' => $entryId]);
                $notice = 'Entry deleted.';
            } else {
                $errors[] = "That entry doesn't exist or isn't yours to delete.";
            }
        }
    } elseif ($action === 'set_born') {
        // Only the owner can set their own birth date — the life-view zoom
        // and life-stage bands are personal, and this form only ever shows
        // on your own timeline anyway, but the check happens here too, not
        // just in the UI.
        if (!$isOwner) {
            $errors[] = 'You can only set your own birth date.';
        } else {
            $bDay   = trim((string) ($_POST['born_day'] ?? ''));
            $bMonth = trim((string) ($_POST['born_month'] ?? ''));
            $bYear  = trim((string) ($_POST['born_year'] ?? ''));
            if ($bDay === '' || $bMonth === '' || $bYear === '') {
                $errors[] = 'Fill in the day, month, and year.';
            } elseif (!ctype_digit($bDay) || !ctype_digit($bMonth) || !ctype_digit($bYear)
                || !checkdate((int) $bMonth, (int) $bDay, (int) $bYear)) {
                $errors[] = 'Enter a real date.';
            } else {
                $bornValue = sprintf('%04d-%02d-%02d', (int) $bYear, (int) $bMonth, (int) $bDay);
                $pdo->prepare('UPDATE persons SET born = :b WHERE id = :id')->execute(['b' => $bornValue, 'id' => $myPersonId]);
                $target = person_row($pdo, (int) $targetId);
                $notice = 'Birth date saved.';
            }
        }
    }
}

$entries = fetch_entries_for_person($pdo, (int) $target['id'], $isOwner);
$targetName = person_display_name($target);

/** occurred_on if set, otherwise the date the entry was created — same fallback the plain-list view used. */
function ourthology_entry_date(array $entry): string
{
    return $entry['occurred_on'] ?: substr((string) $entry['created_at'], 0, 10);
}

$jsEntries = [];
foreach ($entries as $entry) {
    $date = ourthology_entry_date($entry);
    $title = trim((string) ($entry['title'] ?? ''));
    if ($title === '') {
        $title = $entry['entry_type'] === 'diary'
            ? 'Diary — ' . date('j M Y', strtotime($date))
            : 'Untitled memory';
    }
    $media = [];
    foreach ($entry['media'] as $m) {
        $mime = (string) $m['mime_type'];
        $kind = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : 'file');
        $media[] = ['kind' => $kind, 'url' => '/media.php?id=' . (int) $m['id']];
    }
    $jsEntries[] = [
        'id'         => (string) $entry['id'],
        'date'       => $date,
        'title'      => $title,
        'thought'    => (string) ($entry['body'] ?? ''),
        'visibility' => $entry['visibility'],
        'type'       => $entry['entry_type'] === 'diary' ? 'diary' : 'memory',
        'media'      => $media,
    ];
}

// The "All life" zoom and the life-stage colour bands both need a birth date,
// but nothing in the app captures one yet (persons.born is only ever set via
// the small form below). Fall back gracefully rather than requiring it: the
// earliest thing on the timeline, or failing that, when the person record
// itself was created.
$bornIsReal = !empty($target['born']);
if ($bornIsReal) {
    $birthDate = $target['born'];
} elseif ($jsEntries) {
    $allDates = array_column($jsEntries, 'date');
    sort($allDates);
    $birthDate = $allDates[0];
} else {
    $birthDate = substr((string) $target['created_at'], 0, 10);
}
[$birthYear, $birthMonth, $birthDay] = array_map('intval', explode('-', $birthDate));

$entriesJson = json_encode($jsEntries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
// Guard against a literal "</script" inside a title/body from breaking out of
// the embedding <script> tag — "\/" is a valid JSON escape for "/", so this
// is invisible to JSON.parse.
$entriesJsonSafe = str_replace('</', '<\/', (string) $entriesJson);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($targetName, ENT_QUOTES) ?> — timeline — ourthology.com</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,500;0,600;0,700;0,800;1,600&family=Newsreader:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css">
<style>
  :root {
    --accent-bg: #F1DCDC;
    --accent-2: #1C3D5A;
    --accent-2-bg: #DCE6ED;
    --accent-2-glow: #3C6E93;
    --line-soft: #ECE4D2;
    --tape: #B58A3C;
    --fam1: #9A2A2A; --fam1-bg: #F1DCDC;
    --fam2: #B58A3C; --fam2-bg: #F1E4C9;
    --fam3: #3A6B4F; --fam3-bg: #D9E8DE;
    --fam4: #1C3D5A; --fam4-bg: #DCE6ED;
    --fam5: #8A7F6C; --fam5-bg: #E8E2D6;
    --fam6: #7A4B57; --fam6-bg: #E9DBDE;
    --shadow: 0 1px 2px rgba(26,23,20,0.08), 0 10px 26px -14px rgba(26,23,20,0.28);
  }
  body { align-items: flex-start; }
  .wide { max-width: 1220px; }
  .nav { display:flex; gap:10px 16px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin: 18px 0 4px; }
  .nav-links { display:flex; gap:10px; flex-wrap:wrap; }
  .nav a { font-size:13px; padding:7px 12px; border-radius:999px; border:1px solid var(--line); color:var(--ink-soft); text-decoration:none; background:#fff; }
  .whoami { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12.5px; color:var(--ink-faint); }
  .whoami strong { color:var(--ink-soft); font-weight:600; }
  .whoami form { display:inline; }
  .whoami .linklet { font-size:12.5px; background:transparent; border:none; color:var(--accent); cursor:pointer; padding:0; text-decoration:underline; font-family:inherit; }
  .born-prompt { display:flex; align-items:center; gap:10px; flex-wrap:wrap; background:var(--paper-2); border:1px solid var(--line); border-radius:14px; padding:10px 14px; margin:14px 0 4px; font-size:13.5px; color:var(--ink-soft); }
  .born-prompt form { display:flex; align-items:flex-end; gap:8px; }
  .row-3 { display:grid; grid-template-columns: 4em 4em 5.5em; gap:8px; }
  .row-3 input { text-align:center; padding:7px 6px; border:1px solid var(--line); border-radius:6px; font-size:14px; background:#fff; color:var(--ink); }
  .date-slot span { display:block; font-size:10px; color:var(--ink-faint); text-align:center; margin-top:2px; }
  .btn-small { width:auto; margin:0; padding:8px 16px; font-size:13.5px; }
  /* Used on the viewer's Edit/Delete/Close row — a plain <button> already
     picks up a passable boxed look for free from the browser's own default
     button chrome, but the "Edit" link added alongside them needs its own
     real rule to match rather than rendering as bare text. */
  .btn-ghost { font: inherit; font-size:13.5px; padding:7px 14px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--ink-soft); cursor:pointer; }
  .btn-ghost:hover { border-color:var(--ink-faint); }

  h1, h2, h3, .display, .card-title, .viewer-title-view, .segmented button, .zoom-pill, .btn-primary, .rail-heading h2 {
    font-family: "Fraunces", Georgia, serif;
  }
  .mono { font-variant-numeric: tabular-nums; letter-spacing: 0.01em; }

  /* ---------- controls ---------- */
  .controls { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin: 14px 0 18px; }
  .segmented { display:inline-flex; background:var(--paper-2); border:1px solid var(--line); border-radius:999px; padding:4px; gap:2px; box-shadow:var(--shadow); }
  .segmented button { border:none; background:transparent; color:var(--ink-soft); font-weight:700; font-size:13px; padding:7px 13px; border-radius:999px; cursor:pointer; transition:background .15s ease,color .15s ease,transform .1s ease; white-space:nowrap; }
  .segmented button:hover { color:var(--ink); }
  .segmented button.active { background:var(--accent); color:var(--on-accent); transform:scale(1.04); }
  .zoom-pill { display:inline-flex; align-items:center; gap:6px; background:var(--accent-bg); color:var(--accent); border:none; border-radius:999px; padding:7px 12px 7px 13px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; }
  .zoom-pill[hidden] { display:none; }
  .zoom-pill:hover { filter:brightness(0.97); }
  .zoom-pill svg { width:14px; height:14px; flex:0 0 auto; }
  .zoom-pill .zoom-pill-x { font-size:16px; line-height:1; opacity:.75; margin-left:2px; }

  /* ---------- timeline canvas ---------- */
  .arc-wrap { position:relative; background:var(--paper-2); border:1px solid var(--line); border-radius:24px; box-shadow:var(--shadow); overflow:hidden; margin-bottom:26px; padding:8px; }
  .arc-wrap svg { display:block; cursor:crosshair; user-select:none; -webkit-user-select:none; touch-action:none; }
  .selection-rect { fill:var(--accent-glow); fill-opacity:.16; stroke:var(--accent); stroke-width:1.5; stroke-dasharray:4 3; pointer-events:none; display:none; }
  .selection-rect.active { display:block; }
  .arc-wrap.layout-river svg { width:100%; height:auto; }
  .arc-wrap.layout-rings svg, .arc-wrap.layout-spiral svg { width:100%; max-width:560px; height:auto; margin:0 auto; }

  .river-scrollbar { position:relative; height:10px; margin:10px 6px 4px; background:var(--line); border-radius:999px; cursor:pointer; display:none; }
  .arc-wrap.layout-river .river-scrollbar { display:block; }
  .river-scrollbar-thumb { position:absolute; top:0; height:100%; min-width:28px; background:var(--accent); border-radius:999px; cursor:grab; opacity:.8; touch-action:none; transition:opacity .15s; }
  .river-scrollbar-thumb:hover { opacity:1; }
  .river-scrollbar-thumb.dragging { cursor:grabbing; opacity:1; }

  .node-tooltip { position:absolute; left:0; top:0; transform:translate(-50%, calc(-100% - 14px)) scale(.92); background:var(--ink); border-radius:10px; padding:8px 12px; font-size:12.5px; line-height:1.35; box-shadow:var(--shadow); pointer-events:none; opacity:0; white-space:nowrap; max-width:240px; z-index:30; transition:opacity .12s ease, transform .12s ease; }
  .node-tooltip.visible { opacity:1; transform:translate(-50%, calc(-100% - 14px)) scale(1); }
  .node-tooltip::after { content:""; position:absolute; left:50%; top:100%; transform:translateX(-50%); border:6px solid transparent; border-top-color:var(--ink); }
  .node-tooltip-date { color:var(--accent-glow); font-weight:700; font-size:11px; letter-spacing:.02em; margin-bottom:2px; }
  .node-tooltip-title { font-weight:600; color:var(--paper); white-space:normal; }

  .stage-label { fill:var(--ink-faint); font-size:12px; font-weight:700; letter-spacing:.02em; }
  .stage-label-ring { paint-order:stroke; stroke:var(--paper); stroke-width:3px; stroke-linejoin:round; }
  .stage-arc { fill:none; stroke-width:24; opacity:.4; stroke-linecap:round; }
  .stage-ring-arc { fill:none; stroke-width:34; opacity:.5; stroke-linecap:butt; }
  .tick-label { fill:var(--ink-faint); font-size:12.5px; font-weight:600; }
  .tick-line { stroke:var(--line); stroke-width:1.5; }
  .baseline { stroke:var(--line); stroke-width:1.5; }
  .path-guide { fill:none; stroke:var(--ink-faint); stroke-width:2; stroke-linecap:round; stroke-dasharray:1 8; opacity:.55; }
  .path-guide.lived { stroke:var(--accent); stroke-width:3; stroke-linecap:round; stroke-dasharray:none; opacity:.9; }
  .ring-guide { fill:none; stroke:var(--line); stroke-width:1.5; }
  .ring-guide.lived { stroke:var(--accent); stroke-width:2; opacity:.8; }
  .ring-guide.forming { stroke:var(--accent-glow); stroke-width:2.5; stroke-dasharray:1 5; stroke-linecap:round; opacity:.95; }
  .ring-arc { fill:none; stroke:var(--accent); stroke-width:3; stroke-linecap:round; opacity:.9; }
  .ring-arc-rest { fill:none; stroke:var(--ink-faint); stroke-width:2; stroke-linecap:round; stroke-dasharray:1 8; opacity:.5; }
  .today-glow { opacity:.9; }
  .today-ring { fill:none; stroke:var(--accent-glow); stroke-width:1.5; opacity:.55; }

  .node { cursor:pointer; transition:filter .15s ease; }
  .node .ring { fill:var(--card); stroke-width:3; }
  .node.visibility-public .ring { stroke:var(--accent); }
  .node.visibility-private .ring { stroke:var(--accent-2); }
  .node .lock { fill:var(--accent-2); }
  .node.type-diary .ring { stroke-dasharray:3 2.4; }
  .node:hover .ring, .node.highlight .ring { stroke-width:4.5; }
  .node:hover { filter:brightness(1.05); }

  /* ---------- card rail ---------- */
  .rail-heading { display:flex; align-items:baseline; justify-content:space-between; margin-bottom:10px; }
  .rail-heading h2 { font-size:17px; font-style:italic; font-weight:700; margin:0; }
  .rail-heading .count { font-size:13px; color:var(--ink-faint); font-weight:600; }
  .rail { display:flex; gap:20px; overflow-x:auto; padding:10px 4px 18px; scroll-snap-type:x proximity; }
  .rail::-webkit-scrollbar { height:8px; }
  .rail::-webkit-scrollbar-thumb { background:var(--line); border-radius:8px; }
  .mem-card { scroll-snap-align:start; flex:0 0 234px; position:relative; background:var(--paper-2); border:1px solid var(--line); border-radius:18px; box-shadow:var(--shadow); overflow:visible; transition:transform .18s ease, box-shadow .18s ease, outline .15s ease; outline:2px solid transparent; outline-offset:2px; cursor:pointer; }
  .mem-card:hover, .mem-card.highlight { transform:rotate(0deg) translateY(-4px) scale(1.015) !important; box-shadow:0 4px 8px rgba(26,23,20,.1), 0 18px 32px -14px rgba(26,23,20,.35); }
  .mem-card.highlight { outline-color:var(--accent-glow); }
  .card-tape { position:absolute; top:-10px; left:50%; width:54px; height:22px; margin-left:-27px; background:var(--tape); opacity:.85; border-radius:3px; box-shadow:0 1px 2px rgba(0,0,0,.12); transform:rotate(-3deg); }
  .card-media { height:122px; position:relative; border-radius:18px 18px 0 0; overflow:hidden; display:flex; align-items:center; justify-content:center; color:rgba(74,68,61,.4); background:linear-gradient(135deg, var(--stage-a, var(--accent-bg)), var(--stage-b, var(--paper))); }
  .card-media img { width:100%; height:100%; object-fit:cover; border-radius:18px 18px 0 0; }
  .card-media svg { width:34px; height:34px; opacity:.55; }
  .card-media-count { position:absolute; bottom:6px; right:6px; background:rgba(26,23,20,.75); color:#fff; font-size:10px; font-weight:800; padding:2px 7px; border-radius:999px; }
  .card-body { padding:14px 15px 16px; }
  .card-date { font-size:11.5px; color:var(--ink-faint); margin-bottom:4px; font-weight:700; letter-spacing:.02em; }
  .card-title { font-size:15.5px; font-weight:800; margin-bottom:5px; }
  .card-thought { font-size:13.5px; color:var(--ink-soft); line-height:1.5; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
  .card-foot { display:flex; align-items:center; gap:6px; margin-top:10px; }
  .pill { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:700; letter-spacing:.01em; padding:4px 10px 4px 7px; border-radius:20px; }
  .pill svg { width:11px; height:11px; }
  .pill.public { background:var(--accent-bg); color:var(--accent); }
  .pill.private { background:var(--accent-2-bg); color:var(--accent-2); }
  .pill.diary { background:var(--fam2-bg); color:var(--fam2); }
  .empty-state { text-align:center; padding:34px 20px; color:var(--ink-faint); font-size:14px; border:2px dashed var(--line); border-radius:16px; }

  /* ---------- memory viewer (read-only) ---------- */
  .modal-scrim { position:fixed; inset:0; background:rgba(20,16,12,.55); display:flex; align-items:center; justify-content:center; padding:20px; z-index:50; opacity:0; pointer-events:none; transition:opacity .15s ease; }
  .modal-scrim.open { opacity:1; pointer-events:auto; }
  .viewer-modal { background:var(--card); border-radius:22px; max-width:980px; width:94vw; height:86vh; max-height:860px; box-shadow:0 24px 60px -20px rgba(26,23,20,.45); transform:translateY(10px) scale(.98); transition:transform .18s ease; display:flex; flex-direction:column; overflow:hidden; }
  .modal-scrim.open .viewer-modal { transform:translateY(0) scale(1); }
  .modal-head { display:flex; align-items:center; justify-content:space-between; padding:20px 22px 4px; }
  .modal-head h3 { font-size:21px; font-style:italic; margin:0; }
  .modal-close { border:none; background:transparent; color:var(--ink-faint); font-size:22px; cursor:pointer; line-height:1; padding:4px; border-radius:999px; }
  .modal-close:hover { color:var(--ink); background:var(--line-soft); }
  .viewer-body { display:grid; grid-template-columns:1fr 320px; flex:1; min-height:0; }
  .viewer-media { background:var(--paper); display:flex; align-items:center; justify-content:center; overflow:hidden; min-height:0; position:relative; border:1px solid var(--line); border-radius:14px; margin:0 0 20px 20px; }
  .viewer-media img, .viewer-media video { max-width:100%; max-height:100%; object-fit:contain; }
  .viewer-media video { background:#000; width:100%; height:100%; }
  .viewer-empty-media { display:flex; flex-direction:column; align-items:center; gap:10px; color:var(--ink-faint); font-size:13.5px; text-align:center; padding:20px; }
  .viewer-empty-media svg { width:44px; height:44px; }
  .viewer-media-single { width:100%; height:100%; display:flex; align-items:center; justify-content:center; }
  .viewer-media-single a { display:contents; }
  .viewer-media-grid { width:100%; height:100%; display:grid; grid-template-columns:repeat(auto-fill, minmax(140px,1fr)); grid-auto-rows:minmax(120px,1fr); gap:10px; padding:14px; overflow-y:auto; align-content:start; }
  .viewer-media-grid-tile { position:relative; border-radius:10px; overflow:hidden; background:var(--paper); border:1px solid var(--line); display:flex; align-items:center; justify-content:center; transition:border-color .15s ease; }
  .viewer-media-grid-tile:hover { border-color:var(--accent); }
  .viewer-media-grid-tile img { width:100%; height:100%; object-fit:cover; }
  .viewer-media .media-tile { width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; color:var(--ink-faint); }
  .viewer-media .media-tile svg { width:60px; height:60px; }
  .viewer-media .media-tile-badge { font-size:13px; font-weight:800; letter-spacing:.04em; color:var(--ink-soft); background:rgba(255,255,255,.6); border-radius:5px; padding:3px 10px; }
  .viewer-details { padding:22px 24px 12px; display:flex; flex-direction:column; gap:14px; overflow-y:auto; }
  .viewer-title-view { font-family:"Fraunces", Georgia, serif; font-size:22px; font-weight:700; line-height:1.25; }
  .viewer-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .viewer-date { color:var(--ink-faint); font-weight:700; font-size:13px; }
  .viewer-thought-view { font-size:14.5px; line-height:1.65; color:var(--ink-soft); white-space:pre-wrap; margin:0; }
  .viewer-thought-view:empty::before { content:"No thoughts written yet."; color:var(--ink-faint); font-style:italic; }
  .viewer-actions { display:flex; justify-content:space-between; align-items:center; padding:14px 24px; border-top:1px solid var(--line); }

  @media (max-width: 760px) {
    .viewer-modal { width:100vw; height:100vh; max-height:none; border-radius:0; }
    .viewer-body { grid-template-columns:1fr; grid-template-rows:42vh 1fr; overflow-y:auto; }
    .viewer-media { min-height:220px; margin:16px 16px 0 16px; }
  }
  @media (max-width: 620px) {
    .row-3 { grid-template-columns:1fr; }
    .mem-card { flex-basis:200px; }
  }
</style>
</head>
<body>
  <div class="card wide">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <div class="nav">
      <div class="nav-links">
        <a href="/dashboard.php">Dashboard</a>
        <a href="/tree.php">My tree</a>
        <a href="/edit_person.php">Edit a person</a>
        <?php if ($isOwner): ?><a href="/add_entry.php">+ Add a memory</a><?php endif; ?>
      </div>
      <div class="whoami">
        Signed in as <strong><?= htmlspecialchars($me['email'], ENT_QUOTES) ?></strong>
        <form method="post" action="/logout.php"><button type="submit" class="linklet">Log out</button></form>
      </div>
    </div>

    <h3 style="margin-bottom:2px;">
      <?= $isOwner ? 'My timeline' : htmlspecialchars($targetName, ENT_QUOTES) . "'s timeline" ?>
    </h3>
    <?php if (!$isOwner): ?>
      <p style="font-size:13px;color:var(--ink-faint);margin-top:0;">Showing public entries only.</p>
    <?php endif; ?>

    <?php if ($notice): ?><p style="color:var(--accent);font-weight:600;"><?= htmlspecialchars($notice, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($errors): ?>
      <div class="error"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($isOwner && !$bornIsReal): ?>
      <div class="born-prompt">
        <span>Add your birth date for a more accurate "All life" view:</span>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_born">
          <div class="row-3">
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="born_day" placeholder="DD">
              <span>Day</span>
            </div>
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="born_month" placeholder="MM">
              <span>Month</span>
            </div>
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" name="born_year" placeholder="YYYY">
              <span>Year</span>
            </div>
          </div>
          <button type="submit" class="btn-primary btn-small">Save</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="controls">
      <div class="segmented" id="layoutToggle" role="group" aria-label="Visual style">
        <button data-layout="river" class="active">River</button>
        <button data-layout="rings">Rings</button>
        <button data-layout="spiral">Spiral</button>
      </div>
      <div class="segmented" id="zoomToggle" role="group" aria-label="Zoom">
        <button data-zoom="life" class="active">All life</button>
        <button data-zoom="decade">Decade</button>
        <button data-zoom="year">This year</button>
      </div>
      <button class="zoom-pill" id="customZoomPill" type="button" hidden>
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8.5" cy="8.5" r="5.5"/><path d="M16 16l-3.8-3.8" stroke-linecap="round"/></svg>
        <span id="customZoomLabel"></span>
        <span class="zoom-pill-x">×</span>
      </button>
    </div>

    <div class="arc-wrap layout-river" id="arcWrap">
      <svg id="arcSvg" viewBox="0 0 1180 400" preserveAspectRatio="xMidYMid meet">
        <defs>
          <clipPath id="thumbClip"><circle cx="0" cy="0" r="15"/></clipPath>
        </defs>
        <g id="stageBands"></g>
        <line class="baseline" id="baselineRef" x1="60" y1="310" x2="1120" y2="310"/>
        <g id="ticks"></g>
        <g id="ringGuides"></g>
        <path id="pathFuture" class="path-guide"></path>
        <path id="pathLived" class="path-guide lived"></path>
        <g id="todayMarker"></g>
        <g id="nodes"></g>
        <rect id="selectionRect" class="selection-rect" x="0" y="-24" width="0" height="424"></rect>
      </svg>
      <div class="river-scrollbar" id="riverScrollbar">
        <div class="river-scrollbar-thumb" id="riverScrollThumb"></div>
      </div>
      <div class="node-tooltip" id="nodeTooltip">
        <div class="node-tooltip-date mono" id="nodeTooltipDate"></div>
        <div class="node-tooltip-title" id="nodeTooltipTitle"></div>
      </div>
    </div>

    <div class="rail-heading">
      <h2>Memories in view</h2>
      <span class="count mono" id="railCount"></span>
    </div>
    <div class="rail" id="rail"></div>
  </div>

  <div class="modal-scrim" id="viewerScrim">
    <div class="viewer-modal">
      <div class="modal-head">
        <h3 id="viewerHeaderTitle">Memory</h3>
        <button class="modal-close" id="viewerClose" aria-label="Close">×</button>
      </div>
      <div class="viewer-body">
        <div class="viewer-media" id="viewerMedia"></div>
        <div class="viewer-details">
          <div class="viewer-title-view" id="viewerTitleView"></div>
          <div class="viewer-meta" id="viewerMetaView">
            <span class="viewer-date mono" id="viewerDateView"></span>
            <span id="viewerPillView"></span>
          </div>
          <p class="viewer-thought-view" id="viewerThoughtView"></p>
        </div>
      </div>
      <div class="viewer-actions">
        <a href="#" id="viewerEditLink" class="btn-ghost" style="text-decoration:none;display:inline-block;" hidden>Edit</a>
        <form method="post" id="viewerDeleteForm" onsubmit="return confirm('Delete this entry?');" style="margin:0;" hidden>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_entry">
          <input type="hidden" name="entry_id" id="viewerDeleteEntryId" value="">
          <button type="submit" class="btn-ghost" style="color:var(--accent);border-color:var(--accent);">Delete</button>
        </form>
        <button class="btn-ghost" id="viewerCloseBtn" style="margin-left:auto;">Close</button>
      </div>
    </div>
  </div>

  <script id="entriesData" type="application/json"><?= $entriesJsonSafe ?></script>
  <script>
  (function () {
    "use strict";

    var svgNS = "http://www.w3.org/2000/svg";
    var IS_OWNER = <?= $isOwner ? 'true' : 'false' ?>;
    var BIRTH = new Date(<?= (int) $birthYear ?>, <?= (int) $birthMonth - 1 ?>, <?= (int) $birthDay ?>);
    var BUFFER_YEARS = 15;
    var YEAR_MS = 365.25 * 86400000;

    var RIVER_W = 1180, RIVER_H = 400, RIVER_PAD = 60, RIVER_BASE = 170;
    var RADIAL_SIZE = 760, RADIAL_CX = 380, RADIAL_CY = 380, RADIAL_MAXR = 290;

    var entries = JSON.parse(document.getElementById("entriesData").textContent || "[]");

    var stages = [
      { name: "Infancy", from: 0, to: 2 },
      { name: "Childhood", from: 2, to: 12 },
      { name: "Adolescence", from: 12, to: 18 },
      { name: "Early adulthood", from: 18, to: 30 },
      { name: "Adulthood", from: 30, to: 50 },
      { name: "Midlife", from: 50, to: 65 },
      { name: "Later life", from: 65, to: 90 }
    ];

    var DIARY_PILL_HTML = '<span class="pill diary"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 4.3c1.8-.9 3.6-.9 5.4 0v11c-1.8-.9-3.6-.9-5.4 0v-11ZM15.4 4.3c-1.8-.9-3.6-.9-5.4 0v11c1.8-.9 3.6-.9 5.4 0v-11Z" stroke-linejoin="round"/></svg>Diary</span>';
    var VIDEO_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2.5" y="4.5" width="11" height="11" rx="1.5"/><path d="M13.5 8.2 17 6v8l-3.5-2.2" stroke-linejoin="round"/></svg>';
    var DOC_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 2.5h6.5L15 6v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1Z" stroke-linejoin="round"/><path d="M11 2.5V6h4" stroke-linejoin="round"/></svg>';

    var state = { zoom: "life", layout: "river", highlightId: null, customRange: null };

    function findEntry(id) {
      for (var i = 0; i < entries.length; i++) { if (entries[i].id === id) return entries[i]; }
      return null;
    }

    function addYears(d, n) { var r = new Date(d); r.setFullYear(r.getFullYear() + n); return r; }
    function clamp01(v) { return Math.max(0, Math.min(1, v)); }

    function getRange() {
      var now = new Date();
      if (state.customRange) return { start: state.customRange.start, end: state.customRange.end, mode: "custom" };
      if (state.zoom === "life") return { start: BIRTH, end: addYears(now, BUFFER_YEARS), mode: "life" };
      if (state.zoom === "decade") return { start: addYears(now, -5), end: addYears(now, 5), mode: "decade" };
      var jan1 = new Date(now.getFullYear(), 0, 1), jan1next = new Date(now.getFullYear() + 1, 0, 1);
      return { start: jan1, end: jan1next, mode: "year" };
    }

    function fullLifeRange() {
      return { start: BIRTH, end: addYears(new Date(), BUFFER_YEARS) };
    }

    function t(dateObj, range) {
      return clamp01((dateObj.getTime() - range.start.getTime()) / (range.end.getTime() - range.start.getTime()));
    }
    function fracToDate(f, range) {
      return new Date(range.start.getTime() + f * (range.end.getTime() - range.start.getTime()));
    }
    function dayOfYearFrac(d) {
      var jan1 = new Date(d.getFullYear(), 0, 1), next = new Date(d.getFullYear() + 1, 0, 1);
      return (d.getTime() - jan1.getTime()) / (next.getTime() - jan1.getTime());
    }

    function placeFrac(f, range, layout) {
      if (layout === "river") {
        var x = RIVER_PAD + f * (RIVER_W - 2 * RIVER_PAD);
        var y = RIVER_BASE
          + 46 * Math.sin(f * Math.PI * 2 * 1.3 + 0.6)
          + 16 * Math.sin(f * Math.PI * 2 * 2.7 + 2.1);
        return { x: x, y: y };
      }
      if (layout === "rings") {
        var radius, angle;
        if (range.mode === "year") {
          radius = RADIAL_MAXR * 0.72;
          angle = f * Math.PI * 2 - Math.PI / 2;
        } else {
          var d = fracToDate(f, range);
          radius = f * RADIAL_MAXR;
          angle = dayOfYearFrac(d) * Math.PI * 2 - Math.PI / 2;
        }
        return { x: RADIAL_CX + radius * Math.cos(angle), y: RADIAL_CY + radius * Math.sin(angle) };
      }
      var turns = 3;
      var radius2 = 34 + f * (RADIAL_MAXR - 34);
      var angle2 = f * Math.PI * 2 * turns - Math.PI / 2;
      return { x: RADIAL_CX + radius2 * Math.cos(angle2), y: RADIAL_CY + radius2 * Math.sin(angle2) };
    }
    function place(date, range, layout) { return placeFrac(t(date, range), range, layout); }

    function posToFrac(x, y, range, layout) {
      if (layout === "river") return clamp01((x - RIVER_PAD) / (RIVER_W - 2 * RIVER_PAD));
      var dx = x - RADIAL_CX, dy = y - RADIAL_CY;
      var radius = Math.sqrt(dx * dx + dy * dy);
      if (layout === "rings" && range.mode === "year") {
        var angle = Math.atan2(dy, dx) + Math.PI / 2;
        if (angle < 0) angle += Math.PI * 2;
        return clamp01(angle / (Math.PI * 2));
      }
      if (layout === "rings") return clamp01(radius / RADIAL_MAXR);
      return clamp01((radius - 34) / (RADIAL_MAXR - 34));
    }

    function pathD(range, layout, upTo) {
      var steps = layout === "river" ? 60 : 220;
      var pts = [];
      for (var i = 0; i <= steps; i++) {
        var f = i / steps;
        if (upTo !== undefined && f > upTo) break;
        var p = placeFrac(f, range, layout);
        pts.push((i === 0 ? "M" : "L") + p.x.toFixed(1) + "," + p.y.toFixed(1));
      }
      return pts.join(" ");
    }

    function stageIndexForDate(d) {
      var age = (d.getTime() - BIRTH.getTime()) / YEAR_MS;
      if (age < 0) return -1;
      for (var i = 0; i < stages.length; i++) {
        if (age < stages[i].to) return i;
      }
      return stages.length - 1;
    }

    function renderStageBands(range, layout) {
      function overlaps(s) {
        return addYears(BIRTH, s.from) < range.end && addYears(BIRTH, s.to) > range.start;
      }

      if (layout === "river") {
        stages.forEach(function (s, i) {
          if (!overlaps(s)) return;
          var f0 = t(addYears(BIRTH, s.from), range), f1 = t(addYears(BIRTH, s.to), range);
          var p0 = placeFrac(f0, range, layout), p1 = placeFrac(f1, range, layout);
          var w = p1.x - p0.x;
          if (w < 2) return;
          var rect = document.createElementNS(svgNS, "rect");
          rect.setAttribute("x", p0.x.toFixed(1));
          rect.setAttribute("y", "16");
          rect.setAttribute("width", w.toFixed(1));
          rect.setAttribute("height", "294");
          rect.setAttribute("fill", "var(--fam" + ((i % 6) + 1) + "-bg)");
          rect.setAttribute("fill-opacity", "0.45");
          gStages.appendChild(rect);
          if (w > 46) {
            var label = document.createElementNS(svgNS, "text");
            label.setAttribute("x", ((p0.x + p1.x) / 2).toFixed(1));
            label.setAttribute("y", "346");
            label.setAttribute("text-anchor", "middle");
            label.setAttribute("class", "stage-label");
            label.textContent = s.name;
            gStages.appendChild(label);
          }
        });
        return;
      }

      if (layout === "spiral") {
        stages.forEach(function (s, i) {
          if (!overlaps(s)) return;
          var f0 = t(addYears(BIRTH, s.from), range), f1 = t(addYears(BIRTH, s.to), range);
          if (f1 - f0 < 0.004) return;
          var steps = Math.max(2, Math.round((f1 - f0) * 240));
          var pts = [];
          for (var k = 0; k <= steps; k++) {
            var f = f0 + (f1 - f0) * (k / steps);
            var p = placeFrac(f, range, layout);
            pts.push((k === 0 ? "M" : "L") + p.x.toFixed(1) + "," + p.y.toFixed(1));
          }
          var path = document.createElementNS(svgNS, "path");
          path.setAttribute("d", pts.join(" "));
          path.setAttribute("class", "stage-arc");
          path.setAttribute("stroke", "var(--fam" + ((i % 6) + 1) + ")");
          gStages.appendChild(path);
        });
        return;
      }

      // rings
      if (range.mode === "year") {
        var cuts = [range.start];
        stages.forEach(function (s) {
          [s.from, s.to].forEach(function (age) {
            var d = addYears(BIRTH, age);
            if (d > range.start && d < range.end) cuts.push(d);
          });
        });
        cuts.push(range.end);
        cuts.sort(function (a, b) { return a - b; });
        cuts = cuts.filter(function (d, idx) { return idx === 0 || d.getTime() !== cuts[idx - 1].getTime(); });
        for (var ci = 0; ci < cuts.length - 1; ci++) {
          var segStart = cuts[ci], segEnd = cuts[ci + 1];
          var segMid = new Date((segStart.getTime() + segEnd.getTime()) / 2);
          var stageIdx = stageIndexForDate(segMid);
          if (stageIdx === -1) continue;
          var a0 = t(segStart, range) * Math.PI * 2 - Math.PI / 2;
          var a1 = t(segEnd, range) * Math.PI * 2 - Math.PI / 2;
          if (a1 - a0 < 0.002) continue;
          var r = RADIAL_MAXR * 0.72;
          var arc;
          if (cuts.length === 2) {
            arc = document.createElementNS(svgNS, "circle");
            arc.setAttribute("cx", RADIAL_CX);
            arc.setAttribute("cy", RADIAL_CY);
            arc.setAttribute("r", r.toFixed(1));
          } else {
            var x0 = RADIAL_CX + r * Math.cos(a0), y0 = RADIAL_CY + r * Math.sin(a0);
            var x1 = RADIAL_CX + r * Math.cos(a1), y1 = RADIAL_CY + r * Math.sin(a1);
            var large = (a1 - a0) > Math.PI ? 1 : 0;
            arc = document.createElementNS(svgNS, "path");
            arc.setAttribute("d", "M" + x0.toFixed(1) + "," + y0.toFixed(1) + " A" + r.toFixed(1) + "," + r.toFixed(1) + " 0 " + large + " 1 " + x1.toFixed(1) + "," + y1.toFixed(1));
          }
          arc.setAttribute("class", "stage-ring-arc");
          arc.setAttribute("stroke", "var(--fam" + ((stageIdx % 6) + 1) + "-bg)");
          gStages.appendChild(arc);
        }
        return;
      }

      var relevant = [];
      stages.forEach(function (s, i) { if (overlaps(s)) relevant.push({ s: s, i: i }); });
      relevant.sort(function (a, b) { return b.s.to - a.s.to; });
      var g = document.createElementNS(svgNS, "g");
      g.setAttribute("opacity", "0.45");
      relevant.forEach(function (item) {
        var f1 = t(addYears(BIRTH, item.s.to), range);
        var r1 = f1 * RADIAL_MAXR;
        if (r1 < 2) return;
        var circle = document.createElementNS(svgNS, "circle");
        circle.setAttribute("cx", RADIAL_CX);
        circle.setAttribute("cy", RADIAL_CY);
        circle.setAttribute("r", r1.toFixed(1));
        circle.setAttribute("fill", "var(--fam" + ((item.i % 6) + 1) + "-bg)");
        g.appendChild(circle);
      });
      gStages.appendChild(g);

      relevant.forEach(function (item) {
        var f0 = t(addYears(BIRTH, item.s.from), range), f1 = t(addYears(BIRTH, item.s.to), range);
        if (f1 - f0 < 0.03) return;
        var rMid = ((f0 + f1) / 2) * RADIAL_MAXR;
        if (rMid < 10) return;
        var label = document.createElementNS(svgNS, "text");
        label.setAttribute("x", RADIAL_CX);
        label.setAttribute("y", (RADIAL_CY - rMid + 4).toFixed(1));
        label.setAttribute("text-anchor", "middle");
        label.setAttribute("class", "stage-label stage-label-ring");
        label.textContent = item.s.name;
        gStages.appendChild(label);
      });
    }

    function fmtDate(d) {
      return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
    }
    function fmtShort(d, mode) {
      if (mode === "year") return d.toLocaleDateString(undefined, { month: "short" });
      if (mode === "life") {
        var age = Math.round((d.getTime() - BIRTH.getTime()) / YEAR_MS);
        return age === 0 ? "birth" : age + "y";
      }
      return String(d.getFullYear());
    }
    function tickDatesFor(range) {
      var out = [];
      if (range.mode === "life") {
        var maxAge = Math.ceil((range.end.getTime() - BIRTH.getTime()) / YEAR_MS);
        for (var age = 0; age <= maxAge; age += 10) out.push(addYears(BIRTH, age));
      } else if (range.mode === "decade") {
        var y0 = range.start.getFullYear();
        for (var yy = y0; yy <= range.end.getFullYear(); yy++) out.push(new Date(yy, 0, 1));
      } else {
        for (var m = 0; m < 12; m++) out.push(new Date(range.start.getFullYear(), m, 1));
      }
      return out;
    }
    function decadeYearTicksFor(range) {
      var out = [];
      var firstDecade = Math.ceil(range.start.getFullYear() / 10) * 10;
      for (var y = firstDecade; y <= range.end.getFullYear(); y += 10) {
        var d = new Date(y, 0, 1);
        if (d >= range.start && d <= range.end) out.push(d);
      }
      return out;
    }
    function niceTicksFor(range) {
      var spanMs = range.end.getTime() - range.start.getTime();
      var spanYears = spanMs / YEAR_MS;
      var out = [];
      if (spanYears > 6) {
        var stepYears = spanYears > 60 ? 20 : spanYears > 30 ? 10 : spanYears > 15 ? 5 : spanYears > 6 ? 2 : 1;
        var firstYear = Math.ceil(range.start.getFullYear() / stepYears) * stepYears;
        for (var y = firstYear; y <= range.end.getFullYear(); y += stepYears) {
          var d = new Date(y, 0, 1);
          if (d >= range.start && d <= range.end) out.push({ date: d, label: String(y) });
        }
      } else if (spanYears > 0.6) {
        var stepMonths = spanYears > 3 ? 6 : spanYears > 1.2 ? 3 : 1;
        var cursor = new Date(range.start.getFullYear(), range.start.getMonth(), 1);
        while (cursor <= range.end) {
          if (cursor >= range.start) {
            var lbl = cursor.toLocaleDateString(undefined, { month: "short" }) + (cursor.getMonth() === 0 ? " '" + String(cursor.getFullYear()).slice(2) : "");
            out.push({ date: new Date(cursor), label: lbl });
          }
          cursor = new Date(cursor.getFullYear(), cursor.getMonth() + stepMonths, 1);
        }
      } else {
        var spanDays = spanMs / 86400000;
        var stepDays = spanDays > 60 ? 14 : spanDays > 21 ? 7 : spanDays > 6 ? 2 : 1;
        var dayCursor = new Date(range.start.getFullYear(), range.start.getMonth(), range.start.getDate());
        while (dayCursor <= range.end) {
          if (dayCursor >= range.start) {
            out.push({ date: new Date(dayCursor), label: dayCursor.toLocaleDateString(undefined, { month: "short", day: "numeric" }) });
          }
          dayCursor = new Date(dayCursor.getFullYear(), dayCursor.getMonth(), dayCursor.getDate() + stepDays);
        }
      }
      if (out.length > 12) {
        var stride = Math.ceil(out.length / 9);
        out = out.filter(function (_, i) { return i % stride === 0; });
      }
      return out;
    }
    function fmtRangeLabel(range) {
      var sameYear = range.start.getFullYear() === range.end.getFullYear();
      var aLabel = range.start.toLocaleDateString(undefined, sameYear ? { month: "short", day: "numeric" } : { month: "short", day: "numeric", year: "numeric" });
      var bLabel = range.end.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
      return aLabel + " – " + bLabel;
    }

    var svg = document.getElementById("arcSvg");
    var arcWrap = document.getElementById("arcWrap");
    var gTicks = document.getElementById("ticks");
    var gStages = document.getElementById("stageBands");
    var gRingGuides = document.getElementById("ringGuides");
    var gNodes = document.getElementById("nodes");
    var gToday = document.getElementById("todayMarker");
    var pathFuture = document.getElementById("pathFuture");
    var pathLived = document.getElementById("pathLived");
    var baselineRef = document.getElementById("baselineRef");
    var rail = document.getElementById("rail");
    var railCount = document.getElementById("railCount");
    var riverScrollbar = document.getElementById("riverScrollbar");
    var riverScrollThumb = document.getElementById("riverScrollThumb");
    var nodeTooltip = document.getElementById("nodeTooltip");
    var nodeTooltipDate = document.getElementById("nodeTooltipDate");
    var nodeTooltipTitle = document.getElementById("nodeTooltipTitle");
    var hoverTimer = null;

    function hideNodeTooltip() {
      if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; }
      nodeTooltip.classList.remove("visible");
    }
    function showNodeTooltipFor(g, e) {
      var wrapRect = arcWrap.getBoundingClientRect();
      var nodeRect = g.getBoundingClientRect();
      var relX = nodeRect.left + nodeRect.width / 2 - wrapRect.left;
      var relY = nodeRect.top - wrapRect.top;
      var d = new Date(e.date + "T00:00:00");
      nodeTooltipDate.textContent = fmtDate(d);
      nodeTooltipTitle.textContent = e.title;
      nodeTooltip.style.left = relX.toFixed(1) + "px";
      nodeTooltip.style.top = relY.toFixed(1) + "px";
      nodeTooltip.classList.add("visible");
    }

    function drawArcCircle(parent, cx, cy, r, a0, a1, cls) {
      var x0 = cx + r * Math.cos(a0), y0 = cy + r * Math.sin(a0);
      var x1 = cx + r * Math.cos(a1), y1 = cy + r * Math.sin(a1);
      var large = (a1 - a0) % (Math.PI * 2) > Math.PI ? 1 : 0;
      var d = "M" + x0.toFixed(1) + "," + y0.toFixed(1) + " A" + r.toFixed(1) + "," + r.toFixed(1) + " 0 " + large + " 1 " + x1.toFixed(1) + "," + y1.toFixed(1);
      var path = document.createElementNS(svgNS, "path");
      path.setAttribute("d", d);
      path.setAttribute("class", cls);
      parent.appendChild(path);
    }

    function hashStr(s) {
      var h = 0;
      for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) >>> 0; }
      return h;
    }
    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
      });
    }

    function mediaTileHtml(m) {
      if (m.kind === "image") return '<img src="' + m.url + '" alt="">';
      if (m.kind === "video") return '<div class="media-tile">' + VIDEO_ICON + '<span class="media-tile-badge">Video</span></div>';
      return '<div class="media-tile">' + DOC_ICON + '<span class="media-tile-badge">File</span></div>';
    }
    function viewerLargeMediaHtml(m) {
      if (m.kind === "image") return '<img src="' + m.url + '" alt="">';
      if (m.kind === "video") return '<video src="' + m.url + '" controls playsinline></video>';
      return '<div class="media-tile">' + DOC_ICON + '<span class="media-tile-badge">Open file</span></div>';
    }

    function renderRail(list) {
      rail.innerHTML = "";
      if (!list.length) {
        var empty = document.createElement("div");
        empty.className = "empty-state";
        empty.style.flex = "1 0 auto";
        empty.textContent = IS_OWNER
          ? "Nothing in this range yet — add a memory above."
          : "No memories shared here in this range yet.";
        rail.appendChild(empty);
        return;
      }
      var famCount = 6;
      list.forEach(function (e) {
        var d = new Date(e.date + "T00:00:00");
        var card = document.createElement("div");
        card.className = "mem-card" + (e.id === state.highlightId ? " highlight" : "");
        card.setAttribute("data-id", e.id);
        var wobble = (hashStr(e.id) % 50) / 10 - 2.5;
        card.style.transform = "rotate(" + wobble.toFixed(1) + "deg)";
        var fi = (hashStr(e.id) % famCount) + 1;
        var fi2 = (fi % famCount) + 1;
        var mediaInner = (e.media && e.media.length)
          ? mediaTileHtml(e.media[0]) + (e.media.length > 1 ? '<span class="card-media-count">+' + (e.media.length - 1) + '</span>' : '')
          : '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 3.5h6.5L15 7v9.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" stroke-linejoin="round"/><path d="M7 8h6M7 11h6M7 14h4" stroke-linecap="round"/></svg>';
        card.innerHTML =
          '<div class="card-tape" style="background: var(--fam' + fi + ')"></div>' +
          '<div class="card-media" style="--stage-a: var(--fam' + fi + '-bg); --stage-b: var(--fam' + fi2 + '-bg);">' + mediaInner + '</div>' +
          '<div class="card-body">' +
            '<div class="card-date mono">' + fmtDate(d) + '</div>' +
            '<div class="card-title">' + escapeHtml(e.title) + '</div>' +
            '<div class="card-thought">' + escapeHtml(e.thought) + '</div>' +
            '<div class="card-foot">' +
              (e.type === "diary" ? DIARY_PILL_HTML : "") +
              (e.visibility === "public"
                ? '<span class="pill public"><svg viewBox="0 0 20 20" fill="currentColor"><circle cx="10" cy="10" r="6"/></svg>Family</span>'
                : '<span class="pill private"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2c-2 1.6-3 3.2-3 5.8v1.1H6.3A1.3 1.3 0 0 0 5 10.2v6A1.3 1.3 0 0 0 6.3 17.5h7.4A1.3 1.3 0 0 0 15 16.2v-6a1.3 1.3 0 0 0-1.3-1.3H13V7.8c0-2.6-1-4.2-3-5.8Z"/></svg>Private</span>') +
            '</div>' +
          '</div>';
        card.addEventListener("click", function () { openViewer(e.id); });
        rail.appendChild(card);
      });
    }

    function focusCard(id) {
      state.highlightId = id;
      render();
      var card = rail.querySelector('[data-id="' + id + '"]');
      if (card) card.scrollIntoView({ behavior: "smooth", inline: "center", block: "center" });
      setTimeout(function () { state.highlightId = null; render(); }, 1800);
    }

    function render() {
      var range = getRange();
      var layout = state.layout;
      var now = new Date();
      var todayFrac = t(now, range);
      var todayVisible = now >= range.start && now <= range.end;

      svg.setAttribute("viewBox", layout === "river" ? "0 -24 1180 424" : "0 0 760 760");
      arcWrap.className = "arc-wrap layout-" + layout;
      baselineRef.style.display = layout === "river" ? "" : "none";

      gRingGuides.innerHTML = "";
      if (layout === "rings") {
        pathFuture.setAttribute("d", ""); pathLived.setAttribute("d", "");
        var todayRadius = todayVisible ? t(now, range) * RADIAL_MAXR : -1;
        if (range.mode === "year") {
          var startAngle = -Math.PI / 2;
          var endAngle = todayVisible ? startAngle + todayFrac * Math.PI * 2 : startAngle;
          drawArcCircle(gRingGuides, RADIAL_CX, RADIAL_CY, RADIAL_MAXR * 0.72, 0, Math.PI * 2 - 0.001, "ring-arc-rest");
          if (todayVisible) drawArcCircle(gRingGuides, RADIAL_CX, RADIAL_CY, RADIAL_MAXR * 0.72, startAngle, endAngle, "ring-arc");
        } else {
          tickDatesFor(range).forEach(function (d) {
            var r = t(d, range) * RADIAL_MAXR;
            if (r < 4) return;
            var circle = document.createElementNS(svgNS, "circle");
            circle.setAttribute("cx", RADIAL_CX); circle.setAttribute("cy", RADIAL_CY); circle.setAttribute("r", r.toFixed(1));
            circle.setAttribute("class", "ring-guide" + (todayVisible && r <= todayRadius + 0.5 ? " lived" : ""));
            gRingGuides.appendChild(circle);
          });
          if (todayVisible && todayRadius > 4) {
            var forming = document.createElementNS(svgNS, "circle");
            forming.setAttribute("cx", RADIAL_CX); forming.setAttribute("cy", RADIAL_CY); forming.setAttribute("r", todayRadius.toFixed(1));
            forming.setAttribute("class", "ring-guide forming");
            gRingGuides.appendChild(forming);
          }
        }
      } else {
        pathFuture.setAttribute("d", pathD(range, layout));
        pathLived.setAttribute("d", todayVisible ? pathD(range, layout, todayFrac) : (now > range.end ? pathD(range, layout) : ""));
      }

      gStages.innerHTML = "";
      renderStageBands(range, layout);

      gTicks.innerHTML = "";
      var isCustom = range.mode === "custom";
      var tickPairs = isCustom
        ? niceTicksFor(range)
        : tickDatesFor(range).map(function (d) { return { date: d, label: fmtShort(d, range.mode) }; });
      tickPairs.forEach(function (tp) {
        var d = tp.date, f = t(d, range);
        if (layout === "river") {
          var x = RIVER_PAD + f * (RIVER_W - 2 * RIVER_PAD);
          var line = document.createElementNS(svgNS, "line");
          line.setAttribute("class", "tick-line");
          line.setAttribute("x1", x); line.setAttribute("x2", x);
          line.setAttribute("y1", "306"); line.setAttribute("y2", "314");
          gTicks.appendChild(line);
          var label = document.createElementNS(svgNS, "text");
          label.setAttribute("x", x); label.setAttribute("y", "326");
          label.setAttribute("text-anchor", "middle");
          label.setAttribute("class", "tick-label mono");
          label.textContent = tp.label;
          gTicks.appendChild(label);
        } else {
          var labelPos;
          if (layout === "rings" && range.mode !== "year") {
            var r = f * RADIAL_MAXR;
            labelPos = { x: RADIAL_CX, y: RADIAL_CY - r - 8 };
          } else {
            var p = placeFrac(f, range, layout);
            var dx = p.x - RADIAL_CX, dy = p.y - RADIAL_CY;
            var len = Math.sqrt(dx * dx + dy * dy) || 1;
            labelPos = { x: RADIAL_CX + (dx / len) * (len + 16), y: RADIAL_CY + (dy / len) * (len + 16) };
          }
          var lbl2 = document.createElementNS(svgNS, "text");
          lbl2.setAttribute("x", labelPos.x.toFixed(1)); lbl2.setAttribute("y", labelPos.y.toFixed(1));
          lbl2.setAttribute("text-anchor", "middle");
          lbl2.setAttribute("class", "tick-label mono");
          lbl2.textContent = tp.label;
          gTicks.appendChild(lbl2);
        }
      });

      if (layout === "river") {
        var topPairs = range.mode === "life"
          ? decadeYearTicksFor(range).map(function (d) { return { date: d, label: String(d.getFullYear()) }; })
          : tickPairs;
        topPairs.forEach(function (tp) {
          var fTop = t(tp.date, range);
          var xTop = RIVER_PAD + fTop * (RIVER_W - 2 * RIVER_PAD);
          var lineTop = document.createElementNS(svgNS, "line");
          lineTop.setAttribute("class", "tick-line");
          lineTop.setAttribute("x1", xTop); lineTop.setAttribute("x2", xTop);
          lineTop.setAttribute("y1", "12"); lineTop.setAttribute("y2", "20");
          gTicks.appendChild(lineTop);
          var labelTop = document.createElementNS(svgNS, "text");
          labelTop.setAttribute("x", xTop); labelTop.setAttribute("y", "0");
          labelTop.setAttribute("text-anchor", "middle");
          labelTop.setAttribute("class", "tick-label mono");
          labelTop.textContent = tp.label;
          gTicks.appendChild(labelTop);
        });
      }

      var zoomPill = document.getElementById("customZoomPill");
      if (state.customRange) {
        zoomPill.hidden = false;
        document.getElementById("customZoomLabel").textContent = fmtRangeLabel(range);
      } else {
        zoomPill.hidden = true;
      }

      if (layout === "river") {
        var fullR = fullLifeRange();
        var fullSpanMs = fullR.end.getTime() - fullR.start.getTime();
        var curSpanMs = range.end.getTime() - range.start.getTime();
        var leftFrac = clamp01((range.start.getTime() - fullR.start.getTime()) / fullSpanMs);
        var widthFrac = Math.min(1, curSpanMs / fullSpanMs);
        riverScrollThumb.style.left = (leftFrac * 100).toFixed(2) + "%";
        riverScrollThumb.style.width = Math.max(widthFrac * 100, 4).toFixed(2) + "%";
      }

      gToday.innerHTML = "";
      if (todayVisible) {
        var pt = place(now, range, layout);
        var ring = document.createElementNS(svgNS, "circle");
        ring.setAttribute("class", "today-ring");
        ring.setAttribute("cx", pt.x); ring.setAttribute("cy", pt.y); ring.setAttribute("r", "13");
        gToday.appendChild(ring);
        var dot = document.createElementNS(svgNS, "circle");
        dot.setAttribute("class", "today-glow");
        dot.setAttribute("cx", pt.x); dot.setAttribute("cy", pt.y); dot.setAttribute("r", "6");
        dot.setAttribute("fill", "var(--accent-glow)");
        gToday.appendChild(dot);
        var lblPos;
        if (layout === "river") {
          lblPos = { x: pt.x, y: pt.y - 20 };
        } else {
          var ddx = pt.x - RADIAL_CX, ddy = pt.y - RADIAL_CY;
          var dlen = Math.sqrt(ddx * ddx + ddy * ddy) || 1;
          lblPos = { x: RADIAL_CX + (ddx / dlen) * (dlen + 22), y: RADIAL_CY + (ddy / dlen) * (dlen + 22) };
        }
        var lbl = document.createElementNS(svgNS, "text");
        lbl.setAttribute("x", lblPos.x.toFixed(1)); lbl.setAttribute("y", lblPos.y.toFixed(1));
        lbl.setAttribute("text-anchor", "middle");
        lbl.setAttribute("class", "tick-label mono");
        lbl.textContent = "today";
        gToday.appendChild(lbl);
      }

      hideNodeTooltip();
      gNodes.innerHTML = "";
      var visible = entries.filter(function (e) {
        var d = new Date(e.date + "T00:00:00");
        return d >= range.start && d <= range.end;
      }).sort(function (a, b) { return a.date.localeCompare(b.date); });

      visible.forEach(function (e) {
        var d = new Date(e.date + "T00:00:00");
        var p = place(d, range, layout);
        var g = document.createElementNS(svgNS, "g");
        g.setAttribute("class", "node visibility-" + e.visibility + (e.type === "diary" ? " type-diary" : "") + (e.id === state.highlightId ? " highlight" : ""));
        g.setAttribute("transform", "translate(" + p.x.toFixed(1) + "," + p.y.toFixed(1) + ")");
        g.setAttribute("data-id", e.id);

        var ring = document.createElementNS(svgNS, "circle");
        ring.setAttribute("class", "ring");
        ring.setAttribute("r", "15");
        g.appendChild(ring);

        if (e.media && e.media.length && e.media[0].kind === "image") {
          var img = document.createElementNS(svgNS, "image");
          img.setAttributeNS("http://www.w3.org/1999/xlink", "href", e.media[0].url);
          img.setAttribute("x", "-15"); img.setAttribute("y", "-15");
          img.setAttribute("width", "30"); img.setAttribute("height", "30");
          img.setAttribute("clip-path", "url(#thumbClip)");
          img.setAttribute("preserveAspectRatio", "xMidYMid slice");
          g.appendChild(img);
        } else {
          var dot2 = document.createElementNS(svgNS, "circle");
          dot2.setAttribute("r", "5");
          dot2.setAttribute("fill", e.visibility === "public" ? "var(--accent)" : "var(--accent-2)");
          g.appendChild(dot2);
        }

        if (e.visibility === "private") {
          var lock = document.createElementNS(svgNS, "circle");
          lock.setAttribute("class", "lock");
          lock.setAttribute("r", "4.5");
          lock.setAttribute("cx", "11"); lock.setAttribute("cy", "11");
          g.appendChild(lock);
        }

        g.addEventListener("click", function (evt) {
          evt.stopPropagation();
          hideNodeTooltip();
          focusCard(e.id);
        });
        g.addEventListener("mouseenter", function () {
          hideNodeTooltip();
          hoverTimer = setTimeout(function () { showNodeTooltipFor(g, e); }, 750);
        });
        g.addEventListener("mouseleave", hideNodeTooltip);
        gNodes.appendChild(g);
      });

      railCount.textContent = visible.length + (visible.length === 1 ? " memory" : " memories");
      renderRail(visible);
    }

    // ---- toggles ----
    function wireSegmented(id, key) {
      document.getElementById(id).addEventListener("click", function (evt) {
        var btn = evt.target.closest("button[data-" + key + "]");
        if (!btn) return;
        this.querySelectorAll("button").forEach(function (b) { b.classList.remove("active"); });
        btn.classList.add("active");
        state[key] = btn.dataset[key];
        if (key === "zoom") state.customRange = null;
        render();
      });
    }
    wireSegmented("layoutToggle", "layout");
    wireSegmented("zoomToggle", "zoom");

    document.getElementById("customZoomPill").addEventListener("click", function () {
      state.customRange = null;
      render();
    });

    // ---- click on the timeline to add a memory on that date (owner only) ----
    function pad2(n) { return n < 10 ? "0" + n : String(n); }
    function isoDate(d) { return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate()); }

    function clientToSvg(clientX, clientY) {
      var rect = svg.getBoundingClientRect();
      var vb = svg.viewBox.baseVal;
      var scale = vb.width / rect.width;
      return { x: vb.x + (clientX - rect.left) * scale, y: vb.y + (clientY - rect.top) * scale };
    }
    var suppressNextClick = false;
    svg.addEventListener("click", function (evt) {
      if (suppressNextClick) { suppressNextClick = false; return; }
      if (!IS_OWNER) return;
      if (evt.target.closest(".node")) return;
      var range = getRange();
      var pos = clientToSvg(evt.clientX, evt.clientY);
      var f = posToFrac(pos.x, pos.y, range, state.layout);
      window.location.href = "/add_entry.php?date=" + isoDate(fracToDate(f, range));
    });

    // ---- drag-to-zoom on the river ----
    var selectionRect = document.getElementById("selectionRect");
    var DRAG_THRESHOLD = 6;
    var MIN_SPAN_MS = 3 * 86400000;
    var dragState = null;

    svg.addEventListener("pointerdown", function (evt) {
      if (state.layout !== "river") return;
      if (evt.target.closest(".node")) return;
      if (evt.button !== undefined && evt.button !== 0) return;
      dragState = {
        startClientX: evt.clientX,
        startClientY: evt.clientY,
        startSvgX: clientToSvg(evt.clientX, evt.clientY).x,
        dragging: false
      };
    });

    window.addEventListener("pointermove", function (evt) {
      if (!dragState) return;
      var dx = evt.clientX - dragState.startClientX;
      var dy = evt.clientY - dragState.startClientY;
      if (!dragState.dragging) {
        if (Math.abs(dx) < DRAG_THRESHOLD && Math.abs(dy) < DRAG_THRESHOLD) return;
        dragState.dragging = true;
        selectionRect.classList.add("active");
      }
      var curSvgX = clientToSvg(evt.clientX, evt.clientY).x;
      var x0 = Math.min(dragState.startSvgX, curSvgX);
      var x1 = Math.max(dragState.startSvgX, curSvgX);
      x0 = Math.max(RIVER_PAD, Math.min(RIVER_W - RIVER_PAD, x0));
      x1 = Math.max(RIVER_PAD, Math.min(RIVER_W - RIVER_PAD, x1));
      selectionRect.setAttribute("x", x0);
      selectionRect.setAttribute("width", Math.max(0, x1 - x0));
    });

    window.addEventListener("pointerup", function (evt) {
      if (!dragState) return;
      var wasDragging = dragState.dragging;
      if (wasDragging) {
        var range = getRange();
        var curSvgX = clientToSvg(evt.clientX, evt.clientY).x;
        var xA = Math.max(RIVER_PAD, Math.min(RIVER_W - RIVER_PAD, dragState.startSvgX));
        var xB = Math.max(RIVER_PAD, Math.min(RIVER_W - RIVER_PAD, curSvgX));
        var fA = posToFrac(xA, 0, range, "river");
        var fB = posToFrac(xB, 0, range, "river");
        var dA = fracToDate(fA, range), dB = fracToDate(fB, range);
        var newStart = dA < dB ? dA : dB;
        var newEnd = dA < dB ? dB : dA;
        if (newEnd.getTime() - newStart.getTime() >= MIN_SPAN_MS) {
          state.customRange = { start: newStart, end: newEnd };
          render();
        }
        suppressNextClick = true;
        selectionRect.classList.remove("active");
        selectionRect.setAttribute("width", 0);
      }
      dragState = null;
    });

    // ---- scroll-wheel zoom on the river ----
    arcWrap.addEventListener("wheel", function (evt) {
      if (state.layout !== "river") return;
      evt.preventDefault();
      var range = getRange();
      var pos = clientToSvg(evt.clientX, evt.clientY);
      var x = Math.max(RIVER_PAD, Math.min(RIVER_W - RIVER_PAD, pos.x));
      var f = posToFrac(x, 0, range, "river");
      var centerDate = fracToDate(f, range);
      var spanMs = range.end.getTime() - range.start.getTime();
      var zoomFactor = evt.deltaY > 0 ? 1.15 : 1 / 1.15;
      var fullSpanMs = addYears(new Date(), BUFFER_YEARS).getTime() - BIRTH.getTime();
      var newSpanMs = Math.max(MIN_SPAN_MS, Math.min(fullSpanMs, spanMs * zoomFactor));
      var centerMs = centerDate.getTime();
      var startMs = centerMs - (centerMs - range.start.getTime()) * (newSpanMs / spanMs);
      var newStart = new Date(startMs);
      var newEnd = new Date(startMs + newSpanMs);
      state.customRange = { start: newStart, end: newEnd };
      render();
    }, { passive: false });

    // ---- horizontal scrollbar: pan the current window across the full life range ----
    function clampWindowToFull(startMs, endMs) {
      var full = fullLifeRange();
      var fullStartMs = full.start.getTime(), fullEndMs = full.end.getTime();
      if (startMs < fullStartMs) { endMs += (fullStartMs - startMs); startMs = fullStartMs; }
      if (endMs > fullEndMs) { startMs -= (endMs - fullEndMs); endMs = fullEndMs; }
      startMs = Math.max(startMs, fullStartMs);
      return { start: new Date(startMs), end: new Date(endMs) };
    }

    var scrollDrag = null;
    riverScrollThumb.addEventListener("pointerdown", function (evt) {
      evt.stopPropagation();
      var range = getRange();
      var trackRect = riverScrollbar.getBoundingClientRect();
      scrollDrag = {
        startClientX: evt.clientX,
        trackWidth: trackRect.width,
        startMs: range.start.getTime(),
        endMs: range.end.getTime()
      };
      riverScrollThumb.classList.add("dragging");
      try { riverScrollThumb.setPointerCapture(evt.pointerId); } catch (e) {}
    });
    riverScrollThumb.addEventListener("pointermove", function (evt) {
      if (!scrollDrag) return;
      var full = fullLifeRange();
      var fullSpanMs = full.end.getTime() - full.start.getTime();
      var pxToMs = fullSpanMs / Math.max(1, scrollDrag.trackWidth);
      var deltaMs = (evt.clientX - scrollDrag.startClientX) * pxToMs;
      var win = clampWindowToFull(scrollDrag.startMs + deltaMs, scrollDrag.endMs + deltaMs);
      state.customRange = win;
      render();
    });
    function endScrollDrag(evt) {
      if (!scrollDrag) return;
      scrollDrag = null;
      riverScrollThumb.classList.remove("dragging");
      if (evt) { try { riverScrollThumb.releasePointerCapture(evt.pointerId); } catch (e) {} }
    }
    riverScrollThumb.addEventListener("pointerup", endScrollDrag);
    riverScrollThumb.addEventListener("pointercancel", endScrollDrag);

    riverScrollbar.addEventListener("pointerdown", function (evt) {
      if (evt.target === riverScrollThumb) return;
      var range = getRange();
      var full = fullLifeRange();
      var trackRect = riverScrollbar.getBoundingClientRect();
      var frac = clamp01((evt.clientX - trackRect.left) / trackRect.width);
      var fullSpanMs = full.end.getTime() - full.start.getTime();
      var spanMs = range.end.getTime() - range.start.getTime();
      var centerMs = full.start.getTime() + frac * fullSpanMs;
      var win = clampWindowToFull(centerMs - spanMs / 2, centerMs + spanMs / 2);
      state.customRange = win;
      render();
    });

    // ---- read-only memory/diary viewer ----
    var viewerScrim = document.getElementById("viewerScrim");
    var viewerHeaderTitle = document.getElementById("viewerHeaderTitle");
    var viewerMedia = document.getElementById("viewerMedia");
    var viewerTitleView = document.getElementById("viewerTitleView");
    var viewerDateView = document.getElementById("viewerDateView");
    var viewerPillView = document.getElementById("viewerPillView");
    var viewerThoughtView = document.getElementById("viewerThoughtView");
    var viewerDeleteForm = document.getElementById("viewerDeleteForm");
    var viewerDeleteEntryId = document.getElementById("viewerDeleteEntryId");
    var viewerEditLink = document.getElementById("viewerEditLink");

    function viewerMediaViewHtml(mediaList) {
      var list = mediaList || [];
      if (!list.length) {
        return '<div class="viewer-empty-media"><svg viewBox="0 0 20 20" fill="none"><path d="M4 15.5 8 10l3 3 3-4 2 2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><rect x="2.5" y="3.5" width="15" height="13" rx="2" stroke="currentColor" stroke-width="1.6"/></svg><span>No media added to this memory</span></div>';
      }
      if (list.length === 1) {
        return '<div class="viewer-media-single">' + viewerLargeMediaHtml(list[0]) + '</div>';
      }
      return '<div class="viewer-media-grid">' + list.map(function (m) {
        return '<a class="viewer-media-grid-tile" href="' + m.url + '" target="_blank" rel="noopener" title="Open full size">' + mediaTileHtml(m) + '</a>';
      }).join("") + '</div>';
    }

    function openViewer(id) {
      var e = findEntry(id);
      if (!e) return;
      viewerMedia.innerHTML = viewerMediaViewHtml(e.media);
      viewerTitleView.textContent = e.title;
      viewerDateView.textContent = fmtDate(new Date(e.date + "T00:00:00"));
      viewerHeaderTitle.textContent = e.type === "diary" ? "Diary entry" : "Memory";
      viewerPillView.innerHTML =
        (e.type === "diary" ? DIARY_PILL_HTML : "") +
        (e.visibility === "public"
          ? '<span class="pill public"><svg viewBox="0 0 20 20" fill="currentColor"><circle cx="10" cy="10" r="6"/></svg>Family</span>'
          : '<span class="pill private"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2c-2 1.6-3 3.2-3 5.8v1.1H6.3A1.3 1.3 0 0 0 5 10.2v6A1.3 1.3 0 0 0 6.3 17.5h7.4A1.3 1.3 0 0 0 15 16.2v-6a1.3 1.3 0 0 0-1.3-1.3H13V7.8c0-2.6-1-4.2-3-5.8Z"/></svg>Private</span>');
      viewerThoughtView.textContent = e.thought || "";
      if (IS_OWNER) {
        viewerDeleteForm.hidden = false;
        viewerDeleteEntryId.value = e.id;
        viewerEditLink.hidden = false;
        viewerEditLink.href = "/edit_entry.php?entry_id=" + encodeURIComponent(e.id);
      } else {
        viewerDeleteForm.hidden = true;
        viewerEditLink.hidden = true;
      }
      viewerScrim.classList.add("open");
    }
    function closeViewer() {
      viewerScrim.classList.remove("open");
    }
    document.getElementById("viewerClose").addEventListener("click", closeViewer);
    document.getElementById("viewerCloseBtn").addEventListener("click", closeViewer);
    viewerScrim.addEventListener("click", function (e) { if (e.target === viewerScrim) closeViewer(); });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && viewerScrim.classList.contains("open")) closeViewer();
    });

    render();
  })();
  </script>
</body>
</html>
