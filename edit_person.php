<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
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
$myUserId = (int) $me['user_id'];
$myGroup = (int) person_row($pdo, $myPersonId)['family_group_id'];

ourthology_start_session();

$flashNotice = $_SESSION['flash_edit_notice'] ?? null;
unset($_SESSION['flash_edit_notice']);

$personIdRaw = $_GET['person_id'] ?? $_POST['person_id'] ?? '';
$personIdFilter = filter_var($personIdRaw, FILTER_VALIDATE_INT);
$personId = ($personIdFilter !== false && person_in_group($pdo, (int) $personIdFilter, $myGroup)) ? (int) $personIdFilter : null;

// Fetched up front (before any POST handling) so the edit permission is
// known before deciding whether to act on a submitted form at all — an
// unclaimed person can be corrected by anyone in the family group, but a
// claimed person's own record and relationships belong to that account
// holder alone, exactly like their name always has been.
$person = $personId !== null ? person_row($pdo, $personId) : null;
$canEdit = $person !== null && person_is_editable_by($person, $myUserId);
$isClaimedByOther = $person !== null && !empty($person['claimed_by_user_id']) && !$canEdit;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($personId === null) {
        $errors[] = 'Choose a person first.';
    } elseif (!$canEdit) {
        $errors[] = 'This person has claimed their own record — only they can edit their profile.';
    } elseif ($action === 'update_profile') {
        $first = trim((string) ($_POST['first_name'] ?? ''));
        $middle = trim((string) ($_POST['middle_name'] ?? ''));
        $surname = trim((string) ($_POST['surname'] ?? ''));
        if ($first === '') {
            $errors[] = 'First name is required.';
        }

        // Born/died, each entered as three distinct slots (DD/MM/YYYY),
        // same pattern as add_entry.php's date fields — optional, but only
        // together, so the format can't come out wrong.
        $bDay   = trim((string) ($_POST['born_day'] ?? ''));
        $bMonth = trim((string) ($_POST['born_month'] ?? ''));
        $bYear  = trim((string) ($_POST['born_year'] ?? ''));
        $dDay   = trim((string) ($_POST['died_day'] ?? ''));
        $dMonth = trim((string) ($_POST['died_month'] ?? ''));
        $dYear  = trim((string) ($_POST['died_year'] ?? ''));

        $bornValue = null;
        $bCount = (int) ($bDay !== '') + (int) ($bMonth !== '') + (int) ($bYear !== '');
        if ($bCount > 0 && $bCount < 3) {
            $errors[] = 'Fill in the day, month, and year of birth, or leave all three blank.';
        } elseif ($bCount === 3) {
            if (!ctype_digit($bDay) || !ctype_digit($bMonth) || !ctype_digit($bYear)
                || !checkdate((int) $bMonth, (int) $bDay, (int) $bYear)) {
                $errors[] = 'Enter a real birth date (or leave day/month/year all blank).';
            } else {
                $bornValue = sprintf('%04d-%02d-%02d', (int) $bYear, (int) $bMonth, (int) $bDay);
            }
        }

        $diedValue = null;
        $dCount = (int) ($dDay !== '') + (int) ($dMonth !== '') + (int) ($dYear !== '');
        if ($dCount > 0 && $dCount < 3) {
            $errors[] = 'Fill in the day, month, and year of death, or leave all three blank.';
        } elseif ($dCount === 3) {
            if (!ctype_digit($dDay) || !ctype_digit($dMonth) || !ctype_digit($dYear)
                || !checkdate((int) $dMonth, (int) $dDay, (int) $dYear)) {
                $errors[] = 'Enter a real date of death (or leave day/month/year all blank).';
            } else {
                $diedValue = sprintf('%04d-%02d-%02d', (int) $dYear, (int) $dMonth, (int) $dDay);
            }
        }

        if (!$errors && $bornValue !== null && $diedValue !== null && $diedValue < $bornValue) {
            $errors[] = 'The date of death is before the date of birth.';
        }

        if (!$errors) {
            $pdo->prepare(
                'UPDATE persons SET first_name = :f, middle_name = :m, surname = :s, born = :b, died = :d WHERE id = :id'
            )->execute([
                'f'  => $first,
                'm'  => $middle !== '' ? $middle : null,
                's'  => $surname !== '' ? $surname : null,
                'b'  => $bornValue,
                'd'  => $diedValue,
                'id' => $personId,
            ]);
            $_SESSION['flash_edit_notice'] = 'Profile updated.';
            header('Location: /edit_person.php?person_id=' . $personId);
            exit;
        }
    } elseif ($action === 'update_kind') {
        $relId = filter_var($_POST['relationship_id'] ?? '', FILTER_VALIDATE_INT);
        $kind = (string) ($_POST['relation_kind'] ?? '');
        if ($relId === false || !in_array($kind, ['genetic', 'step', 'adoptive'], true)) {
            $errors[] = 'Invalid request.';
        } else {
            $stmt = $pdo->prepare('SELECT parent_id, child_id FROM relationships WHERE id = :id');
            $stmt->execute(['id' => (int) $relId]);
            $rel = $stmt->fetch();
            if (!$rel || ((int) $rel['parent_id'] !== $personId && (int) $rel['child_id'] !== $personId)) {
                $errors[] = 'That relationship is not this person\'s to edit.';
            } else {
                $pdo->prepare("UPDATE relationships SET relation_kind = :k WHERE id = :id")
                    ->execute(['k' => $kind, 'id' => (int) $relId]);
                $_SESSION['flash_edit_notice'] = 'Relationship type updated.';
                header('Location: /edit_person.php?person_id=' . $personId);
                exit;
            }
        }
    } elseif ($action === 'remove_relationship') {
        $relId = filter_var($_POST['relationship_id'] ?? '', FILTER_VALIDATE_INT);
        if ($relId === false) {
            $errors[] = 'Invalid request.';
        } else {
            $stmt = $pdo->prepare('SELECT parent_id, child_id FROM relationships WHERE id = :id');
            $stmt->execute(['id' => (int) $relId]);
            $rel = $stmt->fetch();
            if (!$rel || ((int) $rel['parent_id'] !== $personId && (int) $rel['child_id'] !== $personId)) {
                $errors[] = 'That relationship is not this person\'s to edit.';
            } else {
                $pdo->prepare('DELETE FROM relationships WHERE id = :id')->execute(['id' => (int) $relId]);
                $_SESSION['flash_edit_notice'] = 'Relationship removed. If this was recorded by mistake, use "Attach as a relative" below to add the correct one.';
                header('Location: /edit_person.php?person_id=' . $personId);
                exit;
            }
        }
    } elseif ($action === 'remove_partnership') {
        $partId = filter_var($_POST['partnership_id'] ?? '', FILTER_VALIDATE_INT);
        if ($partId === false) {
            $errors[] = 'Invalid request.';
        } else {
            $stmt = $pdo->prepare('SELECT person_a_id, person_b_id FROM partnerships WHERE id = :id');
            $stmt->execute(['id' => (int) $partId]);
            $part = $stmt->fetch();
            if (!$part || ((int) $part['person_a_id'] !== $personId && (int) $part['person_b_id'] !== $personId)) {
                $errors[] = 'That relationship is not this person\'s to edit.';
            } else {
                $pdo->prepare('DELETE FROM partnerships WHERE id = :id')->execute(['id' => (int) $partId]);
                $_SESSION['flash_edit_notice'] = 'Relationship removed.';
                header('Location: /edit_person.php?person_id=' . $personId);
                exit;
            }
        }
    } elseif ($action === 'add_partnership') {
        $otherId = filter_var($_POST['other_person_id'] ?? '', FILTER_VALIDATE_INT);
        $kind = (string) ($_POST['kind'] ?? 'married');
        if (!in_array($kind, ['married', 'partner'], true)) {
            $kind = 'married';
        }
        if ($otherId === false || (int) $otherId === $personId || !person_in_group($pdo, (int) $otherId, $myGroup)) {
            $errors[] = 'Choose someone else in your family tree to record as their partner.';
        } else {
            try {
                create_confirmed_partnership($pdo, $personId, (int) $otherId, $kind, $myUserId);
                $_SESSION['flash_edit_notice'] = 'Partner added.';
                header('Location: /edit_person.php?person_id=' . $personId);
                exit;
            } catch (RuntimeException $e) {
                $errors[] = 'They are already recorded as partners.';
            }
        }
    } elseif ($action === 'delete_person') {
        // A stricter gate than $canEdit: $canEdit is also true for your own
        // claimed record, but deleting yourself would orphan your own user
        // account (users.person_id would point at nothing), so this only
        // ever proceeds for a still-unclaimed person, checked here again
        // rather than trusting that the delete control was hidden for
        // anyone else.
        if (!empty($person['claimed_by_user_id'])) {
            $errors[] = "This person has claimed their own record and can't be deleted.";
        } else {
            try {
                $pdo->beginTransaction();

                // An unclaimed placeholder can't normally have timeline
                // entries of their own (only a logged-in account can add
                // one, for itself, via add_entry.php) but this is handled
                // defensively in case that's ever no longer true — same
                // file-then-row deletion order timeline.php's own delete
                // action uses, so no media file is ever left orphaned on
                // disk.
                $mediaStmt = $pdo->prepare(
                    'SELECT m.file_path FROM media m
                     JOIN timeline_entries t ON t.id = m.timeline_entry_id
                     WHERE t.person_id = :pid'
                );
                $mediaStmt->execute(['pid' => $personId]);
                foreach ($mediaStmt->fetchAll() as $m) {
                    delete_media_file($m['file_path']);
                }
                // timeline_entries -> media has ON DELETE CASCADE, so
                // deleting the entries is enough to also clear their media
                // rows (the files themselves are already gone, just above).
                $pdo->prepare('DELETE FROM timeline_entries WHERE person_id = :pid')->execute(['pid' => $personId]);
                $pdo->prepare('DELETE FROM claim_tokens WHERE person_id = :pid')->execute(['pid' => $personId]);
                $pdo->prepare('DELETE FROM relationships WHERE parent_id = :pid OR child_id = :pid2')
                    ->execute(['pid' => $personId, 'pid2' => $personId]);
                $pdo->prepare('DELETE FROM partnerships WHERE person_a_id = :pid OR person_b_id = :pid2')
                    ->execute(['pid' => $personId, 'pid2' => $personId]);
                $pdo->prepare('DELETE FROM persons WHERE id = :id')->execute(['id' => $personId]);

                $pdo->commit();
                $_SESSION['flash_edit_notice'] = person_display_name($person) . ' has been deleted, along with their relationships and partnerships.';
                header('Location: /edit_person.php');
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('ourthology delete_person error: ' . $e->getMessage());
                $errors[] = 'Something went wrong deleting that person. Please try again.';
            }
        }
    }
}

