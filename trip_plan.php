<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/memory_tags.php';
require_once __DIR__ . '/includes/trips.php';

/**
 * Phase 69: Memory Planner. Every mutation and read for one trip lives in
 * this one file, mirroring card.php/postcard.php/letter.php's "one file
 * owns its own feature" convention:
 *
 *   GET  ?action=detail&id=<trip_plan_id>  -- JSON for the planner pop-up
 *        to load an existing trip into (view or edit, depending on
 *        canEdit in the response) -- called via fetch() from timeline.php,
 *        not a page navigation.
 *   POST action=save                        -- create or update a trip
 *        (trip_plan_id present means update), then redirect back to
 *        timeline.php with the pop-up reopened on the saved trip -- a
 *        real multipart form POST, not an AJAX save, matching how the
 *        postcard/letter/card composers already submit.
 *
 * Deleting a whole trip is NOT handled here -- a trip's only DB footprint
 * beyond its own tables is one companion timeline_entries row
 * (origin='trip'), so timeline.php's existing delete_entry action already
 * deletes it correctly with no changes at all: it deletes every media
 * file under that entry, then deletes the entry, which cascades through
 * trip_plans -> trip_events -> trip_event_media (and, independently,
 * through media -> trip_event_media) on its own.
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

// --- GET: load one trip's full detail into the planner pop-up ---------

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string) ($_GET['action'] ?? '') === 'detail') {
    header('Content-Type: application/json');

    $tripPlanId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
    $trip = $tripPlanId !== false ? fetch_trip_plan_detail($pdo, (int) $tripPlanId) : null;

    $viewable = $trip !== null && trip_entry_is_viewable_by($pdo, [
        'id'                     => (int) $trip['entry_id'],
        'owner_person_id'        => (int) $trip['owner_person_id'],
        'visibility'             => $trip['visibility'],
        'owner_family_group_id'  => (int) $trip['owner_family_group_id'],
    ], $myPersonId, $myGroup);

    if (!$viewable) {
        // Same "doesn't exist" response whether it really doesn't, or the
        // viewer just isn't allowed to see it -- media.php's own can_view_
        // media() convention, reused here for the same reason.
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found.']);
        exit;
    }

    $canEdit = (int) $trip['owner_family_group_id'] === $myGroup
        && person_is_editable_by(['claimed_by_user_id' => $trip['owner_claimed_by']], $myUserId);

    $taggablePeople = [];
    if ($canEdit) {
        $familyGraph = fetch_family_graph($pdo, $myGroup);
        foreach (graph_people_within_generations($familyGraph, (int) $trip['owner_person_id']) as $p) {
            $taggablePeople[] = [
                'id'        => (int) $p['id'],
                'name'      => person_display_name($p),
                'unclaimed' => empty($p['claimed_by_user_id']),
            ];
        }
    }

    $events = [];
    foreach ($trip['events'] as $ev) {
        $events[] = [
            'id'          => (int) $ev['id'],
            'title'       => (string) $ev['title'],
            'eventDate'   => $ev['event_date'],
            'planNotes'   => (string) ($ev['plan_notes'] ?? ''),
            'memoryNotes' => (string) ($ev['memory_notes'] ?? ''),
            'planMedia'   => $ev['plan_media'],
            'memoryMedia' => $ev['memory_media'],
        ];
    }

    $ownerPersonRow = person_row($pdo, (int) $trip['owner_person_id']);

    echo json_encode([
        'ok'              => true,
        'tripPlanId'      => (int) $trip['id'],
        'entryId'         => (int) $trip['entry_id'],
        'targetPersonId'  => (int) $trip['owner_person_id'],
        'ownerName'       => $ownerPersonRow !== null ? person_display_name($ownerPersonRow) : 'its owner',
        'title'           => (string) $trip['title'],
        'startDate'       => $trip['start_date'],
        'finishDate'      => $trip['finish_date'],
        'visibility'      => $trip['visibility'],
        'canEdit'         => $canEdit,
        'taggedPersonIds' => array_map(fn ($t) => (int) $t['person_id'], $trip['tags']),
        'taggablePeople'  => $taggablePeople,
        'events'          => $events,
    ]);
    exit;
}

// --- Everything else is a POST mutation --------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /timeline.php');
    exit;
}

// Same "the whole request body was silently discarded" guard add_entry.php
// uses -- a multi-event, multi-image submission is exactly the shape most
// likely to exceed post_max_size, so this is worth checking here too.
$bodyTooLarge = empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
if ($bodyTooLarge) {
    $_SESSION['flash_trip_error'] = 'Those files are too large to upload together — try attaching fewer photos at once, or smaller files.';
    header('Location: /timeline.php');
    exit;
}

csrf_check();
$action = (string) ($_POST['action'] ?? '');

if ($action !== 'save') {
    header('Location: /timeline.php');
    exit;
}

$tripPlanId = filter_var($_POST['trip_plan_id'] ?? '', FILTER_VALIDATE_INT);
$isEditing = $tripPlanId !== false;

$existingTrip = null;
if ($isEditing) {
    $existingTrip = fetch_trip_plan_detail($pdo, (int) $tripPlanId);
    $allowed = $existingTrip !== null
        && (int) $existingTrip['owner_family_group_id'] === $myGroup
        && person_is_editable_by(['claimed_by_user_id' => $existingTrip['owner_claimed_by']], $myUserId);
    if (!$allowed) {
        $_SESSION['flash_trip_error'] = "That trip doesn't exist or isn't yours to edit.";
        header('Location: /timeline.php');
        exit;
    }
    $targetPersonId = (int) $existingTrip['owner_person_id'];
} else {
    $targetPersonId = filter_var($_POST['target_person_id'] ?? $myPersonId, FILTER_VALIDATE_INT);
    $targetPerson = $targetPersonId !== false ? person_row($pdo, (int) $targetPersonId) : null;
    $allowed = $targetPerson !== null
        && (int) $targetPerson['family_group_id'] === $myGroup
        && person_is_editable_by($targetPerson, $myUserId);
    if (!$allowed) {
        $_SESSION['flash_trip_error'] = "You don't have permission to plan a trip for that person.";
        header('Location: /timeline.php');
        exit;
    }
    $targetPersonId = (int) $targetPerson['id'];
}

$familyGraph = fetch_family_graph($pdo, $myGroup);
$familyPersonsById = [];
foreach ($familyGraph['persons'] as $p) {
    $familyPersonsById[(int) $p['id']] = $p;
}
$taggablePersonIds = array_map(fn ($p) => (int) $p['id'], graph_people_within_generations($familyGraph, $targetPersonId));

/** Same three-slot day/month/year validation add_entry.php uses for occurred_on -- all three or none, checkdate()-verified. Returns ['error' => string] or ['date' => 'Y-m-d'|null]. */
function trip_parse_date_slots(string $day, string $month, string $year): array
{
    $filled = (int) ($day !== '') + (int) ($month !== '') + (int) ($year !== '');
    if ($filled === 0) {
        return ['date' => null];
    }
    if ($filled < 3 || !ctype_digit($day) || !ctype_digit($month) || !ctype_digit($year)
        || !checkdate((int) $month, (int) $day, (int) $year)) {
        return ['error' => true];
    }
    return ['date' => sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day)];
}

