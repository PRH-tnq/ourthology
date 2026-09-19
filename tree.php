<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/tree_layout.php';
require_once __DIR__ . '/includes/peripheral.php';
require_once __DIR__ . '/includes/tour_engine.php';

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

$flashPeripheral = $_SESSION['flash_peripheral_message'] ?? null;
unset($_SESSION['flash_peripheral_message']);

// Phase 58: peripheral-tree switch icons. $switchIconFor maps a person on
// THIS tree to the peripheral person id switching to them lands on ("switch
// to your other family", shown beside an in-law who's had a peripheral
// tree created for them); $returnToMasterPersonId, when set, means THIS
// tree IS a peripheral one and names the master person id "return to the
// original tree" switches back to (shown beside this tree's own "You"
// node). Visible to every viewer either way (Phase 58 Q3) -- only usable
// by whoever actually owns that identity, decided per-node below from
// claimed_by_user_id, exactly like edit_person.php's own $isEditable.
$switchIconFor = [];
$returnToMasterPersonId = null;
$peripheralLinksStmt = $pdo->prepare(
    'SELECT master_person_id, peripheral_person_id, master_family_group_id, peripheral_family_group_id
     FROM peripheral_tree_links
     WHERE master_family_group_id = :gid1 OR peripheral_family_group_id = :gid2'
);
$peripheralLinksStmt->execute(['gid1' => $myGroup, 'gid2' => $myGroup]);
foreach ($peripheralLinksStmt->fetchAll() as $link) {
    if ((int) $link['master_family_group_id'] === $myGroup) {
        $switchIconFor[(int) $link['master_person_id']] = (int) $link['peripheral_person_id'];
    }
    if ((int) $link['peripheral_family_group_id'] === $myGroup) {
        $returnToMasterPersonId = (int) $link['master_person_id'];
    }
}

