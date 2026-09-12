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
$myGroup = (int) person_row($pdo, (int) $me['person_id'])['family_group_id'];

$graph = fetch_family_graph($pdo, $myGroup);
$maps = graph_build_maps($graph);
$personsById = [];
foreach ($graph['persons'] as $p) {
    $personsById[(int) $p['id']] = $p;
}

/**
 * Attach mode: instead of creating a brand-new person, this run connects
 * an EXISTING person already in the family group (reached from
 * edit_person.php, e.g. "attach this person as a relative of someone
 * else" — the fix for a person who was recorded with the wrong
 * relationship the first time, since there's no other way to reclassify
 * them). Same relationship vocabulary and resolution logic either way;
 * only what "NEW" resolves to, and what happens on success, differs.
 */
$existingPersonId = filter_var($_GET['existing_person_id'] ?? $_POST['existing_person_id'] ?? '', FILTER_VALIDATE_INT);
$existingPersonId = ($existingPersonId !== false && isset($personsById[$existingPersonId])) ? (int) $existingPersonId : null;
$existingPerson = $existingPersonId !== null ? $personsById[$existingPersonId] : null;

$RELATIONSHIP_OPTIONS = relationship_options();
if ($existingPerson !== null) {
    // "Other / not connected" would attach nothing at all — meaningless
    // when the person already exists in the tree.
    unset($RELATIONSHIP_OPTIONS['other']);
}
$VIA_NEEDED = relationship_via_needed();

$anchors = $graph['persons'];
if ($existingPersonId !== null) {
    $anchors = array_values(array_filter($anchors, fn($a) => (int) $a['id'] !== $existingPersonId));
}