$graph = fetch_family_graph($pdo, $myGroup);
$personsById = [];
foreach ($graph['persons'] as $p) {
    $personsById[(int) $p['id']] = $p;
}

// Re-fetch after any successful mutation above would already have redirected,
// so this only runs for a fresh GET or a failed/blocked POST — either way
// $personsById (from the family graph, used for display) should reflect the
// same row $person does.
if ($personId !== null && isset($personsById[$personId])) {
    $person = $personsById[$personId];
    $canEdit = person_is_editable_by($person, $myUserId);
    $isClaimedByOther = !empty($person['claimed_by_user_id']) && !$canEdit;
}

$rels = [];
$parts = [];
if ($person !== null) {
    $relStmt = $pdo->prepare(
        "SELECT r.id, r.parent_id, r.child_id, r.relation_kind,
                pp.first_name AS parent_first, pp.surname AS parent_surname,
                pc.first_name AS child_first, pc.surname AS child_surname
         FROM relationships r
         JOIN persons pp ON pp.id = r.parent_id
         JOIN persons pc ON pc.id = r.child_id
         WHERE r.status = 'confirmed' AND (r.parent_id = :pid OR r.child_id = :pid2)
         ORDER BY r.id"
    );
    $relStmt->execute(['pid' => $personId, 'pid2' => $personId]);
    $rels = $relStmt->fetchAll();

    $partStmt = $pdo->prepare(
        "SELECT p.id, p.person_a_id, p.person_b_id, p.kind,
                pa.first_name AS a_first, pa.surname AS a_surname,
                pb.first_name AS b_first, pb.surname AS b_surname
         FROM partnerships p
         JOIN persons pa ON pa.id = p.person_a_id
         JOIN persons pb ON pb.id = p.person_b_id
         WHERE p.status = 'confirmed' AND (p.person_a_id = :pid OR p.person_b_id = :pid2)
         ORDER BY p.id"
    );
    $partStmt->execute(['pid' => $personId, 'pid2' => $personId]);
    $parts = $partStmt->fetchAll();
}

