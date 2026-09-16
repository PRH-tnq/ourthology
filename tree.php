<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/tree_layout.php';
require_once __DIR__ . '/includes/tour_steps.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_link') {
    csrf_check();
    $personId = filter_var($_POST['person_id'] ?? '', FILTER_VALIDATE_INT);
    if ($personId !== false && person_in_group($pdo, (int) $personId, $myGroup)) {
        // Phase 30: no invite link for someone recorded as deceased — an
        // unclaimed profile with a date of death is never meant to be
        // claimed by anyone, so there's nothing to hand out a link for.
        // This mirrors the same check claim.php makes on any link that
        // was already generated before a death date was added, so this is
        // really just about not generating a NEW dead-end one — the
        // control is also hidden below, this is the same rule enforced
        // server-side rather than trusted from the UI alone.
        $linkPerson = person_row($pdo, (int) $personId);
        if ($linkPerson !== null && empty($linkPerson['claimed_by_user_id']) && empty($linkPerson['died'])) {
            $token = create_claim_token($pdo, (int) $personId, (int) $me['user_id']);
            $_SESSION['flash_claim_link'] = claim_link_url($token);
            $_SESSION['flash_claim_for'] = (int) $personId;
        }
    }
    header('Location: /tree.php');
    exit;
}

$flashLink = $_SESSION['flash_claim_link'] ?? null;
$flashFor  = $_SESSION['flash_claim_for'] ?? null;
unset($_SESSION['flash_claim_link'], $_SESSION['flash_claim_for']);

$pendingCounts = fetch_pending_for_user($pdo, (int) $me['user_id']);
$pendingCount = count($pendingCounts['relationships']) + count($pendingCounts['partnerships']);

$graph = fetch_family_graph($pdo, $myGroup);
$personsById = [];
foreach ($graph['persons'] as $p) {
    $personsById[(int) $p['id']] = $p;
}

$layout = compute_tree_layout($graph, $myPersonId);
$unclaimed = array_filter($graph['persons'], fn($p) => !$p['claimed_by_user_id']);

// Phase 35: anyone in the tree, not recorded as deceased, whose
// birthday falls in the next week -- shown as a reminder banner next
// to the "Your tree" heading below (see graph_upcoming_birthdays() in
// includes/graph.php for the date math).
$upcomingBirthdays = graph_upcoming_birthdays($graph['persons']);

