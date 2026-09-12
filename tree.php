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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My tree — ourthology.com</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,600;0,700;0,800;1,600&family=Newsreader:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css">
<style>
  :root {
    --shadow: 0 1px 2px rgba(26,23,20,0.08), 0 10px 26px -14px rgba(26,23,20,0.28);
  }
  body { align-items: flex-start; }
  .wide { max-width: 900px; }
  .nav { display:flex; gap:10px 16px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin: 18px 0 4px; }
  .nav-links { display:flex; gap:10px; flex-wrap:wrap; }
  .nav a { font-size:13px; padding:7px 12px; border-radius:999px; border:1px solid var(--line); color:var(--ink-soft); text-decoration:none; background:#fff; }
  .nav a.badge { background: var(--error-bg); border-color: var(--error-bg); color: var(--accent); font-weight:600; }
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
  .tree-wrap { background:var(--paper-2); border:1px solid var(--line); border-radius:24px; box-shadow:var(--shadow); overflow:auto; padding:10px; margin-top:10px; }
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
  .tree-node.unclaimed .tn-name { fill:var(--ink-soft); }
  .tree-node:hover .tn-name { fill:var(--accent); }
  .tree-node.you .tn-name { fill:var(--accent); font-size:17px; text-decoration:underline; text-decoration-color:var(--accent-glow); text-underline-offset:4px; }
  .tree-node.you:hover .tn-name { fill:var(--accent); }
  .tree-key { font-family:"Newsreader",Georgia,serif; font-size:12.5px; color:var(--ink-soft); margin-top:8px; }
  .tree-key strong { color:var(--ink); }
</style>
</head>
<body>
  <div class="card wide">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <div class="nav">
      <div class="nav-links">
        <a href="/timeline.php">My timeline</a>
        <a href="/dashboard.php">Dashboard</a>
        <a href="/add_relative.php">+ Add a relative</a>
        <a href="/edit_person.php">Edit a person</a>
        <a href="/link_existing.php">Link to existing account</a>
        <a href="/pending.php" class="<?= $pendingCount ? 'badge' : '' ?>">Pending<?= $pendingCount ? " ($pendingCount)" : '' ?></a>
      </div>
      <div class="whoami">
        Signed in as <strong><?= htmlspecialchars($me['email'], ENT_QUOTES) ?></strong>
        <form method="post" action="/logout.php"><button type="submit" class="linklet">Log out</button></form>
      </div>
    </div>

    <?php if ($flashLink): ?>
      <p style="margin-top:16px;font-weight:600;">Invite link for <?= htmlspecialchars(person_display_name($personsById[$flashFor] ?? []), ENT_QUOTES) ?>:</p>
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
              $bondClearance = 6;
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
                  $lines[] = ['cls' => 'tn-name', 'text' => mb_strimwidth(person_display_name($person), 0, 15, '…')];
                  $lines[] = ['cls' => 'tn-tag', 'text' => $person['claimed_by_user_id'] ? 'Claimed' : 'Unclaimed'];
              }
              if ($datesText !== '') {
                  $lines[] = ['cls' => $stepPrefix !== '' ? 'tn-dates tn-dates-tagged' : 'tn-dates', 'text' => $datesText];
              }
              $lineGap = 13;
              $lineStartY = -($lineGap * (count($lines) - 1)) / 2;
            ?>
            <a href="/timeline.php?person_id=<?= $pid ?>">
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
      <p class="tree-key">
        <strong>You</strong> are underlined in red · plain name = claimed account · <em>Unclaimed</em> label = not yet claimed<br>
        Click anyone to see their timeline. Scroll if the tree is wider than the screen.
        <?php if ($hasAnyStepTag): ?><br><strong>S-</strong> followed by initials = a step relationship, tagged with that step-parent's own initials<?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if ($unclaimed): ?>
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
    <?php endif; ?>
  </div>
</body>
</html>