// Candidates for the "add a partner" picker: anyone else in the family
// group who isn't already recorded as this person's partner.
$existingPartnerIds = [];
foreach ($parts as $p) {
    $existingPartnerIds[] = (int) $p['person_a_id'] === $personId ? (int) $p['person_b_id'] : (int) $p['person_a_id'];
}
$partnerCandidates = [];
if ($person !== null) {
    foreach ($graph['persons'] as $p) {
        $pid = (int) $p['id'];
        if ($pid !== $personId && !in_array($pid, $existingPartnerIds, true)) {
            $partnerCandidates[] = $p;
        }
    }
}

$confirmRel = filter_var($_GET['confirm_rel'] ?? '', FILTER_VALIDATE_INT);
$confirmRel = $confirmRel === false ? null : (int) $confirmRel;
$confirmPart = filter_var($_GET['confirm_part'] ?? '', FILTER_VALIDATE_INT);
$confirmPart = $confirmPart === false ? null : (int) $confirmPart;
$confirmDelete = ($_GET['confirm_delete'] ?? '') === '1';

$kindLabels = ['genetic' => 'genetic', 'step' => 'step', 'adoptive' => 'adoptive'];

// Re-populate the profile form's fields from the just-submitted POST on a
// validation error, so a mistake in one field doesn't lose the others —
// otherwise fall back to the stored values.
function ourthology_split_date(?string $value): array
{
    if (!$value) {
        return ['', '', ''];
    }
    [$y, $m, $d] = array_map('intval', explode('-', $value));
    return [(string) $d, (string) $m, (string) $y];
}