// Phase 58: "optionally...make copies of timeline entries and postcards
// to the other account" -- a non-naggy banner, only shown when this
// identity is actually one half of a peripheral-tree pair AND there's
// really something pending in at least one direction (see
// ourthology_copy_facility_state() in includes/peripheral.php).
$copyState = ourthology_copy_facility_state($pdo, $myPersonId);
if ($copyState !== null && $copyState['pending_to_other'] === 0 && $copyState['pending_from_other'] === 0) {
    $copyState = null;
}

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
<link rel="stylesheet" href="/styles.css?v=26">
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

  /* Phase 58: peripheral-tree switch icons -- always visible to every
     viewer (so it's clear a family member has another tree), but only
     clickable (cursor + hover glow + JS handler below) for whoever
     actually owns that identity; everyone else sees the same icon as a
     plain, inert badge. */
  .tn-switch-affordance[data-switch-target] { cursor:pointer; }
  .tn-switch-bg { fill:var(--card, #fff); stroke:var(--unclaimed); stroke-width:1.5; }
  .tn-switch-icon { fill:none; stroke:var(--unclaimed); stroke-width:1.5; stroke-linecap:round; stroke-linejoin:round; }
  .tn-switch-affordance[data-switch-target] .tn-switch-bg { stroke:var(--accent); }
  .tn-switch-affordance[data-switch-target] .tn-switch-icon { stroke:var(--accent); }
  .tn-switch-affordance[data-switch-target]:hover .tn-switch-bg { fill:var(--paper-2); }

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

  /* Phase 47: "Invite this person" -- a ready-to-send email draft
     shown in its own pop-up (same fixed-overlay pattern as the edit
     pop-up above), instead of just a bare claim link the inviter had
     to write their own message around. The instructions live outside
     the copyable textarea on purpose, so "Copy this" only ever puts
     the actual email draft on the clipboard, never the instructions. */
  .invite-draft-overlay { position:fixed; inset:0; background:rgba(26,23,20,0.55); z-index:1000; display:flex; align-items:center; justify-content:center; padding:20px; }
  .invite-draft-box { position:relative; width:min(96vw, 560px); max-height:92vh; overflow:auto; background:var(--paper); border:2px solid var(--accent); border-radius:16px; box-shadow:0 24px 60px -20px rgba(0,0,0,0.45); padding:20px 22px; }
  .invite-draft-note { font-size:13px; color:var(--ink-soft); margin:0 0 12px; }
  .invite-draft-text { width:100%; box-sizing:border-box; font-family:"Newsreader",Georgia,serif; font-size:13.5px; line-height:1.5; color:var(--ink); background:#fff; border:1px solid var(--line); border-radius:8px; padding:10px 12px; resize:vertical; }
  .invite-draft-actions { display:flex; align-items:center; gap:10px; margin-top:12px; }
  .invite-draft-copy-btn { font-size:13px; font-weight:600; padding:7px 14px; border-radius:999px; border:1px solid var(--accent); color:var(--on-accent); background:var(--accent); cursor:pointer; font-family:inherit; }
  .invite-draft-copy-btn:hover { background:var(--accent-glow); border-color:var(--accent-glow); }

  /* Print: a family tree is wide, so print it landscape and let the full
     diagram scale to the page rather than printing whatever's currently
     scrolled into view — override the on-screen overflow:auto/fixed pixel
     box with overflow:visible + a fluid SVG. Page chrome that isn't part
     of the diagram itself (nav bar, invite-link flash, the unclaimed list)
     is hidden; the heading and the key legend stay since they give useful
     context on a printed page. */
  @media print {
    @page { size: landscape; margin: 10mm; }
    .nav, .flash, .flash-label, .invite-draft-overlay, .birthday-banner, #unclaimedSection { display:none !important; }
    .tree-wrap { overflow:visible; border:none; box-shadow:none; background:transparent; padding:0; cursor:default; }
    .tree-wrap svg { width:100% !important; height:auto !important; }
  }

  /* ---------- onboarding tour (Phase 28) ----------
     Byte-identical to timeline.php's copy of these same rules — the tour
     moves between the two pages, so its spotlight/tooltip needs to look
     the same wherever it's currently showing. */
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
        <a href="/calendar.php" id="tourCalendarLink">Family calendar</a>
        <a href="/pending.php" id="tourPendingLink" class="<?= $pendingCount ? 'badge' : '' ?>">Pending<?= $pendingCount ? " ($pendingCount)" : '' ?></a>
        <button type="button" id="printTreeBtn" class="linklet-btn" onclick="window.print()">Print tree</button>
      </div>
      <div class="whoami">
        Signed in as <strong><?= htmlspecialchars($me['email'], ENT_QUOTES) ?></strong>
        <form method="post" action="/logout.php"><button type="submit" class="linklet">Log out</button></form>
      </div>
    </div>

    <?php if ($flashPeripheral): ?>
      <div class="flash" style="background:var(--paper-2);"><?= htmlspecialchars($flashPeripheral, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php if ($returnToMasterPersonId !== null): ?>
      <p style="margin:0 0 4px;font-size:13px;color:var(--ink-faint);">
        You're viewing a peripheral family tree.
        <form method="post" action="/switch_person.php" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="target_person_id" value="<?= $returnToMasterPersonId ?>">
          <button type="submit" class="linklet">Return to the original tree</button>
        </form>
      </p>
    <?php endif; ?>

    <?php if ($copyState !== null): ?>
      <div class="flash" style="background:var(--paper-2);display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;">
        <span>Want copies of your memories shared with <strong><?= htmlspecialchars($copyState['counterpart_name'], ENT_QUOTES) ?></strong>'s tree?</span>
        <?php if ($copyState['pending_to_other'] > 0): ?>
          <form method="post" action="/peripheral_copy.php" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="direction" value="to_other">
            <button type="submit" class="linklet">Copy <?= (int) $copyState['pending_to_other'] ?> of yours to their tree</button>
          </form>
        <?php endif; ?>
        <?php if ($copyState['pending_from_other'] > 0): ?>
          <form method="post" action="/peripheral_copy.php" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="direction" value="from_other">
            <button type="submit" class="linklet">Copy <?= (int) $copyState['pending_from_other'] ?> of theirs to here</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($flashLink): ?>
      <?php
        // Phase 47: build a ready-to-send email draft around the link
        // instead of just showing the bare URL -- addressed to the
        // invitee by name, signed by the inviter, both already on hand
        // from fetch_family_graph() / current_user_with_person().
        $inviteePerson = $personsById[$flashFor] ?? [];
        $inviteeName = person_display_name($inviteePerson);
        $inviteeFirst = trim((string) ($inviteePerson['first_name'] ?? '')) ?: $inviteeName;
        $inviterName = person_display_name(['first_name' => $me['first_name'], 'surname' => $me['surname']]);
        $inviteSubject = 'Join our family tree on ourthology.com';
        $inviteBody = "Hi {$inviteeFirst},\n\n"
            . "{$inviterName} has started building our family's tree on ourthology.com and would like you to be part of it.\n\n"
            . "You can claim your own profile here:\n{$flashLink}\n\n"
            . "Once you follow the link, you'll be able to add your own memories and photos, and see how you connect to the rest of the family.\n\n"
            . "— {$inviterName}";
        $inviteDraftText = "Subject: {$inviteSubject}\n\n{$inviteBody}";
      ?>
      <div class="invite-draft-overlay" id="inviteDraftOverlay">
        <div class="invite-draft-box" role="dialog" aria-modal="true" aria-labelledby="inviteDraftTitle">
          <button type="button" class="edit-popup-close" id="inviteDraftClose" aria-label="Close">×</button>
          <p class="flash-label" id="inviteDraftTitle" style="margin-top:0;font-weight:600;">Invite <?= htmlspecialchars($inviteeName, ENT_QUOTES) ?></p>
          <p class="invite-draft-note">Copy this into your normal email system and send it to <?= htmlspecialchars($inviteeFirst, ENT_QUOTES) ?>.</p>
          <textarea id="inviteDraftText" class="invite-draft-text" readonly rows="12"><?= htmlspecialchars($inviteDraftText, ENT_QUOTES) ?></textarea>
          <div class="invite-draft-actions">
            <button type="button" id="inviteDraftCopyBtn" class="invite-draft-copy-btn" data-copy-label="Copy this" data-copied-label="Copied!">Copy this</button>
          </div>
        </div>
      </div>
      <script>
        (function () {
          var overlay = document.getElementById('inviteDraftOverlay');
          if (!overlay) return;
          function closeInviteDraft() {
            overlay.remove();
            document.removeEventListener('keydown', onEscape);
          }
          function onEscape(evt) {
            if (evt.key === 'Escape') closeInviteDraft();
          }
          overlay.addEventListener('click', function (evt) {
            if (evt.target === overlay) closeInviteDraft();
          });
          document.getElementById('inviteDraftClose').addEventListener('click', closeInviteDraft);
          document.addEventListener('keydown', onEscape);

          var copyBtn = document.getElementById('inviteDraftCopyBtn');
          var textEl = document.getElementById('inviteDraftText');
          function showCopied() {
            copyBtn.textContent = copyBtn.dataset.copiedLabel || 'Copied!';
            setTimeout(function () {
              copyBtn.textContent = copyBtn.dataset.copyLabel || 'Copy this';
            }, 1600);
          }
          function fallbackCopy() {
            textEl.focus();
            textEl.select();
            try { document.execCommand('copy'); } catch (e) {}
            showCopied();
          }
          copyBtn.addEventListener('click', function () {
            // textEl.value is the draft ONLY -- the instructions paragraph
            // above it is a separate element and is never included here.
            var text = textEl.value;
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(text).then(showCopied, fallbackCopy);
            } else {
              fallbackCopy();
            }
          });
        })();
      </script>
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
                <?php if (isset($switchIconFor[$pid])): ?>
                  <?php
                    // Phase 58: "switch to your other family" -- shown
                    // beside anyone on this tree who's had a peripheral
                    // tree created for them, visible to every viewer but
                    // only clickable (data-switch-target present) for
                    // whoever actually owns that identity -- see the JS
                    // handler below and switch_person.php itself, which
                    // re-checks this exact ownership independently anyway.
                    $switchCx = -$hitW / 2 + 11;
                    $switchCy = -$hitH / 2 + 11;
                    $canSwitch = !empty($person['claimed_by_user_id']) && (int) $person['claimed_by_user_id'] === (int) $me['user_id'];
                  ?>
                  <g class="tn-switch-affordance" <?= $canSwitch ? 'data-switch-target="' . (int) $switchIconFor[$pid] . '"' : '' ?> transform="translate(<?= $switchCx ?>, <?= $switchCy ?>)" aria-label="Switch to their other family tree">
                    <title><?= $canSwitch ? 'Switch to your other family tree' : htmlspecialchars(person_display_name($person), ENT_QUOTES) . ' has another family tree' ?></title>
                    <circle class="tn-switch-bg" r="10"></circle>
                    <path class="tn-switch-icon" d="M-4,-1.5 H3 M0.5,-4 L3,-1.5 L0.5,1 M4,1.5 H-3 M-0.5,4 L-3,1.5 L-0.5,-1"></path>
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
          <?php if ($switchIconFor): ?>
            &nbsp;·&nbsp; <span class="tree-swatch" style="border-radius:50%;border:1.5px solid var(--accent);background:#fff;width:13px;height:13px;"></span>has another family tree of their own
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
                  <button type="submit" class="linklet">Invite this person</button>
                </form>
              <?php endif; ?>
              · <a class="linklet" href="/edit_person.php?person_id=<?= (int) $p['id'] ?>" style="text-decoration:underline;">edit</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>

  <!-- Phase 58: shared hidden form the switch-icon click handler below
       submits to -- one form reused for every node's icon, rather than
       one per node, mirroring how the edit pop-up is one shared overlay
       rather than one per node. -->
  <form method="post" action="/switch_person.php" id="switchPersonForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="target_person_id" id="switchPersonTarget" value="">
  </form>

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

      // Phase 58: the "switch to your other family" icon -- only the
      // ones the server actually gave a data-switch-target (i.e. this
      // viewer owns that identity) do anything; the plain badge shown to
      // everyone else has no target attribute and so gets no handler.
      document.querySelectorAll('.tn-switch-affordance[data-switch-target]').forEach(function (icon) {
        icon.addEventListener('click', function (evt) {
          evt.preventDefault();
          evt.stopPropagation();
          document.getElementById('switchPersonTarget').value = icon.dataset.switchTarget;
          document.getElementById('switchPersonForm').submit();
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

  <?php ourthology_render_tour('tree', (int) $me['person_id']); ?>
</body>
</html>
