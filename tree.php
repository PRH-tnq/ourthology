<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/tree_layout.php';

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
        $token = create_claim_token($pdo, (int) $personId, (int) $me['user_id']);
        $_SESSION['flash_claim_link'] = claim_link_url($token);
        $_SESSION['flash_claim_for'] = (int) $personId;
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
  .tree-node .tn-tag { font-family:"Newsreader",Georgia,serif; font-size:10px; text-transform:uppercase; letter-spacing:.05em; text-anchor:middle; fill:var(--ink-soft); }
  .tree-node .tn-dates { font-family:"Newsreader",Georgia,serif; font-size:10px; text-anchor:middle; fill:var(--ink-faint); }
  .tree-node .tn-dates-tagged { font-weight:600; fill:var(--ink-soft); }
  .tree-node.unclaimed .tn-name { fill:var(--unclaimed); }
  .tree-node:hover .tn-name { fill:var(--accent); }
  .tree-node.you .tn-name { fill:var(--accent); font-size:17px; text-decoration:underline; text-decoration-color:var(--accent-glow); text-underline-offset:4px; }
  .tree-node.you:hover .tn-name { fill:var(--accent); }

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
  .edit-popup-box { position:relative; width:min(96vw, 1020px); height:min(92vh, 820px); background:var(--paper); border-radius:16px; overflow:hidden; box-shadow:0 24px 60px -20px rgba(0,0,0,0.45); }
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
    .nav, .flash, .flash-label, #unclaimedSection { display:none !important; }
    .tree-wrap { overflow:visible; border:none; box-shadow:none; background:transparent; padding:0; cursor:default; }
    .tree-wrap svg { width:100% !important; height:auto !important; }
  }
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
        <a href="/dashboard.php">Dashboard</a>
        <a href="/add_relative.php">+ Add a relative</a>
        <a href="/edit_person.php">Edit a person</a>
        <a href="/link_existing.php">Link to existing account</a>
        <a href="/pending.php" class="<?= $pendingCount ? 'badge' : '' ?>">Pending<?= $pendingCount ? " ($pendingCount)" : '' ?></a>
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

    <h3 style="margin-bottom:4px;">Your tree (<?= count($graph['persons']) ?> <?= count($graph['persons']) === 1 ? 'person' : 'people' ?>)</h3>

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
                  $lines[] = ['cls' => 'tn-tag', 'text' => $person['claimed_by_user_id'] ? 'Claimed' : 'Unclaimed'];
              }
              if ($datesText !== '') {
                  $lines[] = ['cls' => $stepPrefix !== '' ? 'tn-dates tn-dates-tagged' : 'tn-dates', 'text' => $datesText];
              }
              $lineGap = 13;
              $lineStartY = -($lineGap * (count($lines) - 1)) / 2;
            ?>
            <a href="/timeline.php?person_id=<?= $pid ?>" data-person-id="<?= $pid ?>" data-dblclick-edit="<?= ($isYou || !$person['claimed_by_user_id']) ? '1' : '0' ?>">
              <g class="tree-node<?= $isYou ? ' you' : '' ?><?= $person['claimed_by_user_id'] ? '' : ' unclaimed' ?>" transform="translate(<?= $pos['x'] ?>, <?= $pos['y'] ?>)">
                <rect class="tn-hit" x="<?= -$hitW / 2 ?>" y="<?= -$hitH / 2 ?>" width="<?= $hitW ?>" height="<?= $hitH ?>"></rect>
                <?php foreach ($lines as $li => $line): ?>
                  <text class="<?= $line['cls'] ?>" y="<?= $lineStartY + $li * $lineGap ?>"><?= htmlspecialchars($line['text'], ENT_QUOTES) ?></text>
                <?php endforeach; ?>
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
          <strong>Click</strong> a name to open their timeline. <strong>Double-click</strong> your own name, or anyone not yet claimed, to edit their profile.
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
              <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="get_link">
                <input type="hidden" name="person_id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="linklet">get invite link</button>
              </form>
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
    (function () {
      var DBLCLICK_WINDOW = 300;
      document.querySelectorAll('.tree-wrap a[data-dblclick-edit="1"]').forEach(function (a) {
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
</body>
</html>
