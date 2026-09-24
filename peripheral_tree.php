<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/peripheral.php';

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Location: /login.php');
    exit;
}
$pdo = ourthology_pdo();
$myGroup = (int) person_row($pdo, (int) $me['person_id'])['family_group_id'];

/**
 * Phase 58: lands here only via add_relative.php's own redirect, right
 * after it detects an attempt to give an in-law-only person (see
 * includes/peripheral.php) a brand-new ancestor on the master tree. The
 * pending action lives in the session, not the URL/a form field, so it
 * can't be replayed or tampered with from outside that one redirect; it's
 * re-validated here from scratch anyway (see below) since a lot can
 * change in the time it takes to read this page and tick a couple of
 * checkboxes.
 */
$pending = $_SESSION['pending_peripheral'] ?? null;
if (!is_array($pending) || !isset($pending['in_law_person_id'], $pending['created_at'])
    || (time() - (int) $pending['created_at']) > 3600) {
    unset($_SESSION['pending_peripheral']);
    $pending = null;
}

$inLawPersonId = $pending !== null ? (int) $pending['in_law_person_id'] : 0;
$inLawPerson = $pending !== null ? person_row($pdo, $inLawPersonId) : null;
$expired = null;

if ($pending === null) {
    $expired = "That request has expired or wasn't found — go back to your tree and try again.";
} elseif ($inLawPerson === null || (int) $inLawPerson['family_group_id'] !== $myGroup) {
    $expired = "That person isn't in your family tree anymore — go back to your tree and try again.";
    unset($_SESSION['pending_peripheral']);
} elseif (!ourthology_is_in_law_only($pdo, $inLawPersonId)) {
    // Something changed the graph in the meantime (someone else added a
    // blood relationship for them in the interim) -- no longer applies.
    $expired = person_display_name($inLawPerson) . " is now directly connected to your family tree, so this no longer applies — go back to your tree and try again.";
    unset($_SESSION['pending_peripheral']);
}

$household = $inLawPerson !== null ? ourthology_in_law_household($pdo, $inLawPersonId) : ['partners' => [], 'descendants' => []];

$errors = [];
$successLink = null;
$successMessage = null;