$errors = [];

$title = trim((string) ($_POST['title'] ?? ''));
if ($title === '') {
    $errors[] = 'Give the trip a title.';
}

$visibility = (string) ($_POST['visibility'] ?? 'public');
if (!in_array($visibility, ['private', 'public', 'custom'], true)) {
    $errors[] = 'Choose a valid visibility.';
}

$startResult = trip_parse_date_slots(
    trim((string) ($_POST['start_day'] ?? '')),
    trim((string) ($_POST['start_month'] ?? '')),
    trim((string) ($_POST['start_year'] ?? ''))
);
$finishResult = trip_parse_date_slots(
    trim((string) ($_POST['finish_day'] ?? '')),
    trim((string) ($_POST['finish_month'] ?? '')),
    trim((string) ($_POST['finish_year'] ?? ''))
);
if (isset($startResult['error']) || $startResult['date'] === null) {
    $errors[] = 'Enter a real start date.';
}
if (isset($finishResult['error']) || $finishResult['date'] === null) {
    $errors[] = 'Enter a real finish date.';
}
if (!$errors && $finishResult['date'] < $startResult['date']) {
    $errors[] = 'The finish date has to be on or after the start date.';
}
$startDate = $startResult['date'] ?? null;
$finishDate = $finishResult['date'] ?? null;

$submittedTagIds = array_map('intval', array_filter(
    (array) ($_POST['tag_person_ids'] ?? []),
    fn ($v) => filter_var($v, FILTER_VALIDATE_INT) !== false
));
$tagPersonIds = array_values(array_intersect($submittedTagIds, $taggablePersonIds));