// Phase 28: the onboarding tour (see includes/tour_steps.php) walks onto
// this page partway through — this page never starts it (that only ever
// happens from timeline.php, the landing page) but it does need the same
// step data and a matching copy of the engine to pick the tour back up
// when a step's page is "tree".
$tourSteps = ourthology_tour_steps();
$tourStepsJson = json_encode($tourSteps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$tourStepsJsonSafe = str_replace('</', '<\/', (string) $tourStepsJson);

$treeNodeW = TREE_NODE_W;
$treeNodeH = TREE_NODE_H;

/** One letter per name part actually on record (first, middle, surname), in
 *  order, uppercased — "Philip Richard Hart" -> "PRH", "Philip Hart" -> "PH".
 *  Mirrors the prototype's initialsOf(). */
function ourthology_initials(array $p): string
{
    $out = '';
    foreach ([$p['first_name'] ?? '', $p['middle_name'] ?? '', $p['surname'] ?? ''] as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    return $out;
}

/** Splits a full display name onto up to two tree-node lines instead of
 *  truncating it: names at or under the threshold stay on one line
 *  unchanged; a longer one splits at its LAST space (so "Christopher
 *  Alexander Worthington" -> "Christopher Alexander" / "Worthington"),
 *  which in practice puts the surname alone on its own line. A name with
 *  no space at all (nothing to split on) is left on one line however
 *  long — better an unusually wide single line than a line broken
 *  mid-word. Threshold tuned empirically against TREE_NODE_W/screenshots,
 *  not derived from it. */
function ourthology_name_lines(string $name, int $threshold = 20): array
{
    if (mb_strlen($name) <= $threshold) {
        return [$name];
    }
    $lastSpace = mb_strrpos($name, ' ');
    if ($lastSpace === false) {
        return [$name];
    }
    return [
        mb_substr($name, 0, $lastSpace),
        mb_substr($name, $lastSpace + 1),
    ];
}

/** "b. 1965" / "d. 2020" / "1965 – 2020" / "" — year-only, same compact
 *  convention the prototype's formatLifespan() uses (full dates would
 *  crowd a node this small). */
function ourthology_lifespan(?string $born, ?string $died): string
{
    $b = $born ? substr($born, 0, 4) : '';
    $d = $died ? substr($died, 0, 4) : '';
    if ($b && $d) {
        return $b . ' – ' . $d;
    }
    if ($b) {
        return 'b. ' . $b;
    }
    if ($d) {
        return 'd. ' . $d;
    }
    return '';
}

// Phase 39: ourthology_birthday_banner_text() moved to
// includes/graph.php (already required above) so timeline.php can
// share it too -- see that file for the implementation.

// Ported from the prototype's stepTag(): a child with a step-parent gets a
// short "S-" + that parent's own initials next to their dates on the tree
// — the step/genetic distinction isn't drawn on the connector lines
// themselves (every parent-child line looks the same regardless of
// relation_kind), it's spelled out here instead. A child with two step
// parents shows both tags.
$stepTagsByChild = [];
foreach ($graph['relationships'] as $r) {
    if ($r['relation_kind'] !== 'step') {
        continue;
    }
    $parentId = (int) $r['parent_id'];
    $childId = (int) $r['child_id'];
    if (!isset($personsById[$parentId])) {
        continue;
    }
    $initials = ourthology_initials($personsById[$parentId]);
    if ($initials === '') {
        continue; // no name on record for that parent slot yet — no tag rather than a blank one
    }
    $tag = 'S-' . $initials;
    if (!isset($stepTagsByChild[$childId])) {
        $stepTagsByChild[$childId] = [];
    }
    if (!in_array($tag, $stepTagsByChild[$childId], true)) {
        $stepTagsByChild[$childId][] = $tag;
    }
}
$hasAnyStepTag = !empty($stepTagsByChild);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My tree — ourthology.com</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,600;0,700;0,800;1,600&family=Newsreader:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css?v=20">
<style>
  :root {
    --shadow: 0 1px 2px rgba(26,23,20,0.08), 0 10px 26px -14px rgba(26,23,20,0.28);
    /* A muted, cool slate-blue for unclaimed people's names on the tree —
       deliberately not red (that's reserved for "you" and for interactive
       accents elsewhere), distinct enough from --ink to read as a clear
       second state at a glance, but desaturated enough to sit comfortably
       next to the app's warm paper/ink palette rather than clashing with it. */
    --unclaimed: #3F5E6E;
  }
  body { align-items: flex-start; }
  .wide { max-width: min(95vw, 1700px); }
  .nav { display:flex; gap:10px 16px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin: 18px 0 4px; }
  .nav-links { display:flex; gap:10px; flex-wrap:wrap; }
  .nav a { font-size:13px; padding:7px 12px; border-radius:999px; border:1px solid var(--line); color:var(--ink-soft); text-decoration:none; background:#fff; }
  .nav a.badge { background: var(--error-bg); border-color: var(--error-bg); color: var(--accent); font-weight:600; }
  .nav .linklet-btn { font-size:13px; font-weight:600; padding:7px 14px; border-radius:999px; border:1px solid var(--accent); color:var(--on-accent); background:var(--accent); cursor:pointer; font-family:inherit; }
  .nav .linklet-btn:hover { background:var(--accent-glow); border-color:var(--accent-glow); }
  .whoami { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12.5px; color:var(--ink-faint); }
  .whoami strong { color:var(--ink-soft); font-weight:600; }
  .whoami form { display:inline; }
  .whoami .linklet { font-size:12.5px; }
  ul.plain { list-style:none; padding:0; margin:8px 0; }
  ul.plain li { padding:8px 0; border-bottom:1px solid var(--line); font-size:14px; }
  .linklet { font-size:12px; background:transparent; border:none; color:var(--accent); cursor:pointer; padding:0; text-decoration:underline; }
  .flash { word-break:break-all; font-size:13px; background:#fff; border:1px solid var(--line); border-radius:6px; padding:8px; margin:8px 0 16px; }

  /* Phase 35: birthday reminder banner, top-right of the "Your tree"
     heading -- a friendly gold notice, deliberately not the accent red
     (reserved for "you"/partnership/emphasis elsewhere) or the
     unclaimed slate-blue, so it reads as its own, distinct kind of
     notice. */
  .birthday-banner { display:flex; align-items:center; gap:6px; padding:8px 16px; border:1px solid #C2790F; background:#F3DFB8; border-radius:999px; font-size:13px; color:#6B4A0A; max-width:100%; }
  .birthday-banner strong { color:#8A5A0A; font-weight:700; }

  /* Tree diagram — styled to match the original prototype's family-tree
     view: a soft paper-toned well, no boxes around people (just stacked
     text, name over status), warm serif type, blood lines plain and thin,
     partner bonds picked out in accent. */
  .tree-wrap { background:var(--paper-2); border:2px solid var(--accent); border-radius:24px; box-shadow:var(--shadow); overflow:auto; padding:10px; margin-top:10px; cursor:grab; touch-action:none; }
  .tree-wrap.panning { cursor:grabbing; user-select:none; }
  .tree-wrap svg { display:block; margin:0 auto; }
  .tree-link { fill:none; stroke:var(--ink-soft); stroke-width:1.4; }
  .tree-bond line, .tree-bond path { fill:none; stroke:var(--accent); stroke-width:1.8; }
  .tree-row-label { fill:var(--ink-faint); font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
  .tree-node { cursor:pointer; }
  .tree-node .tn-hit { fill:transparent; stroke:none; }
  .tree-node .tn-name { font-family:"Fraunces",Georgia,serif; font-weight:700; font-size:14px; text-anchor:middle; fill:var(--ink); transition:fill .15s ease; }
  .tree-node .tn-dates { font-family:"Newsreader",Georgia,serif; font-size:10px; text-anchor:middle; fill:var(--ink-faint); }
  .tree-node .tn-dates-tagged { font-weight:600; fill:var(--ink-soft); }
  .tree-node.unclaimed .tn-name { fill:var(--unclaimed); }
  .tree-node:hover .tn-name { fill:var(--accent); }
  .tree-node.you .tn-name { fill:var(--accent); font-size:17px; text-decoration:underline; text-decoration-color:var(--accent-glow); text-underline-offset:4px; }
  .tree-node.you:hover .tn-name { fill:var(--accent); }

  /* Phase 29: a direct one-tap "edit" affordance for touchscreens on the
     nodes that support it (your own node, or an unclaimed one). Hidden on
     a mouse/trackpad (pointer:fine) since those already get a real
     double-click there with no delay involved — see the tap-handling
     script below for why touch gets this instead of the same
     wait-and-see double-tap detection. */
  .tn-edit-affordance { display:none; }
  @media (pointer: coarse) {
    .tn-edit-affordance { display:block; }
  }
  .tn-edit-bg { fill:var(--card, #fff); stroke:var(--accent); stroke-width:1.5; }
  .tn-edit-icon { fill:none; stroke:var(--accent); stroke-width:1.5; stroke-linecap:round; stroke-linejoin:round; }

  .tree-legend { margin-top:10px; }
  .tree-key { font-family:"Newsreader",Georgia,serif; font-size:12.5px; color:var(--ink-soft); margin:0; }
  .tree-key strong { color:var(--ink); }
  .tree-swatch { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:5px; vertical-align:middle; position:relative; top:-1px; }
  .tree-swatch-you { background:var(--accent); }
  .tree-swatch-claimed { background:var(--ink); }
  .tree-swatch-unclaimed { background:var(--unclaimed); }
  .tree-help-box { margin-top:8px; background:#fff; border:1px solid var(--line); border-radius:10px; padding:10px 14px; font-family:"Newsreader",Georgia,serif; font-size:12.5px; color:var(--ink-soft); }
  .tree-help-box strong { color:var(--ink); }

  /* Double-click-to-edit pop-up: a fixed backdrop with a floating box
     hosting edit_person.php's own "?popup=1" rendering in an iframe — the
     page inside decides its own layout, this just frames it and supplies
     the close control. */
  .edit-popup-overlay { position:fixed; inset:0; background:rgba(26,23,20,0.55); z-index:1000; display:flex; align-items:center; justify-content:center; padding:20px; }
  /* Phase 25: this overlay box is this app's one actual "pop-up" (the
     edit_person.php form rendered inside it, framed by this box) — gets
     the same red-accent border as the tree/timeline diagrams (Phase 19)
     and the memory composer pop-up (add_entry.php, which handles both
     adding and — since Phase 27 — editing an existing memory). */
  .edit-popup-box { position:relative; width:min(96vw, 1020px); height:min(92vh, 820px); background:var(--paper); border:2px solid var(--accent); border-radius:16px; overflow:hidden; box-shadow:0 24px 60px -20px rgba(0,0,0,0.45); }
  .edit-popup-box iframe { width:100%; height:100%; border:none; display:block; }
  .edit-popup-close { position:absolute; top:10px; right:12px; z-index:2; width:32px; height:32px; border-radius:50%; border:1px solid var(--line); background:#fff; color:var(--ink-soft); font-size:18px; line-height:1; cursor:pointer; }
  .edit-popup-close:hover { background:var(--paper-2); }

  /* Print: a family tree is wide, so print it landscape and let the full
     diagram scale to the page rather than printing whatever's currently
     scrolled into view — override the on-screen overflow:auto/fixed pixel
     box with overflow:visible + a fluid SVG. Page chrome that isn't part
     of the diagram itself (nav bar, invite-link flash, the unclaimed list)
     is hidden; the heading and the key legend stay since they give useful
     context on a printed page. */
  @media print {
    @page { size: landscape; margin: 10mm; }
    .nav, .flash, .flash-label, .birthday-banner, #unclaimedSection { display:none !important; }
    .tree-wrap { overflow:visible; border:none; box-shadow:none; background:transparent; padding:0; cursor:default; }
    .tree-wrap svg { width:100% !important; height:auto !important; }
  }

  /* ---------- onboarding tour (Phase 28) ----------
     Byte-identical to timeline.php's copy of these same rules — the tour
     moves between the two pages, so its spotlight/tooltip needs to look
     the same wherever it's currently showing. */
  .tour-scrim { position:fixed; inset:0; background:rgba(20,16,12,.55); z-index:200; opacity:0; pointer-events:none; transition:opacity .15s ease; }
  .tour-scrim.open { opacity:1; pointer-events:auto; }
  .tour-highlight { position:fixed; border:3px solid var(--accent); border-radius:14px; box-shadow:0 0 0 4px rgba(154,42,42,.25); pointer-events:none; transition:top .2s ease, left .2s ease, width .2s ease, height .2s ease; z-index:201; }
  .tour-tooltip { position:fixed; background:var(--card); border:1px solid var(--line); border-radius:16px; padding:18px 20px; width:280px; box-shadow:0 20px 46px -18px rgba(26,23,20,.5); z-index:202; transition:top .2s ease, left .2s ease; }
  .tour-tooltip.tour-centered { position:fixed; top:50% !important; left:50% !important; transform:translate(-50%,-50%); width:300px; }
  .tour-tooltip h4 { margin:0 0 8px; font-family:"Fraunces",Georgia,serif; font-size:17px; color:var(--ink); }
  .tour-tooltip p { margin:0 0 16px; font-size:13.5px; color:var(--ink-soft); line-height:1.5; }
  .tour-footer { display:flex; align-items:center; justify-content:space-between; gap:10px; }
  .tour-step-label { font-size:11.5px; color:var(--ink-faint); }
  .tour-skip { font-size:12.5px; background:transparent; border:none; color:var(--ink-faint); cursor:pointer; padding:0; text-decoration:underline; }
  .tour-next { padding:8px 16px; border:none; border-radius:999px; background:var(--accent); color:var(--on-accent); font-size:13.5px; font-weight:600; cursor:pointer; }
  .tour-next:hover { background:var(--accent-glow); }
</style>
</head>
<body>
  <div class="card wide">
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

    <div class="nav">
      <div class="nav-links">
        <a href="/timeline.php">My timeline</a>
        <a href="/add_relative.php" id="tourAddRelative">+ Add a relative</a>
        <a href="/edit_person.php">Edit a person</a>
        <a href="/link_existing.php">Link to existing account</a>
        <a href="/pending.php" id="tourPendingLink" class="<?= $pendingCount ? 'badge' : '' ?>">Pending<?= $pendingCount ? " ($pendingCount)" : '' ?></a>
        <button type="button" id="printTreeBtn" class="linklet-btn" onclick="window.print()">Print tree</button>
      </div>
      <div class="whoami">
        Signed in as <strong><?= htmlspecialchars($me['email'], ENT_QUOTES) ?></strong>
        <form method="post" action="/logout.php"><button type="submit" class="linklet">Log out</button></form>
      </div>
    </div>

    <?php if ($flashLink): ?>
      <p class="flash-label" style="margin-top:16px;font-weight:600;">Invite link for <?= htmlspecialchars(person_display_name($personsById[$flashFor] ?? []), ENT_QUOTES) ?>:</p>
      <p class="flash"><?= htmlspecialchars($flashLink, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <h3 style="margin-bottom:4px;">Your tree (<?= count($graph['persons']) ?> <?= count($graph['persons']) === 1 ? 'person' : 'people' ?>)</h3>
      <?php if ($upcomingBirthdays): ?>
        <div class="birthday-banner" role="status">
          <span aria-hidden="true">🎂</span> <?= htmlspecialchars(ourthology_birthday_banner_text($upcomingBirthdays), ENT_QUOTES) ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (count($graph['persons']) <= 1): ?>
      <p style="color:var(--ink-faint);margin-top:8px;">No relationships yet — add a relative to get started.</p>
    <?php else: ?>
      <div class="tree-wrap">
        <svg viewBox="0 0 <?= (int) $layout['width'] ?> <?= (int) $layout['height'] ?>" width="<?= (int) $layout['width'] ?>" height="<?= (int) $layout['height'] ?>">
          <?php foreach ($layout['rowLabels'] as $rl): ?>
            <text class="tree-row-label" x="14" y="<?= $rl['y'] + 4 ?>"><?= htmlspecialchars($rl['text'], ENT_QUOTES) ?></text>
          <?php endforeach; ?>
          <?php foreach ($layout['familyUnits'] as $unit): ?>
            <?php
              $parentXs = [];
              $parentBottomYs = [];
              foreach ($unit['parents'] as $pid) {
                  if (!isset($layout['positions'][$pid])) continue;
                  $parentXs[] = $layout['positions'][$pid]['x'];
                  $parentBottomYs[] = $layout['positions'][$pid]['y'] + $treeNodeH / 2;
              }
              $childXs = [];
              $childTopYs = [];
              foreach ($unit['children'] as $cid) {
                  if (!isset($layout['positions'][$cid])) continue;
                  $childXs[] = $layout['positions'][$cid]['x'];
                  $childTopYs[] = $layout['positions'][$cid]['y'] - $treeNodeH / 2;
              }
              if (!$parentXs || !$childXs) continue;
              // Every parent gets an equal-length stem straight down from
              // their own node — so two parents always read as a mirrored,
              // symmetric pair even with no partnership/bond line recorded
              // between them — meeting at a short horizontal joiner (skipped
              // for a single parent, who has nothing to join to). From
              // there, ONE trunk continues down from the joiner's exact
              // midpoint (or straight from the lone parent) to the
              // children's bar, so that half of the connector is always
              // centered too, regardless of how the children end up spread
              // out below.
              $trunkX = array_sum($parentXs) / count($parentXs);
              $parentBottomY = max($parentBottomYs);
              $busY = ($parentBottomY + min($childTopYs)) / 2;
              $joinY = $parentBottomY + 20;
              $barXs = array_merge($childXs, [$trunkX]);
            ?>
            <?php foreach ($parentXs as $px): ?>
              <line class="tree-link" x1="<?= $px ?>" y1="<?= $parentBottomY ?>" x2="<?= $px ?>" y2="<?= $joinY ?>"></line>
            <?php endforeach; ?>
            <?php if (min($parentXs) !== max($parentXs)): ?>
              <line class="tree-link" x1="<?= min($parentXs) ?>" y1="<?= $joinY ?>" x2="<?= max($parentXs) ?>" y2="<?= $joinY ?>"></line>
            <?php endif; ?>
            <line class="tree-link" x1="<?= $trunkX ?>" y1="<?= $joinY ?>" x2="<?= $trunkX ?>" y2="<?= $busY ?>"></line>
            <?php if (min($barXs) !== max($barXs)): ?>
              <line class="tree-link" x1="<?= min($barXs) ?>" y1="<?= $busY ?>" x2="<?= max($barXs) ?>" y2="<?= $busY ?>"></line>
            <?php endif; ?>
            <?php foreach ($unit['children'] as $i => $cid): ?>
              <?php if (!isset($layout['positions'][$cid])) continue; ?>
              <line class="tree-link" x1="<?= $layout['positions'][$cid]['x'] ?>" y1="<?= $busY ?>" x2="<?= $layout['positions'][$cid]['x'] ?>" y2="<?= $childTopYs[$i] ?>"></line>
            <?php endforeach; ?>
          <?php endforeach; ?>

          <?php foreach ($graph['partnerships'] as $p): ?>
            <?php
              $aId = (int) $p['person_a_id'];
              $bId = (int) $p['person_b_id'];
              if (!isset($layout['positions'][$aId], $layout['positions'][$bId])) continue;
              if ($layout['positions'][$aId]['tier'] !== $layout['positions'][$bId]['tier']) continue;
              $aIsLeft = $layout['positions'][$aId]['x'] < $layout['positions'][$bId]['x'];
              $leftId = $aIsLeft ? $aId : $bId;
              $rightId = $aIsLeft ? $bId : $aId;
              $left = $layout['positions'][$leftId];
              $right = $layout['positions'][$rightId];
              $tier = $left['tier'];
              // Almost always the two partners are seated right next to each
              // other (the layout keeps a couple adjacent on purpose), but a
              // person recorded with more than one partner — remarried,
              // widowed and repartnered, etc. — can have a second bond whose
              // other end sits elsewhere in the row. A straight line between
              // them would then cut across whichever nodes sit in between,
              // running straight through their names — so when that happens
              // the bond instead lifts above the row and travels over the
              // top, clear of every node's text.
              $hasIntervening = false;
              foreach ($layout['order'][$tier] ?? [] as $otherId) {
                  if ($otherId === $aId || $otherId === $bId) continue;
                  $ox = $layout['positions'][$otherId]['x'];
                  if ($ox > $left['x'] && $ox < $right['x']) {
                      $hasIntervening = true;
                      break;
                  }
              }
            ?>
            <?php
              // Inset from each side's own node width (the "You" node is
              // narrower than an ordinary one), plus a small fixed
              // clearance — not a flat pixel count. A flat inset (the
              // first version used 18px regardless of node width) is only
              // safe for short names; a longer name — anything approaching
              // the 15-character truncation limit the node width was
              // itself sized for — renders wider than that, so the bond's
              // endpoint landed underneath the person's own text instead
              // of stopping at the edge of it. Since every name is
              // guaranteed to fit within its node's own envelope (that's
              // what TREE_NODE_W/TREE_ME_W and the truncation limit are
              // for), insetting by half that envelope's width always
              // clears the text, however long the name actually is.
              $leftHalfW = ($leftId === $myPersonId ? TREE_ME_W : TREE_NODE_W) / 2;
              $rightHalfW = ($rightId === $myPersonId ? TREE_ME_W : TREE_NODE_W) / 2;
              $bondClearance = 10;
              $bx1 = $left['x'] + $leftHalfW + $bondClearance;
              $by1 = $left['y'];
              $bx2 = $right['x'] - $rightHalfW - $bondClearance;
              $by2 = $right['y'];
              // Two parallel strokes offset a couple of pixels either side of
              // the bond's own line — the standard genealogy-chart "married"
              // symbol — matching the prototype's treeSpouseBond() exactly,
              // rather than a single thick line.
              $bondDy = 3.2;
              $liftY = $left['y'] - $treeNodeH / 2 - 14;
            ?>
            <g class="tree-bond">
              <?php if ($hasIntervening): ?>
                <path d="M<?= $bx1 ?>,<?= $by1 - $bondDy ?> V<?= $liftY - $bondDy ?> H<?= $bx2 ?> V<?= $by2 - $bondDy ?>"></path>
                <path d="M<?= $bx1 ?>,<?= $by1 + $bondDy ?> V<?= $liftY + $bondDy ?> H<?= $bx2 ?> V<?= $by2 + $bondDy ?>"></path>
              <?php else: ?>
                <line x1="<?= $bx1 ?>" y1="<?= $by1 - $bondDy ?>" x2="<?= $bx2 ?>" y2="<?= $by2 - $bondDy ?>"></line>
                <line x1="<?= $bx1 ?>" y1="<?= $by1 + $bondDy ?>" x2="<?= $bx2 ?>" y2="<?= $by2 + $bondDy ?>"></line>
              <?php endif; ?>
            </g>
          <?php endforeach; ?>

          <?php foreach ($layout['positions'] as $pid => $pos): ?>
            <?php
              $person = $personsById[$pid] ?? null;
              if (!$person) continue;
              $isYou = $pid === $myPersonId;
              $hitW = $isYou ? TREE_ME_W : TREE_NODE_W;
              $hitH = $isYou ? TREE_ME_H : $treeNodeH;

              $stepPrefix = !empty($stepTagsByChild[$pid]) ? implode(' ', $stepTagsByChild[$pid]) : '';
              $lifespan = ourthology_lifespan($person['born'] ?? null, $person['died'] ?? null);
              $datesText = trim($stepPrefix . ' ' . $lifespan);

              $lines = [];
              if ($isYou) {
                  $lines[] = ['cls' => 'tn-name', 'text' => 'You'];
              } else {
                  foreach (ourthology_name_lines(person_display_name($person)) as $nameLine) {
                      $lines[] = ['cls' => 'tn-name', 'text' => $nameLine];
                  }
                  // Phase 30: the "Claimed"/"Unclaimed" text line was dropped —
                  // the name's own colour (see .tree-node.unclaimed .tn-name
                  // and the legend) already carries that distinction, so this
                  // was saying the same thing twice.
              }
              if ($datesText !== '') {
                  $lines[] = ['cls' => $stepPrefix !== '' ? 'tn-dates tn-dates-tagged' : 'tn-dates', 'text' => $datesText];
              }
              $lineGap = 13;
              $lineStartY = -($lineGap * (count($lines) - 1)) / 2;
              $isEditable = $isYou || !$person['claimed_by_user_id'];
            ?>
            <a href="/timeline.php?person_id=<?= $pid ?>" data-person-id="<?= $pid ?>" data-dblclick-edit="<?= $isEditable ? '1' : '0' ?>">
              <g class="tree-node<?= $isYou ? ' you' : '' ?><?= $person['claimed_by_user_id'] ? '' : ' unclaimed' ?>" transform="translate(<?= $pos['x'] ?>, <?= $pos['y'] ?>)">
                <rect class="tn-hit" x="<?= -$hitW / 2 ?>" y="<?= -$hitH / 2 ?>" width="<?= $hitW ?>" height="<?= $hitH ?>"></rect>
                <?php foreach ($lines as $li => $line): ?>
                  <text class="<?= $line['cls'] ?>" y="<?= $lineStartY + $li * $lineGap ?>"><?= htmlspecialchars($line['text'], ENT_QUOTES) ?></text>
                <?php endforeach; ?>
                <?php if ($isEditable): ?>
                  <?php
                    // Phase 29: a direct, one-tap way to reach the edit
                    // pop-up on a touchscreen — CSS shows this only on a
                    // coarse (touch) pointer; see the tap-handling script
                    // for why touch skips the double-tap detection this
                    // node's own <a> uses on a mouse.
                    $editCx = $hitW / 2 - 11;
                    $editCy = -$hitH / 2 + 11;
                  ?>
                  <g class="tn-edit-affordance" data-edit-affordance data-person-id="<?= $pid ?>" transform="translate(<?= $editCx ?>, <?= $editCy ?>)" aria-label="Edit">
                    <circle class="tn-edit-bg" r="10"></circle>
                    <path class="tn-edit-icon" d="M-3.4,3.4 L-1,4 L-0.4,1.6 L3.2,-2 L1.4,-3.8 L-2.2,-0.2 Z M2,-4.6 L4.6,-2"></path>
                  </g>
                <?php endif; ?>
              </g>
            </a>
          <?php endforeach; ?>
        </svg>
      </div>
      <div class="tree-legend">
        <p class="tree-key">
          <span class="tree-swatch tree-swatch-you"></span><strong>You</strong> — underlined in red
          &nbsp;·&nbsp; <span class="tree-swatch tree-swatch-claimed"></span>Claimed account
          &nbsp;·&nbsp; <span class="tree-swatch tree-swatch-unclaimed"></span>Not yet claimed
          <?php if ($hasAnyStepTag): ?>
            &nbsp;·&nbsp; <strong>S-</strong> + initials = a step relationship, tagged with that step-parent's own initials
          <?php endif; ?>
          <br>Scroll or drag if the tree is wider than the screen.
        </p>
        <div class="tree-help-box">
          <strong>Click</strong> a name to open their timeline. <strong>Double-click</strong> your own name, or anyone not yet claimed, to edit their profile — on a touchscreen, tap the small pencil on those instead.
        </div>
      </div>
    <?php endif; ?>

    <?php if ($unclaimed): ?>
      <div id="unclaimedSection">
        <h3 style="margin:24px 0 4px;">Not yet claimed (<?= count($unclaimed) ?>)</h3>
        <ul class="plain">
          <?php foreach ($unclaimed as $p): ?>
            <li>
              <?= htmlspecialchars(person_display_name($p), ENT_QUOTES) ?>
              <?php if (!empty($p['died'])): ?>
                <?php
                  // Phase 30: recorded as deceased — nobody can claim this
                  // profile (see claim.php and the get_link handler above),
                  // so there's no invite link to offer here either.
                ?>
                <span style="color:var(--ink-faint);font-size:12.5px;">— can't be claimed (recorded as deceased)</span>
              <?php else: ?>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="get_link">
                  <input type="hidden" name="person_id" value="<?= (int) $p['id'] ?>">
                  <button type="submit" class="linklet">get invite link</button>
                </form>
              <?php endif; ?>
              · <a class="linklet" href="/edit_person.php?person_id=<?= (int) $p['id'] ?>" style="text-decoration:underline;">edit</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
  <script>
    // Click-and-drag (and touch-drag) panning on empty tree-diagram space.
    // Pointer Events cover mouse and touch in one set of handlers. A drag
    // only STARTS when the pointer goes down outside a person node (so a
    // node's own click-to-navigate is never hijacked at the source), and a
    // small movement threshold — matching the drag-to-zoom threshold
    // already used on timeline.php's river view — distinguishes a genuine
    // click from a real drag so a plain click still navigates normally.
    (function () {
      var wrap = document.querySelector('.tree-wrap');
      if (!wrap) return;
      var DRAG_THRESHOLD = 6;
      var drag = null;
      var suppressNextClick = false;

      function isOnNode(target) {
        return !!(target && target.closest && target.closest('.tree-node'));
      }

      wrap.addEventListener('pointerdown', function (evt) {
        if (evt.button !== undefined && evt.button !== 0) return;
        if (isOnNode(evt.target)) return;
        drag = {
          pointerId: evt.pointerId,
          startX: evt.clientX,
          startY: evt.clientY,
          startScrollLeft: wrap.scrollLeft,
          startScrollTop: wrap.scrollTop,
          dragging: false
        };
      });

      wrap.addEventListener('pointermove', function (evt) {
        if (!drag || drag.pointerId !== evt.pointerId) return;
        var dx = evt.clientX - drag.startX;
        var dy = evt.clientY - drag.startY;
        if (!drag.dragging) {
          if (Math.abs(dx) < DRAG_THRESHOLD && Math.abs(dy) < DRAG_THRESHOLD) return;
          drag.dragging = true;
          wrap.classList.add('panning');
          try { wrap.setPointerCapture(evt.pointerId); } catch (e) {}
        }
        wrap.scrollLeft = drag.startScrollLeft - dx;
        wrap.scrollTop = drag.startScrollTop - dy;
        evt.preventDefault();
      });

      function endDrag(evt) {
        if (!drag || (evt && evt.pointerId !== undefined && evt.pointerId !== drag.pointerId)) return;
        if (drag.dragging) {
          wrap.classList.remove('panning');
          suppressNextClick = true;
          try { wrap.releasePointerCapture(drag.pointerId); } catch (e) {}
        }
        drag = null;
      }
      wrap.addEventListener('pointerup', endDrag);
      wrap.addEventListener('pointercancel', endDrag);

      // Defensive net: if a real drag just ended (wasDragging), swallow the
      // click so the node it happened to end over doesn't navigate.
      wrap.addEventListener('click', function (evt) {
        if (suppressNextClick) {
          suppressNextClick = false;
          evt.preventDefault();
          evt.stopPropagation();
        }
      }, true);
    })();

    // Single click on a name opens their timeline (the plain <a href> below
    // already does this natively — nothing extra needed there). A name
    // that's either UNCLAIMED or is the viewer's own "You" node additionally
    // supports a double-click to open that person's profile for editing, in
    // a small pop-up box right over the tree rather than navigating away —
    // there's no edit path for anyone else's already-claimed record, so
    // those nodes get no such handler at all. A browser fires a real
    // "click" for both the first and second click of a double-click, and by
    // default that first click's own <a href> navigation would fire
    // immediately — before a "dblclick" could ever be detected — so for
    // these nodes the default navigation is suppressed and replaced with a
    // short wait-and-see: no second click within the window means it was a
    // single click (go to the timeline, exactly like every other node), a
    // second click within the window means it was a double-click (open the
    // edit pop-up instead). Every other node is untouched — it keeps
    // navigating on the very first click, with no artificial delay.
    //
    // Phase 29: that wait-and-see is exactly the "single/double tap feels
    // clunky" complaint on a touchscreen — every tap on your own node, or
    // an unclaimed one, waited up to DBLCLICK_WINDOW before it actually
    // went anywhere, because there's no reliable way to tell a plain tap
    // from the first half of a double-tap without waiting for it. A mouse
    // doesn't have that problem (a double-click is a deliberate, distinct
    // gesture there), so only a coarse (touch) pointer skips the wait —
    // its tap always navigates immediately, and the small pencil icon
    // rendered on these nodes (only visible on a coarse pointer — see the
    // CSS) opens the edit pop-up directly instead of relying on a second
    // tap at all.
    var isCoarsePointer = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);

    (function () {
      var DBLCLICK_WINDOW = 300;
      document.querySelectorAll('.tree-wrap a[data-dblclick-edit="1"]').forEach(function (a) {
        if (isCoarsePointer) return;
        var pendingTimer = null;
        a.addEventListener('click', function (evt) {
          evt.preventDefault();
          if (pendingTimer) {
            clearTimeout(pendingTimer);
            pendingTimer = null;
            openEditPopup(a.dataset.personId);
          } else {
            pendingTimer = setTimeout(function () {
              pendingTimer = null;
              // a.href is an SVGAnimatedString on an SVG <a> element, not a
              // plain string like it is on an ordinary HTML anchor — using
              // it directly here stringified to the literal text
              // "[object SVGAnimatedString]" and sent the browser to
              // ourthology.com/[object%20SVGAnimatedString] (a 404).
              // getAttribute() reads the raw attribute value regardless of
              // namespace, which is what's actually needed here.
              window.location.href = a.getAttribute('href');
            }, DBLCLICK_WINDOW);
          }
        });
      });

      // The touch-only pencil icon (see the CSS and the comment above) —
      // one direct tap opens the same edit pop-up a mouse reaches via
      // double-click. It sits inside the node's own <a>, so both
      // preventDefault (stop that link's navigation) and stopPropagation
      // (stop the click from also reaching the dblclick-detection handler
      // above, on nodes where it's still active) are needed.
      document.querySelectorAll('.tn-edit-affordance').forEach(function (icon) {
        icon.addEventListener('click', function (evt) {
          evt.preventDefault();
          evt.stopPropagation();
          openEditPopup(icon.dataset.personId);
        });
      });

      function onEscape(evt) {
        if (evt.key === 'Escape') closeEditPopup();
      }

      function closeEditPopup() {
        var existing = document.getElementById('editPopupOverlay');
        if (existing) existing.remove();
        document.removeEventListener('keydown', onEscape);
      }

      function openEditPopup(personId) {
        closeEditPopup();
        var overlay = document.createElement('div');
        overlay.className = 'edit-popup-overlay';
        overlay.id = 'editPopupOverlay';
        overlay.addEventListener('click', function (evt) {
          if (evt.target === overlay) closeEditPopup();
        });

        var box = document.createElement('div');
        box.className = 'edit-popup-box';

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'edit-popup-close';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', closeEditPopup);

        var iframe = document.createElement('iframe');
        iframe.src = '/edit_person.php?person_id=' + encodeURIComponent(personId) + '&popup=1';

        box.appendChild(closeBtn);
        box.appendChild(iframe);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
        document.addEventListener('keydown', onEscape);
      }
    })();
  </script>

  <!-- Phase 28: this page never STARTS the onboarding tour (only
       timeline.php's landing page does that) but it's where several of the
       tour's own steps live, so it carries the same markup and a matching
       copy of the engine to resume the tour when a step's page is "tree" —
       see timeline.php's own copy of this block for the full explanation. -->
  <div class="tour-scrim" id="tourScrim">
    <div class="tour-highlight" id="tourHighlight" hidden></div>
    <div class="tour-tooltip" id="tourTooltip">
      <h4 id="tourTitle"></h4>
      <p id="tourBody"></p>
      <div class="tour-footer">
        <button type="button" class="tour-skip" id="tourSkipBtn">Skip tour</button>
        <span class="tour-step-label" id="tourStepLabel"></span>
        <button type="button" class="tour-next" id="tourNextBtn"></button>
      </div>
    </div>
  </div>
  <input type="hidden" id="tourCsrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
  <script id="tourStepsData" type="application/json"><?= $tourStepsJsonSafe ?></script>
  <script>
  (function () {
    "use strict";
    var TOUR_PAGE = "tree";
    var TOUR_URLS = { timeline: "/timeline.php", tree: "/tree.php" };
    var TOUR_STEPS = JSON.parse(document.getElementById("tourStepsData").textContent);

    var step = -1;
    var scrim = document.getElementById("tourScrim");
    var highlight = document.getElementById("tourHighlight");
    var tooltip = document.getElementById("tourTooltip");
    var titleEl = document.getElementById("tourTitle");
    var bodyEl = document.getElementById("tourBody");
    var stepLabel = document.getElementById("tourStepLabel");
    var nextBtn = document.getElementById("tourNextBtn");
    var skipBtn = document.getElementById("tourSkipBtn");

    function saveState(i) {
      try {
        sessionStorage.setItem("ourthologyTourStep", String(i));
        sessionStorage.setItem("ourthologyTourActive", "1");
      } catch (e) {}
    }
    function clearState() {
      try {
        sessionStorage.removeItem("ourthologyTourStep");
        sessionStorage.removeItem("ourthologyTourActive");
      } catch (e) {}
    }

    function place() {
      var s = TOUR_STEPS[step];
      titleEl.textContent = s.title;
      bodyEl.textContent = s.body;
      stepLabel.textContent = (step + 1) + " of " + TOUR_STEPS.length;
      nextBtn.textContent = (step === TOUR_STEPS.length - 1) ? "Done" : "Next";

      var target = s.target ? document.querySelector(s.target) : null;
      if (!target) {
        highlight.hidden = true;
        tooltip.classList.add("tour-centered");
        return;
      }
      tooltip.classList.remove("tour-centered");
      if (typeof target.scrollIntoView === "function") {
        // "auto" (instant), not "smooth" — see timeline.php's copy of this
        // block for why: a still-animating smooth scroll makes the very
        // next getBoundingClientRect() read the target's pre-scroll spot,
        // which can push the tooltip (and its Next button) off-screen for
        // anything below the fold, such as the unclaimed-people section.
        target.scrollIntoView({ block: "center", inline: "nearest", behavior: "auto" });
      }
      var r = target.getBoundingClientRect();
      var pad = 8;
      highlight.hidden = false;
      highlight.style.left = (r.left - pad) + "px";
      highlight.style.top = (r.top - pad) + "px";
      highlight.style.width = (r.width + pad * 2) + "px";
      highlight.style.height = (r.height + pad * 2) + "px";

      var tooltipW = 280, tooltipH = tooltip.offsetHeight || 160;
      var spaceBelow = window.innerHeight - r.bottom;
      var top = (spaceBelow > tooltipH + 24) ? (r.bottom + pad + 14) : Math.max(14, r.top - pad - 14 - tooltipH);
      var left = Math.min(Math.max(14, r.left), window.innerWidth - tooltipW - 14);
      tooltip.style.top = top + "px";
      tooltip.style.left = left + "px";
    }

    function open_() {
      scrim.classList.add("open");
      place();
    }

    function finishTour() {
      clearState();
      scrim.classList.remove("open");
      var fd = new FormData();
      fd.append("action", "dismiss_tour");
      fd.append("csrf_token", document.getElementById("tourCsrf").value);
      fetch("/timeline.php", { method: "POST", body: fd, credentials: "same-origin" }).catch(function () {});
    }

    function goToStep(i) {
      if (i >= TOUR_STEPS.length) { finishTour(); return; }
      var s = TOUR_STEPS[i];
      if (s.page !== TOUR_PAGE) {
        saveState(i);
        window.location.href = TOUR_URLS[s.page];
        return;
      }
      step = i;
      saveState(i);
      open_();
    }

    nextBtn.addEventListener("click", function () { goToStep(step + 1); });
    skipBtn.addEventListener("click", finishTour);
    window.addEventListener("resize", function () { if (step >= 0) place(); });

    // This page has no "start the tour" button of its own — it only ever
    // resumes a tour already in progress, handed off from timeline.php.
    var resumeActive = false;
    try { resumeActive = sessionStorage.getItem("ourthologyTourActive") === "1"; } catch (e) {}
    if (resumeActive) {
      var savedStep = 0;
      try { savedStep = parseInt(sessionStorage.getItem("ourthologyTourStep") || "0", 10); } catch (e) {}
      if (TOUR_STEPS[savedStep] && TOUR_STEPS[savedStep].page === TOUR_PAGE) {
        step = savedStep;
        saveState(step);
        open_();
      }
    }
  })();
  </script>
</body>
</html>
