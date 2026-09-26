<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/places.php';
require_once __DIR__ . '/includes/tour_engine.php';

/**
 * Phase 92: "Places we lived" -- one master map per person showing every
 * home they lived in, joined in the order they lived there, each pin (and
 * its matching card in the list beside the map) clickable to expand the
 * full story of that home: address, who lived there and when, photos, and
 * any number of dated updates (an extension, a new kitchen...) each with
 * their own photos. A home is entered once and shared by everyone ticked
 * as living there -- it shows on each of their own maps. See
 * includes/places.php for the data model and permission rules.
 *
 * Every mutation (save / delete_home / remove_me) is handled right here,
 * POST-only, redirect-with-flash -- the same self-contained shape as
 * calendar.php.
 */

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

$graph = fetch_family_graph($pdo, $myGroup);
$familyById = [];
foreach ($graph['persons'] as $p) {
    $familyById[(int) $p['id']] = $p;
}

function places_redirect(int $personId, ?int $homeId = null): void
{
    header('Location: /places.php?person_id=' . $personId . ($homeId ? '&home=' . $homeId . '#home-' . $homeId : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $mapPersonId = filter_var($_POST['map_person_id'] ?? '', FILTER_VALIDATE_INT);
    $mapPersonId = ($mapPersonId !== false && isset($familyById[(int) $mapPersonId])) ? (int) $mapPersonId : $myPersonId;

    $homeId = filter_var($_POST['home_id'] ?? '', FILTER_VALIDATE_INT);
    $home = $homeId !== false ? fetch_home_row($pdo, (int) $homeId) : null;
    $homeResidents = $home ? (fetch_home_residents($pdo, [(int) $home['id']])[(int) $home['id']] ?? []) : [];

    if ($action === 'remove_me') {
        // Take MYSELF off a home someone else put me on -- the home, its
        // photos and everyone else's place on it are untouched (unless I
        // was the only one left, in which case the now-empty home goes).
        $files = [];
        if ($home !== null && (int) $home['family_group_id'] === $myGroup) {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM home_residents WHERE home_id = :h AND person_id = :p')
                ->execute(['h' => (int) $home['id'], 'p' => $myPersonId]);
            $files = places_delete_empty_homes($pdo);
            $pdo->commit();
            foreach ($files as $f) {
                delete_media_file($f);
            }
            $_SESSION['flash_places_notice'] = 'Removed from your map.';
        }
        places_redirect($mapPersonId);
    }

    if ($action === 'delete_home') {
        if ($home === null || !home_can_edit($home, $homeResidents, $myUserId, $myGroup)) {
            $_SESSION['flash_places_error'] = "That home doesn't exist or isn't yours to delete.";
            places_redirect($mapPersonId);
        }
        $pdo->beginTransaction();
        $files = places_delete_homes($pdo, [(int) $home['id']]);
        $pdo->commit();
        foreach ($files as $f) {
            delete_media_file($f);
        }
        $_SESSION['flash_places_notice'] = 'Home deleted.';
        places_redirect($mapPersonId);
    }

    if ($action !== 'save') {
        places_redirect($mapPersonId);
    }

    // ---------------- save (create or edit) ----------------
    $errors = [];
    if ($homeId !== false) {
        if ($home === null || !home_can_edit($home, $homeResidents, $myUserId, $myGroup)) {
            $_SESSION['flash_places_error'] = "That home doesn't exist or isn't yours to edit.";
            places_redirect($mapPersonId);
        }
    }

    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
    $address = mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 1000);
    $postcode = mb_substr(strtoupper(trim((string) ($_POST['postcode'] ?? ''))), 0, 20);
    $country = mb_substr(trim((string) ($_POST['country'] ?? '')), 0, 80);
    $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 5000);
    $visibility = (string) ($_POST['visibility'] ?? 'public');
    if (!in_array($visibility, ['private', 'public', 'custom'], true)) {
        $visibility = 'public';
    }
    $lat = filter_var($_POST['lat'] ?? '', FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['lng'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        $lat = $lng = null;
    }
    if ($name === '' && $address === '' && $postcode === '') {
        $errors[] = 'Give the home a name or an address.';
    }

    // Residents -- anyone in the family group, each with their own dates.
    $residentsIn = [];
    foreach ((array) ($_POST['residents'] ?? []) as $pid => $r) {
        $pid = (int) $pid;
        if (!is_array($r) || empty($r['on']) || !isset($familyById[$pid])) {
            continue;
        }
        $in = places_parse_date((string) ($r['in_day'] ?? ''), (string) ($r['in_month'] ?? ''), (string) ($r['in_year'] ?? ''));
        $out = places_parse_date((string) ($r['out_day'] ?? ''), (string) ($r['out_month'] ?? ''), (string) ($r['out_year'] ?? ''));
        $who = person_display_name($familyById[$pid]);
        if (isset($in['error']) || isset($out['error'])) {
            $errors[] = "Check {$who}'s moving dates — enter a year, a month and year, or a full date.";
            continue;
        }
        if ($in['date'] !== null && $out['date'] !== null && $out['date'] < $in['date']) {
            $errors[] = "{$who}'s move-out date is before their move-in date.";
            continue;
        }
        $residentsIn[$pid] = ['in' => $in, 'out' => $out];
    }
    if (!$residentsIn) {
        $errors[] = 'Tick at least one person who lived there.';
    }

    // Existing media/updates on this home (edit mode), for reconciling.
    $existingMedia = [];     // id => row
    $existingUpdates = [];   // id => row
    if ($home !== null) {
        $m = $pdo->prepare('SELECT * FROM home_media WHERE home_id = :h');
        $m->execute(['h' => (int) $home['id']]);
        foreach ($m->fetchAll() as $row) {
            $existingMedia[(int) $row['id']] = $row;
        }
        $u = $pdo->prepare('SELECT * FROM home_updates WHERE home_id = :h');
        $u->execute(['h' => (int) $home['id']]);
        foreach ($u->fetchAll() as $row) {
            $existingUpdates[(int) $row['id']] = $row;
        }
    }

    // Photos arrive already uploaded (chunked, into MY own media folder)
    // as staging tokens -- see includes/media.php. Only images belong here.
    $resolvePhotos = function (array $tokens) use ($myUserId, $myPersonId, &$errors): array {
        $r = media_staging_resolve($tokens, $myUserId, $myPersonId, 'entry');
        foreach ($r['files'] as $f) {
            if (!str_starts_with($f['mime_type'], 'image/')) {
                $errors[] = 'Only photos can be added to a home (one of the files was not an image).';
                return ['files' => [], 'tokens' => []];
            }
        }
        return $r;
    };
    $keptIds = function (array $raw, ?int $updateId) use ($existingMedia): array {
        $out = [];
        foreach ($raw as $v) {
            $id = filter_var($v, FILTER_VALIDATE_INT);
            if ($id !== false && isset($existingMedia[(int) $id])
                && (($existingMedia[(int) $id]['update_id'] === null && $updateId === null)
                    || ($updateId !== null && (int) $existingMedia[(int) $id]['update_id'] === $updateId))) {
                $out[] = (int) $id;
            }
        }
        return array_values(array_unique($out));
    };

    $generalKept = $keptIds((array) ($_POST['kept_media_ids'] ?? []), null);
    $generalNew = $resolvePhotos((array) ($_POST['staged_media'] ?? []));
    if (count($generalKept) + count($generalNew['files']) > HOME_MAX_PHOTOS_PER_SECTION) {
        $errors[] = 'Add at most ' . HOME_MAX_PHOTOS_PER_SECTION . ' photos of the home itself (use updates for more).';
    }

    $updatesIn = [];
    $rawUpdates = is_array($_POST['updates'] ?? null) ? $_POST['updates'] : [];
    ksort($rawUpdates, SORT_NUMERIC);
    foreach ($rawUpdates as $ru) {
        if (!is_array($ru)) {
            continue;
        }
        $uid = filter_var($ru['id'] ?? '', FILTER_VALIDATE_INT);
        $uid = ($uid !== false && isset($existingUpdates[(int) $uid])) ? (int) $uid : null;
        $title = mb_substr(trim((string) ($ru['title'] ?? '')), 0, 160);
        $unotes = mb_substr(trim((string) ($ru['notes'] ?? '')), 0, 5000);
        $d = places_parse_date((string) ($ru['day'] ?? ''), (string) ($ru['month'] ?? ''), (string) ($ru['year'] ?? ''));
        $kept = $uid !== null ? $keptIds((array) ($ru['kept_media_ids'] ?? []), $uid) : [];
        $new = $resolvePhotos((array) ($ru['staged_media'] ?? []));
        if ($uid === null && $title === '' && $unotes === '' && !$new['files'] && ($d['date'] ?? null) === null) {
            continue; // an untouched blank "add an update" row
        }
        if (isset($d['error'])) {
            $errors[] = 'Check the date on "' . ($title !== '' ? $title : 'an update') . '" — a year, a month and year, or a full date.';
            continue;
        }
        if (count($kept) + count($new['files']) > HOME_MAX_PHOTOS_PER_SECTION) {
            $errors[] = 'Add at most ' . HOME_MAX_PHOTOS_PER_SECTION . ' photos to one update.';
        }
        $updatesIn[] = [
            'id' => $uid, 'title' => $title !== '' ? $title : 'Update', 'notes' => $unotes,
            'date' => $d['date'], 'precision' => $d['precision'], 'kept' => $kept, 'new' => $new,
        ];
    }

    if ($errors) {
        $_SESSION['flash_places_error'] = implode(' ', array_unique($errors));
        places_redirect($mapPersonId, $home ? (int) $home['id'] : null);
    }

    $filesAfterCommit = [];
    $consume = array_merge($generalNew['tokens'], ...array_map(fn ($u) => $u['new']['tokens'], $updatesIn ?: [['new' => ['tokens' => []]]]));
    try {
        $pdo->beginTransaction();
        $fields = [
            'name' => $name !== '' ? $name : null, 'address' => $address !== '' ? $address : null,
            'postcode' => $postcode !== '' ? $postcode : null, 'country' => $country !== '' ? $country : null,
            'lat' => $lat, 'lng' => $lng, 'vis' => $visibility, 'notes' => $notes !== '' ? $notes : null,
        ];
        if ($home === null) {
            $pdo->prepare(
                'INSERT INTO homes (family_group_id, created_by_person_id, created_by_user_id, name, address, postcode, country, lat, lng, visibility, notes)
                 VALUES (:gid, :cp, :cu, :name, :address, :postcode, :country, :lat, :lng, :vis, :notes)'
            )->execute($fields + ['gid' => $myGroup, 'cp' => $myPersonId, 'cu' => $myUserId]);
            $hid = (int) $pdo->lastInsertId();
        } else {
            $hid = (int) $home['id'];
            $pdo->prepare(
                'UPDATE homes SET name = :name, address = :address, postcode = :postcode, country = :country,
                        lat = :lat, lng = :lng, visibility = :vis, notes = :notes
                 WHERE id = :id'
            )->execute($fields + ['id' => $hid]);
        }

        // residents: drop anyone unticked, upsert everyone ticked
        $keepPids = array_keys($residentsIn);
        $in = implode(',', array_fill(0, count($keepPids), '?'));
        $pdo->prepare("DELETE FROM home_residents WHERE home_id = ? AND person_id NOT IN ($in)")
            ->execute(array_merge([$hid], $keepPids));
        $upsert = $pdo->prepare(
            'INSERT INTO home_residents (home_id, person_id, moved_in, moved_in_precision, moved_out, moved_out_precision, created_by_user_id)
             VALUES (:h, :p, :i, :ip, :o, :op, :u)
             ON DUPLICATE KEY UPDATE moved_in = VALUES(moved_in), moved_in_precision = VALUES(moved_in_precision),
                                     moved_out = VALUES(moved_out), moved_out_precision = VALUES(moved_out_precision)'
        );
        foreach ($residentsIn as $pid => $r) {
            $upsert->execute([
                'h' => $hid, 'p' => $pid,
                'i' => $r['in']['date'], 'ip' => $r['in']['precision'],
                'o' => $r['out']['date'], 'op' => $r['out']['precision'], 'u' => $myUserId,
            ]);
        }

        // media reconciliation: anything existing and not kept goes
        $keptAll = $generalKept;
        foreach ($updatesIn as $u) {
            $keptAll = array_merge($keptAll, $u['kept']);
        }
        $submittedUpdateIds = array_filter(array_map(fn ($u) => $u['id'], $updatesIn));
        foreach ($existingMedia as $mid => $row) {
            $inRemovedUpdate = $row['update_id'] !== null && !in_array((int) $row['update_id'], $submittedUpdateIds, true);
            if (!in_array($mid, $keptAll, true) || $inRemovedUpdate) {
                $filesAfterCommit[] = $row['file_path'];
                $pdo->prepare('DELETE FROM home_media WHERE id = :id')->execute(['id' => $mid]);
            }
        }
        foreach ($existingUpdates as $euid => $row) {
            if (!in_array($euid, $submittedUpdateIds, true)) {
                $pdo->prepare('DELETE FROM home_updates WHERE id = :id')->execute(['id' => $euid]);
            }
        }

        $mediaInsert = $pdo->prepare(
            'INSERT INTO home_media (home_id, update_id, file_path, mime_type, byte_size, width, height, sort_order, uploaded_by_user_id)
             VALUES (:h, :u, :path, :mime, :size, :w, :hh, :sort, :by)'
        );
        $addPhotos = function (array $files, ?int $updateId) use ($mediaInsert, $hid, $myUserId): void {
            foreach ($files as $i => $f) {
                $mediaInsert->execute([
                    'h' => $hid, 'u' => $updateId, 'path' => $f['file_path'], 'mime' => $f['mime_type'],
                    'size' => $f['byte_size'], 'w' => $f['width'], 'hh' => $f['height'], 'sort' => 1000 + $i, 'by' => $myUserId,
                ]);
            }
        };
        $addPhotos($generalNew['files'], null);

        foreach ($updatesIn as $sort => $u) {
            if ($u['id'] !== null) {
                $pdo->prepare(
                    'UPDATE home_updates SET sort_order = :s, title = :t, update_date = :d, update_date_precision = :p, notes = :n WHERE id = :id AND home_id = :h'
                )->execute(['s' => $sort, 't' => $u['title'], 'd' => $u['date'], 'p' => $u['precision'],
                            'n' => $u['notes'] !== '' ? $u['notes'] : null, 'id' => $u['id'], 'h' => $hid]);
                $updateId = $u['id'];
            } else {
                $pdo->prepare(
                    'INSERT INTO home_updates (home_id, sort_order, title, update_date, update_date_precision, notes)
                     VALUES (:h, :s, :t, :d, :p, :n)'
                )->execute(['h' => $hid, 's' => $sort, 't' => $u['title'], 'd' => $u['date'], 'p' => $u['precision'],
                            'n' => $u['notes'] !== '' ? $u['notes'] : null]);
                $updateId = (int) $pdo->lastInsertId();
            }
            $addPhotos($u['new']['files'], $updateId);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('ourthology places save error: ' . $e->getMessage());
        $_SESSION['flash_places_error'] = 'Something went wrong saving that home. Please try again.';
        places_redirect($mapPersonId, $home ? (int) $home['id'] : null);
    }
    media_staging_consume($consume, $myUserId);
    foreach ($filesAfterCommit as $f) {
        delete_media_file($f);
    }
    $_SESSION['flash_places_notice'] = $home === null ? 'Home added.' : 'Home updated.';
    // Back to the map it was saved from -- unless that person isn't
    // actually on this home, in which case show it on the first resident's.
    $back = isset($residentsIn[$mapPersonId]) ? $mapPersonId : (int) array_key_first($residentsIn);
    places_redirect($back, $hid);
}

// ---------------- GET: render ----------------
$flashError = $_SESSION['flash_places_error'] ?? null;
$flashNotice = $_SESSION['flash_places_notice'] ?? null;
unset($_SESSION['flash_places_error'], $_SESSION['flash_places_notice']);

$mapPersonId = filter_var($_GET['person_id'] ?? '', FILTER_VALIDATE_INT);
$mapPersonId = ($mapPersonId !== false && isset($familyById[(int) $mapPersonId])) ? (int) $mapPersonId : $myPersonId;
$mapPerson = $familyById[$mapPersonId];
$isMine = $mapPersonId === $myPersonId;
$mapPersonName = person_display_name($mapPerson);
$openHomeId = filter_var($_GET['home'] ?? '', FILTER_VALIDATE_INT);

$homes = fetch_homes_for_person($pdo, $mapPersonId, $myUserId, $myPersonId, $myGroup);
// Phase 93: memories with a map pin, shown as a second layer on the map --
// the same set this viewer would see on that person's timeline.
$canManageMap = $isMine || person_is_editable_by($mapPerson, $myUserId);
$jsMemories = places_memory_pins($pdo, $mapPersonId, $canManageMap, $myPersonId);
$openMemoryId = filter_var($_GET['memory'] ?? '', FILTER_VALIDATE_INT);

$pendingCounts = fetch_pending_for_user($pdo, $myUserId);
$pendingCount = count($pendingCounts['relationships']) + count($pendingCounts['partnerships']);

$photo = fn (array $m) => ['id' => (int) $m['id'], 'url' => '/home_media.php?id=' . (int) $m['id']];
$jsHomes = [];
foreach ($homes as $h) {
    $residents = [];
    foreach ($h['residents'] as $r) {
        [$ind, $inm, $iny] = places_date_slots($r['moved_in'], $r['moved_in_precision']);
        [$outd, $outm, $outy] = places_date_slots($r['moved_out'], $r['moved_out_precision']);
        $residents[] = [
            'personId' => (int) $r['person_id'],
            'name'     => trim($r['first_name'] . ' ' . $r['surname']),
            'inLabel'  => places_date_label($r['moved_in'], $r['moved_in_precision']),
            'outLabel' => places_date_label($r['moved_out'], $r['moved_out_precision']),
            'in'       => [$ind, $inm, $iny],
            'out'      => [$outd, $outm, $outy],
        ];
    }
    $updates = [];
    foreach ($h['updates'] as $u) {
        $updates[] = [
            'id'        => (int) $u['id'],
            'title'     => (string) $u['title'],
            'notes'     => (string) ($u['notes'] ?? ''),
            'dateLabel' => places_date_label($u['update_date'], $u['update_date_precision']),
            'date'      => places_date_slots($u['update_date'], $u['update_date_precision']),
            'photos'    => array_map($photo, $h['media'][(int) $u['id']] ?? []),
        ];
    }
    $isResidentMe = false;
    foreach ($h['residents'] as $r) {
        if ((int) $r['person_id'] === $myPersonId) {
            $isResidentMe = true;
        }
    }
    $jsHomes[] = [
        'id'         => (int) $h['id'],
        'name'       => (string) ($h['name'] ?? ''),
        'address'    => (string) ($h['address'] ?? ''),
        'postcode'   => (string) ($h['postcode'] ?? ''),
        'country'    => (string) ($h['country'] ?? ''),
        'lat'        => $h['lat'] !== null ? (float) $h['lat'] : null,
        'lng'        => $h['lng'] !== null ? (float) $h['lng'] : null,
        'visibility' => $h['visibility'],
        'notes'      => (string) ($h['notes'] ?? ''),
        'canEdit'    => (bool) $h['can_edit'],
        'iLivedHere' => $isResidentMe,
        'inLabel'    => places_date_label($h['my_in'], $h['my_in_p']),
        'outLabel'   => places_date_label($h['my_out'], $h['my_out_p']),
        'residents'  => $residents,
        'photos'     => array_map($photo, $h['media']['general'] ?? []),
        'updates'    => $updates,
    ];
}
$family = [];
foreach ($graph['persons'] as $p) {
    $family[] = ['id' => (int) $p['id'], 'name' => person_display_name($p), 'deceased' => person_is_deceased($p)];
}
usort($family, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
require __DIR__ . '/places_view.php';