$errors = [];
$successLink = null;
$successMessage = null;
$first = $middle = $surname = '';
$anchorId = (string) $me['person_id'];
$relationship = $existingPerson !== null ? 'sibling' : 'parent';
$viaId = '';
$relationKind = 'genetic';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $first        = trim((string) ($_POST['first_name'] ?? ''));
    $middle       = trim((string) ($_POST['middle_name'] ?? ''));
    $surname      = trim((string) ($_POST['surname'] ?? ''));
    $anchorId     = (string) ($_POST['anchor_id'] ?? '');
    $relationship = (string) ($_POST['relationship'] ?? '');
    $viaId        = (string) ($_POST['via_id'] ?? '');
    $relationKind = (string) ($_POST['relation_kind'] ?? 'genetic');

    if ($existingPersonId === null && $first === '') {
        $errors[] = 'First name is required.';
    }
    if (!isset($RELATIONSHIP_OPTIONS[$relationship])) {
        $errors[] = 'Choose a valid relationship.';
    }
    if (!in_array($relationKind, ['genetic', 'step', 'adoptive'], true)) {
        $relationKind = 'genetic';
    }
    $anchorIdInt = filter_var($anchorId, FILTER_VALIDATE_INT);
    if ($anchorIdInt === false || !person_in_group($pdo, (int) $anchorIdInt, $myGroup)) {
        $errors[] = 'That anchor person is not in your family tree.';
    } elseif ($existingPersonId !== null && (int) $anchorIdInt === $existingPersonId) {
        $errors[] = 'Choose someone else to connect them to.';
    }
    $viaIdInt = filter_var($viaId, FILTER_VALIDATE_INT);
    $viaIdInt = $viaIdInt === false ? null : (int) $viaIdInt;
    if ($viaIdInt !== null && !person_in_group($pdo, $viaIdInt, $myGroup)) {
        $errors[] = 'That "connected through" person is not in your family tree.';
    }

    $plan = null;
    if (!$errors) {
        $plan = resolve_relationship($relationship, (int) $anchorIdInt, $viaIdInt, $relationKind, $maps, $personsById, $VIA_NEEDED);
        if (!$plan['ok']) {
            $errors[] = $plan['error'];
        }
    }

    if (!$errors && $plan !== null) {
        try {
            $pdo->beginTransaction();

            if ($existingPersonId !== null) {
                $newPersonId = $existingPersonId;
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO persons (first_name, middle_name, surname, claimed_by_user_id, created_by_user_id, family_group_id)
                     VALUES (:first, :middle, :surname, NULL, :creator, :gid)'
                );
                $stmt->execute([
                    'first'   => $first,
                    'middle'  => $middle !== '' ? $middle : null,
                    'surname' => $surname !== '' ? $surname : null,
                    'creator' => $me['user_id'],
                    'gid'     => $myGroup,
                ]);
                $newPersonId = (int) $pdo->lastInsertId();
            }

            $resolveNew = function ($v) use ($newPersonId) {
                return $v === 'NEW' ? $newPersonId : (int) $v;
            };

            foreach ($plan['edges'] ?? [] as $edge) {
                $pdo->prepare(
                    "INSERT INTO relationships (parent_id, child_id, relation_kind, status, created_by_user_id)
                     VALUES (:p, :c, :kind, 'confirmed', :uid)"
                )->execute([
                    'p'    => $resolveNew($edge['parent']),
                    'c'    => $resolveNew($edge['child']),
                    'kind' => $edge['kind'],
                    'uid'  => $me['user_id'],
                ]);
            }
            foreach ($plan['partnerships'] ?? [] as $part) {
                $a = $resolveNew($part['a']);
                $b = $resolveNew($part['b']);
                // create_confirmed_partnership() throws RuntimeException(
                // 'duplicate_partnership') if this exact pair is already
                // recorded — worth guarding directly, especially now that
                // "attach an existing person" makes it easy to re-submit a
                // link that's already there.
                create_confirmed_partnership($pdo, $a, $b, 'married', (int) $me['user_id']);
            }

            if ($existingPersonId !== null) {
                $pdo->commit();
                $anchorName = isset($personsById[(int) $anchorIdInt]) ? person_display_name($personsById[(int) $anchorIdInt]) : 'that person';
                $successMessage = person_display_name($existingPerson) . ' is now recorded as a relative of ' . $anchorName . '.';
            } else {
                $token = create_claim_token($pdo, $newPersonId, (int) $me['user_id']);
                $pdo->commit();
                $successLink = claim_link_url($token);
            }
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors[] = 'They are already recorded as partners.';
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('ourthology add_relative error: ' . $e->getMessage());
            $errors[] = 'Something went wrong adding that person. Please try again.';
        }
    }
}

