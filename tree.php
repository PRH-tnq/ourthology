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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My tree — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
<style>
  body { align-items: flex-start; }
  .wide { max-width: 640px; }
  .nav { display:flex; gap:10px; flex-wrap:wrap; margin: 18px 0 4px; }
  .nav a { font-size:13px; padding:7px 12px; border-radius:999px; border:1px solid var(--line); color:var(--ink-soft); text-decoration:none; background:#fff; }
  .nav a.badge { background: var(--error-bg); border-color: var(--error-bg); color: var(--accent); font-weight:600; }
  ul.plain { list-style:none; padding:0; margin:8px 0; }
  ul.plain li { padding:8px 0; border-bottom:1px solid var(--line); font-size:14px; }
  .tag { display:inline-block; font-size:11px; text-transform:uppercase; letter-spacing:.03em; padding:2px 6px; border-radius:4px; margin-left:6px; }
  .tag.claimed { background:#e2ecdf; color:#3a6b4f; }
  .tag.unclaimed { background:var(--paper-2); color:var(--ink-faint); }
  .linklet { font-size:12px; background:transparent; border:none; color:var(--accent); cursor:pointer; padding:0; text-decoration:underline; }
  .flash { word-break:break-all; font-size:13px; background:#fff; border:1px solid var(--line); border-radius:6px; padding:8px; margin:8px 0 16px; }
</style>
</head>
<body>
  <div class="card wide">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <div class="nav">
      <a href="/dashboard.php">Dashboard</a>
      <a href="/add_relative.php">+ Add a relative</a>
      <a href="/link_existing.php">Link to existing account</a>
      <a href="/pending.php" class="<?= $pendingCount ? 'badge' : '' ?>">Pending<?= $pendingCount ? " ($pendingCount)" : '' ?></a>
    </div>

    <?php if ($flashLink): ?>
      <p style="margin-top:16px;font-weight:600;">Invite link for <?= htmlspecialchars(person_display_name($personsById[$flashFor] ?? []), ENT_QUOTES) ?>:</p>
      <p class="flash"><?= htmlspecialchars($flashLink, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <h3 style="margin-bottom:4px;">People in your tree (<?= count($graph['persons']) ?>)</h3>
    <ul class="plain">
      <?php foreach ($graph['persons'] as $p): ?>
        <li>
          <a href="/timeline.php?person_id=<?= (int) $p['id'] ?>" style="color:var(--ink);text-decoration:none;font-weight:600;"><?= htmlspecialchars(person_display_name($p), ENT_QUOTES) ?></a>
          <?php if ((int) $p['id'] === $myPersonId): ?><em>(you)</em><?php endif; ?>
          <span class="tag <?= $p['claimed_by_user_id'] ? 'claimed' : 'unclaimed' ?>"><?= $p['claimed_by_user_id'] ? 'claimed' : 'unclaimed' ?></span>
          <?php if (!$p['claimed_by_user_id']): ?>
            <form method="post" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="get_link">
              <input type="hidden" name="person_id" value="<?= (int) $p['id'] ?>">
              <button type="submit" class="linklet">get invite link</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <h3 style="margin-bottom:4px;">Relationships</h3>
    <ul class="plain">
      <?php foreach ($graph['relationships'] as $r): ?>
        <li>
          <?= htmlspecialchars(person_display_name($personsById[(int) $r['parent_id']] ?? []), ENT_QUOTES) ?>
          is the <?= htmlspecialchars($r['relation_kind'], ENT_QUOTES) ?> parent of
          <?= htmlspecialchars(person_display_name($personsById[(int) $r['child_id']] ?? []), ENT_QUOTES) ?>
        </li>
      <?php endforeach; ?>
      <?php foreach ($graph['partnerships'] as $p): ?>
        <li>
          <?= htmlspecialchars(person_display_name($personsById[(int) $p['person_a_id']] ?? []), ENT_QUOTES) ?>
          &amp; <?= htmlspecialchars(person_display_name($personsById[(int) $p['person_b_id']] ?? []), ENT_QUOTES) ?>
          — <?= htmlspecialchars($p['kind'], ENT_QUOTES) ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$graph['relationships'] && !$graph['partnerships']): ?>
        <li style="border:none;color:var(--ink-faint);">No relationships yet — add a relative to get started.</li>
      <?php endif; ?>
    </ul>

    <p style="font-size:12px;color:var(--ink-faint);margin-top:20px;">This plain-list view is the Phase 2 data layer — the real visual tree (river/rings/spiral) moves here next.</p>
  </div>
</body>
</html>