if ($expired === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Never trust the submitted checkboxes directly — re-intersect with
    // the household as it stands right now in the database. Filtering
    // $household['descendants'] (rather than building a new array from
    // the checked ids) keeps it in the same parents-before-children order
    // ourthology_create_peripheral_tree() relies on.
    $checkedPartnerIds    = array_map('intval', $_POST['partner_ids'] ?? []);
    $checkedDescendantIds = array_map('intval', $_POST['descendant_ids'] ?? []);
    $bringPartners    = array_values(array_filter($household['partners'], fn($p) => in_array((int) $p['id'], $checkedPartnerIds, true)));
    $bringDescendants = array_values(array_filter($household['descendants'], fn($d) => in_array((int) $d['id'], $checkedDescendantIds, true)));

    try {
        $pdo->beginTransaction();
        $result = ourthology_create_peripheral_tree(
            $pdo,
            $inLawPerson,
            (int) $me['user_id'],
            ['first' => $pending['new_first'], 'middle' => $pending['new_middle'], 'surname' => $pending['new_surname']],
            ['kind' => $pending['edge_kind']],
            $bringPartners,
            $bringDescendants
        );

        if ($result['claimed']) {
            $pdo->commit();
            unset($_SESSION['pending_peripheral']);
            ourthology_switch_active_person($pdo, (int) $me['user_id'], $result['peripheral_person_id']);
            $newAntecedentName = trim($pending['new_first'] . ($pending['new_surname'] !== '' ? ' ' . $pending['new_surname'] : ''));
            $_SESSION['flash_peripheral_message'] = "You're now on " . person_display_name($inLawPerson) . "'s own family tree — "
                . $newAntecedentName . ' has been added here. Use the icon beside your name any time to switch back to the original tree.';
            header('Location: /tree.php');
            exit;
        }

        // The invite link is for the in-law's ORIGINAL (master) node, same
        // as any other unclaimed person's invite link elsewhere in this
        // app -- not the new peripheral "YOU" node. claim.php propagates
        // the claim to the linked peripheral node automatically once this
        // is claimed (see its own comment), so claiming this one link
        // gives them both.
        $token = create_claim_token($pdo, $inLawPersonId, (int) $me['user_id']);
        $pdo->commit();
        unset($_SESSION['pending_peripheral']);
        $successLink = claim_link_url($token);
        $successMessage = "A new family tree has been created for " . person_display_name($inLawPerson)
            . ", with the new relative added to it. Since " . person_display_name($inLawPerson)
            . " hasn't claimed their own profile yet, here's an invite link to pass on to them — claiming it will give them access to this new tree.";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('ourthology peripheral_tree error: ' . $e->getMessage());
        $errors[] = 'Something went wrong creating that tree. Please try again.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>A new family tree — ourthology.com</title>
<link rel="stylesheet" href="/styles.css?v=27">
<style>
  .household-list { list-style:none; margin:10px 0 0; padding:0; }
  .household-list li { padding:8px 0; border-bottom:1px solid var(--line); }
  .household-list li:last-child { border-bottom:none; }
  .household-list label { display:flex; align-items:center; gap:10px; font-weight:400; text-transform:none; cursor:pointer; }
  .explain-box { background:var(--paper-2); border-radius:8px; padding:14px; margin:16px 0; font-size:14px; line-height:1.5; }
</style>
</head>
<body>
  <div class="card" style="max-width:460px;">
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

    <?php if ($expired !== null): ?>
      <div class="error"><div><?= htmlspecialchars($expired, ENT_QUOTES) ?></div></div>
      <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>

    <?php elseif ($successLink): ?>
      <div style="background:var(--paper-2);border-radius:8px;padding:14px;margin-top:16px;">
        <p style="margin:0 0 8px;font-weight:600;"><?= htmlspecialchars($successMessage, ENT_QUOTES) ?></p>
        <p style="word-break:break-all;font-size:13px;background:#fff;border:1px solid var(--line);border-radius:6px;padding:8px;"><?= htmlspecialchars($successLink, ENT_QUOTES) ?></p>
      </div>
      <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>

    <?php else: ?>

      <h1 style="font-size:19px;margin:4px 0 0;">A new family tree for <?= htmlspecialchars(person_display_name($inLawPerson), ENT_QUOTES) ?></h1>

      <div class="explain-box">
        <p style="margin:0 0 10px;">
          <strong><?= htmlspecialchars(person_display_name($inLawPerson), ENT_QUOTES) ?></strong> is connected to your
          family tree by marriage or partnership, but isn't a blood relative on it — so their own parents,
          grandparents and so on can't be added onto this tree without mixing two separate families together.
        </p>
        <p style="margin:0;">
          Instead, we're creating <?= htmlspecialchars(person_display_name($inLawPerson), ENT_QUOTES) ?> a brand-new
          family tree of their own to build out — a full tree, with every feature this one has. You'll be able to
          switch between the two any time using an icon beside
          <?= ((int) ($inLawPerson['claimed_by_user_id'] ?? 0) === (int) $me['user_id']) ? 'your name' : 'their name' ?>
          on the tree.
        </p>
      </div>

      <?php if ($errors): ?>
        <div class="error">
          <?php foreach ($errors as $err): ?>
            <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($household['partners'] || $household['descendants']): ?>
        <form method="post" id="peripheralBringForm">
          <?= csrf_field() ?>
          <p style="margin:16px 0 0;font-weight:600;font-size:14px;">
            Also bring across (only what you tick here is copied — nothing else from the original tree comes with it):
          </p>
          <ul class="household-list">
            <?php foreach ($household['partners'] as $p): ?>
              <li><label><input type="checkbox" name="partner_ids[]" value="<?= (int) $p['id'] ?>"> <?= htmlspecialchars(person_display_name($p), ENT_QUOTES) ?> (their partner)</label></li>
            <?php endforeach; ?>
            <?php foreach ($household['descendants'] as $d): ?>
              <li style="padding-left:<?= 8 + 20 * ((int) $d['depth'] - 1) ?>px;">
                <label>
                  <input type="checkbox" name="descendant_ids[]" value="<?= (int) $d['id'] ?>"
                         data-descendant-id="<?= (int) $d['id'] ?>" data-parent-master-id="<?= (int) $d['parent_master_id'] ?>">
                  <?= htmlspecialchars(person_display_name($d), ENT_QUOTES) ?> (their <?= htmlspecialchars(ourthology_descendant_label((int) $d['depth']), ENT_QUOTES) ?>)
                </label>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if (array_filter($household['descendants'], fn($d) => (int) $d['depth'] > 1)): ?>
            <p class="hint" style="margin-top:6px;">A grandchild (or further down) can only come across together with their own parent — tick the parent first and their own box will unlock.</p>
          <?php endif; ?>
          <p class="hint" style="margin-top:8px;">A copy is made as a starting point on the new tree — it won't stay linked to or update from the original.</p>
          <button type="submit" class="btn-primary" style="margin-top:16px;">Create their family tree</button>
        </form>
        <script>
          (function () {
            var form = document.getElementById('peripheralBringForm');
            if (!form) return;
            var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-descendant-id]'));
            var byId = {};
            boxes.forEach(function (b) { byId[b.getAttribute('data-descendant-id')] = b; });

            function childBoxesOf(id) {
              return boxes.filter(function (b) { return b.getAttribute('data-parent-master-id') === id; });
            }

            function sync(box) {
              var parentBox = byId[box.getAttribute('data-parent-master-id')];
              var enabled = !parentBox || parentBox.checked;
              box.disabled = !enabled;
              if (!enabled && box.checked) {
                box.checked = false;
              }
              childBoxesOf(box.getAttribute('data-descendant-id')).forEach(sync);
            }

            boxes.forEach(function (b) {
              b.addEventListener('change', function () {
                childBoxesOf(b.getAttribute('data-descendant-id')).forEach(sync);
              });
            });
            boxes.forEach(sync);
          })();
        </script>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <button type="submit" class="btn-primary" style="margin-top:6px;">Create their family tree</button>
        </form>
      <?php endif; ?>

      <p class="foot-link"><a href="/tree.php">Cancel — back to my tree</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
