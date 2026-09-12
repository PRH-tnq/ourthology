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
$anchors = $graph['persons'];
$maps = graph_build_maps($graph);
$personsById = [];
foreach ($graph['persons'] as $p) {
    $personsById[(int) $p['id']] = $p;
}

/**
 * The full relationship vocabulary from the original prototype
 * (prototypes/timeline.html's RELATIONSHIPS list), grouped the same way.
 * Unlike the prototype — which just filed each one under a fixed
 * generation tier with a label, with no real graph behind it — every one
 * of these has to resolve to actual parent/child/partner edges in this
 * app's shared multi-user graph. Most of them (grandparent, aunt/uncle,
 * sibling, cousin, niece/nephew, grandchild, the in-laws) only make sense
 * "through" some other person already in the tree, so those show a
 * "Connected through" picker (see $VIA_SOURCE below); "parent", "child",
 * "spouse" and "other" don't need one.
 */
$RELATIONSHIP_OPTIONS = [
    'grandparent'    => ['label' => 'Grandparent',           'group' => 'Older generation'],
    'parent'         => ['label' => 'Parent',                'group' => 'Older generation'],
    'parent-in-law'  => ['label' => 'Parent-in-law',         'group' => 'Older generation'],
    'step-parent'    => ['label' => 'Step-parent',           'group' => 'Older generation'],
    'aunt-uncle'     => ['label' => 'Aunt / Uncle',          'group' => 'Older generation'],
    'sibling'        => ['label' => 'Sibling',               'group' => 'Same generation'],
    'spouse'         => ['label' => 'Spouse / Partner',      'group' => 'Same generation'],
    'sibling-in-law' => ['label' => 'Sibling-in-law',        'group' => 'Same generation'],
    'step-sibling'   => ['label' => 'Step-sibling',          'group' => 'Same generation'],
    'cousin'         => ['label' => 'Cousin',                'group' => 'Same generation'],
    'child'          => ['label' => 'Child',                 'group' => 'Younger generation'],
    'child-in-law'   => ['label' => 'Child-in-law',          'group' => 'Younger generation'],
    'step-child'     => ['label' => 'Step-child',            'group' => 'Younger generation'],
    'niece-nephew'   => ['label' => 'Niece / Nephew',        'group' => 'Younger generation'],
    'grandchild'     => ['label' => 'Grandchild',            'group' => 'Younger generation'],
    'other'          => ['label' => 'Other / not connected', 'group' => 'Other'],
];

/**
 * Which existing-person set the "connected through" picker offers for each
 * relationship that needs one, and what to say when that set is empty.
 * 'source' names a lookup this file and the page's own JS both know how to
 * compute from the same parent/child/partner maps ($maps here, mirrored as
 * plain JSON for the client) — so the dropdown the user sees and the
 * server-side check that runs on submit are always in agreement.
 */
$VIA_NEEDED = [
    'grandparent'    => ['source' => 'parentsOf',     'prompt' => 'Whose parent are they?',                 'empty' => 'Add one of their parents first, then add a grandparent through them.'],
    'parent-in-law'  => ['source' => 'partnersOf',    'prompt' => 'They are the parent of…',                 'empty' => 'Add their spouse/partner first, then add a parent-in-law through them.'],
    'aunt-uncle'     => ['source' => 'parentsOf',     'prompt' => 'Sibling of which of their parents?',      'empty' => 'Add one of their parents first, then add an aunt or uncle through them.'],
    'sibling-in-law' => ['source' => 'siblingsOf',    'prompt' => 'Spouse of which sibling?',                'empty' => 'Add a sibling first, then add their spouse as a sibling-in-law.'],
    'step-sibling'   => ['source' => 'parentsOf',     'prompt' => 'Step-child of which of their parents?',   'empty' => 'Add one of their parents first, then add a step-sibling through them.'],
    'cousin'         => ['source' => 'auntsUnclesOf', 'prompt' => 'Child of which aunt or uncle?',           'empty' => 'Add an aunt or uncle first, then add their child as a cousin.'],
    'child-in-law'   => ['source' => 'childrenOf',    'prompt' => 'Spouse of which child?',                  'empty' => 'Add a child first, then add their spouse as a child-in-law.'],
    'niece-nephew'   => ['source' => 'siblingsOf',    'prompt' => 'Child of which sibling?',                 'empty' => 'Add a sibling first, then add their child as a niece or nephew.'],
    'grandchild'     => ['source' => 'childrenOf',    'prompt' => 'Child of which child?',                   'empty' => 'Add a child first, then add their child as a grandchild.'],
];

function candidates_for_source(string $source, int $anchorId, array $maps): array
{
    switch ($source) {
        case 'parentsOf':     return $maps['parentsOf'][$anchorId] ?? [];
        case 'childrenOf':    return $maps['childrenOf'][$anchorId] ?? [];
        case 'partnersOf':    return $maps['partnersOf'][$anchorId] ?? [];
        case 'siblingsOf':    return graph_siblings_of($maps, $anchorId);
        case 'auntsUnclesOf': return graph_aunts_uncles_of($maps, $anchorId);
        default:              return [];
    }
}

/**
 * Independently re-derives what edges a submission means, never trusting
 * the client's dynamically-populated "connected through" list — it's
 * rebuilt here from the current database state. Returns
 * ['ok' => true, 'edges' => [...], 'partnerships' => [...]] (each edge/
 * partnership using the string 'NEW' as a stand-in for the not-yet-inserted
 * person) or ['ok' => false, 'error' => '...'].
 */