// Existing events/media for this trip, keyed for the reconciliation below
// -- empty arrays when creating a brand-new trip.
$existingEventsById = [];
if ($existingTrip !== null) {
    foreach ($existingTrip['events'] as $ev) {
        $existingEventsById[(int) $ev['id']] = $ev;
    }
}

$rawEvents = is_array($_POST['events'] ?? null) ? $_POST['events'] : [];
$rawEventFiles = is_array($_FILES['events'] ?? null) ? $_FILES['events'] : [];
ksort($rawEvents, SORT_NUMERIC); // preserve the order the pop-up submitted them in

$parsedEvents = [];
$eventSort = 0;
foreach ($rawEvents as $idx => $ev) {
    if (!is_array($ev)) {
        continue;
    }
    $idx = (int) $idx;

    $existingEventId = filter_var($ev['id'] ?? '', FILTER_VALIDATE_INT);
    $belongsToTrip = $existingEventId !== false && isset($existingEventsById[(int) $existingEventId]);

    $rawTitle = trim((string) ($ev['title'] ?? ''));
    $evDateResult = trip_parse_date_slots(
        trim((string) ($ev['event_day'] ?? '')),
        trim((string) ($ev['event_month'] ?? '')),
        trim((string) ($ev['event_year'] ?? ''))
    );
    if (isset($evDateResult['error'])) {
        $errors[] = 'Enter a real date for "' . ($rawTitle !== '' ? $rawTitle : 'one of the events') . '", or leave its day/month/year all blank.';
    }
    $planNotes = trim((string) ($ev['plan_notes'] ?? ''));
    $memoryNotes = trim((string) ($ev['memory_notes'] ?? ''));

    $planFilesField = trip_flatten_event_files($rawEventFiles, $idx, 'plan_media');
    $memoryFilesField = trip_flatten_event_files($rawEventFiles, $idx, 'memory_media');
    $newPlanFiles = normalize_multi_file_upload($planFilesField);
    $newMemoryFiles = normalize_multi_file_upload($memoryFilesField);

    $existingPlanIds = [];
    $existingMemoryIds = [];
    if ($belongsToTrip) {
        $existingPlanIds = array_map(fn ($m) => (int) $m['id'], $existingEventsById[(int) $existingEventId]['plan_media']);
        $existingMemoryIds = array_map(fn ($m) => (int) $m['id'], $existingEventsById[(int) $existingEventId]['memory_media']);
    }
    $keptPlanIds = array_values(array_intersect(array_map('intval', array_filter(
        (array) ($ev['existing_plan_media_ids'] ?? []),
        fn ($v) => filter_var($v, FILTER_VALIDATE_INT) !== false
    )), $existingPlanIds));
    $keptMemoryIds = array_values(array_intersect(array_map('intval', array_filter(
        (array) ($ev['existing_memory_media_ids'] ?? []),
        fn ($v) => filter_var($v, FILTER_VALIDATE_INT) !== false
    )), $existingMemoryIds));

    if (count($keptPlanIds) + count($newPlanFiles) > TRIP_MAX_MEDIA_PER_ROLE) {
        $errors[] = 'Attach at most ' . TRIP_MAX_MEDIA_PER_ROLE . ' plan photos to one event.';
    }
    if (count($keptMemoryIds) + count($newMemoryFiles) > TRIP_MAX_MEDIA_PER_ROLE) {
        $errors[] = 'Attach at most ' . TRIP_MAX_MEDIA_PER_ROLE . ' memory photos to one event.';
    }

    // A stray, entirely-blank "add another event" row (never given a
    // title, date, notes, or any photo) is silently dropped rather than
    // saved as clutter -- only an existing event being edited is kept
    // regardless of how empty it looks, since that's a deliberate clear-
    // out of that event's own text, not an unused blank row.
    $isBlankNewRow = !$belongsToTrip && $rawTitle === '' && $evDateResult['date'] === null
        && $planNotes === '' && $memoryNotes === '' && !$newPlanFiles && !$newMemoryFiles;
    if ($isBlankNewRow) {
        continue;
    }

    $parsedEvents[] = [
        'existingId'    => $belongsToTrip ? (int) $existingEventId : null,
        'sortOrder'     => $eventSort++,
        'title'         => $rawTitle !== '' ? $rawTitle : ('Event ' . (count($parsedEvents) + 1)),
        'eventDate'     => $evDateResult['date'] ?? null,
        'planNotes'     => $planNotes,
        'memoryNotes'   => $memoryNotes,
        'keptPlanIds'   => $keptPlanIds,
        'keptMemoryIds' => $keptMemoryIds,
        'planFilesField'   => $planFilesField,
        'memoryFilesField' => $memoryFilesField,
        'newPlanCount'     => count($newPlanFiles),
        'newMemoryCount'   => count($newMemoryFiles),
    ];
}

