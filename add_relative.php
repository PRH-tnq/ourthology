<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/tour_engine.php';

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
$secondParentId = '';
$secondParentKind = 'genetic';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $first        = trim((string) ($_POST['first_name'] ?? ''));
    $middle       = trim((string) ($_POST['middle_name'] ?? ''));
    $surname      = trim((string) ($_POST['surname'] ?? ''));
    $anchorId     = (string) ($_POST['anchor_id'] ?? '');
    $relationship = (string) ($_POST['relationship'] ?? '');
    $viaId        = (string) ($_POST['via_id'] ?? '');
    $relationKind = (string) ($_POST['relation_kind'] ?? 'genetic');
    $secondParentId   = (string) ($_POST['second_parent_id'] ?? '');
    $secondParentKind = (string) ($_POST['second_parent_kind'] ?? 'genetic');

    if ($existingPersonId === null && $first === '') {
        $errors[] = 'First name is required.';
    }
    if (!isset($RELATIONSHIP_OPTIONS[$relationship])) {
        $errors[] = 'Choose a valid relationship.';
    }
    if (!in_array($relationKind, ['genetic', 'step', 'adoptive'], true)) {
        $relationKind = 'genetic';
    }
    if (!in_array($secondParentKind, ['genetic', 'step', 'adoptive'], true)) {
        $secondParentKind = 'genetic';
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

    // A child almost always has two parents — when adding one, the anchor's
    // own current partner (if they have one on record) can be recorded as
    // the second parent in the same step, tagged genetic/step/adoptive
    // independently of the anchor's own tag. Only meaningful for the
    // 'child' relationship, and never trusting the client's dynamically
    // populated dropdown — re-checked here against the anchor's actual
    // recorded partners.
    $secondParentIdInt = null;
    if ($relationship === 'child' && $secondParentId !== '') {
        $candidateId = filter_var($secondParentId, FILTER_VALIDATE_INT);
        if ($candidateId === false || !in_array((int) $candidateId, $maps['partnersOf'][(int) $anchorIdInt] ?? [], true)) {
            $errors[] = "That person isn't recorded as this anchor's partner, so they can't be added as the second parent.";
        } elseif ($existingPersonId !== null && (int) $candidateId === $existingPersonId) {
            $errors[] = 'Choose someone else as the second parent.';
        } else {
            $secondParentIdInt = (int) $candidateId;
        }
    }

    $plan = null;
    if (!$errors) {
        $plan = resolve_relationship($relationship, (int) $anchorIdInt, $viaIdInt, $relationKind, $maps, $personsById, $VIA_NEEDED, $secondParentIdInt, $secondParentKind);
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
                $pId = $resolveNew($edge['parent']);
                $cId = $resolveNew($edge['child']);
                // relationships has uniq_parent_child, but a plain insert
                // failure there would surface as an opaque "something went
                // wrong" — only reachable when attaching an EXISTING person
                // (a brand-new person can't already have this edge), but
                // now more likely to come up given a child can gain a
                // second parent edge in the same submission, so worth its
                // own clear message rather than falling through generic.
                if ($existingPersonId !== null) {
                    $dupStmt = $pdo->prepare('SELECT 1 FROM relationships WHERE parent_id = :p AND child_id = :c');
                    $dupStmt->execute(['p' => $pId, 'c' => $cId]);
                    if ($dupStmt->fetchColumn()) {
                        throw new RuntimeException('duplicate_relationship');
                    }
                }
                $pdo->prepare(
                    "INSERT INTO relationships (parent_id, child_id, relation_kind, status, created_by_user_id)
                     VALUES (:p, :c, :kind, 'confirmed', :uid)"
                )->execute([
                    'p'    => $pId,
                    'c'    => $cId,
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
            $errors[] = $e->getMessage() === 'duplicate_relationship'
                ? 'That relationship is already recorded.'
                : 'They are already recorded as partners.';
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
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $existingPerson !== null ? 'Attach ' . htmlspecialchars(person_display_name($existingPerson), ENT_QUOTES) : 'Add a relative' ?> — ourthology.com</title>
<link rel="stylesheet" href="/styles.css?v=23">
<style>
  select { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; font-size:15px; font-family:inherit; background:#fff; color:var(--ink); }
  .field-group { margin-top:0; }
  .hint { margin:4px 0 0; font-size:12px; color:var(--ink-faint); }
  input[type="text"]::placeholder { color:var(--ink-faint); opacity:1; }
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

      <div class="field-group" id="parent2Group" hidden>
        <label for="second_parent_id">Second parent <span style="text-transform:none;font-weight:400;">(optional)</span></label>
        <select id="second_parent_id" name="second_parent_id">
          <option value="" <?= $secondParentId === '' ? 'selected' : '' ?>>No second parent</option>
        </select>
        <p class="hint">Only whoever's already recorded as that person's partner can be picked here — a child almost always has two parents, so this records the second one in the same step.</p>
        <div id="parent2KindGroup" hidden style="margin-top:10px;">
          <label for="second_parent_kind">Their relationship to this child</label>
          <select id="second_parent_kind" name="second_parent_kind">
            <option value="genetic" <?= $secondParentKind === 'genetic' ? 'selected' : '' ?>>Genetic</option>
            <option value="step" <?= $secondParentKind === 'step' ? 'selected' : '' ?>>Step</option>
            <option value="adoptive" <?= $secondParentKind === 'adoptive' ? 'selected' : '' ?>>Adoptive</option>
          </select>
        </div>
      </div>

      <?php if ($existingPerson === null): ?>
        <div class="row-2">
          <div>
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($first, ENT_QUOTES) ?>" placeholder="e.g. Alex" maxlength="60" required>
          </div>
          <div>
            <label for="surname">Surname</label>
            <input type="text" id="surname" name="surname" value="<?= htmlspecialchars($surname, ENT_QUOTES) ?>" placeholder="e.g. Rivera" maxlength="60">
          </div>
        </div>
        <label for="middle_name">Middle name <span style="text-transform:none;font-weight:400;">(optional)</span></label>
        <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($middle, ENT_QUOTES) ?>" placeholder="e.g. Marie" maxlength="60">
      <?php endif; ?>

      <button type="submit" class="btn-primary" id="addRelativeSubmitBtn"><?= $existingPerson !== null ? 'Attach relationship' : 'Add person' ?></button>
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
  var parent2Group = document.getElementById('parent2Group');
  var parent2Sel = document.getElementById('second_parent_id');
  var parent2KindGroup = document.getElementById('parent2KindGroup');
  var saveBtn = document.querySelector('#addRelativeForm button[type="submit"]');
  // Re-selected after a validation error redisplays the form, so a mistake
  // elsewhere (e.g. the name field) doesn't also lose this choice.
  var previousViaId = <?= json_encode($viaId !== '' ? $viaId : null) ?>;
  var previousParent2Id = <?= json_encode($secondParentId !== '' ? $secondParentId : null) ?>;
  // Excluded from the second-parent picker so an existing person being
  // attached (edit_person.php's "attach as a relative" mode) can never be
  // offered as their own second parent, even if they happen to already be
  // recorded as the anchor's partner.
  var EXISTING_PERSON_ID = <?= json_encode($existingPersonId) ?>;

  // A child almost always has two parents — only the 'child' relationship
  // offers a second-parent picker, and only from whoever's already
  // recorded as the anchor's own partner (that's who a second parent
  // realistically is here); tagged genetic/step/adoptive independently of
  // the anchor's own tag above.
  function refreshParent2() {
    var rel = relationshipSel.value;
    var anchorId = anchorSel.value;
    var isChildRel = (rel === 'child');
    parent2Group.hidden = !isChildRel;
    parent2Sel.innerHTML = '';
    if (!isChildRel) {
      parent2KindGroup.hidden = true;
      return;
    }
    var partners = (MAPS.partnersOf[anchorId] || []).filter(function (id) {
      return EXISTING_PERSON_ID === null || String(id) !== String(EXISTING_PERSON_ID);
    });
    var noneOpt = document.createElement('option');
    noneOpt.value = '';
    noneOpt.textContent = partners.length ? 'No second parent' : 'No partner on record for them yet';
    parent2Sel.appendChild(noneOpt);
    partners.forEach(function (id) {
      var opt = document.createElement('option');
      opt.value = id;
      opt.textContent = MAPS.names[id] || ('#' + id);
      parent2Sel.appendChild(opt);
    });
    if (previousParent2Id !== null && partners.indexOf(String(previousParent2Id)) !== -1) {
      parent2Sel.value = previousParent2Id;
    }
    parent2KindGroup.hidden = !parent2Sel.value;
  }
  parent2Sel && parent2Sel.addEventListener('change', function () {
    parent2KindGroup.hidden = !parent2Sel.value;
  });

  function refresh() {
    var rel = relationshipSel.value;
    var anchorId = anchorSel.value;
    var cfg = VIA_NEEDED[rel];
    var kindApplies = (rel === 'parent' || rel === 'child');
    kindGroup.hidden = !kindApplies;
    refreshParent2();

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
  previousParent2Id = null;
})();
</script>
  <?php ourthology_render_tour('add_relative', (int) $me['person_id']); ?>
</body>
</html>