$postedProfile = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile' && $errors;
if ($postedProfile) {
    $pFirst = trim((string) ($_POST['first_name'] ?? ''));
    $pMiddle = trim((string) ($_POST['middle_name'] ?? ''));
    $pSurname = trim((string) ($_POST['surname'] ?? ''));
    $pBornDay = trim((string) ($_POST['born_day'] ?? ''));
    $pBornMonth = trim((string) ($_POST['born_month'] ?? ''));
    $pBornYear = trim((string) ($_POST['born_year'] ?? ''));
    $pDiedDay = trim((string) ($_POST['died_day'] ?? ''));
    $pDiedMonth = trim((string) ($_POST['died_month'] ?? ''));
    $pDiedYear = trim((string) ($_POST['died_year'] ?? ''));
} elseif ($person !== null) {
    $pFirst = (string) $person['first_name'];
    $pMiddle = (string) ($person['middle_name'] ?? '');
    $pSurname = (string) ($person['surname'] ?? '');
    [$pBornDay, $pBornMonth, $pBornYear] = ourthology_split_date($person['born'] ?? null);
    [$pDiedDay, $pDiedMonth, $pDiedYear] = ourthology_split_date($person['died'] ?? null);
} else {
    $pFirst = $pMiddle = $pSurname = $pBornDay = $pBornMonth = $pBornYear = $pDiedDay = $pDiedMonth = $pDiedYear = '';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit person — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
<style>
  .nav { display:flex; gap:10px 16px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin: 18px 0 4px; }
  .nav-links { display:flex; gap:10px; flex-wrap:wrap; }
  .nav a { font-size:13px; padding:7px 12px; border-radius:999px; border:1px solid var(--line); color:var(--ink-soft); text-decoration:none; background:#fff; }
  .whoami { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12.5px; color:var(--ink-faint); }
  .whoami strong { color:var(--ink-soft); font-weight:600; }
  .whoami form { display:inline; }
  .whoami .linklet { font-size:12.5px; }
  select { padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; background:#fff; color:var(--ink); }
  .notice { background:var(--paper-2); border-radius:8px; padding:10px 12px; font-size:14px; margin-top:16px; }
  .locked-notice { background:var(--paper-2); border-radius:8px; padding:10px 12px; font-size:14px; margin-top:8px; color:var(--ink-soft); }
  .rel-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 0; border-bottom:1px solid var(--line); font-size:14px; flex-wrap:wrap; }
  .rel-desc { flex:1 1 auto; min-width:220px; }
  .rel-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .rel-actions form { display:inline-flex; align-items:center; gap:6px; }
  .btn-danger { font-size:12px; background:transparent; border:1px solid var(--error); color:var(--error); border-radius:999px; padding:5px 10px; cursor:pointer; }
  .btn-danger:hover { background:var(--error-bg); }
  .btn-small { font-size:12px; background:#fff; border:1px solid var(--line); color:var(--ink-soft); border-radius:999px; padding:5px 10px; cursor:pointer; }
  .confirm-box { background:var(--error-bg); border-radius:8px; padding:12px 14px; margin:10px 0; font-size:14px; }
  .confirm-box .actions { display:flex; gap:10px; margin-top:10px; }
  select.kind-select { font-size:12px; padding:4px 6px; }
  .date-row { display:flex; gap:10px; margin-top:6px; }
  .date-slot input { width:100%; padding:9px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; text-align:center; }
  .date-slot { flex:1 1 0; }
  .date-slot span { display:block; font-size:11px; color:var(--ink-faint); text-align:center; margin-top:4px; }
  .add-partner-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-top:10px; }
  .add-partner-row select { min-width:180px; }
</style>
</head>
<body>
  <div class="card" style="max-width:560px;">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <div class="nav">
      <div class="nav-links">
        <a href="/timeline.php">My timeline</a>
        <a href="/tree.php">My tree</a>
        <a href="/add_relative.php">+ Add a relative</a>
        <a href="/edit_person.php?person_id=<?= $myPersonId ?>">Edit my own profile</a>
      </div>
      <div class="whoami">
        Signed in as <strong><?= htmlspecialchars($me['email'], ENT_QUOTES) ?></strong>
        <form method="post" action="/logout.php"><button type="submit" class="linklet">Log out</button></form>
      </div>
    </div>

    <?php if ($flashNotice): ?>
      <div class="notice"><?= htmlspecialchars($flashNotice, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p style="font-size:13px;color:var(--ink-faint);margin-top:12px;">
      Fix a person's name, dates, or partners, or correct a relationship that was recorded wrong (for example, someone who should show up as a sibling but was accidentally added as a parent). Anyone in your family tree can be corrected here while they're still unclaimed; once someone claims their own record, only they can change it.
    </p>

    <form method="get" style="margin-top:12px;">
      <label for="person_id">Person</label>
      <select id="person_id" name="person_id" onchange="this.form.submit()">
        <option value="">Choose someone…</option>
        <?php foreach ($graph['persons'] as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= $personId === (int) $p['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars(person_display_name($p), ENT_QUOTES) ?><?= (int) $p['id'] === $myPersonId ? ' (you)' : '' ?><?= $p['claimed_by_user_id'] ? '' : ' (unclaimed)' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn-small" style="margin-top:8px;">Go</button></noscript>
    </form>

    <?php if ($person !== null): ?>

      <?php if ($isClaimedByOther): ?>
        <div class="locked-notice">
          <?= htmlspecialchars(person_display_name($person), ENT_QUOTES) ?> has claimed their own record, so only they can edit their profile, dates, and relationships. You can still see what's on record below.
        </div>
      <?php endif; ?>

      <h3 style="margin:24px 0 4px;">Profile</h3>
      <?php if ($canEdit): ?>
        <form method="post" style="margin-top:8px;">
          <?= csrf_field() ?>
          <input type="hidden" name="person_id" value="<?= $personId ?>">
          <input type="hidden" name="action" value="update_profile">
          <div class="row-2">
            <div>
              <label for="first_name">First name</label>
              <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($pFirst, ENT_QUOTES) ?>" maxlength="60" required>
            </div>
            <div>
              <label for="surname">Surname</label>
              <input type="text" id="surname" name="surname" value="<?= htmlspecialchars($pSurname, ENT_QUOTES) ?>" maxlength="60">
            </div>
          </div>
          <label for="middle_name">Middle name <span style="text-transform:none;font-weight:400;">(optional)</span></label>
          <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($pMiddle, ENT_QUOTES) ?>" maxlength="60">

          <label style="margin-top:14px;display:block;">Born <span style="text-transform:none;font-weight:400;">(optional)</span></label>
          <div class="date-row">
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="born_day" placeholder="DD" value="<?= htmlspecialchars($pBornDay, ENT_QUOTES) ?>">
              <span>Day</span>
            </div>
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="born_month" placeholder="MM" value="<?= htmlspecialchars($pBornMonth, ENT_QUOTES) ?>">
              <span>Month</span>
            </div>
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" name="born_year" placeholder="YYYY" value="<?= htmlspecialchars($pBornYear, ENT_QUOTES) ?>">
              <span>Year</span>
            </div>
          </div>

          <label style="margin-top:14px;display:block;">Died <span style="text-transform:none;font-weight:400;">(optional)</span></label>
          <div class="date-row">
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="died_day" placeholder="DD" value="<?= htmlspecialchars($pDiedDay, ENT_QUOTES) ?>">
              <span>Day</span>
            </div>
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="died_month" placeholder="MM" value="<?= htmlspecialchars($pDiedMonth, ENT_QUOTES) ?>">
              <span>Month</span>
            </div>
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" name="died_year" placeholder="YYYY" value="<?= htmlspecialchars($pDiedYear, ENT_QUOTES) ?>">
              <span>Year</span>
            </div>
          </div>

          <button type="submit" class="btn-primary" style="margin-top:14px;">Save profile</button>
        </form>
      <?php else: ?>
        <p style="font-size:14px;">
          <strong><?= htmlspecialchars(person_display_name($person), ENT_QUOTES) ?></strong>
          <?php if (!empty($person['born'])): ?> · born <?= htmlspecialchars(date('j M Y', strtotime((string) $person['born'])), ENT_QUOTES) ?><?php endif; ?>
          <?php if (!empty($person['died'])): ?> · died <?= htmlspecialchars(date('j M Y', strtotime((string) $person['died'])), ENT_QUOTES) ?><?php endif; ?>
        </p>
      <?php endif; ?>

      <h3 style="margin:24px 0 4px;">Relationships</h3>
      <?php if (!$rels): ?>
        <p style="font-size:14px;color:var(--ink-faint);">Not connected to any parent or child yet.</p>
      <?php endif; ?>

      <?php foreach ($rels as $r): ?>
        <?php
          $isParentSide = (int) $r['parent_id'] === $personId;
          $parentName = trim($r['parent_first'] . ' ' . $r['parent_surname']);
          $childName = trim($r['child_first'] . ' ' . $r['child_surname']);
          $otherName = $isParentSide ? $childName : $parentName;
          $desc = $isParentSide
            ? '<strong>' . htmlspecialchars($otherName, ENT_QUOTES) . '</strong> is their child'
            : '<strong>' . htmlspecialchars($otherName, ENT_QUOTES) . '</strong> is their parent';
        ?>
        <div class="rel-row">
          <div class="rel-desc"><?= $desc ?> <span style="color:var(--ink-faint);">(<?= htmlspecialchars($kindLabels[$r['relation_kind']] ?? $r['relation_kind'], ENT_QUOTES) ?>)</span></div>
          <?php if ($canEdit): ?>
          <div class="rel-actions">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="person_id" value="<?= $personId ?>">
              <input type="hidden" name="action" value="update_kind">
              <input type="hidden" name="relationship_id" value="<?= (int) $r['id'] ?>">
              <select name="relation_kind" class="kind-select" onchange="this.form.submitBtn.disabled=false">
                <option value="genetic" <?= $r['relation_kind'] === 'genetic' ? 'selected' : '' ?>>genetic</option>
                <option value="step" <?= $r['relation_kind'] === 'step' ? 'selected' : '' ?>>step</option>
                <option value="adoptive" <?= $r['relation_kind'] === 'adoptive' ? 'selected' : '' ?>>adoptive</option>
              </select>
              <button type="submit" name="submitBtn" class="btn-small">Save</button>
            </form>
            <a href="/edit_person.php?person_id=<?= $personId ?>&confirm_rel=<?= (int) $r['id'] ?>" class="btn-danger">Remove</a>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($canEdit && $confirmRel === (int) $r['id']): ?>
          <div class="confirm-box">
            Remove this relationship — <?= htmlspecialchars($parentName, ENT_QUOTES) ?> as parent of <?= htmlspecialchars($childName, ENT_QUOTES) ?>? This can't be undone (you'd need to add it again from scratch).
            <div class="actions">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="person_id" value="<?= $personId ?>">
                <input type="hidden" name="action" value="remove_relationship">
                <input type="hidden" name="relationship_id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn-danger">Yes, remove it</button>
              </form>
              <a href="/edit_person.php?person_id=<?= $personId ?>" class="btn-small" style="text-decoration:none;display:inline-block;">Cancel</a>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>

      <h3 style="margin:24px 0 4px;">Partners</h3>
      <?php if (!$parts): ?>
        <p style="font-size:14px;color:var(--ink-faint);">No partner recorded yet.</p>
      <?php endif; ?>

      <?php foreach ($parts as $p): ?>
        <?php
          $aName = trim($p['a_first'] . ' ' . $p['a_surname']);
          $bName = trim($p['b_first'] . ' ' . $p['b_surname']);
          $otherName = (int) $p['person_a_id'] === $personId ? $bName : $aName;
        ?>
        <div class="rel-row">
          <div class="rel-desc"><strong><?= htmlspecialchars($otherName, ENT_QUOTES) ?></strong> is their <?= $p['kind'] === 'partner' ? 'partner' : 'spouse' ?></div>
          <?php if ($canEdit): ?>
          <div class="rel-actions">
            <a href="/edit_person.php?person_id=<?= $personId ?>&confirm_part=<?= (int) $p['id'] ?>" class="btn-danger">Remove</a>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($canEdit && $confirmPart === (int) $p['id']): ?>
          <div class="confirm-box">
            Remove the partnership between <?= htmlspecialchars($aName, ENT_QUOTES) ?> and <?= htmlspecialchars($bName, ENT_QUOTES) ?>? This can't be undone (you'd need to add it again from scratch).
            <div class="actions">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="person_id" value="<?= $personId ?>">
                <input type="hidden" name="action" value="remove_partnership">
                <input type="hidden" name="partnership_id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="btn-danger">Yes, remove it</button>
              </form>
              <a href="/edit_person.php?person_id=<?= $personId ?>" class="btn-small" style="text-decoration:none;display:inline-block;">Cancel</a>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($canEdit && $partnerCandidates): ?>
        <form method="post" class="add-partner-row">
          <?= csrf_field() ?>
          <input type="hidden" name="person_id" value="<?= $personId ?>">
          <input type="hidden" name="action" value="add_partnership">
          <div>
            <label for="other_person_id">Add a partner</label>
            <select id="other_person_id" name="other_person_id">
              <?php foreach ($partnerCandidates as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars(person_display_name($c), ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="kind">As</label>
            <select id="kind" name="kind">
              <option value="married">Married</option>
              <option value="partner">Partner</option>
            </select>
          </div>
          <button type="submit" class="btn-small">Add</button>
        </form>
        <p style="font-size:12px;color:var(--ink-faint);margin-top:6px;">Works for two people who don't have their own accounts yet too — this is how to show two placeholder relatives as a couple.</p>
      <?php endif; ?>

      <?php if ($canEdit): ?>
      <p class="foot-link" style="margin-top:20px;">
        <a href="/add_relative.php?existing_person_id=<?= $personId ?>">Attach <?= htmlspecialchars(person_display_name($person), ENT_QUOTES) ?> as a relative of someone else</a>
      </p>
      <p style="font-size:12px;color:var(--ink-faint);margin-top:-12px;">Use this after removing a wrong relationship above, to record the correct one — grandparent, sibling, cousin, and the rest are all available, not just parent/child.</p>
      <?php endif; ?>

      <?php if (empty($person['claimed_by_user_id'])): ?>
        <h3 style="margin:28px 0 4px;color:var(--error);">Delete this person</h3>
        <p style="font-size:13px;color:var(--ink-faint);">Only possible while they're still unclaimed. Removes <?= htmlspecialchars(person_display_name($person), ENT_QUOTES) ?> completely, along with every relationship and partnership recorded for them.</p>
        <?php if (!$confirmDelete): ?>
          <a href="/edit_person.php?person_id=<?= $personId ?>&confirm_delete=1" class="btn-danger">Delete this person</a>
        <?php else: ?>
          <div class="confirm-box">
            Delete <strong><?= htmlspecialchars(person_display_name($person), ENT_QUOTES) ?></strong> completely —
            <?= count($rels) ?> relationship<?= count($rels) === 1 ? '' : 's' ?> and
            <?= count($parts) ?> partnership<?= count($parts) === 1 ? '' : 's' ?> recorded for them will be removed too?
            This can't be undone.
            <div class="actions">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="person_id" value="<?= $personId ?>">
                <input type="hidden" name="action" value="delete_person">
                <button type="submit" class="btn-danger">Yes, delete them</button>
              </form>
              <a href="/edit_person.php?person_id=<?= $personId ?>" class="btn-small" style="text-decoration:none;display:inline-block;">Cancel</a>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    <?php endif; ?>

    <p class="foot-link"><a href="/tree.php">Back to my tree</a></p>
  </div>
</body>
</html>