function resolve_relationship(string $rel, int $anchorId, ?int $viaId, string $directKind, array $maps, array $personsById, array $viaNeeded): array
{
    if (isset($viaNeeded[$rel])) {
        $cfg = $viaNeeded[$rel];
        $candidates = candidates_for_source($cfg['source'], $anchorId, $maps);
        if (!$candidates) {
            return ['ok' => false, 'error' => $cfg['empty']];
        }
        if ($viaId === null || !in_array($viaId, $candidates, true)) {
            return ['ok' => false, 'error' => 'Choose who to connect them through.'];
        }
    }

    switch ($rel) {
        case 'parent':
            return ['ok' => true, 'edges' => [['parent' => 'NEW', 'child' => $anchorId, 'kind' => $directKind]]];
        case 'step-parent':
            return ['ok' => true, 'edges' => [['parent' => 'NEW', 'child' => $anchorId, 'kind' => 'step']]];
        case 'child':
            return ['ok' => true, 'edges' => [['parent' => $anchorId, 'child' => 'NEW', 'kind' => $directKind]]];
        case 'step-child':
            return ['ok' => true, 'edges' => [['parent' => $anchorId, 'child' => 'NEW', 'kind' => 'step']]];
        case 'spouse':
            return ['ok' => true, 'partnerships' => [['a' => $anchorId, 'b' => 'NEW']]];
        case 'grandparent':
            return ['ok' => true, 'edges' => [['parent' => 'NEW', 'child' => $viaId, 'kind' => 'genetic']]];
        case 'parent-in-law':
            return ['ok' => true, 'edges' => [['parent' => 'NEW', 'child' => $viaId, 'kind' => 'genetic']]];
        case 'aunt-uncle':
            $grandparents = $maps['parentsOf'][$viaId] ?? [];
            if (!$grandparents) {
                $name = isset($personsById[$viaId]) ? person_display_name($personsById[$viaId]) : 'That person';
                return ['ok' => false, 'error' => $name . ' has no parent on record yet — add one first, then add an aunt or uncle through them.'];
            }
            $edges = [];
            foreach ($grandparents as $g) {
                $edges[] = ['parent' => $g, 'child' => 'NEW', 'kind' => 'genetic'];
            }
            return ['ok' => true, 'edges' => $edges];
        case 'sibling':
            $parents = $maps['parentsOf'][$anchorId] ?? [];
            if (!$parents) {
                return ['ok' => false, 'error' => 'Add one of their parents first, then add a sibling through them.'];
            }
            $edges = [];
            foreach ($parents as $p) {
                $edges[] = ['parent' => $p, 'child' => 'NEW', 'kind' => 'genetic'];
            }
            return ['ok' => true, 'edges' => $edges];
        case 'sibling-in-law':
            return ['ok' => true, 'partnerships' => [['a' => $viaId, 'b' => 'NEW']]];
        case 'step-sibling':
            return ['ok' => true, 'edges' => [['parent' => $viaId, 'child' => 'NEW', 'kind' => 'step']]];
        case 'cousin':
            return ['ok' => true, 'edges' => [['parent' => $viaId, 'child' => 'NEW', 'kind' => 'genetic']]];
        case 'child-in-law':
            return ['ok' => true, 'partnerships' => [['a' => $viaId, 'b' => 'NEW']]];
        case 'niece-nephew':
            return ['ok' => true, 'edges' => [['parent' => $viaId, 'child' => 'NEW', 'kind' => 'genetic']]];
        case 'grandchild':
            return ['ok' => true, 'edges' => [['parent' => $viaId, 'child' => 'NEW', 'kind' => 'genetic']]];
        case 'other':
            return ['ok' => true, 'edges' => [], 'partnerships' => []];
        default:
            return ['ok' => false, 'error' => 'Choose a valid relationship.'];
    }
}

$errors = [];
$successLink = null;
$first = $middle = $surname = '';
$anchorId = (string) $me['person_id'];
$relationship = 'parent';
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

    if ($first === '') {
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
                $lo = min($a, $b);
                $hi = max($a, $b);
                $pdo->prepare(
                    "INSERT INTO partnerships (person_a_id, person_b_id, kind, status, created_by_user_id)
                     VALUES (:a, :b, 'married', 'confirmed', :uid)"
                )->execute(['a' => $lo, 'b' => $hi, 'uid' => $me['user_id']]);
            }

            $token = create_claim_token($pdo, $newPersonId, (int) $me['user_id']);

            $pdo->commit();

            $successLink = claim_link_url($token);
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
<title>Add a relative — ourthology.com</title>
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
    <?php else: ?>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate id="addRelativeForm">
      <?= csrf_field() ?>

      <label for="anchor_id">Connected to</label>
      <select id="anchor_id" name="anchor_id">
        <?php foreach ($anchors as $a): ?>
          <option value="<?= (int) $a['id'] ?>" <?= (string) $a['id'] === $anchorId ? 'selected' : '' ?>>
            <?= htmlspecialchars(person_display_name($a), ENT_QUOTES) ?><?= (int) $a['id'] === (int) $me['person_id'] ? ' (you)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label for="relationship">New person is that person's</label>
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

      <button type="submit" class="btn-primary">Add person</button>
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