if ($errors) {
    $_SESSION['flash_trip_error'] = implode(' ', array_unique($errors));
    header('Location: /timeline.php' . ($targetPersonId !== $myPersonId ? '?person_id=' . $targetPersonId : ''));
    exit;
}

try {
    $pdo->beginTransaction();

    if ($isEditing) {
        $entryId = (int) $existingTrip['entry_id'];
        $pdo->prepare(
            'UPDATE timeline_entries SET title = :title, occurred_on = :occurred, visibility = :vis
             WHERE id = :id AND person_id = :pid'
        )->execute([
            'title'    => $title,
            'occurred' => $startDate,
            'vis'      => $visibility,
            'id'       => $entryId,
            'pid'      => $targetPersonId,
        ]);
        $pdo->prepare(
            'UPDATE trip_plans SET title = :title, start_date = :start, finish_date = :finish WHERE id = :id'
        )->execute(['title' => $title, 'start' => $startDate, 'finish' => $finishDate, 'id' => $tripPlanId]);

        sync_memory_tags($pdo, $entryId, $tagPersonIds, $familyPersonsById, $myUserId);

        // Whole events dropped from the submission entirely (removed in
        // the pop-up) -- their photos are deleted from disk first, same
        // as any other removed attachment, before the DB rows go.
        $submittedEventIds = array_filter(array_map(fn ($e) => $e['existingId'], $parsedEvents));
        $eventIdsToDelete = array_diff(array_keys($existingEventsById), $submittedEventIds);
        if ($eventIdsToDelete) {
            $allRemovedMediaIds = [];
            foreach ($eventIdsToDelete as $delId) {
                foreach (array_merge($existingEventsById[$delId]['plan_media'], $existingEventsById[$delId]['memory_media']) as $m) {
                    $allRemovedMediaIds[] = (int) $m['id'];
                }
            }
            if ($allRemovedMediaIds) {
                $ph = implode(',', array_fill(0, count($allRemovedMediaIds), '?'));
                $pathStmt = $pdo->prepare("SELECT file_path FROM media WHERE id IN ($ph)");
                $pathStmt->execute($allRemovedMediaIds);
                foreach ($pathStmt->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                    delete_media_file((string) $fp);
                }
                $pdo->prepare("DELETE FROM media WHERE id IN ($ph)")->execute($allRemovedMediaIds);
            }
            $ph = implode(',', array_fill(0, count($eventIdsToDelete), '?'));
            $pdo->prepare("DELETE FROM trip_events WHERE id IN ($ph)")->execute(array_values($eventIdsToDelete));
        }
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO timeline_entries (person_id, entry_type, origin, title, body, occurred_on, visibility, created_by_user_id)
             VALUES (:pid, :type, :origin, :title, :body, :occurred, :vis, :uid)'
        );
        $stmt->execute([
            'pid'      => $targetPersonId,
            'type'     => 'note',
            'origin'   => 'trip',
            'title'    => $title,
            'body'     => null,
            'occurred' => $startDate,
            'vis'      => $visibility,
            'uid'      => $myUserId,
        ]);
        $entryId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO trip_plans (person_id, timeline_entry_id, title, start_date, finish_date, created_by_user_id)
             VALUES (:pid, :eid, :title, :start, :finish, :uid)'
        )->execute([
            'pid' => $targetPersonId, 'eid' => $entryId, 'title' => $title,
            'start' => $startDate, 'finish' => $finishDate, 'uid' => $myUserId,
        ]);
        $tripPlanId = (int) $pdo->lastInsertId();

        foreach ($tagPersonIds as $tagId) {
            create_memory_tag($pdo, $entryId, $familyPersonsById[$tagId], $myUserId);
        }
    }

    $mediaInsert = $pdo->prepare(
        'INSERT INTO media (timeline_entry_id, file_path, mime_type, byte_size, width, height)
         VALUES (:eid, :path, :mime, :size, :w, :h)'
    );
    $tripMediaInsert = $pdo->prepare(
        'INSERT INTO trip_event_media (trip_event_id, media_id, role, sort_order) VALUES (:tev, :mid, :role, :sort)'
    );

    foreach ($parsedEvents as $pe) {
        if ($pe['existingId'] !== null) {
            $thisEventId = $pe['existingId'];
            $pdo->prepare(
                'UPDATE trip_events SET sort_order = :sort, title = :title, event_date = :date,
                        plan_notes = :plan, memory_notes = :memory
                 WHERE id = :id AND trip_plan_id = :tpid'
            )->execute([
                'sort' => $pe['sortOrder'], 'title' => $pe['title'], 'date' => $pe['eventDate'],
                'plan' => $pe['planNotes'] !== '' ? $pe['planNotes'] : null,
                'memory' => $pe['memoryNotes'] !== '' ? $pe['memoryNotes'] : null,
                'id' => $thisEventId, 'tpid' => $tripPlanId,
            ]);

            // Reconcile removed plan/memory photos on an existing event --
            // anything that WAS there and isn't in the kept list anymore.
            $existingRow = $existingEventsById[$thisEventId];
            foreach (['plan' => 'keptPlanIds', 'memory' => 'keptMemoryIds'] as $role => $keptKey) {
                $wasIds = array_map(fn ($m) => (int) $m['id'], $existingRow[$role . '_media']);
                $removedIds = array_diff($wasIds, $pe[$keptKey]);
                if ($removedIds) {
                    $ph = implode(',', array_fill(0, count($removedIds), '?'));
                    $pathStmt = $pdo->prepare("SELECT file_path FROM media WHERE id IN ($ph)");
                    $pathStmt->execute(array_values($removedIds));
                    foreach ($pathStmt->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                        delete_media_file((string) $fp);
                    }
                    $pdo->prepare("DELETE FROM media WHERE id IN ($ph)")->execute(array_values($removedIds));
                }
            }
        } else {
            $pdo->prepare(
                'INSERT INTO trip_events (trip_plan_id, sort_order, title, event_date, plan_notes, memory_notes)
                 VALUES (:tpid, :sort, :title, :date, :plan, :memory)'
            )->execute([
                'tpid' => $tripPlanId, 'sort' => $pe['sortOrder'], 'title' => $pe['title'], 'date' => $pe['eventDate'],
                'plan' => $pe['planNotes'] !== '' ? $pe['planNotes'] : null,
                'memory' => $pe['memoryNotes'] !== '' ? $pe['memoryNotes'] : null,
            ]);
            $thisEventId = (int) $pdo->lastInsertId();
        }

        foreach (['plan' => 'planFilesField', 'memory' => 'memoryFilesField'] as $role => $fieldKey) {
            if (($role === 'plan' ? $pe['newPlanCount'] : $pe['newMemoryCount']) === 0) {
                continue;
            }
            $stored = store_uploaded_media_files($pe[$fieldKey], $targetPersonId);
            foreach ($stored as $sortIdx => $s) {
                $mediaInsert->execute([
                    'eid' => $entryId, 'path' => $s['file_path'], 'mime' => $s['mime_type'],
                    'size' => $s['byte_size'], 'w' => $s['width'], 'h' => $s['height'],
                ]);
                $mediaId = (int) $pdo->lastInsertId();
                $tripMediaInsert->execute(['tev' => $thisEventId, 'mid' => $mediaId, 'role' => $role, 'sort' => $sortIdx]);
            }
        }
    }

    $pdo->commit();

    $_SESSION['flash_trip_sent'] = $isEditing ? 'Trip plan updated.' : 'Trip plan saved to the timeline.';
    header('Location: /timeline.php'
        . ($targetPersonId !== $myPersonId ? '?person_id=' . $targetPersonId . '&' : '?')
        . 'open_trip=' . $entryId);
    exit;
} catch (RuntimeException $e) {
    $pdo->rollBack();
    $_SESSION['flash_trip_error'] = $e->getMessage();
    header('Location: /timeline.php' . ($targetPersonId !== $myPersonId ? '?person_id=' . $targetPersonId : ''));
    exit;
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('ourthology trip_plan error: ' . $e->getMessage());
    $_SESSION['flash_trip_error'] = 'Something went wrong saving that trip. Please try again.';
    header('Location: /timeline.php' . ($targetPersonId !== $myPersonId ? '?person_id=' . $targetPersonId : ''));
    exit;
}