// Plain adjacency maps for the page's own JS, so the "connected through"
// dropdown can be rebuilt live as the anchor/relationship selections
// change — using the exact same siblingsOf/auntsUnclesOf definitions as
// resolve_relationship() above, just computed client-side for instant
// feedback (the server still re-checks everything on submit).
$namesJson = [];
foreach ($personsById as $pid => $p) {
    $namesJson[$pid] = person_display_name($p) . ($pid === (int) $me['person_id'] ? ' (you)' : '');
}
$jsMaps = [
    'names'      => $namesJson,
    'parentsOf'  => $maps['parentsOf'],
    'childrenOf' => $maps['childrenOf'],
    'partnersOf' => $maps['partnersOf'],
];
$viaNeededJson = [];
foreach ($VIA_NEEDED as $rel => $cfg) {
    $viaNeededJson[$rel] = ['source' => $cfg['source'], 'prompt' => $cfg['prompt']];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $existingPerson !== null ? 'Attach ' . htmlspecialchars(person_display_name($existingPerson), ENT_QUOTES) : 'Add a relative' ?> — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
<style>
  select { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; font-size:15px; font-family:inherit; background:#fff; color:var(--ink); }
  .field-group { margin-top:0; }
  .hint { margin:4px 0 0; font-size:12px; color:var(--ink-faint); }
</style>
</head>
<body>
  <div class="card" style="max-width:460px;">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <?php if ($successLink): ?>
      <div style="background:var(--paper-2);border-radius:8px;padding:14px;margin-top:16px;">
        <p style="margin:0 0 8px;font-weight:600;">Added — here's their invite link:</p>
        <p style="word-break:break-all;font-size:13px;background:#fff;border:1px solid var(--line);border-radius:6px;padding:8px;"><?= htmlspecialchars($successLink, ENT_QUOTES) ?></p>
        <p style="margin:8px 0 0;font-size:13px;color:var(--ink-faint);">Copy this and send it to them yourself (text, email, whatever) — it lets them set a password and claim this record as their own. It expires in 30 days; you can generate a fresh one later from your tree page.</p>
      </div>
      <p class="foot-link"><a href="/tree.php">Back to my tree</a> · <a href="/add_relative.php">Add another</a></p>
    <?php elseif ($successMessage): ?>
      <div style="background:var(--paper-2);border-radius:8px;padding:14px;margin-top:16px;">
        <p style="margin:0;font-weight:600;"><?= htmlspecialchars($successMessage, ENT_QUOTES) ?></p>
      </div>
      <p class="foot-link"><a href="/tree.php">Back to my tree</a> · <a href="/edit_person.php?person_id=<?= $existingPersonId ?>">Their profile</a></p>
    <?php else: ?>

    <?php if ($existingPerson !== null): ?>
      <p style="margin-top:16px;font-size:14px;">
        Attaching <strong><?= htmlspecialchars(person_display_name($existingPerson), ENT_QUOTES) ?></strong> — already in your tree — as a relative of someone else in it. This doesn't create a new person, it just records a new relationship for them.
      </p>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate id="addRelativeForm">
      <?= csrf_field() ?>
      <?php if ($existingPersonId !== null): ?>
        <input type="hidden" name="existing_person_id" value="<?= $existingPersonId ?>">
      <?php endif; ?>

      <label for="anchor_id">Connected to</label>
      <select id="anchor_id" name="anchor_id">
        <?php foreach ($anchors as $a): ?>
          <option value="<?= (int) $a['id'] ?>" <?= (string) $a['id'] === $anchorId ? 'selected' : '' ?>>
            <?= htmlspecialchars(person_display_name($a), ENT_QUOTES) ?><?= (int) $a['id'] === (int) $me['person_id'] ? ' (you)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label for="relationship"><?= $existingPerson !== null ? htmlspecialchars(person_display_name($existingPerson), ENT_QUOTES) . ' is that person\'s' : 'New person is that person\'s' ?></label>
      <select id="relationship" name="relationship">
        <?php
          $groups = [];
          foreach ($RELATIONSHIP_OPTIONS as $val => $opt) {
              $groups[$opt['group']][] = $val;
          }
        ?>
        <?php foreach ($groups as $groupLabel => $vals): ?>
          <optgroup label="<?= htmlspecialchars($groupLabel, ENT_QUOTES) ?>">
            <?php foreach ($vals as $val): ?>
              <option value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= $relationship === $val ? 'selected' : '' ?>><?= htmlspecialchars($RELATIONSHIP_OPTIONS[$val]['label'], ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>

      <div class="field-group" id="viaGroup" hidden>
        <label for="via_id" id="viaLabel">Connected through</label>
        <select id="via_id" name="via_id"></select>
        <p class="hint">Only people already in your tree can be picked here — if no one shows up, add that relative first.</p>
      </div>

      <div class="field-group" id="kindGroup">
        <label for="relation_kind">Relationship type</label>
        <select id="relation_kind" name="relation_kind">
          <option value="genetic" <?= $relationKind === 'genetic' ? 'selected' : '' ?>>Genetic</option>
          <option value="step" <?= $relationKind === 'step' ? 'selected' : '' ?>>Step</option>
          <option value="adoptive" <?= $relationKind === 'adoptive' ? 'selected' : '' ?>>Adoptive</option>
        </select>
      </div>

      <?php if ($existingPerson === null): ?>
        <div class="row-2">
          <div>
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($first, ENT_QUOTES) ?>" maxlength="60" required>
          </div>
          <div>
            <label for="surname">Surname</label>
            <input type="text" id="surname" name="surname" value="<?= htmlspecialchars($surname, ENT_QUOTES) ?>" maxlength="60">
          </div>
        </div>
        <label for="middle_name">Middle name <span style="text-transform:none;font-weight:400;">(optional)</span></label>
        <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($middle, ENT_QUOTES) ?>" maxlength="60">
      <?php endif; ?>

      <button type="submit" class="btn-primary"><?= $existingPerson !== null ? 'Attach relationship' : 'Add person' ?></button>
    </form>
    <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>
    <?php endif; ?>
  </div>

<script id="graphMapsData" type="application/json"><?= str_replace('</', '<\/', (string) json_encode($jsMaps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></script>
<script id="viaNeededData" type="application/json"><?= str_replace('</', '<\/', (string) json_encode($viaNeededJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></script>
<script>
(function () {
  "use strict";
  var MAPS = JSON.parse(document.getElementById('graphMapsData').textContent);
  var VIA_NEEDED = JSON.parse(document.getElementById('viaNeededData').textContent);

  function siblingsOf(id) {
    var out = {};
    (MAPS.parentsOf[id] || []).forEach(function (p) {
      (MAPS.childrenOf[p] || []).forEach(function (c) {
        if (String(c) !== String(id)) out[c] = true;
      });
    });
    return Object.keys(out);
  }
  function auntsUnclesOf(id) {
    var out = {};
    (MAPS.parentsOf[id] || []).forEach(function (p) {
      siblingsOf(p).forEach(function (s) { out[s] = true; });
    });
    return Object.keys(out);
  }
  function candidatesFor(source, anchorId) {
    if (source === 'siblingsOf') return siblingsOf(anchorId);
    if (source === 'auntsUnclesOf') return auntsUnclesOf(anchorId);
    return (MAPS[source] && MAPS[source][anchorId]) || [];
  }

  var relationshipSel = document.getElementById('relationship');
  var anchorSel = document.getElementById('anchor_id');
  var viaGroup = document.getElementById('viaGroup');
  var viaLabel = document.getElementById('viaLabel');
  var viaSel = document.getElementById('via_id');
  var kindGroup = document.getElementById('kindGroup');
  var saveBtn = document.querySelector('#addRelativeForm button[type="submit"]');
  // Re-selected after a validation error redisplays the form, so a mistake
  // elsewhere (e.g. the name field) doesn't also lose this choice.
  var previousViaId = <?= json_encode($viaId !== '' ? $viaId : null) ?>;

  function refresh() {
    var rel = relationshipSel.value;
    var anchorId = anchorSel.value;
    var cfg = VIA_NEEDED[rel];
    var kindApplies = (rel === 'parent' || rel === 'child');
    kindGroup.hidden = !kindApplies;

    if (!cfg) {
      viaGroup.hidden = true;
      viaSel.innerHTML = '';
      saveBtn.disabled = false;
      return;
    }

    viaGroup.hidden = false;
    viaLabel.textContent = cfg.prompt;
    var ids = candidatesFor(cfg.source, anchorId);
    viaSel.innerHTML = '';
    if (!ids.length) {
      var opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'None recorded yet — add that relative first';
      viaSel.appendChild(opt);
      saveBtn.disabled = true;
      return;
    }
    ids.forEach(function (id) {
      var opt = document.createElement('option');
      opt.value = id;
      opt.textContent = MAPS.names[id] || ('#' + id);
      viaSel.appendChild(opt);
    });
    if (previousViaId !== null && ids.indexOf(String(previousViaId)) !== -1) {
      viaSel.value = previousViaId;
    }
    saveBtn.disabled = false;
  }

  relationshipSel.addEventListener('change', refresh);
  anchorSel.addEventListener('change', refresh);
  refresh();
  previousViaId = null; // only restore once, right after page load
})();
</script>
</body>
</html>
