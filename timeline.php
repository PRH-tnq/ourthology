<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/entries.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/memory_tags.php';
require_once __DIR__ . '/includes/postcards.php';
require_once __DIR__ . '/includes/letters.php';
require_once __DIR__ . '/includes/cards.php';
require_once __DIR__ . '/includes/calendar.php'; // Phase 68: ourthology_calendar_reminder_rows(), fetch_calendar_event_for_group() -- the reminder banner now covers key dates too, not just birthdays
require_once __DIR__ . '/includes/trips.php'; // Phase 69: Memory Planner -- fetch_trip_plan_detail(), reused below for the "open_trip=" auto-open flow
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
$myPerson = person_row($pdo, $myPersonId);
$myGroup = (int) $myPerson['family_group_id'];

$targetId = filter_var($_GET['person_id'] ?? $myPersonId, FILTER_VALIDATE_INT);
if ($targetId === false) {
    $targetId = $myPersonId;
}
$target = person_row($pdo, (int) $targetId);

if ($target === null) {
    http_response_code(404);
    exit('No such person.');
}

$isOwner = (int) $target['id'] === $myPersonId;
$sameGroup = (int) $target['family_group_id'] === $myGroup;

if (!$isOwner && !$sameGroup) {
    http_response_code(403);
    exit("You don't have access to this person's timeline.");
}

// Anyone in the family group may manage (add/edit/delete memories on, and
// see every private entry on) an unclaimed person's timeline — the same
// "unclaimed = shared" rule person_is_editable_by() already applies to
// editing the person record itself (Phase 10) and to who may add a memory
// for them (Phase 17) — extended here so the memories those same people
// were allowed to add aren't then invisible to them, or to each other,
// once saved. $isOwner itself stays narrow (literally your own claimed
// record) since it also drives "My timeline" wording and the birth-date
// prompt below, neither of which make sense for someone else's profile
// even an unclaimed one.
$canManage = $isOwner || person_is_editable_by($target, (int) $me['user_id']);

$notice = null;
$errors = [];

// Phase 48: postcard.php (a separate file -- it owns every postcard
// mutation, same as add_entry.php owns every timeline-entry one)
// redirects back here with one of these flashes after a send
// succeeds or fails. Reuses this page's own $notice/$errors render
// slot below rather than inventing a second one.
if (!empty($_SESSION['flash_postcard_sent'])) {
    $notice = (string) $_SESSION['flash_postcard_sent'];
}
if (!empty($_SESSION['flash_postcard_error'])) {
    $errors[] = (string) $_SESSION['flash_postcard_error'];
}
unset($_SESSION['flash_postcard_sent'], $_SESSION['flash_postcard_error']);
// Phase 53: letter.php's own send flash -- same reused $notice/$errors
// slot, same reasoning as the postcard flashes just above.
if (!empty($_SESSION['flash_letter_sent'])) {
    $notice = (string) $_SESSION['flash_letter_sent'];
}
if (!empty($_SESSION['flash_letter_error'])) {
    $errors[] = (string) $_SESSION['flash_letter_error'];
}
unset($_SESSION['flash_letter_sent'], $_SESSION['flash_letter_error']);
// Phase 67: card.php's own send flash -- same reused $notice/$errors slot.
if (!empty($_SESSION['flash_card_sent'])) {
    $notice = (string) $_SESSION['flash_card_sent'];
}
if (!empty($_SESSION['flash_card_error'])) {
    $errors[] = (string) $_SESSION['flash_card_error'];
}
unset($_SESSION['flash_card_sent'], $_SESSION['flash_card_error']);
// Phase 69: trip_plan.php's own save flash -- same reused $notice/$errors slot.
if (!empty($_SESSION['flash_trip_sent'])) {
    $notice = (string) $_SESSION['flash_trip_sent'];
}
if (!empty($_SESSION['flash_trip_error'])) {
    $errors[] = (string) $_SESSION['flash_trip_error'];
}
unset($_SESSION['flash_trip_sent'], $_SESSION['flash_trip_error']);

// Phase 69: trip_plan.php redirects back here with ?open_trip=<entry_id>
// right after a save -- reopens the planner pop-up already showing that
// trip, so "plan it, then come back later and add memories to it" is a
// single click away rather than having to find the card in the rail
// again. Resolved to a trip_plan_id (what the pop-up's own fetch() needs)
// here, server-side, rather than trusting a plan id the client might
// otherwise have to guess at.
$directOpenTripPlanId = null;
if (isset($_GET['open_trip'])) {
    $wantEntryId = filter_var($_GET['open_trip'], FILTER_VALIDATE_INT);
    if ($wantEntryId !== false) {
        $tripLookup = $pdo->prepare('SELECT id FROM trip_plans WHERE timeline_entry_id = :eid');
        $tripLookup->execute(['eid' => (int) $wantEntryId]);
        $foundTripPlanId = $tripLookup->fetchColumn();
        if ($foundTripPlanId !== false) {
            $directOpenTripPlanId = (int) $foundTripPlanId;
        }
    }
}
// The compose pop-up's recipient checkboxes -- always resolved for the
// ACTUAL logged-in user, regardless of whose timeline is currently
// being viewed (sending is a personal action, not scoped to $target).
$postcardRecipientOptions = fetch_postcard_recipient_options($pdo, $myGroup, $myPersonId);
// Phase 54: the compose preview isn't a real postcard yet, so there's no
// stored postmark_angle to show -- just roll one for this page load
// (create_postcard() rolls the real one that actually gets saved when
// they send). Cosmetic only, never sent anywhere.
$previewPostmarkAngle = random_int(-18, 22);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete_entry') {
        $entryId = filter_var($_POST['entry_id'] ?? '', FILTER_VALIDATE_INT);
        if ($entryId !== false) {
            // Ownership check happens in the query itself, not just in the
            // UI: an entry can be deleted by the person it belongs to, or by
            // anyone who can manage its owner's profile because that owner
            // is unclaimed (same person_is_editable_by() rule $canManage
            // above uses) — never by whoever merely happens to be viewing
            // this particular target's timeline right now, which is why the
            // entry's OWN owning person is re-fetched and re-checked here
            // rather than trusting $canManage from above.
            $stmt = $pdo->prepare(
                'SELECT te.id, p.claimed_by_user_id, p.family_group_id
                 FROM timeline_entries te JOIN persons p ON p.id = te.person_id
                 WHERE te.id = :id'
            );
            $stmt->execute(['id' => $entryId]);
            $row = $stmt->fetch() ?: null;
            $allowed = $row !== null
                && (int) $row['family_group_id'] === $myGroup
                && person_is_editable_by($row, (int) $me['user_id']);
            if ($allowed) {
                $mediaStmt = $pdo->prepare('SELECT file_path FROM media WHERE timeline_entry_id = :eid');
                $mediaStmt->execute(['eid' => $entryId]);
                foreach ($mediaStmt->fetchAll() as $m) {
                    delete_media_file($m['file_path']);
                }
                // timeline_entries -> memory_tags has ON DELETE CASCADE, so
                // deleting the entry also clears anyone else's tag on it.
                $pdo->prepare('DELETE FROM timeline_entries WHERE id = :id')->execute(['id' => $entryId]);
                $notice = 'Entry deleted.';
            } else {
                $errors[] = "That entry doesn't exist or isn't yours to delete.";
            }
        }
    } elseif ($action === 'save_tag_note') {
        // Anyone with an approved tag on a memory may write/edit their OWN
        // note about it — never the memory's title/body/media, which stay
        // the creator's alone to change (see add_entry.php's edit mode). The UPDATE's
        // own WHERE clause is the entire permission check: it only ever
        // touches a row that is both this memory and an APPROVED tag
        // belonging to me, so there's nothing to separately verify first.
        $entryId = filter_var($_POST['entry_id'] ?? '', FILTER_VALIDATE_INT);
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($entryId === false) {
            $errors[] = 'Invalid request.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE memory_tags SET note = :note
                 WHERE timeline_entry_id = :eid AND person_id = :pid AND status = 'approved'"
            );
            $stmt->execute(['note' => $note !== '' ? $note : null, 'eid' => $entryId, 'pid' => $myPersonId]);
            if ($stmt->rowCount() > 0) {
                $notice = 'Note saved.';
            } else {
                $errors[] = "You don't have an approved tag on that memory.";
            }
        }
    } elseif ($action === 'add_tagged_media') {
        // Phase 41: a person with an APPROVED tag on a memory may add --
        // never remove or replace -- media on it, same spirit as their own
        // note just above, just able to carry a photo instead of only
        // text. The memory itself (title, body, visibility, and any
        // EXISTING media) stays the owner's alone to change (see
        // add_entry.php's edit mode); this path only ever INSERTs new
        // media rows, nothing else.
        $entryId = filter_var($_POST['entry_id'] ?? '', FILTER_VALIDATE_INT);
        $addedMedia = [];
        if ($entryId === false || !person_has_approved_tag($pdo, $entryId, $myPersonId)) {
            $errors[] = "You don't have an approved tag on that memory.";
        } else {
            // The memory's own owner -- not me -- is whose folder and
            // storage total this media belongs to, same as every other
            // file already on their timeline (Phase 34's usage accounting
            // is per memory-owner, not per-uploader; an occasional added
            // photo from someone I've tagged and approved staying inside
            // that same accounting keeps this a lightweight, family-trust
            // feature rather than needing its own separate quota).
            $ownerStmt = $pdo->prepare('SELECT person_id FROM timeline_entries WHERE id = :id');
            $ownerStmt->execute(['id' => $entryId]);
            $ownerPersonId = $ownerStmt->fetchColumn();
            $existingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM media WHERE timeline_entry_id = :eid');
            $existingCountStmt->execute(['eid' => $entryId]);
            $existingCount = (int) $existingCountStmt->fetchColumn();
            $incoming = normalize_multi_file_upload($_FILES['media'] ?? []);

            if ($ownerPersonId === false) {
                $errors[] = "That memory doesn't exist.";
            } elseif (count($incoming) === 0) {
                $errors[] = 'Choose at least one photo, video, or document to add.';
            } elseif ($existingCount + count($incoming) > MEDIA_MAX_FILES_PER_ENTRY) {
                $errors[] = 'Attach at most ' . MEDIA_MAX_FILES_PER_ENTRY . ' files total on one entry.';
            } else {
                $stored = [];
                try {
                    $pdo->beginTransaction();
                    $stored = store_uploaded_media_files($_FILES['media'] ?? [], (int) $ownerPersonId);
                    $mediaStmt = $pdo->prepare(
                        'INSERT INTO media (timeline_entry_id, file_path, mime_type, byte_size, width, height)
                         VALUES (:eid, :path, :mime, :size, :w, :h)'
                    );
                    foreach ($stored as $file) {
                        $mediaStmt->execute([
                            'eid'  => $entryId,
                            'path' => $file['file_path'],
                            'mime' => $file['mime_type'],
                            'size' => $file['byte_size'],
                            'w'    => $file['width'],
                            'h'    => $file['height'],
                        ]);
                        // Phase 43: the freshly-inserted row's own id is what the
                        // AJAX response below needs to build a working
                        // /media.php?id=... URL for each newly-added file, so it's
                        // captured here rather than re-querying everything back out
                        // afterward.
                        $addedMedia[] = [
                            'kind' => str_starts_with($file['mime_type'], 'video/')
                                ? 'video'
                                : (str_starts_with($file['mime_type'], 'image/') ? 'image' : 'file'),
                            'url'  => '/media.php?id=' . (int) $pdo->lastInsertId(),
                        ];
                    }
                    $pdo->commit();
                    $notice = count($stored) === 1 ? 'Photo added.' : count($stored) . ' files added.';
                } catch (RuntimeException $e) {
                    $pdo->rollBack();
                    $addedMedia = [];
                    $errors[] = $e->getMessage();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $addedMedia = [];
                    foreach ($stored as $done) {
                        delete_media_file($done['file_path']);
                    }
                    error_log('ourthology add_tagged_media error: ' . $e->getMessage());
                    $errors[] = 'Something went wrong saving that. Please try again.';
                }
            }
        }

        // Phase 43: the memory-viewer modal now submits this form over
        // fetch() so it can stay open and update the media grid in place --
        // the old plain-form full-page POST+reload closed the modal and put
        // any success/error message at the top of an unrelated-looking fresh
        // page load, easy to miss (the most likely explanation behind a
        // report that "Add Media" doesn't work). A hidden "ajax" field marks
        // a fetch() submission; a non-JS fallback (no such field) still gets
        // the normal full-page render below, unchanged.
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(empty($errors)
                ? ['ok' => true, 'notice' => $notice, 'media' => $addedMedia]
                : ['ok' => false, 'error' => $errors[array_key_last($errors)]]);
            exit;
        }
    } elseif ($action === 'dismiss_tour') {
        // Fired by the onboarding tour's own JS (Skip, or the last step's
        // "Get started") — always about the CURRENTLY logged-in account,
        // never anyone else's, so there's nothing else to check. Silent by
        // design (no $notice) since the overlay is already gone client-side
        // by the time this returns; the whole point is just to make sure it
        // never shows again on a future visit or a different device.
        $pdo->prepare('UPDATE users SET tour_completed_at = NOW() WHERE id = :uid')->execute(['uid' => (int) $me['user_id']]);
        $me['tour_completed_at'] = date('Y-m-d H:i:s');
    } elseif ($action === 'set_born') {
        // Only the owner can set their own birth date — the life-view zoom
        // and life-stage bands are personal, and this form only ever shows
        // on your own timeline anyway, but the check happens here too, not
        // just in the UI.
        if (!$isOwner) {
            $errors[] = 'You can only set your own birth date.';
        } else {
            $bDay   = trim((string) ($_POST['born_day'] ?? ''));
            $bMonth = trim((string) ($_POST['born_month'] ?? ''));
            $bYear  = trim((string) ($_POST['born_year'] ?? ''));
            if ($bDay === '' || $bMonth === '' || $bYear === '') {
                $errors[] = 'Fill in the day, month, and year.';
            } elseif (!ctype_digit($bDay) || !ctype_digit($bMonth) || !ctype_digit($bYear)
                || !checkdate((int) $bMonth, (int) $bDay, (int) $bYear)) {
                $errors[] = 'Enter a real date.';
            } else {
                $bornValue = sprintf('%04d-%02d-%02d', (int) $bYear, (int) $bMonth, (int) $bDay);
                $pdo->prepare('UPDATE persons SET born = :b WHERE id = :id')->execute(['b' => $bornValue, 'id' => $myPersonId]);
                $target = person_row($pdo, (int) $targetId);
                $notice = 'Birth date saved.';
            }
        }
    }
}

// Phase 28: the onboarding tour now spans both timeline.php and tree.php
// (Phil's own training script walks through both), so it only ever
// AUTO-STARTS here — the tour's own "your" wording only makes sense on
// your own landing page — but the tour UI itself, the step data, and the
// "take the tour again" replay button are rendered unconditionally below,
// since a returning owner can replay it any time. Computed after the POST
// handling above (not before) so that dismissing it via the dismiss_tour
// action takes effect immediately, on this same request, rather than
// needing one more page load.
$autostartTour = $isOwner && empty($me['tour_completed_at']);

$entries = fetch_entries_for_person($pdo, (int) $target['id'], $canManage, $myPersonId);
$targetName = person_display_name($target);

// Phase 39: the same "anyone in my family, not marked deceased, with a
// birthday due within a week" reminder Phase 35 added to tree.php, now
// also shown here per Phil's request -- $myGroup matches tree.php's own
// scoping (the viewer's own family group; always the same group $target
// belongs to, per the access check above).
//
// Phase 68: "extend [send a card] to all pages and for all events that
// appear on the calendar" -- widened from birthdays alone to
// ourthology_calendar_reminder_rows()'s merged birthday + key-date list
// (see that function's own doc comment in includes/calendar.php).
// Sender's own first name feeds the card composer's default closing line
// ("lots of love, Phil"), computed once here rather than per-row.
$graph = fetch_family_graph($pdo, $myGroup);
$reminderCardRows = ourthology_calendar_reminder_rows($pdo, $graph['persons'], $myGroup);
$myFirstName = (string) ($me['first_name'] ?? '');

// Phase 68: a "Send a card" link from a page OTHER than this one
// (tree.php's own banner, or calendar.php's "Coming up" list) can point
// at a birthday or key date that's outside this page's own 7-day
// reminder window (calendar.php's list reaches out to 31 days) -- so
// rather than requiring a matching row to already be rendered in
// $reminderCardRows, this looks the specific requested person/event up
// directly and hands the composer everything it needs via
// window.ourthologyOpenCardComposer() on load, regardless of whether it
// would otherwise appear in the banner at all right now.
$directOpenCardRow = null;
if (isset($_GET['send_card_to'])) {
    $wantPersonId = filter_var($_GET['send_card_to'], FILTER_VALIDATE_INT);
    if ($wantPersonId !== false) {
        foreach ($graph['persons'] as $p) {
            if ((int) $p['id'] === $wantPersonId && empty($p['died']) && !empty($p['born'])) {
                $name = person_display_name($p);
                // Keys here are camelCase (personId, not person_id) to match
                // what window.ourthologyOpenCardComposer() already expects
                // from the .birthday-send-card-btn click handler below --
                // this array is handed to that same function via
                // json_encode(), so the two call sites need the same shape.
                $directOpenCardRow = [
                    'kind'            => 'birthday',
                    'personId'        => (int) $p['id'],
                    'eventId'         => null,
                    'name'            => $name,
                    'firstName'       => (string) ($p['first_name'] ?? $name),
                    'coverDefault'    => 'Happy Birthday!',
                    'greetingDefault' => 'Happy Birthday',
                ];
                break;
            }
        }
    }
} elseif (isset($_GET['send_card_for_event'])) {
    $wantEventId = filter_var($_GET['send_card_for_event'], FILTER_VALIDATE_INT);
    if ($wantEventId !== false) {
        $wantedEvent = fetch_calendar_event_for_group($pdo, $wantEventId, $myGroup);
        if ($wantedEvent !== null) {
            $eventTitle = (string) $wantedEvent['title'];
            $directOpenCardRow = [
                'kind'            => 'key_date',
                'personId'        => null,
                'eventId'         => (int) $wantedEvent['id'],
                'name'            => $eventTitle,
                'firstName'       => '',
                'coverDefault'    => mb_substr($eventTitle, 0, 60),
                'greetingDefault' => mb_substr($eventTitle, 0, 60),
            ];
        }
    }
}

/** occurred_on if set, otherwise the date the entry was created — same fallback the plain-list view used. */
function ourthology_entry_date(array $entry): string
{
    return $entry['occurred_on'] ?: substr((string) $entry['created_at'], 0, 10);
}

$jsEntries = [];
foreach ($entries as $entry) {
    $date = ourthology_entry_date($entry);
    $title = trim((string) ($entry['title'] ?? ''));
    if ($title === '') {
        $title = $entry['entry_type'] === 'diary'
            ? 'Diary — ' . date('j M Y', strtotime($date))
            : 'Untitled memory';
    }
    $media = [];
    foreach ($entry['media'] as $m) {
        $mime = (string) $m['mime_type'];
        $kind = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : 'file');
        $media[] = ['kind' => $kind, 'url' => '/media.php?id=' . (int) $m['id']];
    }

    // Editable per ENTRY, not per page — a memory tagged onto my own
    // timeline that someone else wrote stays theirs to edit/delete, even
    // while I'm looking at it on my own "My timeline" page (only its owning
    // person's own account holder, or anyone managing that person's
    // still-unclaimed profile, may touch the memory itself; see
    // add_entry.php's edit mode).
    $entryCanEdit = (int) $entry['owner_family_group'] === $myGroup
        && person_is_editable_by(['claimed_by_user_id' => $entry['owner_claimed_by']], (int) $me['user_id']);

    $tags = [];
    $myNote = null;
    $iAmTagged = false;
    foreach ($entry['tags'] as $t) {
        $tags[] = ['name' => $t['name'], 'note' => (string) ($t['note'] ?? '')];
        if ($t['person_id'] === $myPersonId) {
            $iAmTagged = true;
            $myNote = (string) ($t['note'] ?? '');
        }
    }

    $jsEntries[] = [
        'id'         => (string) $entry['id'],
        'date'       => $date,
        'title'      => $title,
        'thought'    => (string) ($entry['body'] ?? ''),
        'visibility' => $entry['visibility'],
        'type'       => $entry['entry_type'] === 'diary' ? 'diary' : 'memory',
        'media'      => $media,
        'canEdit'    => $entryCanEdit,
        'tags'       => $tags,
        'iAmTagged'  => $iAmTagged,
        'myNote'     => $myNote,
        // Phase 54: "show it on their timeline as a little mini
        // postcard/envelope symbol" -- set only on the copy a save
        // creates (save_postcard_to_timeline()/save_letter_copy_to_
        // timeline() in includes/postcards.php / includes/letters.php),
        // never on an ordinary memory, so the card rail can badge just
        // those.
        'origin'     => $entry['origin'],
        // Phase 69: Memory Planner summary -- null for every ordinary
        // entry, set only for origin='trip' rows, and used both for the
        // rail card's own date-range/event-count label (renderRail()
        // below) and to know which trip_plans.id to fetch when the card
        // is clicked. NOTE: the visual river/rings/spiral timeline graphic
        // itself still only ever plots one point in time per entry (its
        // `date` above, the trip's start date) -- there's no rendering of
        // a date range on that diagram, only in the rail card's text and
        // inside the planner pop-up itself.
        'trip'       => ($entry['origin'] === 'trip' && $entry['trip']) ? [
            'tripPlanId' => (int) $entry['trip']['trip_plan_id'],
            'startDate'  => $entry['trip']['start_date'],
            'finishDate' => $entry['trip']['finish_date'],
            'eventCount' => (int) $entry['trip']['event_count'],
        ] : null,
    ];
}

// The "All life" zoom and the life-stage colour bands both need a birth date,
// but nothing in the app captures one yet (persons.born is only ever set via
// the small form below). Fall back gracefully rather than requiring it: the
// earliest thing on the timeline, or failing that, when the person record
// itself was created.
$bornIsReal = !empty($target['born']);
if ($bornIsReal) {
    $birthDate = $target['born'];
} elseif ($jsEntries) {
    $allDates = array_column($jsEntries, 'date');
    sort($allDates);
    $birthDate = $allDates[0];
} else {
    $birthDate = substr((string) $target['created_at'], 0, 10);
}
[$birthYear, $birthMonth, $birthDay] = array_map('intval', explode('-', $birthDate));

$entriesJson = json_encode($jsEntries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
// Guard against a literal "</script" inside a title/body from breaking out of
// the embedding <script> tag — "\/" is a valid JSON escape for "/", so this
// is invisible to JSON.parse.
$entriesJsonSafe = str_replace('</', '<\/', (string) $entriesJson);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($targetName, ENT_QUOTES) ?> — timeline — ourthology.com</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,500;0,600;0,700;0,800;1,600&family=Newsreader:ital,wght@0,400;0,500;0,600;1,400&family=Caveat:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css?v=26">
<script defer src="https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js"></script>
<style>
  :root {
    --accent-bg: #F1DCDC;
    --accent-2: #1C3D5A;
    --accent-2-bg: #DCE6ED;
    --accent-2-glow: #3C6E93;
    --line-soft: #ECE4D2;
    --tape: #B58A3C;
    --fam1: #9A2A2A; --fam1-bg: #F1DCDC;
    --fam2: #B58A3C; --fam2-bg: #F1E4C9;
    --fam3: #3A6B4F; --fam3-bg: #D9E8DE;
    --fam4: #1C3D5A; --fam4-bg: #DCE6ED;
    --fam5: #8A7F6C; --fam5-bg: #E8E2D6;
    --fam6: #7A4B57; --fam6-bg: #E9DBDE;
    --shadow: 0 1px 2px rgba(26,23,20,0.08), 0 10px 26px -14px rgba(26,23,20,0.28);
  }
  body { align-items: flex-start; }
  .wide { max-width: 1220px; }
  /* Phase 27: the brand row and the "who's signed in" strip both used to
     leave the page's top-right corner empty — now the viewed person's
     profile picture sits there instead, and "Signed in as" moves down to
     sit alongside the nav buttons rather than stranded on its own on the
     far right. */
  /* Phase 29: that big avatar used to be a flex sibling of .brand, with
     .nav as a separate FULL-WIDTH row underneath — since .nav's own
     content (My tree / + Add a memory / Signed in as) never needed
     anywhere near the full card width, that left a large blank block to
     the right of the nav row and below the avatar (Phil circled it in a
     screenshot). Floating the avatar instead, with .brand and .nav kept
     as ordinary flex boxes (each one already establishes its own layout
     context, so its box narrows to avoid a float rather than spanning
     full width), makes both of them wrap up snugly against the avatar's
     left edge instead of sitting in their own full-width row beneath it —
     the same "text wraps around an image" behaviour as a magazine layout.
     .page-head::after clears the float so the rest of the page always
     starts below both the avatar and the (now much shorter) nav block,
     whichever is taller. */
  .page-head { position:relative; }
  .page-head::after { content:""; display:table; clear:both; }
  /* Phil asked for this bigger, then bigger again — now double the second
     size (156px desktop, up from an initial 78px). */
  /* Phase 71 note: a first attempt at moving "Take the tour" / "What's
     new" put them in their own column stacked under this photo -- that
     made .page-head's floated block much taller than the nav row beside
     it, leaving a large blank gap between "My timeline" and the
     River/Rings/Spiral row below (Phil flagged it with a screenshot).
     Reverted back to a plain floated photo, with the buttons living in
     .controls instead, on the River/Rings/Spiral row -- see
     .tour-controls-group below. */
  /* Phase 71 (mobile): "beside the profile image... it will look much
     neater" -- on the stacked mobile layout there's no River/Rings/Spiral
     row directly under the header the way there is on desktop, so the
     buttons on that row (.tour-controls-group) end up wrapping onto their
     own row below it instead, further down the page than the header.
     .header-avatar-row wraps the avatar so a second, mobile-only pair of
     buttons (.header-avatar-actions-mobile -- same IDs' worth of function,
     different elements, since these need to show only below 620px while
     .tour-controls-group's originals keep showing above it) can sit next
     to it, hidden entirely above 620px so desktop is untouched. */
  .header-avatar-row { float:right; margin:0 0 12px 18px; }
  .header-avatar-actions-mobile { display:none; }
  .header-avatar, .header-avatar-placeholder { width:156px; height:156px; border-radius:50%; object-fit:cover; background:#fff; border:1px solid var(--line); }
  .header-avatar-placeholder { display:flex; align-items:center; justify-content:center; font-family:"Georgia",serif; font-size:62px; color:var(--ink-faint); }
  @media (max-width: 620px) {
    /* At this size the photo no longer fits beside the wordmark on a real
       phone width (confirmed by measuring, not by eye — it ran off the
       card's right edge before this), so on a narrow phone the header
       goes back to a plain stacked column instead of wrapping text around
       the float — "order" puts the avatar row back between the brand and
       the nav regardless of where it sits in the markup (it has to come
       first in the markup for the desktop float-wrap above to work). */
    .page-head { display:flex; flex-direction:column; }
    .page-head .brand { order:1; }
    .header-avatar-row { order:2; float:none; width:100%; margin:4px 0 10px 0; display:flex; align-items:center; justify-content:flex-end; gap:10px; }
    .header-avatar, .header-avatar-placeholder { width:122px; height:122px; font-size:49px; flex:none; }
    .header-avatar-actions-mobile { display:flex; flex-direction:column; gap:6px; }
    .header-avatar-actions-mobile .linklet-btn { font-size:12px; font-weight:600; padding:6px 11px; border-radius:999px; border:1px solid var(--accent); color:var(--on-accent); background:var(--accent); cursor:pointer; font-family:inherit; white-space:nowrap; text-align:center; }
    .header-avatar-actions-mobile .linklet-btn.ghost { background:transparent; color:var(--accent); }
    .page-head .nav { order:3; }
    .page-head .page-head-heading { order:4; }
    /* The vertical divider before "Signed in as" only makes sense when it
       sits on the same line as the nav buttons — once it wraps to its own
       line on a narrow screen, a lone leading bar with nothing beside it
       looks like a stray mark, so it becomes a top divider instead. */
    .whoami { margin-left:0; padding-left:0; border-left:none; padding-top:8px; border-top:1px solid var(--line); width:100%; }
  }
  .nav { display:flex; gap:10px 16px; flex-wrap:wrap; align-items:center; margin: 18px 0 4px; }
  /* Phase 29: flex:1 so this fills .nav's own width (which now stops at
     the floated avatar rather than the far edge of the card) — without
     it, .whoami's margin-left:auto below had nothing to push against,
     since .nav-links, being .nav's only child, was otherwise only ever as
     wide as its own buttons, leaving a second blank gap between them and
     the avatar. */
  .nav-links { display:flex; flex:1 1 auto; gap:10px; flex-wrap:wrap; align-items:center; }
  .nav a { font-size:13px; padding:7px 12px; border-radius:999px; border:1px solid var(--line); color:var(--ink-soft); text-decoration:none; background:#fff; }
  /* Phase 28: the "Take the tour" replay button — same red-pill treatment
     tree.php's own "Print tree" button already uses for a stand-out action
     among plain nav links, kept here so it's not just a plain-looking pill. */
  .nav .linklet-btn { font-size:13px; font-weight:600; padding:7px 14px; border-radius:999px; border:1px solid var(--accent); color:var(--on-accent); background:var(--accent); cursor:pointer; font-family:inherit; }
  .nav .linklet-btn:hover { background:var(--accent-glow); border-color:var(--accent-glow); }
  /* Phase 70: #tourWhatsNewBtn sits right next to #tourReplayBtn -- ghost
     (outline, not filled) so it reads as the lighter-weight, secondary
     option of the two rather than competing with "Take the tour". */
  .nav .linklet-btn.ghost { background:transparent; color:var(--accent); }
  .nav .linklet-btn.ghost:hover { background:var(--paper-2); border-color:var(--accent); color:var(--accent); }

  /* Phase 48: "Send a postcard" -- a compose pop-up styled like a
     physical postcard, front (photo) and back (handwritten note) as two
     faces of a 3D-flipped card. Same fixed-overlay convention as this
     app's other pop-ups; pending.php's read-only version of this same
     card reuses every one of these classes byte-for-byte. */
  .postcard-overlay { position:fixed; inset:0; background:rgba(26,23,20,0.6); z-index:1000; display:flex; align-items:center; justify-content:center; padding:20px; overflow:auto; }
  .postcard-box { position:relative; width:min(96vw, 640px); max-height:94vh; overflow:auto; background:var(--paper); border:2px solid var(--accent); border-radius:16px; box-shadow:0 24px 60px -20px rgba(0,0,0,0.45); padding:22px 24px 26px; box-sizing:border-box; }
  .postcard-close { position:absolute; top:10px; right:12px; z-index:2; width:32px; height:32px; border-radius:50%; border:1px solid var(--line); background:#fff; color:var(--ink-soft); font-size:18px; line-height:1; cursor:pointer; }
  .postcard-close:hover { background:var(--paper-2); }

  .postcard-audience { margin:0 0 14px; }
  .postcard-audience .radio-row { display:flex; align-items:center; gap:8px; font-size:14px; margin-bottom:6px; cursor:pointer; }
  .postcard-recipient-list { display:none; flex-wrap:wrap; gap:6px 16px; margin:6px 0 4px 24px; max-height:130px; overflow:auto; }
  .postcard-recipient-list.is-open { display:flex; }
  .postcard-recipient-list label { display:flex; align-items:center; gap:6px; font-size:13.5px; cursor:pointer; }

  .postcard-flip-scene { perspective:1600px; width:100%; aspect-ratio:3/2; margin:4px 0 14px; }
  .postcard-flip-inner { position:relative; width:100%; height:100%; transition:transform 0.7s cubic-bezier(.4,.2,.2,1); transform-style:preserve-3d; }
  .postcard-flip-inner.is-flipped { transform:rotateY(180deg); }
  .postcard-face { position:absolute; inset:0; backface-visibility:hidden; -webkit-backface-visibility:hidden; border:2px solid var(--accent); border-radius:12px; background:#fff; box-shadow:0 6px 18px -10px rgba(0,0,0,0.35); overflow:hidden; }
  .postcard-face-back { transform:rotateY(180deg); display:flex; flex-direction:column; }

  /* Phase 49: the front's photo now sits "matted" on a card-coloured
     backdrop -- like a printed photo tucked onto a postcard -- instead
     of bleeding edge to edge. */
  /* Phase 50: a thick WHITE border on the front (like a printed photo
     tucked onto the card) and a diagonal striped "airmail" border on the
     back -- closer to Phil's reference postcard than Phase 49's plainer
     coloured-gradient mat. */
  .postcard-face-front { padding:16px; box-sizing:border-box; background:#fff; }
  .postcard-photo-mat { position:relative; width:100%; height:100%; border-radius:2px; overflow:hidden; background:#fff; }
  /* Phase 52: thicker stripes, closer to Phil's reference image than
     Phase 50's narrower 9px band. */
  .postcard-face-back {
    border-width:14px;
    border-style:solid;
    border-image-source: repeating-linear-gradient(-45deg,
      #9a2a2a 0, #9a2a2a 14px,
      #fff 14px, #fff 28px,
      #29456e 28px, #29456e 42px,
      #fff 42px, #fff 56px);
    border-image-slice:46;
    border-image-repeat:round;
    border-radius:0;
  }
  .postcard-drop-zone { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; flex-direction:column; gap:8px; cursor:pointer; color:var(--ink-faint); font-size:13.5px; text-align:center; padding:16px; box-sizing:border-box; }
  .postcard-drop-zone.is-dragover { background:var(--paper-2); }
  .postcard-face-front.has-image .postcard-drop-zone { display:none; }
  .postcard-front-preview { position:absolute; inset:0; display:none; width:100%; height:100%; object-fit:cover; }
  .postcard-face-front.has-image .postcard-front-preview { display:block; }
  .postcard-change-photo { display:none; position:absolute; bottom:8px; right:8px; z-index:1; font-size:11.5px; padding:5px 10px; border-radius:999px; background:rgba(26,23,20,0.65); color:#fff; border:none; cursor:pointer; }
  .postcard-face-front.has-image .postcard-change-photo { display:block; }

  /* Phase 49: the back is now the classic two-column postcard layout --
     ruled message on the left, a stamp + address lines on the right,
     divided by a rule -- with the flip button moved into its own
     toolbar strip instead of floating on top of the message text
     (previously overlapping the first line or two of whatever was
     typed). */
  .postcard-back-toolbar { flex:0 0 auto; display:flex; align-items:center; padding:8px 10px; border-bottom:1px solid var(--line); background:var(--paper-2); }
  .postcard-back-content { flex:1 1 auto; display:flex; min-height:0; }
  .postcard-back-message { flex:1 1 58%; width:auto; min-width:0; box-sizing:border-box; border:none; resize:none; padding:16px 18px; font-family:'Caveat',cursive; font-size:22px; line-height:1.5; color:#2b2620; background:repeating-linear-gradient(to bottom, transparent, transparent 34px, var(--line) 35px); outline:none; }
  .postcard-read-message { flex:1 1 58%; width:auto; min-width:0; box-sizing:border-box; padding:16px 18px; font-family:'Caveat',cursive; font-size:22px; line-height:1.5; color:#2b2620; overflow:auto; }
  .postcard-back-address { flex:0 0 40%; box-sizing:border-box; border-left:1px dashed var(--line); padding:14px 16px; display:flex; flex-direction:column; }
  /* Phase 52: a real postmark -- a branded postage-stamp graphic (the
     site's own brand mark) plus a cancellation-style circular postmark
     reading "OURTHOLOGY POST OFFICE" -- replacing the placeholder
     dashed box. */
  .postcard-stamp { position:static; align-self:flex-end; flex:0 0 auto; width:104px; aspect-ratio:118/84; margin-bottom:16px; }
  .postcard-stamp svg { display:block; width:100%; height:100%; overflow:visible; }
  .postcard-address-lines { display:flex; flex-direction:column; gap:12px; margin-top:auto; }
  /* Phase 54: "let me edit the To and From lines" -- these used to be
     read-only spans filled in from the recipient/sender's real names;
     now they're editable, prefilled with a sensible default and posted
     as to_line/from_line (see postcard.php's send action +
     create_postcard() in includes/postcards.php). pending.php's read
     view keeps the old read-only .postcard-address-line/.is-filled
     styling for the same spot -- it never needs to be editable there.
     Phase 55: these started life as real <input>s, but Phil found the
     "From" text rendered mirrored after flipping the card from photo to
     message side -- a native form control painted inside the nested
     rotateY(180deg)+rotateY(180deg) flip-card transform, which some
     browsers/GPUs don't compose correctly for OS-drawn widgets (regular
     text nodes are unaffected, which is why the textarea/message side
     never showed it). Swapped for plain contenteditable spans, exactly
     the technique the letter body editor already uses (see
     #letterBodyEditor below) -- ordinary DOM text, no native widget, so
     there's nothing for a 3D transform to mis-render. Synced into the
     real to_line/from_line hidden inputs on submit (wireForm() below). */
  .postcard-address-field { display:flex; align-items:baseline; gap:6px; border-bottom:1px solid var(--line); padding-bottom:4px; }
  .postcard-address-label { flex:0 0 auto; font-size:10.5px; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-faint); font-family:inherit; }
  .postcard-address-input { flex:1 1 auto; min-width:0; outline:none; font-family:'Caveat',cursive; font-size:16px; color:#2b2620; white-space:nowrap; overflow:hidden; }
  .postcard-address-input:empty::before { content:attr(data-placeholder); color:var(--ink-faint); font-family:'Caveat',cursive; opacity:.8; }

  .postcard-back-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:10px 16px; border-top:1px solid var(--line); background:var(--paper-2); }
  .postcard-back-footer label { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--ink-soft); }
  .postcard-flip-btn { font-size:12.5px; font-weight:600; padding:6px 12px; border-radius:999px; border:1px solid var(--accent); color:var(--accent); background:#fff; cursor:pointer; font-family:inherit; }
  .postcard-flip-btn:hover { background:var(--paper-2); }
  .postcard-send-btn { width:auto; margin:0; padding:9px 20px; }
  .postcard-read-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:4px; }

  /* Phase 53: "send a letter instead" -- a longer-form single-recipient
     alternative offered from the same compose pop-up, styled like a
     single sheet of ourthology.com letterhead rather than a flipped
     postcard: a branded header (sender + date, same as a standard form
     letter), a recipient picker that fills in "Dear X,", a large
     handwriting-font body that can carry inline photos, and an
     auto-generated "Best regards" close. */
  /* Phase 54: split into plain lead-in text plus a real button for just
     the "Send a letter instead" part -- it used to be one giant button
     with the whole sentence as its label. */
  .letter-switch-text { margin:0 0 14px; font-size:13px; color:var(--ink-soft); }
  .letter-switch-btn { display:inline; background:none; border:none; padding:0; margin:0; font-size:13px; font-weight:700; color:var(--accent); text-decoration:underline; cursor:pointer; font-family:inherit; }
  .letter-mode-panel { display:none; }
  .letter-back-link { display:inline-block; background:none; border:none; padding:0; margin:0 0 12px; font-size:12.5px; color:var(--ink-soft); text-decoration:underline; cursor:pointer; font-family:inherit; }
  .letterhead { display:flex; align-items:center; gap:12px; padding:14px 18px; border:1px solid var(--line); border-radius:12px 12px 0 0; background:linear-gradient(135deg, #9A2A2A, #7a2020); color:#FBF8F1; }
  .letterhead .letter-brand-mark { flex:none; }
  .letterhead-text { flex:1 1 auto; min-width:0; }
  .letterhead-word { margin:0; font-family:"Fraunces", Georgia, serif; font-size:17px; font-weight:700; }
  .letterhead-word .tld { color:#e8c9c9; font-weight:400; }
  .letterhead-meta { margin:2px 0 0; font-size:12px; color:#e8c9c9; }
  .letter-sheet { border:1px solid var(--line); border-top:none; border-radius:0 0 12px 12px; background:#fffdf7; padding:18px 22px 20px; box-shadow:0 6px 18px -12px rgba(0,0,0,0.3); }
  .letter-recipient-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:0 0 14px; font-size:13.5px; }
  .letter-recipient-row select { width:auto; flex:1 1 220px; padding:7px 10px; border:1px solid var(--line); border-radius:8px; background:#fff; font-family:inherit; font-size:13.5px; }
  .letter-salutation { font-family:'Caveat',cursive; font-size:24px; color:#2b2620; margin:0 0 6px; }
  /* Phase 55: "let me edit the To and From names" on letters too -- the
     greeting/closing names are contenteditable spans right inside the
     "Dear ___," / "Best regards, ___" sentence (matching the postcard
     address fields' contenteditable technique -- see the CSS comment
     above .postcard-address-field), synced into hidden to_line/from_line
     inputs on submit (wireLetterForm() below). */
  .letter-name-editable { display:inline-block; min-width:20px; outline:none; border-bottom:1px dashed transparent; }
  .letter-name-editable:hover, .letter-name-editable:focus { border-bottom-color:var(--line); }
  .letter-name-editable:empty::before { content:attr(data-placeholder); color:var(--ink-faint); opacity:.8; }
  .letter-body-editor { min-height:220px; max-height:420px; overflow:auto; padding:10px 2px; font-family:'Caveat',cursive; font-size:21px; line-height:1.55; color:#2b2620; outline:none; }
  .letter-body-editor:empty::before { content:attr(data-placeholder); color:var(--ink-faint); font-family:'Caveat',cursive; font-size:21px; }
  .letter-body-editor img { max-width:100%; border-radius:6px; margin:8px 0; display:block; }
  .letter-closing { font-family:'Caveat',cursive; font-size:22px; color:#2b2620; margin:10px 0 0; line-height:1.3; }
  .letter-toolbar { display:flex; align-items:center; gap:10px; margin:0 0 4px; padding-bottom:8px; border-bottom:1px dashed var(--line); }
  .letter-insert-photo { font-size:12.5px; font-weight:600; padding:6px 12px; border-radius:999px; border:1px solid var(--accent); color:var(--accent); background:#fff; cursor:pointer; font-family:inherit; }
  .letter-insert-photo:hover { background:var(--paper-2); }
  .letter-insert-photo:disabled { opacity:0.6; cursor:default; }
  .letter-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-top:16px; padding-top:12px; border-top:1px solid var(--line); }
  .letter-footer label { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--ink-soft); }
  .letter-send-btn { width:auto; margin:0; padding:9px 20px; }

  /* Phase 61: "when a user hits the send letter button, do an animation
     that folds the page up and puts it into the envelope, and then
     flies it off the screen to the right hand side, and then returns
     the user to the timeline page. Do the same 'fly off the screen'
     animation ... for postcards." Purely a client-side delay in front
     of the existing real form submit -- postcard.php/letter.php's own
     send logic and redirect-to-timeline.php-with-a-flash-message flow
     are completely unchanged, JS just holds the POST back for as long
     as the animation runs. Both overlay and box need overflow switched
     to visible for the fly-off, or the box's own overflow:auto would
     just clip it at its own edge instead of letting it cross the
     dimmed backdrop and leave the viewport. */
  .postcard-overlay.is-sending { overflow:visible; }
  .postcard-box.is-sending { overflow:visible; pointer-events:none; }
  @keyframes ourthologyFold {
    0%   { transform:scaleY(1); opacity:1; }
    55%  { transform:scaleY(0.08); opacity:1; }
    100% { transform:scaleY(0.04); opacity:0; }
  }
  .is-folding { animation:ourthologyFold 0.4s cubic-bezier(.6,.04,.7,.46) forwards; }
  @keyframes ourthologyPopIn {
    0%   { transform:scale(0.6); opacity:0; }
    70%  { transform:scale(1.08); opacity:1; }
    100% { transform:scale(1); opacity:1; }
  }
  .is-popping { animation:ourthologyPopIn 0.18s ease-out forwards; }
  @keyframes ourthologyFlyOff {
    0%   { transform:translate(0,0) rotate(0deg); opacity:1; }
    18%  { transform:translate(-8px,-12px) rotate(-5deg); opacity:1; }
    100% { transform:translate(160vw,-30px) rotate(24deg); opacity:0; }
  }
  .is-flying { animation:ourthologyFlyOff 0.62s cubic-bezier(.45,0,.6,1) forwards; }
  @media (prefers-reduced-motion: reduce) {
    .is-folding, .is-popping, .is-flying { animation-duration:0.001s !important; }
  }
  /* The little envelope the letter "goes into" once it's folded flat --
     same flap/gradient look as pending.php's .envelope-row, just sized
     for this pop-up, with its own small stamp for continuity. */
  .letter-fly-wrap { position:absolute; inset:0; display:none; align-items:center; justify-content:center; pointer-events:none; z-index:5; }
  .letter-fly-envelope { position:relative; width:200px; height:128px; border-radius:6px; background:linear-gradient(135deg,#f3ead9,#e9dcc4); border:1px solid #c9b998; box-shadow:0 10px 22px -10px rgba(0,0,0,.4); overflow:hidden; opacity:0; }
  .letter-fly-flap { position:absolute; top:0; left:0; width:100%; height:58%; background:linear-gradient(135deg,#ede1c9,#ddcba3); clip-path:polygon(0 0,100% 0,50% 100%); box-shadow:0 1px 3px rgba(0,0,0,.15); }
  .letter-fly-stamp { position:absolute; top:10px; right:12px; width:52px; z-index:2; }
  .letter-fly-stamp svg { display:block; width:100%; height:auto; overflow:visible; }

  /* Phase 67: the greeting card pop-up, opened from a birthday banner's
     "Send a card" button rather than a general compose button -- taller
     than it is wide, like a real greeting card, with a front cover
     (photo + editable overlay message + "Add your message" button) that
     swings open on its left edge -- like turning a book cover, not a
     symmetric flip -- to reveal an inside spread of editable fields
     underneath. Its own class prefix (gcard-) throughout so nothing here
     touches the postcard/letter styles above, even though the overlay
     backdrop and box chrome are deliberately the same look. */
  .gcard-overlay { position:fixed; inset:0; background:rgba(26,23,20,0.6); z-index:1000; display:flex; align-items:center; justify-content:center; padding:20px; overflow:auto; }
  .gcard-box { position:relative; width:min(92vw, 380px); max-height:94vh; overflow:auto; background:var(--paper); border:2px solid var(--accent); border-radius:16px; box-shadow:0 24px 60px -20px rgba(0,0,0,0.45); padding:22px 22px 26px; box-sizing:border-box; }
  .gcard-close { position:absolute; top:10px; right:12px; z-index:6; width:32px; height:32px; border-radius:50%; border:1px solid var(--line); background:#fff; color:var(--ink-soft); font-size:18px; line-height:1; cursor:pointer; }
  .gcard-close:hover { background:var(--paper-2); }
  .gcard-title { margin:0 0 12px; }

  /* Phase 68: only shown for a key-date card (no single implied
     recipient the way a birthday card has) -- sits above the scene since
     the sender needs to pick who it's for before the "To........." field
     inside means anything. */
  .gcard-recipient-picker { display:flex; flex-direction:column; gap:4px; margin:0 0 14px; }
  .gcard-recipient-picker label { font-size:12.5px; color:var(--ink-faint); }
  .gcard-recipient-picker select { padding:9px 10px; border:1px solid var(--line); border-radius:8px; font-family:inherit; font-size:14px; background:#fff; color:var(--ink-soft); }

  /* Taller than wide (5:7 is a standard greeting-card proportion) -- the
     cover sits on top (z-index above the inside), hinged on its LEFT
     edge (transform-origin) so opening it reads as a book/card cover
     swinging open, not a postcard-style centre flip. */
  .gcard-scene { position:relative; perspective:1800px; width:100%; aspect-ratio:5/7; margin:4px 0 0; }
  .gcard-cover { position:absolute; inset:0; z-index:3; transform-origin:left center; transition:transform 0.5s cubic-bezier(.4,.2,.2,1); transform-style:preserve-3d; }
  .gcard-cover.is-open { transform:rotateY(-150deg); pointer-events:none; }
  .gcard-cover-face { position:absolute; inset:0; backface-visibility:hidden; -webkit-backface-visibility:hidden; border:2px solid var(--accent); border-radius:12px; overflow:hidden; box-shadow:0 6px 18px -10px rgba(0,0,0,0.35); }
  .gcard-cover-front { background:#fff; }
  .gcard-cover-back { transform:rotateY(180deg); background:#fdfaf6; }

  .gcard-drop-zone { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; flex-direction:column; gap:8px; cursor:pointer; color:var(--ink-faint); font-size:13.5px; text-align:center; padding:16px; box-sizing:border-box; background:#fff; }
  .gcard-drop-zone.is-dragover { background:var(--paper-2); }
  .gcard-cover-front.has-image .gcard-drop-zone { display:none; }
  .gcard-front-preview { position:absolute; inset:0; display:none; width:100%; height:100%; object-fit:cover; }
  .gcard-cover-front.has-image .gcard-front-preview { display:block; }
  .gcard-change-photo { display:none; position:absolute; bottom:8px; right:8px; z-index:2; font-size:11px; padding:5px 10px; border-radius:999px; background:rgba(26,23,20,0.65); color:#fff; border:none; cursor:pointer; }
  .gcard-cover-front.has-image .gcard-change-photo { display:block; }

  /* "Overlay a message ... make it so this is the first thing the
     creator sees" -- a large editable line sat over the bottom of the
     photo with a dark scrim behind it so it reads on any picture. */
  .gcard-cover-message { position:absolute; left:0; right:0; bottom:0; z-index:2; padding:34px 14px 16px; box-sizing:border-box; text-align:center; background:linear-gradient(to top, rgba(20,16,10,0.65), rgba(20,16,10,0) 90%); pointer-events:none; }
  .gcard-cover-message-text { display:inline-block; min-width:50%; max-width:100%; outline:none; font-family:"Fraunces", Georgia, serif; font-size:24px; font-weight:700; line-height:1.2; color:#fff; text-shadow:0 2px 10px rgba(0,0,0,0.55); pointer-events:auto; }
  .gcard-cover-message-text:empty::before { content:attr(data-placeholder); color:rgba(255,255,255,0.8); }

  .gcard-front-footer { display:flex; justify-content:center; margin:14px 0 4px; }
  .gcard-open-btn { width:auto; margin:0; padding:10px 22px; }

  /* The inside spread -- sits statically UNDER the cover the whole time
     (z-index below it); opening the cover just reveals what was already
     there, nothing about the inside itself animates in. */
  .gcard-inside { position:absolute; inset:0; z-index:1; display:flex; flex-direction:column; border:2px solid var(--accent); border-radius:12px; background:#fdfaf6; box-shadow:0 6px 18px -10px rgba(0,0,0,0.35); padding:20px 18px 16px; box-sizing:border-box; overflow:auto; }
  .gcard-field { margin:0 0 13px; }
  .gcard-field-label { display:block; font-size:15px; font-family:'Caveat',cursive; color:var(--ink-faint); margin-bottom:2px; }
  .gcard-field-input { display:block; width:100%; box-sizing:border-box; outline:none; border:none; border-bottom:1px dashed var(--line); padding-bottom:4px; font-family:'Caveat',cursive; font-size:19px; line-height:1.3; color:#2b2620; min-height:1.3em; }
  .gcard-field-input:empty::before { content:attr(data-placeholder); color:var(--ink-faint); opacity:0.75; }
  .gcard-field-message { flex:1 1 auto; display:flex; flex-direction:column; min-height:0; }
  .gcard-message-input { flex:1 1 auto; border-bottom:none; background:repeating-linear-gradient(to bottom, transparent, transparent 29px, var(--line) 30px); font-size:18px; line-height:1.5; min-height:80px; overflow:auto; }
  .gcard-closing-input { border-bottom:none; font-size:20px; text-align:right; }

  .gcard-inside-footer { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:8px; padding-top:10px; border-top:1px solid var(--line); }
  .gcard-back-link { display:inline-block; background:none; border:none; padding:0; margin:0; font-size:12.5px; color:var(--ink-soft); text-decoration:underline; cursor:pointer; font-family:inherit; }
  .gcard-send-btn { width:auto; margin:0; padding:9px 20px; }

  /* Phase 61's send-animation convention, reused for the card: lock the
     overlay so × / click-outside / Escape can't tear it down mid-
     animation, switch overflow to visible so the fly-off can cross the
     box's own edge and the dimmed backdrop. */
  .gcard-overlay.is-sending { overflow:visible; }
  .gcard-box.is-sending { overflow:visible; pointer-events:none; }

  /* "animate the close of the card with the card being added to an
     envelope ... animate it going off to the recipient ... with more of
     a flourish" -- three stages: the open cover swings back shut
     (reusing .gcard-cover's own transition, just toggling .is-open off),
     then the whole card scene pops into a little envelope, then that
     envelope flies off with a bit more wobble/bounce than the plain
     postcard/letter toss (ourthologyFlyOff above) gets -- a left-right
     flourish before launch and a scale pulse on landing in the envelope. */
  @keyframes gcardFold {
    0%   { transform:scale(1) rotate(0deg); opacity:1; }
    55%  { transform:scale(0.16) rotate(-3deg); opacity:1; }
    100% { transform:scale(0.05) rotate(-3deg); opacity:0; }
  }
  .gcard-scene.is-folding { animation:gcardFold 0.45s cubic-bezier(.6,.04,.7,.46) forwards; }
  @keyframes gcardPopIn {
    0%   { transform:scale(0.5) rotate(-10deg); opacity:0; }
    55%  { transform:scale(1.18) rotate(5deg); opacity:1; }
    75%  { transform:scale(0.94) rotate(-3deg); opacity:1; }
    100% { transform:scale(1) rotate(0deg); opacity:1; }
  }
  .gcard-envelope.is-popping { animation:gcardPopIn 0.32s cubic-bezier(.34,1.56,.64,1) forwards; }
  @keyframes gcardFlyOff {
    0%   { transform:translate(0,0) rotate(0deg) scale(1); opacity:1; }
    12%  { transform:translate(-14px,-18px) rotate(-10deg) scale(1.06); opacity:1; }
    26%  { transform:translate(12px,-34px) rotate(8deg) scale(1); opacity:1; }
    40%  { transform:translate(-4px,-46px) rotate(-4deg) scale(1.02); opacity:1; }
    100% { transform:translate(170vw,-70px) rotate(34deg) scale(0.85); opacity:0; }
  }
  .gcard-envelope.is-flying { animation:gcardFlyOff 0.85s cubic-bezier(.45,0,.55,1) forwards; }
  @media (prefers-reduced-motion: reduce) {
    .gcard-cover, .gcard-scene.is-folding, .gcard-envelope.is-popping, .gcard-envelope.is-flying { transition-duration:0.001s !important; animation-duration:0.001s !important; }
  }
  /* The envelope a card goes into -- "whiter than that used for a
     letter": .letter-fly-envelope above is a warm cream/tan gradient
     (#f3ead9 -> #e9dcc4); this is a crisp near-white instead, a
     deliberately different, more formal envelope for a keepsake card. */
  .gcard-envelope-wrap { position:absolute; inset:0; display:none; align-items:center; justify-content:center; pointer-events:none; z-index:5; }
  .gcard-envelope { position:relative; width:200px; height:132px; border-radius:6px; background:linear-gradient(135deg,#ffffff,#f7f6f2); border:1px solid #e4e0d5; box-shadow:0 10px 24px -10px rgba(0,0,0,.4); overflow:hidden; opacity:0; }
  .gcard-envelope-flap { position:absolute; top:0; left:0; width:100%; height:58%; background:linear-gradient(135deg,#ffffff,#f1f0ea); clip-path:polygon(0 0,100% 0,50% 100%); box-shadow:0 1px 3px rgba(0,0,0,.12); }
  .gcard-envelope-stamp { position:absolute; top:10px; right:12px; width:52px; z-index:2; }
  .gcard-envelope-stamp svg { display:block; width:100%; height:auto; overflow:visible; }

  .whoami { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12.5px; color:var(--ink-faint); margin-left:auto; padding-left:14px; border-left:1px solid var(--line); }
  .whoami strong { color:var(--ink-soft); font-weight:600; }
  .whoami form { display:inline; }
  .whoami .linklet { font-size:12.5px; background:transparent; border:none; color:var(--accent); cursor:pointer; padding:0; text-decoration:underline; font-family:inherit; }
  .born-prompt { display:flex; align-items:center; gap:10px; flex-wrap:wrap; background:var(--paper-2); border:1px solid var(--line); border-radius:14px; padding:10px 14px; margin:14px 0 4px; font-size:13.5px; color:var(--ink-soft); }
  .born-prompt form { display:flex; align-items:flex-end; gap:8px; }
  .row-3 { display:grid; grid-template-columns: 4em 4em 5.5em; gap:8px; }
  .row-3 input { text-align:center; padding:7px 6px; border:1px solid var(--line); border-radius:6px; font-size:14px; background:#fff; color:var(--ink); }
  .date-slot span { display:block; font-size:10px; color:var(--ink-faint); text-align:center; margin-top:2px; }
  .btn-small { width:auto; margin:0; padding:8px 16px; font-size:13.5px; }
  /* Used on the viewer's Edit/Delete/Close row — a plain <button> already
     picks up a passable boxed look for free from the browser's own default
     button chrome, but the "Edit" link added alongside them needs its own
     real rule to match rather than rendering as bare text. */
  .btn-ghost { font: inherit; font-size:13.5px; padding:7px 14px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--ink-soft); cursor:pointer; }
  .btn-ghost:hover { border-color:var(--ink-faint); }

  h1, h2, h3, .display, .card-title, .viewer-title-view, .segmented button, .zoom-pill, .btn-primary, .rail-heading h2 {
    font-family: "Fraunces", Georgia, serif;
  }
  .mono { font-variant-numeric: tabular-nums; letter-spacing: 0.01em; }

  /* ---------- controls ---------- */
  .controls { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin: 14px 0 18px; }
  .segmented { display:inline-flex; background:var(--paper-2); border:1px solid var(--line); border-radius:999px; padding:4px; gap:2px; box-shadow:var(--shadow); }
  .segmented button { border:none; background:transparent; color:var(--ink-soft); font-weight:700; font-size:13px; padding:7px 13px; border-radius:999px; cursor:pointer; transition:background .15s ease,color .15s ease,transform .1s ease; white-space:nowrap; }
  .segmented button:hover { color:var(--ink); }
  .segmented button.active { background:var(--accent); color:var(--on-accent); transform:scale(1.04); }
  .zoom-pill { display:inline-flex; align-items:center; gap:6px; background:var(--accent-bg); color:var(--accent); border:none; border-radius:999px; padding:7px 12px 7px 13px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; }
  .zoom-pill[hidden] { display:none; }
  .zoom-pill:hover { filter:brightness(0.97); }
  .zoom-pill svg { width:14px; height:14px; flex:0 0 auto; }
  .zoom-pill .zoom-pill-x { font-size:16px; line-height:1; opacity:.75; margin-left:2px; }

  /* Phase 39: birthday reminder banner, shared with tree.php's Phase 35
     original -- sits as the right-pushed last item in .controls, i.e.
     top-right of the page, just under the avatar, in line with the
     River/Rings/Spiral and zoom buttons. */
  .birthday-banner { display:flex; align-items:center; gap:6px; padding:8px 16px; border:1px solid #C2790F; background:#F3DFB8; border-radius:999px; font-size:13px; color:#6B4A0A; max-width:100%; }
  .birthday-banner strong { color:#8A5A0A; font-weight:700; }
  /* Phase 67: "add an option in that banner to 'send a card'" -- the
     banner is now one pill per upcoming birthday (rather than one pill
     with every name joined into a single string) so each can carry its
     own button, wrapped in a group so several still sit together the way
     the old single pill did. */
  .birthday-banner-group { display:flex; flex-wrap:wrap; gap:8px; max-width:100%; }
  .birthday-banner-group .birthday-banner { margin-left:0; }
  .birthday-send-card-btn { flex:none; font-size:12px; font-weight:700; padding:5px 11px; margin-left:2px; border-radius:999px; border:1px solid #8A5A0A; color:#FBF8F1; background:#C2790F; cursor:pointer; font-family:inherit; white-space:nowrap; }
  .birthday-send-card-btn:hover { background:#A9670C; }

  /* Phase 71: "Take the tour" / "What's new" moved out of the nav row
     (and out of a too-tall column under the avatar -- see the note by
     .header-avatar above) onto the River/Rings/Spiral row instead, right
     next to each other. .controls-right is the single right-pushed group
     now -- it holds this button pair and the birthday banner group
     (whichever of the two are present), so however many of them render,
     they sit adjacent to each other at the row's right edge with one
     shared push, rather than each fighting for its own margin-left:auto
     and leaving a gap between them. */
  .controls-right { display:flex; align-items:center; flex-wrap:wrap; gap:8px; margin-left:auto; max-width:100%; }
  .tour-controls-group { display:flex; gap:8px; }
  .tour-controls-group .linklet-btn { font-size:13px; font-weight:600; padding:7px 14px; border-radius:999px; border:1px solid var(--accent); color:var(--on-accent); background:var(--accent); cursor:pointer; font-family:inherit; white-space:nowrap; }
  .tour-controls-group .linklet-btn:hover { background:var(--accent-glow); border-color:var(--accent-glow); }
  .tour-controls-group .linklet-btn.ghost { background:transparent; color:var(--accent); }
  .tour-controls-group .linklet-btn.ghost:hover { background:var(--paper-2); border-color:var(--accent); color:var(--accent); }
  /* Phase 71 (mobile): this pair moves beside the profile photo instead
     (.header-avatar-actions-mobile, up in .page-head) below 620px -- this
     override has to come after the base .tour-controls-group rule above,
     since a media-query rule earlier in the file loses a same-specificity
     tie to a later unconditional one, regardless of which one currently
     matches. */
  @media (max-width: 620px) {
    .tour-controls-group { display:none; }
  }

  /* ---------- timeline canvas ---------- */
  .arc-wrap { position:relative; background:var(--paper-2); border:2px solid var(--accent); border-radius:24px; box-shadow:var(--shadow); overflow:hidden; margin-bottom:26px; padding:8px; }
  .arc-wrap svg { display:block; cursor:crosshair; user-select:none; -webkit-user-select:none; touch-action:none; }
  .selection-rect { fill:var(--accent-glow); fill-opacity:.16; stroke:var(--accent); stroke-width:1.5; stroke-dasharray:4 3; pointer-events:none; display:none; }
  .selection-rect.active { display:block; }
  .arc-wrap.layout-river svg { width:100%; height:auto; }
  .arc-wrap.layout-rings svg, .arc-wrap.layout-spiral svg { width:100%; max-width:560px; height:auto; margin:0 auto; }

  .river-scrollbar { position:relative; height:10px; margin:10px 6px 4px; background:var(--line); border-radius:999px; cursor:pointer; display:none; }
  .arc-wrap.layout-river .river-scrollbar { display:block; }
  .river-scrollbar-thumb { position:absolute; top:0; height:100%; min-width:28px; background:var(--accent); border-radius:999px; cursor:grab; opacity:.8; touch-action:none; transition:opacity .15s; }
  .river-scrollbar-thumb:hover { opacity:1; }
  .river-scrollbar-thumb.dragging { cursor:grabbing; opacity:1; }

  .node-tooltip { position:absolute; left:0; top:0; transform:translate(-50%, calc(-100% - 14px)) scale(.92); background:var(--ink); border-radius:10px; padding:8px 12px; font-size:12.5px; line-height:1.35; box-shadow:var(--shadow); pointer-events:none; opacity:0; white-space:nowrap; max-width:240px; z-index:30; transition:opacity .12s ease, transform .12s ease; }
  .node-tooltip.visible { opacity:1; transform:translate(-50%, calc(-100% - 14px)) scale(1); }
  .node-tooltip::after { content:""; position:absolute; left:50%; top:100%; transform:translateX(-50%); border:6px solid transparent; border-top-color:var(--ink); }
  .node-tooltip-date { color:var(--accent-glow); font-weight:700; font-size:11px; letter-spacing:.02em; margin-bottom:2px; }
  .node-tooltip-title { font-weight:600; color:var(--paper); white-space:normal; }

  .stage-label { fill:var(--ink-faint); font-size:12px; font-weight:700; letter-spacing:.02em; }
  .stage-label-ring { paint-order:stroke; stroke:var(--paper); stroke-width:3px; stroke-linejoin:round; }
  .stage-arc { fill:none; stroke-width:24; opacity:.4; stroke-linecap:round; }
  .stage-ring-arc { fill:none; stroke-width:34; opacity:.5; stroke-linecap:butt; }
  .tick-label { fill:var(--ink-faint); font-size:12.5px; font-weight:600; }
  .tick-line { stroke:var(--line); stroke-width:1.5; }
  .baseline { stroke:var(--line); stroke-width:1.5; }
  .path-guide { fill:none; stroke:var(--ink-faint); stroke-width:2; stroke-linecap:round; stroke-dasharray:1 8; opacity:.55; }
  .path-guide.lived { stroke:var(--accent); stroke-width:3; stroke-linecap:round; stroke-dasharray:none; opacity:.9; }
  .ring-guide { fill:none; stroke:var(--line); stroke-width:1.5; }
  .ring-guide.lived { stroke:var(--accent); stroke-width:2; opacity:.8; }
  .ring-guide.forming { stroke:var(--accent-glow); stroke-width:2.5; stroke-dasharray:1 5; stroke-linecap:round; opacity:.95; }
  .ring-arc { fill:none; stroke:var(--accent); stroke-width:3; stroke-linecap:round; opacity:.9; }
  .ring-arc-rest { fill:none; stroke:var(--ink-faint); stroke-width:2; stroke-linecap:round; stroke-dasharray:1 8; opacity:.5; }
  .today-glow { opacity:.9; }
  .today-ring { fill:none; stroke:var(--accent-glow); stroke-width:1.5; opacity:.55; }

  .node { cursor:pointer; transition:filter .15s ease; }
  .node .ring { fill:var(--card); stroke-width:3; }
  .node.visibility-public .ring { stroke:var(--accent); }
  .node.visibility-private .ring { stroke:var(--accent-2); }
  .node.visibility-custom .ring { stroke:var(--fam3); }
  .node .lock { fill:var(--accent-2); }
  .node.type-diary .ring { stroke-dasharray:3 2.4; }
  .node:hover .ring, .node.highlight .ring { stroke-width:4.5; }
  .node:hover { filter:brightness(1.05); }

  /* ---------- card rail ---------- */
  .rail-heading { display:flex; align-items:baseline; justify-content:space-between; margin-bottom:10px; }
  .rail-heading h2 { font-size:17px; font-style:italic; font-weight:700; margin:0; }
  .rail-heading .count { font-size:13px; color:var(--ink-faint); font-weight:600; }
  .rail { display:flex; gap:20px; overflow-x:auto; padding:10px 4px 18px; scroll-snap-type:x proximity; }
  .rail::-webkit-scrollbar { height:8px; }
  .rail::-webkit-scrollbar-thumb { background:var(--line); border-radius:8px; }
  .mem-card { scroll-snap-align:start; flex:0 0 234px; position:relative; background:var(--paper-2); border:1px solid var(--line); border-radius:18px; box-shadow:var(--shadow); overflow:visible; transition:transform .18s ease, box-shadow .18s ease, outline .15s ease; outline:2px solid transparent; outline-offset:2px; cursor:pointer; }
  .mem-card:hover, .mem-card.highlight { transform:rotate(0deg) translateY(-4px) scale(1.015) !important; box-shadow:0 4px 8px rgba(26,23,20,.1), 0 18px 32px -14px rgba(26,23,20,.35); }
  .mem-card.highlight { outline-color:var(--accent-glow); }
  .card-tape { position:absolute; top:-10px; left:50%; width:54px; height:22px; margin-left:-27px; background:var(--tape); opacity:.85; border-radius:3px; box-shadow:0 1px 2px rgba(0,0,0,.12); transform:rotate(-3deg); }
  .card-media { height:122px; position:relative; border-radius:18px 18px 0 0; overflow:hidden; display:flex; align-items:center; justify-content:center; color:rgba(74,68,61,.4); background:linear-gradient(135deg, var(--stage-a, var(--accent-bg)), var(--stage-b, var(--paper))); }
  .card-media img { width:100%; height:100%; object-fit:cover; border-radius:18px 18px 0 0; }
  .card-media svg { width:34px; height:34px; opacity:.55; }
  .card-media-count { position:absolute; bottom:6px; right:6px; background:rgba(26,23,20,.75); color:#fff; font-size:10px; font-weight:800; padding:2px 7px; border-radius:999px; }
  /* Phase 54: "show it on their timeline as a little mini postcard/
     envelope symbol" -- a small round badge in the corner of a saved
     postcard's or letter's own card, distinguishing it from an ordinary
     memory at a glance without needing to open it. */
  .card-origin-badge { position:absolute; top:8px; left:8px; width:24px; height:24px; border-radius:50%; background:#fff; box-shadow:0 2px 6px rgba(26,23,20,.3); display:flex; align-items:center; justify-content:center; color:var(--accent); }
  .card-origin-badge svg { width:14px; height:14px; }
  .card-body { padding:14px 15px 16px; }
  .card-date { font-size:11.5px; color:var(--ink-faint); margin-bottom:4px; font-weight:700; letter-spacing:.02em; }
  .card-title { font-size:15.5px; font-weight:800; margin-bottom:5px; }
  .card-thought { font-size:13.5px; color:var(--ink-soft); line-height:1.5; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
  .card-foot { display:flex; align-items:center; gap:6px; margin-top:10px; }
  .pill { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:700; letter-spacing:.01em; padding:4px 10px 4px 7px; border-radius:20px; }
  .pill svg { width:11px; height:11px; }
  .pill.public { background:var(--accent-bg); color:var(--accent); }
  .pill.private { background:var(--accent-2-bg); color:var(--accent-2); }
  .pill.custom { background:var(--fam3-bg); color:var(--fam3); }
  .pill.diary { background:var(--fam2-bg); color:var(--fam2); }
  .empty-state { text-align:center; padding:34px 20px; color:var(--ink-faint); font-size:14px; border:2px dashed var(--line); border-radius:16px; }


  /* ---------- memory viewer (read-only) ---------- */
  .modal-scrim { position:fixed; inset:0; background:rgba(20,16,12,.55); display:flex; align-items:center; justify-content:center; padding:20px; z-index:50; opacity:0; pointer-events:none; transition:opacity .15s ease; }
  .modal-scrim.open { opacity:1; pointer-events:auto; }
  .viewer-modal { background:var(--card); border-radius:22px; max-width:980px; width:94vw; height:86vh; max-height:860px; box-shadow:0 24px 60px -20px rgba(26,23,20,.45); transform:translateY(10px) scale(.98); transition:transform .18s ease; display:flex; flex-direction:column; overflow:hidden; }
  .modal-scrim.open .viewer-modal { transform:translateY(0) scale(1); }
  .modal-head { display:flex; align-items:center; justify-content:space-between; padding:20px 22px 4px; }
  .modal-head h3 { font-size:21px; font-style:italic; margin:0; }
  .modal-close { border:none; background:transparent; color:var(--ink-faint); font-size:22px; cursor:pointer; line-height:1; padding:4px; border-radius:999px; }
  .modal-close:hover { color:var(--ink); background:var(--line-soft); }
  .viewer-body { display:grid; grid-template-columns:1fr 320px; flex:1; min-height:0; }
  .viewer-media { background:var(--paper); display:flex; align-items:center; justify-content:center; overflow:hidden; min-height:0; position:relative; border:1px solid var(--line); border-radius:14px; margin:0 0 20px 20px; }
  .viewer-media img, .viewer-media video { max-width:100%; max-height:100%; object-fit:contain; }
  .viewer-media video { background:#000; width:100%; height:100%; }
  .viewer-empty-media { display:flex; flex-direction:column; align-items:center; gap:10px; color:var(--ink-faint); font-size:13.5px; text-align:center; padding:20px; }
  .viewer-empty-media svg { width:44px; height:44px; }
  .viewer-media-single { width:100%; height:100%; display:flex; align-items:center; justify-content:center; }
  .viewer-media-single a { display:contents; }
  /* Phase 32: a real grid instead of "however many 140px-min columns fit" —
     that auto-fill rule packed every item into a single stretched-tall row
     (4 photos rendered as 4 skinny full-height strips) or left a lopsided
     leftover row (6 as 4-then-2, 9 as 4-then-4-then-1) with a big empty gap
     on the right. --cols (set inline per-render from the actual media
     count — see viewerMediaViewHtml() below) instead picks a column count
     that keeps the grid roughly square: 1 stays the single-media view
     below, 2 is 2×1, 4 is 2×2, 6 is 3×2, 9 is 3×3, and anything else lands
     as close to that as a whole column count allows. Tiles are square
     (aspect-ratio) and the grid is only as tall as it needs to be — not
     stretched to fill the pane — so a light row centers in the space
     instead of every tile being stretched edge-to-edge. */
  .viewer-media-grid { width:100%; max-height:100%; display:grid; grid-template-columns:repeat(var(--cols, 2), 1fr); gap:10px; padding:14px; overflow-y:auto; align-content:center; justify-content:center; }
  .viewer-media-grid-tile { position:relative; aspect-ratio:1/1; border-radius:10px; overflow:hidden; background:var(--paper); border:1px solid var(--line); display:flex; align-items:center; justify-content:center; transition:border-color .15s ease; }
  .viewer-media-grid-tile:hover { border-color:var(--accent); }
  .viewer-media-grid-tile img { width:100%; height:100%; object-fit:cover; }
  .viewer-media .media-tile { width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; color:var(--ink-faint); }
  .viewer-media .media-tile svg { width:60px; height:60px; }
  .viewer-media .media-tile-badge { font-size:13px; font-weight:800; letter-spacing:.04em; color:var(--ink-soft); background:rgba(255,255,255,.6); border-radius:5px; padding:3px 10px; }
  .viewer-details { padding:22px 24px 12px; display:flex; flex-direction:column; gap:14px; overflow-y:auto; }
  .viewer-title-view { font-family:"Fraunces", Georgia, serif; font-size:22px; font-weight:700; line-height:1.25; }
  .viewer-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .viewer-date { color:var(--ink-faint); font-weight:700; font-size:13px; }
  .viewer-thought-view { font-size:14.5px; line-height:1.65; color:var(--ink-soft); white-space:pre-wrap; margin:0; }
  .viewer-thought-view:empty::before { content:"No thoughts written yet."; color:var(--ink-faint); font-style:italic; }
  .viewer-actions { display:flex; justify-content:space-between; align-items:center; padding:14px 24px; border-top:1px solid var(--line); }
  /* Pre-existing bug, found incidentally while testing Phase 41: this link
     carries its own inline "display:inline-block" (needed for its padding
     when visible), which -- because an inline style always outranks a
     plain stylesheet rule -- silently defeated the ordinary [hidden]{display:none}
     rule the browser applies for free everywhere else in this modal. A
     viewer with no edit rights (e.g. someone who only has an approved tag
     on the memory, not its owner) could see a clickable "Edit" link that
     led nowhere but a 403 from add_entry.php's own server-side check --
     never a real permission hole, just a confusing dead end. !important is
     the only way to override an inline style from here. */
  #viewerEditLink[hidden] { display:none !important; }

  /* Notes family members tagged on this memory have added — read-only for
     everyone else, always shown when at least one exists (regardless of
     whose timeline you're viewing it from, since the memory is the same
     shared row wherever it appears); the current viewer's OWN note, when
     they're one of the tagged people, gets an editable textarea instead. */
  .viewer-tag-notes { display:flex; flex-direction:column; gap:8px; border-top:1px solid var(--line-soft, var(--line)); padding-top:12px; }
  .viewer-tag-notes[hidden] { display:none; }
  .viewer-tag-note { font-size:13.5px; line-height:1.5; color:var(--ink-soft); }
  .viewer-tag-note b { color:var(--ink); }
  .viewer-my-note { border-top:1px solid var(--line-soft, var(--line)); padding-top:12px; }
  .viewer-my-note[hidden] { display:none; }
  .viewer-my-note label { display:block; font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:var(--ink-faint); margin-bottom:6px; }
  .viewer-my-note textarea { width:100%; min-height:56px; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:13.5px; font-family:inherit; color:var(--ink); resize:vertical; }
  .viewer-my-note .btn-ghost { margin-top:6px; }

  @media (max-width: 760px) {
    .viewer-modal { width:100vw; height:100vh; max-height:none; border-radius:0; }
    .viewer-body { grid-template-columns:1fr; grid-template-rows:42vh 1fr; overflow-y:auto; }
    .viewer-media { min-height:220px; margin:16px 16px 0 16px; }
  }
  @media (max-width: 620px) {
    .row-3 { grid-template-columns:1fr; }
    .mem-card { flex-basis:200px; }
  }

  /* Phase 69: Memory Planner. Reuses .modal-scrim/.viewer-modal/.modal-
     head/.modal-close for the same overlay chrome the memory viewer above
     already has (backdrop fade, rounded box, × close button) — only the
     body layout below is new. .tag-picker/.visibility-toggle are the same
     rules add_entry.php keeps its own copy of (this app's convention:
     see styles.css's own Phase 43 comment on when a rule gets promoted to
     shared instead — a tag/visibility picker hasn't been, same as here). */
  .trip-modal { max-width:1040px; }
  .trip-modal-body { flex:1; min-height:0; overflow-y:auto; padding:4px 26px 24px; }
  .trip-top-fields { display:grid; grid-template-columns:1.6fr 1fr 1fr 1.4fr; gap:16px; align-items:start; margin-bottom:18px; }
  .trip-field label { display:block; font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:var(--ink-faint); margin-bottom:6px; }
  .trip-field input[type="text"] { width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:8px; font-size:14.5px; font-family:inherit; background:#fff; color:var(--ink); box-sizing:border-box; }
  .tag-picker { display:flex; flex-direction:column; gap:6px; overflow-y:auto; border:1px solid var(--line); border-radius:10px; padding:10px 12px; background:#fff; max-height:150px; }
  .tag-picker label { display:flex; align-items:center; gap:8px; margin:0; font-size:13.5px; font-weight:400; }
  .tag-picker-empty { font-size:13px; color:var(--ink-faint); margin:6px 0 0; }
  .visibility-toggle { display:flex; gap:6px; }
  .visibility-toggle label { flex:1 1 0; display:flex; flex-direction:column; align-items:center; text-align:center; gap:2px; margin:0; padding:8px 6px; border:1px solid var(--line); border-radius:9px; background:#fff; font-size:12.5px; font-weight:600; color:var(--ink-soft); cursor:pointer; }
  .visibility-toggle label:hover { border-color:var(--accent); }
  .visibility-toggle input { position:absolute; opacity:0; width:0; height:0; }
  .visibility-toggle label:has(input:checked) { border-color:var(--accent); background:var(--accent-bg, #F1DCDC); color:var(--ink); }
  .visibility-toggle .vis-caption { display:block; font-size:10px; font-weight:400; color:var(--ink-faint); }

  .trip-events { display:flex; flex-direction:column; gap:18px; margin-bottom:16px; }
  .trip-event { border:1px solid var(--line); border-radius:14px; padding:16px 18px 18px; background:var(--paper-2, #fff); }
  .trip-event-head { display:flex; align-items:flex-start; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
  .trip-event-title-input { flex:1 1 220px; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:14.5px; font-weight:700; font-family:inherit; color:var(--ink); box-sizing:border-box; }
  .trip-event-date { grid-template-columns:3.6em 3.6em 5em; flex:0 0 auto; }
  .trip-event-remove { border:none; background:transparent; color:var(--ink-faint); font-size:20px; cursor:pointer; line-height:1; padding:4px 6px; border-radius:999px; }
  .trip-event-remove:hover { color:var(--accent); background:var(--line-soft, #eee); }
  .trip-event-columns { display:grid; grid-template-columns:1fr 1fr; gap:0 24px; }
  .trip-event-col + .trip-event-col { border-left:1px solid var(--line); padding-left:24px; }
  .trip-event-col h4 { margin:0 0 8px; font-size:12px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-faint); }
  .trip-event-col textarea { width:100%; margin-top:10px; padding:9px 11px; border:1px solid var(--line); border-radius:8px; font-size:13.5px; font-family:inherit; color:var(--ink); resize:vertical; box-sizing:border-box; }
  .trip-picker { min-height:0; }
  @media (max-width: 760px) {
    .trip-top-fields { grid-template-columns:1fr 1fr; }
    .trip-event-columns { display:block; }
    .trip-event-col + .trip-event-col { border-left:none; padding-left:0; margin-top:16px; padding-top:16px; border-top:1px solid var(--line); }
  }

  .trip-empty-events { font-size:13.5px; color:var(--ink-faint); text-align:center; padding:18px 0; }
  .trip-actions { display:flex; align-items:center; gap:12px; padding-top:6px; border-top:1px solid var(--line); margin-top:4px; padding-top:16px; }
  .trip-error { color:var(--accent); font-size:13.5px; margin:0 0 12px; }
  .trip-readonly-note { font-size:13px; color:var(--ink-faint); background:var(--paper-2, #f7f2ea); border:1px solid var(--line); border-radius:9px; padding:8px 12px; margin-bottom:14px; }

  /* The image-zoom lightbox — first of its kind in this app (every other
     "open a photo" path so far has just been a new browser tab), scoped
     to the planner since that's the only place it was asked for. */
  .trip-lightbox { position:fixed; inset:0; background:rgba(10,8,6,.86); z-index:1600; display:flex; align-items:center; justify-content:center; padding:30px; opacity:0; pointer-events:none; transition:opacity .12s ease; }
  .trip-lightbox.open { opacity:1; pointer-events:auto; }
  .trip-lightbox img { max-width:100%; max-height:100%; border-radius:6px; box-shadow:0 20px 60px rgba(0,0,0,.5); }
  .trip-lightbox-close { position:absolute; top:18px; right:24px; border:none; background:rgba(255,255,255,.12); color:#fff; font-size:26px; width:40px; height:40px; border-radius:999px; cursor:pointer; line-height:1; }
  .trip-lightbox-close:hover { background:rgba(255,255,255,.22); }
</style>
</head>
<body>
  <div class="card wide">
    <div class="page-head">
      <?php /* Phase 29: the avatar comes FIRST in the markup (even though
              it renders top-right) because that's what lets it float and
              have .brand and .nav wrap up against it — see the .page-head
              comment in <style> above. */ ?>
      <?php // Phase 71 (mobile): .header-avatar-row is a plain no-op wrapper
            // above 620px (just the float, unchanged from before); below
            // 620px it becomes the flex row that puts these two buttons
            // beside the photo instead of on the River/Rings/Spiral row,
            // which .tour-controls-group's copies keep doing above 620px. ?>
      <div class="header-avatar-row">
        <?php if ($isOwner): ?>
          <div class="header-avatar-actions-mobile">
            <button type="button" id="tourReplayBtnMobile" class="linklet-btn tour-replay-btn">Take the tour</button>
            <button type="button" id="tourWhatsNewBtnMobile" class="linklet-btn ghost tour-whatsnew-btn">What's new</button>
          </div>
        <?php endif; ?>
        <?php if (!empty($target['avatar_path'])): ?>
          <img class="header-avatar" src="/avatar.php?person_id=<?= (int) $target['id'] ?>&v=<?= urlencode((string) $target['avatar_path']) ?>" alt="<?= htmlspecialchars($targetName, ENT_QUOTES) ?>">
        <?php else: ?>
          <div class="header-avatar-placeholder" aria-hidden="true"><?= htmlspecialchars(mb_substr($targetName, 0, 1) ?: '?', ENT_QUOTES) ?></div>
        <?php endif; ?>
      </div>

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
          <a href="/tree.php" id="tourMyTree">My tree</a>
          <?php if ($canManage): ?><a href="/add_entry.php<?= $isOwner ? '' : '?person_id=' . (int) $target['id'] ?>" id="tourAddMemory">+ Add a memory</a><?php endif; ?>
          <?php if ($canManage): ?><button type="button" id="tripPlannerOpenBtn" class="linklet-btn">Memory planner</button><?php endif; ?>
          <button type="button" id="sendPostcardBtn" class="linklet-btn">Send a postcard</button>
          <span class="whoami">
            Signed in as <strong><?= htmlspecialchars($me['email'], ENT_QUOTES) ?></strong>
            <form method="post" action="/logout.php"><button type="submit" class="linklet">Log out</button></form>
          </span>
        </div>
      </div>

      <?php
        // Phase 29: the heading lives inside .page-head now too (still
        // wrapping beside the floated avatar, same as .brand and .nav
        // above it) — brand+nav alone left a noticeable blank strip below
        // the nav row and above "My timeline", since neither reached
        // anywhere near the avatar's own height. Pulling the heading up
        // here closes most of that gap with content that was going to
        // render right there anyway, rather than leaving it as whitespace.
      ?>
      <div class="page-head-heading">
        <h3 style="margin-bottom:2px;">
          <?= $isOwner ? 'My timeline' : htmlspecialchars($targetName, ENT_QUOTES) . "'s timeline" ?>
        </h3>
        <?php if (!$canManage): ?>
          <p style="font-size:13px;color:var(--ink-faint);margin-top:0;">Showing public entries only.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isOwner): ?>
    <div class="post-tour-nudge" id="postTourNudge" hidden>
      <p>Let's get started &mdash; time to add your first memory! But first, don't forget to set up your profile: go to <a href="/tree.php">My tree</a>, then double-click your name (or tap the pencil icon if you're on mobile or a tablet), and add your birth date &mdash; that way your timeline starts in the right place.</p>
      <button type="button" id="postTourNudgeClose" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>
    <?php if ($notice): ?><p style="color:var(--accent);font-weight:600;"><?= htmlspecialchars($notice, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($errors): ?>
      <div class="error"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($isOwner && !$bornIsReal): ?>
      <div class="born-prompt">
        <span>Add your birth date for a more accurate "All life" view:</span>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_born">
          <div class="row-3">
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="born_day" placeholder="DD">
              <span>Day</span>
            </div>
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="born_month" placeholder="MM">
              <span>Month</span>
            </div>
            <div class="date-slot">
              <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" name="born_year" placeholder="YYYY">
              <span>Year</span>
            </div>
          </div>
          <button type="submit" class="btn-primary btn-small">Save</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="controls">
      <div class="segmented" id="layoutToggle" role="group" aria-label="Visual style">
        <button data-layout="river" class="active">River</button>
        <button data-layout="rings">Rings</button>
        <button data-layout="spiral">Spiral</button>
      </div>
      <div class="segmented" id="zoomToggle" role="group" aria-label="Zoom">
        <button data-zoom="life" class="active">All life</button>
        <button data-zoom="decade">Decade</button>
        <button data-zoom="year">This year</button>
      </div>
      <button class="zoom-pill" id="customZoomPill" type="button" hidden>
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8.5" cy="8.5" r="5.5"/><path d="M16 16l-3.8-3.8" stroke-linecap="round"/></svg>
        <span id="customZoomLabel"></span>
        <span class="zoom-pill-x">×</span>
      </button>
      <div class="controls-right">
        <?php // Phase 71: "Take the tour" / "What's new", moved here from
              // the nav row above -- same River/Rings/Spiral row, right
              // next to each other. ?>
        <?php if ($isOwner): ?>
          <div class="tour-controls-group">
            <button type="button" id="tourReplayBtn" class="linklet-btn tour-replay-btn">Take the tour</button>
            <button type="button" id="tourWhatsNewBtn" class="linklet-btn ghost tour-whatsnew-btn">What's new</button>
          </div>
        <?php endif; ?>
        <?php if ($reminderCardRows): ?>
          <div class="birthday-banner-group">
            <?php foreach ($reminderCardRows as $row): ?>
              <div class="birthday-banner" role="status">
                <span aria-hidden="true"><?= $row['kind'] === 'birthday' ? '&#127874;' : '&#128197;' ?></span>
                <span><?= htmlspecialchars($row['text'], ENT_QUOTES) ?></span>
                <button
                  type="button"
                  class="birthday-send-card-btn"
                  data-kind="<?= htmlspecialchars($row['kind'], ENT_QUOTES) ?>"
                  data-person-id="<?= $row['person_id'] !== null ? (int) $row['person_id'] : '' ?>"
                  data-event-id="<?= $row['event_id'] !== null ? (int) $row['event_id'] : '' ?>"
                  data-first-name="<?= htmlspecialchars($row['first_name'], ENT_QUOTES) ?>"
                  data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
                  data-cover-default="<?= htmlspecialchars($row['cover_default'], ENT_QUOTES) ?>"
                  data-greeting-default="<?= htmlspecialchars($row['greeting_default'], ENT_QUOTES) ?>"
                >&#127873; Send a card</button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="arc-wrap layout-river" id="arcWrap">
      <svg id="arcSvg" viewBox="0 0 1180 400" preserveAspectRatio="xMidYMid meet">
        <defs>
          <clipPath id="thumbClip"><circle cx="0" cy="0" r="15"/></clipPath>
        </defs>
        <g id="stageBands"></g>
        <line class="baseline" id="baselineRef" x1="60" y1="310" x2="1120" y2="310"/>
        <g id="ticks"></g>
        <g id="ringGuides"></g>
        <path id="pathFuture" class="path-guide"></path>
        <path id="pathLived" class="path-guide lived"></path>
        <g id="todayMarker"></g>
        <g id="nodes"></g>
        <rect id="selectionRect" class="selection-rect" x="0" y="-24" width="0" height="424"></rect>
      </svg>
      <div class="river-scrollbar" id="riverScrollbar">
        <div class="river-scrollbar-thumb" id="riverScrollThumb"></div>
      </div>
      <div class="node-tooltip" id="nodeTooltip">
        <div class="node-tooltip-date mono" id="nodeTooltipDate"></div>
        <div class="node-tooltip-title" id="nodeTooltipTitle"></div>
      </div>
    </div>

    <div class="rail-heading">
      <h2>Memories in view</h2>
      <span class="count mono" id="railCount"></span>
    </div>
    <div class="rail" id="rail"></div>
  </div>

  <div class="modal-scrim" id="viewerScrim">
    <div class="viewer-modal">
      <div class="modal-head">
        <h3 id="viewerHeaderTitle">Memory</h3>
        <button class="modal-close" id="viewerClose" aria-label="Close">×</button>
      </div>
      <div class="viewer-body">
        <div class="viewer-media" id="viewerMedia"></div>
        <div class="viewer-details">
          <div class="viewer-title-view" id="viewerTitleView"></div>
          <div class="viewer-meta" id="viewerMetaView">
            <span class="viewer-date mono" id="viewerDateView"></span>
            <span id="viewerPillView"></span>
          </div>
          <p class="viewer-thought-view" id="viewerThoughtView"></p>

          <div class="viewer-tag-notes" id="viewerTagNotes" hidden></div>

          <form method="post" class="viewer-my-note" id="viewerMyNoteForm" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_tag_note">
            <input type="hidden" name="entry_id" id="viewerMyNoteEntryId" value="">
            <label for="viewerMyNoteText">Your note on this memory <span style="text-transform:none;font-weight:400;">(shown to everyone who can see it)</span></label>
            <textarea name="note" id="viewerMyNoteText" placeholder="Add what you remember about this…"></textarea>
            <button type="submit" class="btn-ghost">Save note</button>
          </form>

          <form method="post" enctype="multipart/form-data" class="viewer-my-note" id="viewerAddMediaForm" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_tagged_media">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="entry_id" id="viewerAddMediaEntryId" value="">
            <label for="viewerAddMediaInput">Add your own photo, video, or document to this memory</label>
            <div class="photo-drop media-picker" id="viewerAddMediaDrop" tabindex="0" role="button" aria-label="Attach photos, videos or documents">
              <div class="media-picker-empty" id="viewerAddMediaDropEmpty" hidden>
                <div class="thumb">
                  <svg viewBox="0 0 20 20" fill="none"><path d="M4 15.5 8 10l3 3 3-4 2 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><rect x="2.5" y="3.5" width="15" height="13" rx="2" stroke="currentColor" stroke-width="1.8"/></svg>
                </div>
                <div class="copy"><b>Click to attach</b> or drop files here<span class="paste-hint">You can also paste from your clipboard, and add more than one</span></div>
              </div>
              <div class="media-picker-grid" id="viewerAddMediaGrid" hidden></div>
              <input type="file" id="viewerAddMediaInput" name="media[]" multiple hidden
                accept="image/*,.heic,.heif,video/*,application/pdf,.pdf,.doc,.docx,.txt,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain">
            </div>
            <p class="media-picker-error" id="viewerAddMediaError"></p>
            <p class="media-picker-status" id="viewerAddMediaStatus"></p>
            <button type="submit" class="btn-ghost" id="viewerAddMediaSubmitBtn">Add media</button>
          </form>
        </div>
      </div>
      <div class="viewer-actions">
        <a href="#" id="viewerEditLink" class="btn-ghost" style="text-decoration:none;display:inline-block;" hidden>Edit</a>
        <form method="post" id="viewerDeleteForm" onsubmit="return confirm('Delete this entry?');" style="margin:0;" hidden>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_entry">
          <input type="hidden" name="entry_id" id="viewerDeleteEntryId" value="">
          <button type="submit" class="btn-ghost" style="color:var(--accent);border-color:var(--accent);">Delete</button>
        </form>
        <button class="btn-ghost" id="viewerCloseBtn" style="margin-left:auto;">Close</button>
      </div>
    </div>
  </div>

  <?php // Phase 69: Memory Planner pop-up -- one modal reused for creating a
        // brand-new trip, editing/adding-to an existing one, and (read-only,
        // inputs disabled) viewing one someone else owns that this viewer is
        // merely tagged-and-approved on. Its "Plans"/"Memories" event rows
        // are built entirely by JS (tripRenderEvent()) rather than server-
        // rendered, since both the blank-create and prefilled-edit cases —
        // and adding/removing an event live in the pop-up — all need the
        // exact same row markup regenerated on demand. ?>
  <div class="modal-scrim" id="tripScrim">
    <div class="viewer-modal trip-modal">
      <div class="modal-head">
        <h3 id="tripModalHeading">Memory planner</h3>
        <button class="modal-close" id="tripModalClose" aria-label="Close">×</button>
      </div>
      <div class="trip-modal-body">
        <p class="trip-error" id="tripError" hidden></p>
        <p class="trip-readonly-note" id="tripReadonlyNote" hidden>You're seeing this trip because you're tagged on it — only <span id="tripReadonlyOwnerName">its owner</span> can change the plan.</p>
        <form method="post" action="/trip_plan.php" enctype="multipart/form-data" id="tripForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="trip_plan_id" id="tripPlanIdField" value="">
          <input type="hidden" name="target_person_id" id="tripTargetPersonField" value="<?= (int) $target['id'] ?>">

          <div class="trip-top-fields">
            <div class="trip-field">
              <label for="tripTitleInput">Title</label>
              <input type="text" id="tripTitleInput" name="title" maxlength="255" placeholder="e.g. Our trip to Cornwall" required>
            </div>
            <div class="trip-field">
              <label>Start date</label>
              <div class="row-3">
                <div class="date-slot"><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="start_day" id="tripStartDay" placeholder="DD"><span>Day</span></div>
                <div class="date-slot"><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="start_month" id="tripStartMonth" placeholder="MM"><span>Month</span></div>
                <div class="date-slot"><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" name="start_year" id="tripStartYear" placeholder="YYYY"><span>Year</span></div>
              </div>
            </div>
            <div class="trip-field">
              <label>Finish date</label>
              <div class="row-3">
                <div class="date-slot"><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="finish_day" id="tripFinishDay" placeholder="DD"><span>Day</span></div>
                <div class="date-slot"><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="finish_month" id="tripFinishMonth" placeholder="MM"><span>Month</span></div>
                <div class="date-slot"><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" name="finish_year" id="tripFinishYear" placeholder="YYYY"><span>Year</span></div>
              </div>
            </div>
            <div class="trip-field">
              <label>Visibility</label>
              <div class="visibility-toggle" id="tripVisibilityBlock">
                <label><input type="radio" name="visibility" value="private"><span>Private<span class="vis-caption">Only me</span></span></label>
                <label><input type="radio" name="visibility" value="public" checked><span>Public<span class="vis-caption">Family</span></span></label>
                <label><input type="radio" name="visibility" value="custom"><span>Custom<span class="vis-caption">Chosen</span></span></label>
              </div>
            </div>
          </div>

          <div class="trip-field" id="tripTagField" style="margin-bottom:18px;">
            <label>Tag people in this trip <span style="text-transform:none;font-weight:400;">(tag-and-approve, same as a normal memory)</span></label>
            <div class="tag-picker" id="tripTagPicker"></div>
          </div>

          <div class="trip-events-heading" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2px;">
            <label style="font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-faint);">Events</label>
          </div>
          <div class="trip-events" id="tripEventsContainer"></div>
          <button type="button" class="btn-ghost" id="tripAddEventBtn">+ Add an event</button>
        </form>

        <!-- Phase 69: these live OUTSIDE #tripForm above, as siblings, not
             nested inside it -- a <form> can't contain another <form> (the
             browser silently drops a nested one, along with its id), and
             the Save button below points back at #tripForm by id via its
             own form="" attribute rather than being a descendant of it.
             Delete submits to the same action=delete_entry endpoint
             timeline.php's own memory-viewer delete button already uses,
             so deleting a trip needs no code of its own there at all (see
             trip_plan.php's own header comment). -->
        <div class="trip-actions">
          <button type="submit" form="tripForm" class="btn-primary" id="tripSaveBtn">Save trip plan</button>
          <form method="post" id="tripDeleteForm" onsubmit="return confirm('Delete this whole trip, including every plan and memory in it?');" style="margin:0;" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_entry">
            <input type="hidden" name="entry_id" id="tripDeleteEntryId" value="">
            <button type="submit" class="btn-ghost" style="color:var(--accent);border-color:var(--accent);">Delete trip</button>
          </form>
          <button type="button" class="btn-ghost" id="tripCloseBtn" style="margin-left:auto;">Close</button>
        </div>
      </div>
    </div>
  </div>

  <div class="trip-lightbox" id="tripLightbox">
    <button type="button" class="trip-lightbox-close" id="tripLightboxClose" aria-label="Close">×</button>
    <img id="tripLightboxImg" src="" alt="">
  </div>

  <script id="entriesData" type="application/json"><?= $entriesJsonSafe ?></script>
  <?php
    // Phase 69: the tag-and-approve picker for a BRAND-NEW trip is bounded
    // to $target the same way add_entry.php bounds a new memory's picker
    // -- a new trip is always created for whoever this timeline page's
    // "Memory planner" button targets. Editing an EXISTING trip rebuilds
    // this list instead from trip_plan.php's own GET response, since an
    // existing trip's owner (bounding its own picker) isn't necessarily
    // $target -- a trip tagged onto $target from someone else's timeline
    // still shows up here (Phase 17's tagged-in-memories behaviour), and
    // its picker has to be bounded to ITS owner, not to $target.
    $tripCreateTaggable = [];
    if ($canManage) {
        foreach (graph_people_within_generations($graph, (int) $target['id']) as $tp) {
            $tripCreateTaggable[] = [
                'id'        => (int) $tp['id'],
                'name'      => person_display_name($tp),
                'unclaimed' => empty($tp['claimed_by_user_id']),
            ];
        }
    }
  ?>
  <script id="tripCreateTaggableData" type="application/json"><?= json_encode($tripCreateTaggable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
  <script>
  (function () {
    "use strict";

    var svgNS = "http://www.w3.org/2000/svg";
    var IS_OWNER = <?= $isOwner ? 'true' : 'false' ?>;
    var CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
    var ADD_ENTRY_QS = <?= json_encode($isOwner ? '' : '?person_id=' . (int) $target['id'], JSON_UNESCAPED_SLASHES) ?>;
    var BIRTH = new Date(<?= (int) $birthYear ?>, <?= (int) $birthMonth - 1 ?>, <?= (int) $birthDay ?>);
    var BUFFER_YEARS = 15;
    var YEAR_MS = 365.25 * 86400000;

    var RIVER_W = 1180, RIVER_H = 400, RIVER_PAD = 60, RIVER_BASE = 170;
    var RADIAL_SIZE = 760, RADIAL_CX = 380, RADIAL_CY = 380, RADIAL_MAXR = 290;

    var entries = JSON.parse(document.getElementById("entriesData").textContent || "[]");

    var stages = [
      { name: "Infancy", from: 0, to: 2 },
      { name: "Childhood", from: 2, to: 12 },
      { name: "Adolescence", from: 12, to: 18 },
      { name: "Early adulthood", from: 18, to: 30 },
      { name: "Adulthood", from: 30, to: 50 },
      { name: "Midlife", from: 50, to: 65 },
      { name: "Later life", from: 65, to: 90 }
    ];

    var DIARY_PILL_HTML = '<span class="pill diary"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 4.3c1.8-.9 3.6-.9 5.4 0v11c-1.8-.9-3.6-.9-5.4 0v-11ZM15.4 4.3c-1.8-.9-3.6-.9-5.4 0v11c1.8-.9 3.6-.9 5.4 0v-11Z" stroke-linejoin="round"/></svg>Diary</span>';
    // Phase 54: the little corner badge on a saved postcard's/letter's own
    // card (see .card-origin-badge) -- a postcard icon (a photo with a
    // torn corner) or an envelope icon, matched to entry.origin.
    // Phase 67: a third icon (a folded greeting card) for entries saved
    // from a greeting card -- origin='card'.
    var ORIGIN_BADGE_HTML = {
      postcard: '<span class="card-origin-badge" title="Saved from a postcard"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2.5" y="4.5" width="15" height="11" rx="1.2"/><path d="M2.5 7.5h15M6 4.5v3" stroke-linecap="round"/></svg></span>',
      letter: '<span class="card-origin-badge" title="Saved from a letter"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2.2" y="4.5" width="15.6" height="11.5" rx="1.2"/><path d="M2.6 5.3l7.4 6 7.4-6" stroke-linejoin="round"/></svg></span>',
      card: '<span class="card-origin-badge" title="Saved from a greeting card"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3v14M3.5 5.5h13a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1Z" stroke-linejoin="round"/></svg></span>',
      trip: '<span class="card-origin-badge" title="A planned trip"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 16.5 7.5 6h5l4.5 10.5M6 13h8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>'
    };
    // Phase 33: shared by the memory-card rail and the viewer's detail
    // panel — previously each had its own copy of the public/private
    // ternary, which is exactly the kind of place a third value (Custom)
    // is easy to add in one spot and forget in the other.
    var VISIBILITY_PILL_HTML = {
      public: '<span class="pill public"><svg viewBox="0 0 20 20" fill="currentColor"><circle cx="10" cy="10" r="6"/></svg>Family</span>',
      custom: '<span class="pill custom"><svg viewBox="0 0 20 20" fill="currentColor"><circle cx="7" cy="8" r="3"/><circle cx="14" cy="9" r="2.3"/><path d="M2 16.5c.5-3.3 2.4-5 5-5s4.5 1.7 5 5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>Custom</span>',
      private: '<span class="pill private"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2c-2 1.6-3 3.2-3 5.8v1.1H6.3A1.3 1.3 0 0 0 5 10.2v6A1.3 1.3 0 0 0 6.3 17.5h7.4A1.3 1.3 0 0 0 15 16.2v-6a1.3 1.3 0 0 0-1.3-1.3H13V7.8c0-2.6-1-4.2-3-5.8Z"/></svg>Private</span>'
    };
    function visibilityPillHtml(visibility) {
      return VISIBILITY_PILL_HTML[visibility] || VISIBILITY_PILL_HTML.private;
    }
    var VIDEO_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2.5" y="4.5" width="11" height="11" rx="1.5"/><path d="M13.5 8.2 17 6v8l-3.5-2.2" stroke-linejoin="round"/></svg>';
    var DOC_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 2.5h6.5L15 6v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1Z" stroke-linejoin="round"/><path d="M11 2.5V6h4" stroke-linejoin="round"/></svg>';

    var state = { zoom: "life", layout: "river", highlightId: null, customRange: null };

    function findEntry(id) {
      for (var i = 0; i < entries.length; i++) { if (entries[i].id === id) return entries[i]; }
      return null;
    }

    function addYears(d, n) { var r = new Date(d); r.setFullYear(r.getFullYear() + n); return r; }
    function clamp01(v) { return Math.max(0, Math.min(1, v)); }

    function getRange() {
      var now = new Date();
      if (state.customRange) return { start: state.customRange.start, end: state.customRange.end, mode: "custom" };
      if (state.zoom === "life") return { start: BIRTH, end: addYears(now, BUFFER_YEARS), mode: "life" };
      if (state.zoom === "decade") return { start: addYears(now, -5), end: addYears(now, 5), mode: "decade" };
      var jan1 = new Date(now.getFullYear(), 0, 1), jan1next = new Date(now.getFullYear() + 1, 0, 1);
      return { start: jan1, end: jan1next, mode: "year" };
    }

    function fullLifeRange() {
      return { start: BIRTH, end: addYears(new Date(), BUFFER_YEARS) };
    }

    function t(dateObj, range) {
      return clamp01((dateObj.getTime() - range.start.getTime()) / (range.end.getTime() - range.start.getTime()));
    }
    function fracToDate(f, range) {
      return new Date(range.start.getTime() + f * (range.end.getTime() - range.start.getTime()));
    }
    function dayOfYearFrac(d) {
      var jan1 = new Date(d.getFullYear(), 0, 1), next = new Date(d.getFullYear() + 1, 0, 1);
      return (d.getTime() - jan1.getTime()) / (next.getTime() - jan1.getTime());
    }

    function placeFrac(f, range, layout) {
      if (layout === "river") {
        var x = RIVER_PAD + f * (RIVER_W - 2 * RIVER_PAD);
        var y = RIVER_BASE
          + 46 * Math.sin(f * Math.PI * 2 * 1.3 + 0.6)
          + 16 * Math.sin(f * Math.PI * 2 * 2.7 + 2.1);
        return { x: x, y: y };
      }
      if (layout === "rings") {
        var radius, angle;
        if (range.mode === "year") {
          radius = RADIAL_MAXR * 0.72;
          angle = f * Math.PI * 2 - Math.PI / 2;
        } else {
          var d = fracToDate(f, range);
          radius = f * RADIAL_MAXR;
          angle = dayOfYearFrac(d) * Math.PI * 2 - Math.PI / 2;
        }
        return { x: RADIAL_CX + radius * Math.cos(angle), y: RADIAL_CY + radius * Math.sin(angle) };
      }
      var turns = 3;
      var radius2 = 34 + f * (RADIAL_MAXR - 34);
      var angle2 = f * Math.PI * 2 * turns - Math.PI / 2;
      return { x: RADIAL_CX + radius2 * Math.cos(angle2), y: RADIAL_CY + radius2 * Math.sin(angle2) };
    }
    function place(date, range, layout) { return placeFrac(t(date, range), range, layout); }

    function posToFrac(x, y, range, layout) {
      if (layout === "river") return clamp01((x - RIVER_PAD) / (RIVER_W - 2 * RIVER_PAD));
      var dx = x - RADIAL_CX, dy = y - RADIAL_CY;
      var radius = Math.sqrt(dx * dx + dy * dy);
      if (layout === "rings" && range.mode === "year") {
        var angle = Math.atan2(dy, dx) + Math.PI / 2;
        if (angle < 0) angle += Math.PI * 2;
        return clamp01(angle / (Math.PI * 2));
      }
      if (layout === "rings") return clamp01(radius / RADIAL_MAXR);
      return clamp01((radius - 34) / (RADIAL_MAXR - 34));
    }

    function pathD(range, layout, upTo) {
      var steps = layout === "river" ? 60 : 220;
      var pts = [];
      for (var i = 0; i <= steps; i++) {
        var f = i / steps;
        if (upTo !== undefined && f > upTo) break;
        var p = placeFrac(f, range, layout);
        pts.push((i === 0 ? "M" : "L") + p.x.toFixed(1) + "," + p.y.toFixed(1));
      }
      return pts.join(" ");
    }

    function stageIndexForDate(d) {
      var age = (d.getTime() - BIRTH.getTime()) / YEAR_MS;
      if (age < 0) return -1;
      for (var i = 0; i < stages.length; i++) {
        if (age < stages[i].to) return i;
      }
      return stages.length - 1;
    }

    function renderStageBands(range, layout) {
      function overlaps(s) {
        return addYears(BIRTH, s.from) < range.end && addYears(BIRTH, s.to) > range.start;
      }

      if (layout === "river") {
        stages.forEach(function (s, i) {
          if (!overlaps(s)) return;
          var f0 = t(addYears(BIRTH, s.from), range), f1 = t(addYears(BIRTH, s.to), range);
          var p0 = placeFrac(f0, range, layout), p1 = placeFrac(f1, range, layout);
          var w = p1.x - p0.x;
          if (w < 2) return;
          var rect = document.createElementNS(svgNS, "rect");
          rect.setAttribute("x", p0.x.toFixed(1));
          rect.setAttribute("y", "16");
          rect.setAttribute("width", w.toFixed(1));
          rect.setAttribute("height", "294");
          rect.setAttribute("fill", "var(--fam" + ((i % 6) + 1) + "-bg)");
          rect.setAttribute("fill-opacity", "0.45");
          gStages.appendChild(rect);
          if (w > 46) {
            var label = document.createElementNS(svgNS, "text");
            label.setAttribute("x", ((p0.x + p1.x) / 2).toFixed(1));
            label.setAttribute("y", "346");
            label.setAttribute("text-anchor", "middle");
            label.setAttribute("class", "stage-label");
            label.textContent = s.name;
            gStages.appendChild(label);
          }
        });
        return;
      }

      if (layout === "spiral") {
        stages.forEach(function (s, i) {
          if (!overlaps(s)) return;
          var f0 = t(addYears(BIRTH, s.from), range), f1 = t(addYears(BIRTH, s.to), range);
          if (f1 - f0 < 0.004) return;
          var steps = Math.max(2, Math.round((f1 - f0) * 240));
          var pts = [];
          for (var k = 0; k <= steps; k++) {
            var f = f0 + (f1 - f0) * (k / steps);
            var p = placeFrac(f, range, layout);
            pts.push((k === 0 ? "M" : "L") + p.x.toFixed(1) + "," + p.y.toFixed(1));
          }
          var path = document.createElementNS(svgNS, "path");
          path.setAttribute("d", pts.join(" "));
          path.setAttribute("class", "stage-arc");
          path.setAttribute("stroke", "var(--fam" + ((i % 6) + 1) + ")");
          gStages.appendChild(path);
        });
        return;
      }

      // rings
      if (range.mode === "year") {
        var cuts = [range.start];
        stages.forEach(function (s) {
          [s.from, s.to].forEach(function (age) {
            var d = addYears(BIRTH, age);
            if (d > range.start && d < range.end) cuts.push(d);
          });
        });
        cuts.push(range.end);
        cuts.sort(function (a, b) { return a - b; });
        cuts = cuts.filter(function (d, idx) { return idx === 0 || d.getTime() !== cuts[idx - 1].getTime(); });
        for (var ci = 0; ci < cuts.length - 1; ci++) {
          var segStart = cuts[ci], segEnd = cuts[ci + 1];
          var segMid = new Date((segStart.getTime() + segEnd.getTime()) / 2);
          var stageIdx = stageIndexForDate(segMid);
          if (stageIdx === -1) continue;
          var a0 = t(segStart, range) * Math.PI * 2 - Math.PI / 2;
          var a1 = t(segEnd, range) * Math.PI * 2 - Math.PI / 2;
          if (a1 - a0 < 0.002) continue;
          var r = RADIAL_MAXR * 0.72;
          var arc;
          if (cuts.length === 2) {
            arc = document.createElementNS(svgNS, "circle");
            arc.setAttribute("cx", RADIAL_CX);
            arc.setAttribute("cy", RADIAL_CY);
            arc.setAttribute("r", r.toFixed(1));
          } else {
            var x0 = RADIAL_CX + r * Math.cos(a0), y0 = RADIAL_CY + r * Math.sin(a0);
            var x1 = RADIAL_CX + r * Math.cos(a1), y1 = RADIAL_CY + r * Math.sin(a1);
            var large = (a1 - a0) > Math.PI ? 1 : 0;
            arc = document.createElementNS(svgNS, "path");
            arc.setAttribute("d", "M" + x0.toFixed(1) + "," + y0.toFixed(1) + " A" + r.toFixed(1) + "," + r.toFixed(1) + " 0 " + large + " 1 " + x1.toFixed(1) + "," + y1.toFixed(1));
          }
          arc.setAttribute("class", "stage-ring-arc");
          arc.setAttribute("stroke", "var(--fam" + ((stageIdx % 6) + 1) + "-bg)");
          gStages.appendChild(arc);
        }
        return;
      }

      var relevant = [];
      stages.forEach(function (s, i) { if (overlaps(s)) relevant.push({ s: s, i: i }); });
      relevant.sort(function (a, b) { return b.s.to - a.s.to; });
      var g = document.createElementNS(svgNS, "g");
      g.setAttribute("opacity", "0.45");
      relevant.forEach(function (item) {
        var f1 = t(addYears(BIRTH, item.s.to), range);
        var r1 = f1 * RADIAL_MAXR;
        if (r1 < 2) return;
        var circle = document.createElementNS(svgNS, "circle");
        circle.setAttribute("cx", RADIAL_CX);
        circle.setAttribute("cy", RADIAL_CY);
        circle.setAttribute("r", r1.toFixed(1));
        circle.setAttribute("fill", "var(--fam" + ((item.i % 6) + 1) + "-bg)");
        g.appendChild(circle);
      });
      gStages.appendChild(g);

      relevant.forEach(function (item) {
        var f0 = t(addYears(BIRTH, item.s.from), range), f1 = t(addYears(BIRTH, item.s.to), range);
        if (f1 - f0 < 0.03) return;
        var rMid = ((f0 + f1) / 2) * RADIAL_MAXR;
        if (rMid < 10) return;
        var label = document.createElementNS(svgNS, "text");
        label.setAttribute("x", RADIAL_CX);
        label.setAttribute("y", (RADIAL_CY - rMid + 4).toFixed(1));
        label.setAttribute("text-anchor", "middle");
        label.setAttribute("class", "stage-label stage-label-ring");
        label.textContent = item.s.name;
        gStages.appendChild(label);
      });
    }

    function fmtDate(d) {
      return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
    }
    function fmtShort(d, mode) {
      if (mode === "year") return d.toLocaleDateString(undefined, { month: "short" });
      if (mode === "life") {
        var age = Math.round((d.getTime() - BIRTH.getTime()) / YEAR_MS);
        return age === 0 ? "birth" : age + "y";
      }
      return String(d.getFullYear());
    }
    function tickDatesFor(range) {
      var out = [];
      if (range.mode === "life") {
        var maxAge = Math.ceil((range.end.getTime() - BIRTH.getTime()) / YEAR_MS);
        for (var age = 0; age <= maxAge; age += 10) out.push(addYears(BIRTH, age));
      } else if (range.mode === "decade") {
        var y0 = range.start.getFullYear();
        for (var yy = y0; yy <= range.end.getFullYear(); yy++) out.push(new Date(yy, 0, 1));
      } else {
        for (var m = 0; m < 12; m++) out.push(new Date(range.start.getFullYear(), m, 1));
      }
      return out;
    }
    function decadeYearTicksFor(range) {
      var out = [];
      var firstDecade = Math.ceil(range.start.getFullYear() / 10) * 10;
      for (var y = firstDecade; y <= range.end.getFullYear(); y += 10) {
        var d = new Date(y, 0, 1);
        if (d >= range.start && d <= range.end) out.push(d);
      }
      return out;
    }
    function niceTicksFor(range) {
      var spanMs = range.end.getTime() - range.start.getTime();
      var spanYears = spanMs / YEAR_MS;
      var out = [];
      if (spanYears > 6) {
        var stepYears = spanYears > 60 ? 20 : spanYears > 30 ? 10 : spanYears > 15 ? 5 : spanYears > 6 ? 2 : 1;
        var firstYear = Math.ceil(range.start.getFullYear() / stepYears) * stepYears;
        for (var y = firstYear; y <= range.end.getFullYear(); y += stepYears) {
          var d = new Date(y, 0, 1);
          if (d >= range.start && d <= range.end) out.push({ date: d, label: String(y) });
        }
      } else if (spanYears > 0.6) {
        var stepMonths = spanYears > 3 ? 6 : spanYears > 1.2 ? 3 : 1;
        var cursor = new Date(range.start.getFullYear(), range.start.getMonth(), 1);
        while (cursor <= range.end) {
          if (cursor >= range.start) {
            var lbl = cursor.toLocaleDateString(undefined, { month: "short" }) + (cursor.getMonth() === 0 ? " '" + String(cursor.getFullYear()).slice(2) : "");
            out.push({ date: new Date(cursor), label: lbl });
          }
          cursor = new Date(cursor.getFullYear(), cursor.getMonth() + stepMonths, 1);
        }
      } else {
        var spanDays = spanMs / 86400000;
        var stepDays = spanDays > 60 ? 14 : spanDays > 21 ? 7 : spanDays > 6 ? 2 : 1;
        var dayCursor = new Date(range.start.getFullYear(), range.start.getMonth(), range.start.getDate());
        while (dayCursor <= range.end) {
          if (dayCursor >= range.start) {
            out.push({ date: new Date(dayCursor), label: dayCursor.toLocaleDateString(undefined, { month: "short", day: "numeric" }) });
          }
          dayCursor = new Date(dayCursor.getFullYear(), dayCursor.getMonth(), dayCursor.getDate() + stepDays);
        }
      }
      if (out.length > 12) {
        var stride = Math.ceil(out.length / 9);
        out = out.filter(function (_, i) { return i % stride === 0; });
      }
      return out;
    }
    function fmtRangeLabel(range) {
      var sameYear = range.start.getFullYear() === range.end.getFullYear();
      var aLabel = range.start.toLocaleDateString(undefined, sameYear ? { month: "short", day: "numeric" } : { month: "short", day: "numeric", year: "numeric" });
      var bLabel = range.end.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
      return aLabel + " – " + bLabel;
    }

    var svg = document.getElementById("arcSvg");
    var arcWrap = document.getElementById("arcWrap");
    var gTicks = document.getElementById("ticks");
    var gStages = document.getElementById("stageBands");
    var gRingGuides = document.getElementById("ringGuides");
    var gNodes = document.getElementById("nodes");
    var gToday = document.getElementById("todayMarker");
    var pathFuture = document.getElementById("pathFuture");
    var pathLived = document.getElementById("pathLived");
    var baselineRef = document.getElementById("baselineRef");
    var rail = document.getElementById("rail");
    var railCount = document.getElementById("railCount");
    var riverScrollbar = document.getElementById("riverScrollbar");
    var riverScrollThumb = document.getElementById("riverScrollThumb");
    var nodeTooltip = document.getElementById("nodeTooltip");
    var nodeTooltipDate = document.getElementById("nodeTooltipDate");
    var nodeTooltipTitle = document.getElementById("nodeTooltipTitle");
    var hoverTimer = null;

    function hideNodeTooltip() {
      if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; }
      nodeTooltip.classList.remove("visible");
    }
    function showNodeTooltipFor(g, e) {
      var wrapRect = arcWrap.getBoundingClientRect();
      var nodeRect = g.getBoundingClientRect();
      var relX = nodeRect.left + nodeRect.width / 2 - wrapRect.left;
      var relY = nodeRect.top - wrapRect.top;
      var d = new Date(e.date + "T00:00:00");
      nodeTooltipDate.textContent = fmtDate(d);
      nodeTooltipTitle.textContent = e.title;
      nodeTooltip.style.left = relX.toFixed(1) + "px";
      nodeTooltip.style.top = relY.toFixed(1) + "px";
      nodeTooltip.classList.add("visible");
    }

    function drawArcCircle(parent, cx, cy, r, a0, a1, cls) {
      var x0 = cx + r * Math.cos(a0), y0 = cy + r * Math.sin(a0);
      var x1 = cx + r * Math.cos(a1), y1 = cy + r * Math.sin(a1);
      var large = (a1 - a0) % (Math.PI * 2) > Math.PI ? 1 : 0;
      var d = "M" + x0.toFixed(1) + "," + y0.toFixed(1) + " A" + r.toFixed(1) + "," + r.toFixed(1) + " 0 " + large + " 1 " + x1.toFixed(1) + "," + y1.toFixed(1);
      var path = document.createElementNS(svgNS, "path");
      path.setAttribute("d", d);
      path.setAttribute("class", cls);
      parent.appendChild(path);
    }

    function hashStr(s) {
      var h = 0;
      for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) >>> 0; }
      return h;
    }
    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
      });
    }

    function mediaTileHtml(m) {
      // Phase 27: the small rail card only ever needs a preview-sized
      // image, never the full original upload (which can be several MB
      // straight off a phone camera) — &thumb=1 asks media.php for a
      // cached, resized copy instead (see includes/media.php's
      // ensure_media_thumbnail()). loading="lazy" also defers fetching
      // any card that's scrolled out of view. The full-resolution image
      // is still used in the opened-memory viewer below.
      if (m.kind === "image") return '<img src="' + m.url + '&thumb=1" alt="" loading="lazy" decoding="async">';
      if (m.kind === "video") return '<div class="media-tile">' + VIDEO_ICON + '<span class="media-tile-badge">Video</span></div>';
      return '<div class="media-tile">' + DOC_ICON + '<span class="media-tile-badge">File</span></div>';
    }
    function viewerLargeMediaHtml(m) {
      if (m.kind === "image") return '<img src="' + m.url + '" alt="">';
      if (m.kind === "video") return '<video src="' + m.url + '" controls playsinline></video>';
      // A document (PDF, DOC, TXT, …) has no inline preview, so it needs an
      // actual link to be reachable at all — the multi-file grid already
      // wraps every tile in one (see viewerMediaViewHtml below), but this
      // single-attachment path used to render a bare, non-clickable div
      // with nothing to click, which is why opening a memory's one-and-only
      // PDF never did anything. `.viewer-media-single a{display:contents}`
      // was already sitting in the CSS for exactly this, just never used.
      return '<a href="' + m.url + '" target="_blank" rel="noopener" title="Open file"><div class="media-tile">' + DOC_ICON + '<span class="media-tile-badge">Open file</span></div></a>';
    }

    function renderRail(list) {
      rail.innerHTML = "";
      if (!list.length) {
        var empty = document.createElement("div");
        empty.className = "empty-state";
        empty.style.flex = "1 0 auto";
        empty.textContent = CAN_MANAGE
          ? "Nothing in this range yet — add a memory above."
          : "No memories shared here in this range yet.";
        rail.appendChild(empty);
        return;
      }
      var famCount = 6;
      list.forEach(function (e) {
        var d = new Date(e.date + "T00:00:00");
        var card = document.createElement("div");
        card.className = "mem-card" + (e.id === state.highlightId ? " highlight" : "");
        card.setAttribute("data-id", e.id);
        var wobble = (hashStr(e.id) % 50) / 10 - 2.5;
        card.style.transform = "rotate(" + wobble.toFixed(1) + "deg)";
        var fi = (hashStr(e.id) % famCount) + 1;
        var fi2 = (fi % famCount) + 1;
        var mediaInner = (e.media && e.media.length)
          ? mediaTileHtml(e.media[0]) + (e.media.length > 1 ? '<span class="card-media-count">+' + (e.media.length - 1) + '</span>' : '')
          : '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 3.5h6.5L15 7v9.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" stroke-linejoin="round"/><path d="M7 8h6M7 11h6M7 14h4" stroke-linecap="round"/></svg>';
        // Phase 69: a trip card shows its date SPAN and event count instead
        // of the single occurred-on date and body text an ordinary memory
        // card shows -- the underlying visual timeline diagram still only
        // ever plots the trip's single start-date point (see the `trip`
        // field's own comment above, in the PHP that builds `entries`),
        // but the rail card itself can show the fuller picture.
        var dateLabel = e.trip
          ? fmtDate(d) + " – " + fmtDate(new Date(e.trip.finishDate + "T00:00:00"))
          : fmtDate(d);
        var thoughtLabel = e.trip
          ? (e.trip.eventCount === 1 ? "1 event planned" : e.trip.eventCount + " events planned")
          : e.thought;
        card.innerHTML =
          '<div class="card-tape" style="background: var(--fam' + fi + ')"></div>' +
          '<div class="card-media" style="--stage-a: var(--fam' + fi + '-bg); --stage-b: var(--fam' + fi2 + '-bg);">' + mediaInner + (ORIGIN_BADGE_HTML[e.origin] || '') + '</div>' +
          '<div class="card-body">' +
            '<div class="card-date mono">' + dateLabel + '</div>' +
            '<div class="card-title">' + escapeHtml(e.title) + '</div>' +
            '<div class="card-thought">' + escapeHtml(thoughtLabel) + '</div>' +
            '<div class="card-foot">' +
              (e.type === "diary" ? DIARY_PILL_HTML : "") +
              visibilityPillHtml(e.visibility) +
            '</div>' +
          '</div>';
        card.addEventListener("click", function () {
          if (e.trip) { openTripPlanner(e.trip.tripPlanId); } else { openViewer(e.id); }
        });
        rail.appendChild(card);
      });
    }

    function focusCard(id) {
      state.highlightId = id;
      render();
      var card = rail.querySelector('[data-id="' + id + '"]');
      if (card) card.scrollIntoView({ behavior: "smooth", inline: "center", block: "center" });
      setTimeout(function () { state.highlightId = null; render(); }, 1800);
    }

    function render() {
      var range = getRange();
      var layout = state.layout;
      var now = new Date();
      var todayFrac = t(now, range);
      var todayVisible = now >= range.start && now <= range.end;

      svg.setAttribute("viewBox", layout === "river" ? "0 -24 1180 424" : "0 0 760 760");
      arcWrap.className = "arc-wrap layout-" + layout;
      baselineRef.style.display = layout === "river" ? "" : "none";

      gRingGuides.innerHTML = "";
      if (layout === "rings") {
        pathFuture.setAttribute("d", ""); pathLived.setAttribute("d", "");
        var todayRadius = todayVisible ? t(now, range) * RADIAL_MAXR : -1;
        if (range.mode === "year") {
          var startAngle = -Math.PI / 2;
          var endAngle = todayVisible ? startAngle + todayFrac * Math.PI * 2 : startAngle;
          drawArcCircle(gRingGuides, RADIAL_CX, RADIAL_CY, RADIAL_MAXR * 0.72, 0, Math.PI * 2 - 0.001, "ring-arc-rest");
          if (todayVisible) drawArcCircle(gRingGuides, RADIAL_CX, RADIAL_CY, RADIAL_MAXR * 0.72, startAngle, endAngle, "ring-arc");
        } else {
          tickDatesFor(range).forEach(function (d) {
            var r = t(d, range) * RADIAL_MAXR;
            if (r < 4) return;
            var circle = document.createElementNS(svgNS, "circle");
            circle.setAttribute("cx", RADIAL_CX); circle.setAttribute("cy", RADIAL_CY); circle.setAttribute("r", r.toFixed(1));
            circle.setAttribute("class", "ring-guide" + (todayVisible && r <= todayRadius + 0.5 ? " lived" : ""));
            gRingGuides.appendChild(circle);
          });
          if (todayVisible && todayRadius > 4) {
            var forming = document.createElementNS(svgNS, "circle");
            forming.setAttribute("cx", RADIAL_CX); forming.setAttribute("cy", RADIAL_CY); forming.setAttribute("r", todayRadius.toFixed(1));
            forming.setAttribute("class", "ring-guide forming");
            gRingGuides.appendChild(forming);
          }
        }
      } else {
        pathFuture.setAttribute("d", pathD(range, layout));
        pathLived.setAttribute("d", todayVisible ? pathD(range, layout, todayFrac) : (now > range.end ? pathD(range, layout) : ""));
      }

      gStages.innerHTML = "";
      renderStageBands(range, layout);

      gTicks.innerHTML = "";
      var isCustom = range.mode === "custom";
      var tickPairs = isCustom
        ? niceTicksFor(range)
        : tickDatesFor(range).map(function (d) { return { date: d, label: fmtShort(d, range.mode) }; });
      tickPairs.forEach(function (tp) {
        var d = tp.date, f = t(d, range);
        if (layout === "river") {
          var x = RIVER_PAD + f * (RIVER_W - 2 * RIVER_PAD);
          var line = document.createElementNS(svgNS, "line");
          line.setAttribute("class", "tick-line");
          line.setAttribute("x1", x); line.setAttribute("x2", x);
          line.setAttribute("y1", "306"); line.setAttribute("y2", "314");
          gTicks.appendChild(line);
          var label = document.createElementNS(svgNS, "text");
          label.setAttribute("x", x); label.setAttribute("y", "326");
          label.setAttribute("text-anchor", "middle");
          label.setAttribute("class", "tick-label mono");
          label.textContent = tp.label;
          gTicks.appendChild(label);
        } else {
          var labelPos;
          if (layout === "rings" && range.mode !== "year") {
            var r = f * RADIAL_MAXR;
            labelPos = { x: RADIAL_CX, y: RADIAL_CY - r - 8 };
          } else {
            var p = placeFrac(f, range, layout);
            var dx = p.x - RADIAL_CX, dy = p.y - RADIAL_CY;
            var len = Math.sqrt(dx * dx + dy * dy) || 1;
            labelPos = { x: RADIAL_CX + (dx / len) * (len + 16), y: RADIAL_CY + (dy / len) * (len + 16) };
          }
          var lbl2 = document.createElementNS(svgNS, "text");
          lbl2.setAttribute("x", labelPos.x.toFixed(1)); lbl2.setAttribute("y", labelPos.y.toFixed(1));
          lbl2.setAttribute("text-anchor", "middle");
          lbl2.setAttribute("class", "tick-label mono");
          lbl2.textContent = tp.label;
          gTicks.appendChild(lbl2);
        }
      });

      if (layout === "river") {
        var topPairs = range.mode === "life"
          ? decadeYearTicksFor(range).map(function (d) { return { date: d, label: String(d.getFullYear()) }; })
          : tickPairs;
        topPairs.forEach(function (tp) {
          var fTop = t(tp.date, range);
          var xTop = RIVER_PAD + fTop * (RIVER_W - 2 * RIVER_PAD);
          var lineTop = document.createElementNS(svgNS, "line");
          lineTop.setAttribute("class", "tick-line");
          lineTop.setAttribute("x1", xTop); lineTop.setAttribute("x2", xTop);
          lineTop.setAttribute("y1", "12"); lineTop.setAttribute("y2", "20");
          gTicks.appendChild(lineTop);
          var labelTop = document.createElementNS(svgNS, "text");
          labelTop.setAttribute("x", xTop); labelTop.setAttribute("y", "0");
          labelTop.setAttribute("text-anchor", "middle");
          labelTop.setAttribute("class", "tick-label mono");
          labelTop.textContent = tp.label;
          gTicks.appendChild(labelTop);
        });
      }

      var zoomPill = document.getElementById("customZoomPill");
      if (state.customRange) {
        zoomPill.hidden = false;
        document.getElementById("customZoomLabel").textContent = fmtRangeLabel(range);
      } else {
        zoomPill.hidden = true;
      }

      if (layout === "river") {
        var fullR = fullLifeRange();
        var fullSpanMs = fullR.end.getTime() - fullR.start.getTime();
        var curSpanMs = range.end.getTime() - range.start.getTime();
        var leftFrac = clamp01((range.start.getTime() - fullR.start.getTime()) / fullSpanMs);
        var widthFrac = Math.min(1, curSpanMs / fullSpanMs);
        riverScrollThumb.style.left = (leftFrac * 100).toFixed(2) + "%";
        riverScrollThumb.style.width = Math.max(widthFrac * 100, 4).toFixed(2) + "%";
      }

      gToday.innerHTML = "";
      if (todayVisible) {
        var pt = place(now, range, layout);
        var ring = document.createElementNS(svgNS, "circle");
        ring.setAttribute("class", "today-ring");
        ring.setAttribute("cx", pt.x); ring.setAttribute("cy", pt.y); ring.setAttribute("r", "13");
        gToday.appendChild(ring);
        var dot = document.createElementNS(svgNS, "circle");
        dot.setAttribute("class", "today-glow");
        dot.setAttribute("cx", pt.x); dot.setAttribute("cy", pt.y); dot.setAttribute("r", "6");
        dot.setAttribute("fill", "var(--accent-glow)");
        gToday.appendChild(dot);
        var lblPos;
        if (layout === "river") {
          lblPos = { x: pt.x, y: pt.y - 20 };
        } else {
          var ddx = pt.x - RADIAL_CX, ddy = pt.y - RADIAL_CY;
          var dlen = Math.sqrt(ddx * ddx + ddy * ddy) || 1;
          lblPos = { x: RADIAL_CX + (ddx / dlen) * (dlen + 22), y: RADIAL_CY + (ddy / dlen) * (dlen + 22) };
        }
        var lbl = document.createElementNS(svgNS, "text");
        lbl.setAttribute("x", lblPos.x.toFixed(1)); lbl.setAttribute("y", lblPos.y.toFixed(1));
        lbl.setAttribute("text-anchor", "middle");
        lbl.setAttribute("class", "tick-label mono");
        lbl.textContent = "today";
        gToday.appendChild(lbl);
      }

      hideNodeTooltip();
      gNodes.innerHTML = "";
      var visible = entries.filter(function (e) {
        var d = new Date(e.date + "T00:00:00");
        return d >= range.start && d <= range.end;
      }).sort(function (a, b) { return a.date.localeCompare(b.date); });

      visible.forEach(function (e) {
        var d = new Date(e.date + "T00:00:00");
        var p = place(d, range, layout);
        var g = document.createElementNS(svgNS, "g");
        g.setAttribute("class", "node visibility-" + e.visibility + (e.type === "diary" ? " type-diary" : "") + (e.id === state.highlightId ? " highlight" : ""));
        g.setAttribute("transform", "translate(" + p.x.toFixed(1) + "," + p.y.toFixed(1) + ")");
        g.setAttribute("data-id", e.id);

        var ring = document.createElementNS(svgNS, "circle");
        ring.setAttribute("class", "ring");
        ring.setAttribute("r", "15");
        g.appendChild(ring);

        if (e.media && e.media.length && e.media[0].kind === "image") {
          var img = document.createElementNS(svgNS, "image");
          // Phase 29: this dot is 30x30px on screen, but without "&thumb=1"
          // it was fetching and decoding the full original upload (often
          // several MB straight off a phone camera) for every visible
          // memory, every time the river re-renders — the same waste the
          // memory-card rail below was already fixed for in Phase 27. On a
          // timeline with many photos this was a real, measurable slowdown
          // (worst on iPad, where memory and decode speed are tightest),
          // and it's pure waste since the same small cached preview file
          // is reused here too.
          img.setAttributeNS("http://www.w3.org/1999/xlink", "href", e.media[0].url + "&thumb=1");
          img.setAttribute("x", "-15"); img.setAttribute("y", "-15");
          img.setAttribute("width", "30"); img.setAttribute("height", "30");
          img.setAttribute("clip-path", "url(#thumbClip)");
          img.setAttribute("preserveAspectRatio", "xMidYMid slice");
          g.appendChild(img);
        } else {
          var dot2 = document.createElementNS(svgNS, "circle");
          dot2.setAttribute("r", "5");
          dot2.setAttribute("fill", e.visibility === "public" ? "var(--accent)" : (e.visibility === "custom" ? "var(--fam3)" : "var(--accent-2)"));
          g.appendChild(dot2);
        }

        if (e.visibility === "private") {
          var lock = document.createElementNS(svgNS, "circle");
          lock.setAttribute("class", "lock");
          lock.setAttribute("r", "4.5");
          lock.setAttribute("cx", "11"); lock.setAttribute("cy", "11");
          g.appendChild(lock);
        }

        g.addEventListener("click", function (evt) {
          evt.stopPropagation();
          hideNodeTooltip();
          focusCard(e.id);
        });
        g.addEventListener("mouseenter", function () {
          hideNodeTooltip();
          hoverTimer = setTimeout(function () { showNodeTooltipFor(g, e); }, 750);
        });
        g.addEventListener("mouseleave", hideNodeTooltip);
        gNodes.appendChild(g);
      });

      railCount.textContent = visible.length + (visible.length === 1 ? " memory" : " memories");
      renderRail(visible);
    }

    // ---- toggles ----
    function wireSegmented(id, key) {
      document.getElementById(id).addEventListener("click", function (evt) {
        var btn = evt.target.closest("button[data-" + key + "]");
        if (!btn) return;
        this.querySelectorAll("button").forEach(function (b) { b.classList.remove("active"); });
        btn.classList.add("active");
        state[key] = btn.dataset[key];
        if (key === "zoom") state.customRange = null;
        render();
      });
    }
    wireSegmented("layoutToggle", "layout");
    wireSegmented("zoomToggle", "zoom");

    document.getElementById("customZoomPill").addEventListener("click", function () {
      state.customRange = null;
      render();
    });

    // ---- click on the timeline to add a memory on that date (owner only) ----
    function pad2(n) { return n < 10 ? "0" + n : String(n); }
    function isoDate(d) { return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate()); }

    function clientToSvg(clientX, clientY) {
      var rect = svg.getBoundingClientRect();
      var vb = svg.viewBox.baseVal;
      var scale = vb.width / rect.width;
      return { x: vb.x + (clientX - rect.left) * scale, y: vb.y + (clientY - rect.top) * scale };
    }
    var suppressNextClick = false;
    svg.addEventListener("click", function (evt) {
      if (suppressNextClick) { suppressNextClick = false; return; }
      if (!CAN_MANAGE) return;
      if (evt.target.closest(".node")) return;
      var range = getRange();
      var pos = clientToSvg(evt.clientX, evt.clientY);
      var f = posToFrac(pos.x, pos.y, range, state.layout);
      var dateParam = "date=" + isoDate(fracToDate(f, range));
      window.location.href = "/add_entry.php?" + (ADD_ENTRY_QS ? ADD_ENTRY_QS.slice(1) + "&" + dateParam : dateParam);
    });

    // ---- drag-to-zoom on the river ----
    var selectionRect = document.getElementById("selectionRect");
    var DRAG_THRESHOLD = 6;
    var MIN_SPAN_MS = 3 * 86400000;
    var dragState = null;

    svg.addEventListener("pointerdown", function (evt) {
      if (state.layout !== "river") return;
      if (evt.target.closest(".node")) return;
      if (evt.button !== undefined && evt.button !== 0) return;
      dragState = {
        startClientX: evt.clientX,
        startClientY: evt.clientY,
        startSvgX: clientToSvg(evt.clientX, evt.clientY).x,
        dragging: false
      };
    });

    window.addEventListener("pointermove", function (evt) {
      if (!dragState) return;
      var dx = evt.clientX - dragState.startClientX;
      var dy = evt.clientY - dragState.startClientY;
      if (!dragState.dragging) {
        if (Math.abs(dx) < DRAG_THRESHOLD && Math.abs(dy) < DRAG_THRESHOLD) return;
        dragState.dragging = true;
        selectionRect.classList.add("active");
      }
      var curSvgX = clientToSvg(evt.clientX, evt.clientY).x;
      var x0 = Math.min(dragState.startSvgX, curSvgX);
      var x1 = Math.max(dragState.startSvgX, curSvgX);
      x0 = Math.max(RIVER_PAD, Math.min(RIVER_W - RIVER_PAD, x0));
      x1 = Math.max(RIVER_PAD, Math.min(RIVER_W - RIVER_PAD, x1));
      selectionRect.setAttribute("x", x0);
      selectionRect.setAttribute("width", Math.max(0, x1 - x0));
    });

    window.addEventListener("pointerup", function (evt) {
      if (!dragState) return;
      var wasDragging = dragState.dragging;
      if (wasDragging) {
        var range = getRange();
        var curSvgX = clientToSvg(evt.clientX, evt.clientY).x;
        var xA = Math.max(RIVER_PAD, Math.min(RIVER_W - RIVER_PAD, dragState.startSvgX));
        var xB = Math.max(RIVER_PAD, Math.min(RIVER_W - RIVER_PAD, curSvgX));
        var fA = posToFrac(xA, 0, range, "river");
        var fB = posToFrac(xB, 0, range, "river");
        var dA = fracToDate(fA, range), dB = fracToDate(fB, range);
        var newStart = dA < dB ? dA : dB;
        var newEnd = dA < dB ? dB : dA;
        if (newEnd.getTime() - newStart.getTime() >= MIN_SPAN_MS) {
          state.customRange = { start: newStart, end: newEnd };
          render();
        }
        suppressNextClick = true;
        selectionRect.classList.remove("active");
        selectionRect.setAttribute("width", 0);
      }
      dragState = null;
    });

    // ---- scroll-wheel zoom on the river ----
    arcWrap.addEventListener("wheel", function (evt) {
      if (state.layout !== "river") return;
      evt.preventDefault();
      var range = getRange();
      var pos = clientToSvg(evt.clientX, evt.clientY);
      var x = Math.max(RIVER_PAD, Math.min(RIVER_W - RIVER_PAD, pos.x));
      var f = posToFrac(x, 0, range, "river");
      var centerDate = fracToDate(f, range);
      var spanMs = range.end.getTime() - range.start.getTime();
      var zoomFactor = evt.deltaY > 0 ? 1.15 : 1 / 1.15;
      var fullSpanMs = addYears(new Date(), BUFFER_YEARS).getTime() - BIRTH.getTime();
      var newSpanMs = Math.max(MIN_SPAN_MS, Math.min(fullSpanMs, spanMs * zoomFactor));
      var centerMs = centerDate.getTime();
      var startMs = centerMs - (centerMs - range.start.getTime()) * (newSpanMs / spanMs);
      var newStart = new Date(startMs);
      var newEnd = new Date(startMs + newSpanMs);
      state.customRange = { start: newStart, end: newEnd };
      render();
    }, { passive: false });

    // ---- horizontal scrollbar: pan the current window across the full life range ----
    function clampWindowToFull(startMs, endMs) {
      var full = fullLifeRange();
      var fullStartMs = full.start.getTime(), fullEndMs = full.end.getTime();
      if (startMs < fullStartMs) { endMs += (fullStartMs - startMs); startMs = fullStartMs; }
      if (endMs > fullEndMs) { startMs -= (endMs - fullEndMs); endMs = fullEndMs; }
      startMs = Math.max(startMs, fullStartMs);
      return { start: new Date(startMs), end: new Date(endMs) };
    }

    var scrollDrag = null;
    riverScrollThumb.addEventListener("pointerdown", function (evt) {
      evt.stopPropagation();
      var range = getRange();
      var trackRect = riverScrollbar.getBoundingClientRect();
      scrollDrag = {
        startClientX: evt.clientX,
        trackWidth: trackRect.width,
        startMs: range.start.getTime(),
        endMs: range.end.getTime()
      };
      riverScrollThumb.classList.add("dragging");
      try { riverScrollThumb.setPointerCapture(evt.pointerId); } catch (e) {}
    });
    riverScrollThumb.addEventListener("pointermove", function (evt) {
      if (!scrollDrag) return;
      var full = fullLifeRange();
      var fullSpanMs = full.end.getTime() - full.start.getTime();
      var pxToMs = fullSpanMs / Math.max(1, scrollDrag.trackWidth);
      var deltaMs = (evt.clientX - scrollDrag.startClientX) * pxToMs;
      var win = clampWindowToFull(scrollDrag.startMs + deltaMs, scrollDrag.endMs + deltaMs);
      state.customRange = win;
      render();
    });
    function endScrollDrag(evt) {
      if (!scrollDrag) return;
      scrollDrag = null;
      riverScrollThumb.classList.remove("dragging");
      if (evt) { try { riverScrollThumb.releasePointerCapture(evt.pointerId); } catch (e) {} }
    }
    riverScrollThumb.addEventListener("pointerup", endScrollDrag);
    riverScrollThumb.addEventListener("pointercancel", endScrollDrag);

    riverScrollbar.addEventListener("pointerdown", function (evt) {
      if (evt.target === riverScrollThumb) return;
      var range = getRange();
      var full = fullLifeRange();
      var trackRect = riverScrollbar.getBoundingClientRect();
      var frac = clamp01((evt.clientX - trackRect.left) / trackRect.width);
      var fullSpanMs = full.end.getTime() - full.start.getTime();
      var spanMs = range.end.getTime() - range.start.getTime();
      var centerMs = full.start.getTime() + frac * fullSpanMs;
      var win = clampWindowToFull(centerMs - spanMs / 2, centerMs + spanMs / 2);
      state.customRange = win;
      render();
    });

    // ---- read-only memory/diary viewer ----
    var viewerScrim = document.getElementById("viewerScrim");
    var viewerHeaderTitle = document.getElementById("viewerHeaderTitle");
    var viewerMedia = document.getElementById("viewerMedia");
    var viewerTitleView = document.getElementById("viewerTitleView");
    var viewerDateView = document.getElementById("viewerDateView");
    var viewerPillView = document.getElementById("viewerPillView");
    var viewerThoughtView = document.getElementById("viewerThoughtView");
    var viewerDeleteForm = document.getElementById("viewerDeleteForm");
    var viewerDeleteEntryId = document.getElementById("viewerDeleteEntryId");
    var viewerEditLink = document.getElementById("viewerEditLink");
    var viewerTagNotes = document.getElementById("viewerTagNotes");
    var viewerMyNoteForm = document.getElementById("viewerMyNoteForm");
    var viewerMyNoteEntryId = document.getElementById("viewerMyNoteEntryId");
    var viewerMyNoteText = document.getElementById("viewerMyNoteText");
    var viewerAddMediaForm = document.getElementById("viewerAddMediaForm");
    var viewerAddMediaEntryId = document.getElementById("viewerAddMediaEntryId");

    // Phase 43: a richer, drag-and-drop-capable file picker for the "add
    // media to a memory I'm tagged on" form -- matching add_entry.php's own
    // #photoDrop pattern (same CSS, now shared via styles.css) but simpler,
    // since this form only ever ADDS new files: there's no "kept" existing-
    // media list to render alongside it (the memory's existing media is
    // already shown, read-only, in #viewerMedia above). Submitted over
    // fetch() below (see the "submit" listener further down) so the memory
    // viewer stays open and the media grid updates in place, instead of the
    // old plain-form full-page POST that closed the modal and put any
    // error/success message at the top of an unrelated-looking fresh page
    // load. Names are all "vam"-prefixed to avoid colliding with this same
    // big IIFE's other top-level names (render, place, entries, and so on).
    var vamDropzone = document.getElementById("viewerAddMediaDrop");
    var vamEmptyState = document.getElementById("viewerAddMediaDropEmpty");
    var vamGrid = document.getElementById("viewerAddMediaGrid");
    var vamInput = document.getElementById("viewerAddMediaInput");
    var vamErrorEl = document.getElementById("viewerAddMediaError");
    var vamStatusEl = document.getElementById("viewerAddMediaStatus");
    var vamSubmitBtn = document.getElementById("viewerAddMediaSubmitBtn");
    var vamPending = []; // { file, kind, url }
    var vamRemainingSlots = 10; // recomputed per-memory in openViewer() below
    var vamSubmitting = false;
    var vamStatusTimer = null;
    var VAM_MAX_BYTES = 25 * 1024 * 1024;
    var VAM_HEIC_RE = /\.(heic|heif)$/i;
    var VAM_ADD_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 4v12M4 10h12" stroke-linecap="round"/></svg>';

    function vamKindOfFile(file) {
      if (file.type.indexOf("image/") === 0) return "image";
      if (file.type.indexOf("video/") === 0) return "video";
      return "document";
    }
    function vamExtLabel(name) {
      var m = /\.([a-z0-9]+)$/i.exec(name || "");
      return m ? m[1].toUpperCase() : "FILE";
    }
    function vamTileHtml(item) {
      if (item.kind === "converting") return '<span class="tile-spinner" aria-hidden="true"></span><span class="media-tile-name">Converting\u2026</span>';
      if (item.kind === "image") return '<img src="' + item.url + '" alt="">';
      if (item.kind === "video") return VIDEO_ICON + '<span class="media-tile-badge">Video</span>';
      return DOC_ICON + '<span class="media-tile-name">' + escapeHtml(item.file.name) + '</span><span class="media-tile-badge">' + escapeHtml(vamExtLabel(item.file.name)) + '</span>';
    }
    function vamShowError(msg) {
      vamErrorEl.textContent = msg || "";
      vamErrorEl.style.display = msg ? "block" : "none";
    }
    function vamShowStatus(msg) {
      if (vamStatusTimer) { clearTimeout(vamStatusTimer); vamStatusTimer = null; }
      vamStatusEl.textContent = msg || "";
      vamStatusEl.style.display = msg ? "block" : "none";
      if (msg) {
        vamStatusTimer = setTimeout(function () { vamStatusEl.style.display = "none"; }, 4000);
      }
    }
    function vamSyncInput() {
      var dt = new DataTransfer();
      vamPending.forEach(function (item) { if (item.file) dt.items.add(item.file); });
      vamInput.files = dt.files;
    }
    function vamRender() {
      if (!vamPending.length) {
        vamEmptyState.hidden = false;
        vamGrid.hidden = true;
        vamGrid.innerHTML = "";
        return;
      }
      vamEmptyState.hidden = true;
      vamGrid.hidden = false;
      var tiles = vamPending.map(function (item, i) {
        var cls = item.kind === "video" ? " has-video" : (item.kind !== "image" ? " has-doc" : "");
        return '<div class="pick-tile' + cls + '" data-pending-idx="' + i + '">' + vamTileHtml(item) +
          '<button type="button" class="pick-remove" data-pending-idx="' + i + '" aria-label="Remove">\u00d7</button></div>';
      }).join("");
      if (vamPending.length < vamRemainingSlots) {
        tiles += '<div class="pick-tile pick-tile--add" data-add="1" title="Add more">' + VAM_ADD_ICON + '</div>';
      }
      vamGrid.innerHTML = tiles;
    }
    function vamLooksLikeHeic(file) {
      return VAM_HEIC_RE.test(file.name || "") || file.type === "image/heic" || file.type === "image/heif";
    }
    function vamHeicToJpegFile(file) {
      if (typeof heic2any !== "function") return Promise.reject(new Error("heic2any not available"));
      return heic2any({ blob: file, toType: "image/jpeg", quality: 0.88 }).then(function (result) {
        var blob = Array.isArray(result) ? result[0] : result;
        var newName = file.name.replace(VAM_HEIC_RE, "") + ".jpg";
        return new File([blob], newName, { type: "image/jpeg" });
      });
    }
    function vamAddOrdinaryFile(f) {
      var kind = vamKindOfFile(f);
      vamPending.push({ file: f, kind: kind, url: kind === "image" ? URL.createObjectURL(f) : null });
    }
    function vamAddHeicFile(f) {
      var placeholder = { file: f, kind: "converting", url: null };
      vamPending.push(placeholder);
      vamSyncInput();
      vamRender();
      vamHeicToJpegFile(f).then(function (jpegFile) {
        var idx = vamPending.indexOf(placeholder);
        if (idx === -1) return;
        vamPending[idx] = { file: jpegFile, kind: "image", url: URL.createObjectURL(jpegFile) };
        vamSyncInput();
        vamRender();
      }).catch(function () {
        var idx = vamPending.indexOf(placeholder);
        if (idx === -1) return;
        vamPending[idx] = { file: f, kind: "document", url: null };
        vamRender();
      });
    }
    function vamAddFiles(fileList) {
      var incoming = Array.prototype.slice.call(fileList || []);
      if (!incoming.length) return;
      vamShowError("");
      for (var i = 0; i < incoming.length; i++) {
        if (vamPending.length >= vamRemainingSlots) {
          vamShowError(vamRemainingSlots <= 0 ? "This memory already has the most files it can hold." : "Attach at most " + vamRemainingSlots + " more file" + (vamRemainingSlots === 1 ? "" : "s") + " to this memory.");
          break;
        }
        var f = incoming[i];
        if (f.size > VAM_MAX_BYTES) { vamShowError('"' + f.name + '" is larger than 25MB and was skipped.'); continue; }
        if (vamLooksLikeHeic(f)) { vamAddHeicFile(f); continue; }
        vamAddOrdinaryFile(f);
      }
      vamSyncInput();
      vamRender();
    }
    function vamRemoveAt(idx) {
      var item = vamPending[idx];
      if (item && item.url) URL.revokeObjectURL(item.url);
      vamPending.splice(idx, 1);
      vamSyncInput();
      vamRender();
    }
    function vamReset() {
      vamPending.forEach(function (item) { if (item.url) URL.revokeObjectURL(item.url); });
      vamPending = [];
      vamSyncInput();
      vamShowError("");
      vamShowStatus("");
      vamRender();
    }
    if (vamDropzone) {
      vamDropzone.addEventListener("click", function (e) {
        var removeBtn = e.target.closest(".pick-remove");
        if (removeBtn) {
          e.stopPropagation();
          vamRemoveAt(parseInt(removeBtn.getAttribute("data-pending-idx"), 10));
          return;
        }
        if (e.target.closest(".pick-tile") && !e.target.closest(".pick-tile--add")) return;
        vamInput.click();
      });
      vamDropzone.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); vamInput.click(); }
      });
      ["dragenter", "dragover"].forEach(function (evtName) {
        vamDropzone.addEventListener(evtName, function (e) { e.preventDefault(); e.stopPropagation(); vamDropzone.classList.add("dragover"); });
      });
      ["dragleave", "drop"].forEach(function (evtName) {
        vamDropzone.addEventListener(evtName, function (e) {
          e.preventDefault(); e.stopPropagation();
          if (evtName === "dragleave" && e.target !== vamDropzone) return;
          vamDropzone.classList.remove("dragover");
        });
      });
      vamDropzone.addEventListener("drop", function (e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) vamAddFiles(e.dataTransfer.files);
      });
    }
    if (vamInput) {
      vamInput.addEventListener("change", function () { vamAddFiles(vamInput.files); });
    }
    document.addEventListener("paste", function (e) {
      if (!viewerScrim.classList.contains("open") || viewerAddMediaForm.hidden || !e.clipboardData) return;
      var files = [];
      if (e.clipboardData.files && e.clipboardData.files.length) {
        files = Array.prototype.slice.call(e.clipboardData.files);
      } else if (e.clipboardData.items) {
        for (var i = 0; i < e.clipboardData.items.length; i++) {
          if (e.clipboardData.items[i].kind === "file") {
            var f = e.clipboardData.items[i].getAsFile();
            if (f) files.push(f);
          }
        }
      }
      if (files.length) { e.preventDefault(); vamAddFiles(files); }
    });
    viewerAddMediaForm.addEventListener("submit", function (evt) {
      evt.preventDefault();
      if (vamSubmitting) return;
      if (!vamPending.length) {
        vamShowError("Choose at least one photo, video, or document to add.");
        return;
      }
      vamSubmitting = true;
      vamSubmitBtn.disabled = true;
      vamSubmitBtn.textContent = "Adding\u2026";
      vamShowError("");
      vamShowStatus("");
      var fd = new FormData(viewerAddMediaForm);
      fetch(window.location.href, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          vamSubmitting = false;
          vamSubmitBtn.disabled = false;
          vamSubmitBtn.textContent = "Add media";
          if (!data || !data.ok) {
            vamShowError((data && data.error) || "Something went wrong saving that. Please try again.");
            return;
          }
          var e = findEntry(viewerAddMediaEntryId.value);
          if (e) {
            e.media = (e.media || []).concat(data.media || []);
            viewerMedia.innerHTML = viewerMediaViewHtml(e.media);
            vamRemainingSlots = Math.max(0, 10 - e.media.length);
          }
          vamReset();
          vamShowStatus(data.notice || "Added.");
        })
        .catch(function () {
          vamSubmitting = false;
          vamSubmitBtn.disabled = false;
          vamSubmitBtn.textContent = "Add media";
          vamShowError("Something went wrong saving that. Please try again.");
        });
    });

    function viewerMediaViewHtml(mediaList) {
      var list = mediaList || [];
      if (!list.length) {
        return '<div class="viewer-empty-media"><svg viewBox="0 0 20 20" fill="none"><path d="M4 15.5 8 10l3 3 3-4 2 2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><rect x="2.5" y="3.5" width="15" height="13" rx="2" stroke="currentColor" stroke-width="1.6"/></svg><span>No media added to this memory</span></div>';
      }
      if (list.length === 1) {
        return '<div class="viewer-media-single">' + viewerLargeMediaHtml(list[0]) + '</div>';
      }
      // Square-ish grid: ceil(sqrt(count)) columns, so 2 items is 2x1, 4 is
      // 2x2, 6 is 3x2, 9 is 3x3 — a whole-number column count nearest to a
      // square for any other count in between (e.g. 5 or 7 lands as 3
      // columns too, just with a shorter last row).
      var cols = Math.max(2, Math.ceil(Math.sqrt(list.length)));
      return '<div class="viewer-media-grid" style="--cols:' + cols + '">' + list.map(function (m) {
        return '<a class="viewer-media-grid-tile" href="' + m.url + '" target="_blank" rel="noopener" title="Open full size">' + mediaTileHtml(m) + '</a>';
      }).join("") + '</div>';
    }

    function openViewer(id) {
      var e = findEntry(id);
      if (!e) return;
      viewerMedia.innerHTML = viewerMediaViewHtml(e.media);
      viewerTitleView.textContent = e.title;
      viewerDateView.textContent = fmtDate(new Date(e.date + "T00:00:00"));
      viewerHeaderTitle.textContent = e.type === "diary" ? "Diary entry" : "Memory";
      viewerPillView.innerHTML =
        (e.type === "diary" ? DIARY_PILL_HTML : "") +
        visibilityPillHtml(e.visibility);
      viewerThoughtView.textContent = e.thought || "";

      // Notes family members tagged on this memory have written, read-only
      // — shown regardless of whose timeline it's being viewed from, since
      // it's the same shared memory wherever it appears. Notes with no text
      // yet are skipped here; mine gets its own editable box below instead
      // of appearing twice.
      var notesHtml = (e.tags || [])
        .filter(function (t) { return t.note && t.note.trim() !== ""; })
        .map(function (t) {
          return '<p class="viewer-tag-note"><b>' + escapeHtml(t.name) + ':</b> ' + escapeHtml(t.note) + "</p>";
        }).join("");
      viewerTagNotes.innerHTML = notesHtml;
      viewerTagNotes.hidden = notesHtml === "";

      if (e.iAmTagged) {
        viewerMyNoteForm.hidden = false;
        viewerMyNoteEntryId.value = e.id;
        viewerMyNoteText.value = e.myNote || "";
        viewerAddMediaForm.hidden = false;
        viewerAddMediaEntryId.value = e.id;
        vamRemainingSlots = Math.max(0, 10 - (e.media || []).length);
        vamReset();
      } else {
        viewerMyNoteForm.hidden = true;
        viewerAddMediaForm.hidden = true;
      }

      // Edit/Delete are per-MEMORY, not per-page: a memory shared onto my
      // own timeline that someone else wrote (or that belongs to someone
      // else's unclaimed profile I don't manage) stays theirs to change.
      if (e.canEdit) {
        viewerDeleteForm.hidden = false;
        viewerDeleteEntryId.value = e.id;
        viewerEditLink.hidden = false;
        // Phase 27: edit_entry.php was merged into add_entry.php (an
        // entry_id switches it into edit mode) — linking straight there
        // skips a pointless redirect hop edit_entry.php would otherwise add
        // to every "Edit" click.
        viewerEditLink.href = "/add_entry.php?entry_id=" + encodeURIComponent(e.id);
      } else {
        viewerDeleteForm.hidden = true;
        viewerEditLink.hidden = true;
      }
      viewerScrim.classList.add("open");
    }
    function closeViewer() {
      viewerScrim.classList.remove("open");
    }
    document.getElementById("viewerClose").addEventListener("click", closeViewer);
    document.getElementById("viewerCloseBtn").addEventListener("click", closeViewer);
    viewerScrim.addEventListener("click", function (e) { if (e.target === viewerScrim) closeViewer(); });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && viewerScrim.classList.contains("open")) closeViewer();
    });

    render();
  })();
  </script>

  <script>
  // Phase 69: Memory Planner. Its own IIFE, matching this file's existing
  // "each composer is scoped to its own IIFE, no shared helper functions
  // between them" convention (see the postcard/letter composer and the
  // greeting-card composer further below) -- only openTripPlanner() itself
  // is exposed on window, since renderRail() in the IIFE above needs to
  // call it from a rail card's click handler.
  (function () {
    "use strict";

    var TRIP_VIDEO_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2.5" y="4" width="15" height="12" rx="2"/><path d="M8.3 7.6v4.8l4.4-2.4-4.4-2.4Z" fill="currentColor" stroke="none"/></svg>';
    var TRIP_DOC_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 2.5h6.5L15 6v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1Z" stroke-linejoin="round"/><path d="M11 2.5V6h4" stroke-linejoin="round"/></svg>';
    var TRIP_ADD_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 4v12M4 10h12" stroke-linecap="round"/></svg>';
    var TRIP_MAX_FILES = 10;
    var TRIP_MAX_BYTES = 25 * 1024 * 1024;
    var TRIP_HEIC_RE = /\.(heic|heif)$/i;

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }
    function pad2(n) { return n < 10 ? '0' + n : String(n); }
    function setDateSlots(dayEl, monthEl, yearEl, iso) {
      if (!iso) { dayEl.value = ''; monthEl.value = ''; yearEl.value = ''; return; }
      var parts = iso.split('-');
      yearEl.value = parts[0]; monthEl.value = String(parseInt(parts[1], 10)); dayEl.value = String(parseInt(parts[2], 10));
    }
    function realDate(y, m, d) {
      var dt = new Date(y, m - 1, d);
      return dt.getFullYear() === y && dt.getMonth() === m - 1 && dt.getDate() === d;
    }

    var tripScrim = document.getElementById('tripScrim');
    var tripModalHeading = document.getElementById('tripModalHeading');
    var tripError = document.getElementById('tripError');
    var tripReadonlyNote = document.getElementById('tripReadonlyNote');
    var tripReadonlyOwnerName = document.getElementById('tripReadonlyOwnerName');
    var tripForm = document.getElementById('tripForm');
    var tripPlanIdField = document.getElementById('tripPlanIdField');
    var tripTargetPersonField = document.getElementById('tripTargetPersonField');
    var tripTitleInput = document.getElementById('tripTitleInput');
    var tripStartDay = document.getElementById('tripStartDay'), tripStartMonth = document.getElementById('tripStartMonth'), tripStartYear = document.getElementById('tripStartYear');
    var tripFinishDay = document.getElementById('tripFinishDay'), tripFinishMonth = document.getElementById('tripFinishMonth'), tripFinishYear = document.getElementById('tripFinishYear');
    var tripVisibilityBlock = document.getElementById('tripVisibilityBlock');
    var tripTagField = document.getElementById('tripTagField');
    var tripTagPicker = document.getElementById('tripTagPicker');
    var tripEventsContainer = document.getElementById('tripEventsContainer');
    var tripAddEventBtn = document.getElementById('tripAddEventBtn');
    var tripSaveBtn = document.getElementById('tripSaveBtn');
    var tripDeleteForm = document.getElementById('tripDeleteForm');
    var tripDeleteEntryId = document.getElementById('tripDeleteEntryId');
    var tripCloseBtn = document.getElementById('tripCloseBtn');
    var tripLightbox = document.getElementById('tripLightbox');
    var tripLightboxImg = document.getElementById('tripLightboxImg');
    var tripLightboxClose = document.getElementById('tripLightboxClose');

    var CREATE_TAGGABLE_PEOPLE = JSON.parse(document.getElementById('tripCreateTaggableData').textContent || '[]');
    var CREATE_TARGET_PERSON_ID = <?= (int) $target['id'] ?>;

    var tripEventCounter = 0;
    var readOnlyMode = false;
    var activeTripPicker = null; // last-focused picker's addFiles(), for document-level paste

    function renderTagPicker(people, checkedIds) {
      if (!people.length) {
        tripTagPicker.innerHTML = '<p class="tag-picker-empty">Nobody close enough in the tree yet to tag.</p>';
        return;
      }
      tripTagPicker.innerHTML = people.map(function (p) {
        var checked = checkedIds.indexOf(p.id) !== -1 ? ' checked' : '';
        return '<label><input type="checkbox" name="tag_person_ids[]" value="' + p.id + '"' + checked + (readOnlyMode ? ' disabled' : '') + '> ' +
          escapeHtml(p.name) + (p.unclaimed ? ' <span style="color:var(--ink-faint);">(unclaimed)</span>' : '') + '</label>';
      }).join('');
    }

    // One reusable drag/drop/paste/HEIC-converting picker, instantiated
    // twice per event row (plan + memory) -- generalizes the same pattern
    // add_entry.php's #photoDrop and the memory viewer's vam-uploader
    // above each keep their own single copy of, since the planner can have
    // many of these live on the page (up to 10 events x 2 roles) at once.
    function tripInitPicker(root, existingItems) {
      var empty = root.querySelector('.media-picker-empty');
      var grid = root.querySelector('.media-picker-grid');
      var input = root.querySelector('.trip-picker-input');
      // .trip-kept-inputs is a SIBLING of this picker within their shared
      // .trip-event-col (not a descendant of the picker itself), so it's
      // found from the picker's parent, not from `root` directly.
      var keptContainer = root.parentElement.querySelector('.trip-kept-inputs');
      var keptFieldName = input.getAttribute('data-kept-name');

      var kept = (existingItems || []).slice();
      var pending = [];

      function extLabel(name) { var m = /\.([a-z0-9]+)$/i.exec(name || ''); return m ? m[1].toUpperCase() : 'FILE'; }
      function kindOfFile(file) { return file.type.indexOf('image/') === 0 ? 'image' : (file.type.indexOf('video/') === 0 ? 'video' : 'document'); }
      function existingTileHtml(item) {
        if (item.kind === 'image') return '<img src="' + item.url + '&thumb=1" alt="" loading="lazy" decoding="async" class="trip-zoomable" data-full="' + item.url + '">';
        if (item.kind === 'video') return TRIP_VIDEO_ICON + '<span class="media-tile-badge">Video</span>';
        return TRIP_DOC_ICON + '<span class="media-tile-badge">File</span>';
      }
      function pendingTileHtml(item) {
        if (item.kind === 'converting') return '<span class="tile-spinner" aria-hidden="true"></span><span class="media-tile-name">Converting…</span>';
        if (item.kind === 'image') return '<img src="' + item.url + '" alt="" class="trip-zoomable" data-full="' + item.url + '">';
        return TRIP_DOC_ICON + '<span class="media-tile-name">' + escapeHtml(item.file.name) + '</span><span class="media-tile-badge">' + escapeHtml(extLabel(item.file.name)) + '</span>';
      }
      function syncInput() {
        var dt = new DataTransfer();
        pending.forEach(function (item) { if (item.file) dt.items.add(item.file); });
        input.files = dt.files;
      }
      function syncKeptInputs() {
        keptContainer.innerHTML = kept.map(function (item) {
          return '<input type="hidden" name="' + keptFieldName + '" value="' + item.id + '">';
        }).join('');
      }
      function totalCount() { return kept.length + pending.length; }
      function render() {
        syncKeptInputs();
        if (!totalCount()) {
          empty.hidden = false; grid.hidden = true; grid.innerHTML = '';
          return;
        }
        empty.hidden = true; grid.hidden = false;
        var tiles = kept.map(function (item, i) {
          var cls = item.kind === 'video' ? ' has-video' : (item.kind !== 'image' ? ' has-doc' : '');
          return '<div class="pick-tile' + cls + '" data-existing-idx="' + i + '">' + existingTileHtml(item) +
            (readOnlyMode ? '' : '<button type="button" class="pick-remove" data-existing-idx="' + i + '" aria-label="Remove">×</button>') + '</div>';
        }).join('');
        tiles += pending.map(function (item, i) {
          var cls = item.kind === 'video' ? ' has-video' : (item.kind === 'converting' ? ' has-doc' : (item.kind !== 'image' ? ' has-doc' : ''));
          return '<div class="pick-tile' + cls + '" data-pending-idx="' + i + '">' + pendingTileHtml(item) +
            '<button type="button" class="pick-remove" data-pending-idx="' + i + '" aria-label="Remove">×</button></div>';
        }).join('');
        if (!readOnlyMode && totalCount() < TRIP_MAX_FILES) {
          tiles += '<div class="pick-tile pick-tile--add" data-add="1" title="Add more">' + TRIP_ADD_ICON + '</div>';
        }
        grid.innerHTML = tiles;
      }
      function heicToJpegFile(file) {
        if (typeof heic2any !== 'function') return Promise.reject(new Error('heic2any not available'));
        return heic2any({ blob: file, toType: 'image/jpeg', quality: 0.88 }).then(function (result) {
          var blob = Array.isArray(result) ? result[0] : result;
          var newName = file.name.replace(TRIP_HEIC_RE, '') + '.jpg';
          return new File([blob], newName, { type: 'image/jpeg' });
        });
      }
      function addOrdinaryFile(f) {
        var kind = kindOfFile(f);
        pending.push({ file: f, kind: kind, url: kind === 'image' ? URL.createObjectURL(f) : null });
      }
      function addHeicFile(f) {
        var placeholder = { file: f, kind: 'converting', url: null };
        pending.push(placeholder);
        syncInput(); render();
        heicToJpegFile(f).then(function (jpegFile) {
          var idx = pending.indexOf(placeholder);
          if (idx === -1) return;
          pending[idx] = { file: jpegFile, kind: 'image', url: URL.createObjectURL(jpegFile) };
          syncInput(); render();
        }).catch(function () {
          var idx = pending.indexOf(placeholder);
          if (idx === -1) return;
          pending[idx] = { file: f, kind: 'document', url: null };
          render();
        });
      }
      function addFiles(fileList) {
        if (readOnlyMode) return;
        var incoming = Array.prototype.slice.call(fileList || []);
        if (!incoming.length) return;
        for (var i = 0; i < incoming.length; i++) {
          if (totalCount() >= TRIP_MAX_FILES) break;
          var f = incoming[i];
          if (f.size > TRIP_MAX_BYTES) continue;
          if (TRIP_HEIC_RE.test(f.name || '') || f.type === 'image/heic' || f.type === 'image/heif') { addHeicFile(f); continue; }
          addOrdinaryFile(f);
        }
        syncInput(); render();
      }
      root.addEventListener('click', function (e) {
        var zoomImg = e.target.closest('.trip-zoomable');
        if (zoomImg) { openTripLightbox(zoomImg.getAttribute('data-full')); return; }
        var removeBtn = e.target.closest('.pick-remove');
        if (removeBtn) {
          e.stopPropagation();
          if (removeBtn.hasAttribute('data-existing-idx')) { kept.splice(parseInt(removeBtn.getAttribute('data-existing-idx'), 10), 1); }
          else {
            var pidx = parseInt(removeBtn.getAttribute('data-pending-idx'), 10);
            var item = pending[pidx];
            if (item && item.url) URL.revokeObjectURL(item.url);
            pending.splice(pidx, 1);
            syncInput();
          }
          render();
          return;
        }
        if (readOnlyMode) return;
        if (e.target.closest('.pick-tile') && !e.target.closest('.pick-tile--add')) return;
        input.click();
      });
      root.addEventListener('focus', function () { activeTripPicker = addFiles; }, true);
      root.addEventListener('mouseenter', function () { activeTripPicker = addFiles; });
      if (!readOnlyMode) {
        input.addEventListener('change', function () { addFiles(input.files); });
        ['dragenter', 'dragover'].forEach(function (n) { root.addEventListener(n, function (e) { e.preventDefault(); e.stopPropagation(); root.classList.add('dragover'); }); });
        ['dragleave', 'drop'].forEach(function (n) { root.addEventListener(n, function (e) { e.preventDefault(); e.stopPropagation(); if (n === 'dragleave' && e.target !== root) return; root.classList.remove('dragover'); }); });
        root.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) addFiles(e.dataTransfer.files); });
      }
      render();
    }

    function tripEventRowHtml(index, ev) {
      ev = ev || {};
      var existingId = ev.id || '';
      var title = ev.title || '';
      var iso = ev.eventDate || null;
      var dparts = iso ? iso.split('-') : ['', '', ''];
      var dis = readOnlyMode ? ' disabled' : '';
      return '' +
        '<div class="trip-event" data-event-index="' + index + '">' +
          '<div class="trip-event-head">' +
            '<input type="hidden" name="events[' + index + '][id]" value="' + escapeHtml(String(existingId)) + '">' +
            '<input type="text" class="trip-event-title-input" name="events[' + index + '][title]" maxlength="255" placeholder="Event title (e.g. Flight out)" value="' + escapeHtml(title) + '"' + dis + '>' +
            '<div class="row-3 trip-event-date">' +
              '<div class="date-slot"><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="events[' + index + '][event_day]" placeholder="DD" value="' + escapeHtml(dparts[2] ? String(parseInt(dparts[2], 10)) : '') + '"' + dis + '><span>Day</span></div>' +
              '<div class="date-slot"><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="events[' + index + '][event_month]" placeholder="MM" value="' + escapeHtml(dparts[1] ? String(parseInt(dparts[1], 10)) : '') + '"' + dis + '><span>Month</span></div>' +
              '<div class="date-slot"><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" name="events[' + index + '][event_year]" placeholder="YYYY" value="' + escapeHtml(dparts[0] || '') + '"' + dis + '><span>Year</span></div>' +
            '</div>' +
            (readOnlyMode ? '' : '<button type="button" class="trip-event-remove" aria-label="Remove this event">×</button>') +
          '</div>' +
          '<div class="trip-event-columns">' +
            '<div class="trip-event-col trip-event-plan">' +
              '<h4>Plans</h4>' +
              '<div class="photo-drop media-picker trip-picker" tabindex="0" role="button" aria-label="Attach booking receipts or tickets">' +
                '<div class="media-picker-empty"><div class="thumb"><svg viewBox="0 0 20 20" fill="none"><path d="M4 15.5 8 10l3 3 3-4 2 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><rect x="2.5" y="3.5" width="15" height="13" rx="2" stroke="currentColor" stroke-width="1.8"/></svg></div><div class="copy"><b>Click to attach</b> or drop receipts/tickets here</div></div>' +
                '<div class="media-picker-grid" hidden></div>' +
                '<input type="file" class="trip-picker-input" name="events[' + index + '][plan_media][]" data-kept-name="events[' + index + '][existing_plan_media_ids][]" multiple hidden accept="image/*,.heic,.heif,application/pdf,.pdf">' +
              '</div>' +
              '<div class="trip-kept-inputs"></div>' +
              '<textarea name="events[' + index + '][plan_notes]" placeholder="Scribble notes — confirmation numbers, addresses, times…" rows="4"' + dis + '>' + escapeHtml(ev.planNotes || '') + '</textarea>' +
            '</div>' +
            '<div class="trip-event-col trip-event-memory">' +
              '<h4>Memories</h4>' +
              '<div class="photo-drop media-picker trip-picker" tabindex="0" role="button" aria-label="Attach photos or videos">' +
                '<div class="media-picker-empty"><div class="thumb"><svg viewBox="0 0 20 20" fill="none"><path d="M4 15.5 8 10l3 3 3-4 2 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><rect x="2.5" y="3.5" width="15" height="13" rx="2" stroke="currentColor" stroke-width="1.8"/></svg></div><div class="copy"><b>Click to attach</b> or drop photos/videos here</div></div>' +
                '<div class="media-picker-grid" hidden></div>' +
                '<input type="file" class="trip-picker-input" name="events[' + index + '][memory_media][]" data-kept-name="events[' + index + '][existing_memory_media_ids][]" multiple hidden accept="image/*,.heic,.heif,video/*">' +
              '</div>' +
              '<div class="trip-kept-inputs"></div>' +
              '<textarea name="events[' + index + '][memory_notes]" placeholder="How did it go? Write about it as it happens…" rows="4"' + dis + '>' + escapeHtml(ev.memoryNotes || '') + '</textarea>' +
            '</div>' +
          '</div>' +
        '</div>';
    }

    function tripAddEventRow(ev) {
      var index = tripEventCounter++;
      tripEventsContainer.insertAdjacentHTML('beforeend', tripEventRowHtml(index, ev));
      var rowEl = tripEventsContainer.querySelector('[data-event-index="' + index + '"]');
      var pickers = rowEl.querySelectorAll('.trip-picker');
      tripInitPicker(pickers[0], (ev && ev.planMedia) || []);
      tripInitPicker(pickers[1], (ev && ev.memoryMedia) || []);
    }

    tripEventsContainer.addEventListener('click', function (e) {
      var btn = e.target.closest('.trip-event-remove');
      if (!btn) return;
      var row = btn.closest('.trip-event');
      if (row) row.remove();
      tripSyncEmptyState();
    });
    function tripSyncEmptyState() {
      var existing = tripEventsContainer.querySelector('.trip-empty-events');
      if (existing) existing.remove();
      if (!tripEventsContainer.querySelector('.trip-event') && readOnlyMode) {
        tripEventsContainer.insertAdjacentHTML('beforeend', '<p class="trip-empty-events">No events planned yet.</p>');
      }
    }

    tripAddEventBtn.addEventListener('click', function () { tripAddEventRow(null); });

    function setReadOnly(ro, ownerName) {
      readOnlyMode = ro;
      [tripTitleInput, tripStartDay, tripStartMonth, tripStartYear, tripFinishDay, tripFinishMonth, tripFinishYear].forEach(function (el) { el.disabled = ro; });
      tripVisibilityBlock.querySelectorAll('input').forEach(function (el) { el.disabled = ro; });
      tripTagField.style.display = ro ? 'none' : '';
      tripAddEventBtn.style.display = ro ? 'none' : '';
      tripSaveBtn.style.display = ro ? 'none' : '';
      tripReadonlyNote.hidden = !ro;
      if (ro) tripReadonlyOwnerName.textContent = ownerName || 'its owner';
    }

    function resetTripForm() {
      tripError.hidden = true; tripError.textContent = '';
      tripEventsContainer.innerHTML = '';
      tripEventCounter = 0;
      tripPlanIdField.value = '';
      tripDeleteForm.hidden = true;
      tripTitleInput.value = '';
      setDateSlots(tripStartDay, tripStartMonth, tripStartYear, null);
      setDateSlots(tripFinishDay, tripFinishMonth, tripFinishYear, null);
      tripVisibilityBlock.querySelector('input[value="public"]').checked = true;
    }

    function openTripPlannerNew() {
      resetTripForm();
      setReadOnly(false);
      tripModalHeading.textContent = 'Memory planner — new trip';
      tripTargetPersonField.value = CREATE_TARGET_PERSON_ID;
      renderTagPicker(CREATE_TAGGABLE_PEOPLE, []);
      tripAddEventRow(null);
      tripScrim.classList.add('open');
    }

    window.openTripPlanner = function (tripPlanId) {
      resetTripForm();
      tripModalHeading.textContent = 'Memory planner';
      tripScrim.classList.add('open');
      fetch('/trip_plan.php?action=detail&id=' + encodeURIComponent(tripPlanId), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { closeTripPlanner(); return; }
          tripPlanIdField.value = data.tripPlanId;
          tripTargetPersonField.value = data.targetPersonId;
          tripTitleInput.value = data.title;
          setDateSlots(tripStartDay, tripStartMonth, tripStartYear, data.startDate);
          setDateSlots(tripFinishDay, tripFinishMonth, tripFinishYear, data.finishDate);
          var visInput = tripVisibilityBlock.querySelector('input[value="' + data.visibility + '"]');
          if (visInput) visInput.checked = true;
          setReadOnly(!data.canEdit, data.ownerName);
          if (data.canEdit) renderTagPicker(data.taggablePeople, data.taggedPersonIds);
          tripDeleteForm.hidden = !data.canEdit;
          tripDeleteEntryId.value = data.entryId;
          tripEventsContainer.innerHTML = '';
          tripEventCounter = 0;
          data.events.forEach(function (ev) { tripAddEventRow(ev); });
          tripSyncEmptyState();
        })
        .catch(function () { closeTripPlanner(); });
    };

    function closeTripPlanner() { tripScrim.classList.remove('open'); }
    document.getElementById('tripPlannerOpenBtn') && document.getElementById('tripPlannerOpenBtn').addEventListener('click', openTripPlannerNew);
    document.getElementById('tripModalClose').addEventListener('click', closeTripPlanner);
    tripCloseBtn.addEventListener('click', closeTripPlanner);
    tripScrim.addEventListener('click', function (e) { if (e.target === tripScrim) closeTripPlanner(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && tripLightbox.classList.contains('open')) { closeTripLightbox(); return; }
      if (e.key === 'Escape' && tripScrim.classList.contains('open')) closeTripPlanner();
    });

    function openTripLightbox(url) { tripLightboxImg.src = url; tripLightbox.classList.add('open'); }
    function closeTripLightbox() { tripLightbox.classList.remove('open'); tripLightboxImg.src = ''; }
    tripLightboxClose.addEventListener('click', closeTripLightbox);
    tripLightbox.addEventListener('click', function (e) { if (e.target === tripLightbox) closeTripLightbox(); });

    document.addEventListener('paste', function (e) {
      if (!tripScrim.classList.contains('open') || !activeTripPicker || readOnlyMode) return;
      if (!e.clipboardData) return;
      var files = [];
      if (e.clipboardData.files && e.clipboardData.files.length) files = Array.prototype.slice.call(e.clipboardData.files);
      else if (e.clipboardData.items) {
        for (var i = 0; i < e.clipboardData.items.length; i++) {
          if (e.clipboardData.items[i].kind === 'file') { var f = e.clipboardData.items[i].getAsFile(); if (f) files.push(f); }
        }
      }
      if (files.length) { e.preventDefault(); activeTripPicker(files); }
    });

    tripForm.addEventListener('submit', function (e) {
      var y = parseInt(tripStartYear.value, 10), m = parseInt(tripStartMonth.value, 10), d = parseInt(tripStartDay.value, 10);
      var fy = parseInt(tripFinishYear.value, 10), fm = parseInt(tripFinishMonth.value, 10), fd = parseInt(tripFinishDay.value, 10);
      var msg = null;
      if (tripTitleInput.value.trim() === '') msg = 'Give the trip a title.';
      else if (!tripStartDay.value || !tripStartMonth.value || !tripStartYear.value || !realDate(y, m, d)) msg = 'Enter a real start date.';
      else if (!tripFinishDay.value || !tripFinishMonth.value || !tripFinishYear.value || !realDate(fy, fm, fd)) msg = 'Enter a real finish date.';
      else if (new Date(fy, fm - 1, fd) < new Date(y, m - 1, d)) msg = 'The finish date has to be on or after the start date.';
      if (msg) {
        e.preventDefault();
        tripError.textContent = msg;
        tripError.hidden = false;
        return;
      }
      tripError.hidden = true;
    });

    <?php if ($directOpenTripPlanId !== null): ?>
    window.openTripPlanner(<?= (int) $directOpenTripPlanId ?>);
    <?php endif; ?>
  })();
  </script>

  <template id="postcardComposeTemplate">
    <div class="postcard-overlay" id="postcardComposeOverlay">
      <div class="postcard-box">
        <button type="button" class="postcard-close" id="postcardComposeClose" aria-label="Close">×</button>
        <div class="postcard-mode-panel" id="postcardModePanel">
        <h3 style="margin:0 0 14px;">Send a postcard</h3>
        <?php if (!$postcardRecipientOptions): ?>
          <p class="notice">Nobody else in your family has claimed a profile yet — a postcard needs someone actually signed up to receive it.</p>
        <?php else: ?>
          <p class="letter-switch-text">Need a longer form of message? <button type="button" class="letter-switch-btn" id="switchToLetterBtn">Send a letter instead</button></p>
          <form method="post" action="/postcard.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">

            <div class="postcard-audience">
              <label class="radio-row"><input type="radio" name="audience" value="everyone"> Everyone in my family</label>
              <label class="radio-row"><input type="radio" name="audience" value="selected" checked> Choose people</label>
              <div class="postcard-recipient-list is-open">
                <?php foreach ($postcardRecipientOptions as $opt): ?>
                  <label><input type="checkbox" name="recipient_ids[]" value="<?= (int) $opt['id'] ?>"> <?= htmlspecialchars(person_display_name($opt), ENT_QUOTES) ?></label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="postcard-flip-scene">
              <div class="postcard-flip-inner">
                <div class="postcard-face postcard-face-front">
                  <div class="postcard-photo-mat">
                    <div class="postcard-drop-zone">
                      <svg width="34" height="34" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.6"/><circle cx="8.5" cy="10" r="1.6" stroke="currentColor" stroke-width="1.4"/><path d="M5 16l4.5-4.5 3 3L16 10l3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                      <span>Drop a photo here, or click to choose one</span>
                    </div>
                    <img class="postcard-front-preview" alt="">
                  </div>
                  <button type="button" class="postcard-change-photo">Change photo</button>
                  <button type="button" class="postcard-flip-btn" style="position:absolute;top:8px;right:8px;z-index:1;">Write a message →</button>
                  <input type="file" name="image" class="postcard-image-input" accept="image/*" hidden>
                </div>
                <div class="postcard-face postcard-face-back">
                  <div class="postcard-back-toolbar">
                    <button type="button" class="postcard-flip-btn">← Back to photo</button>
                  </div>
                  <div class="postcard-back-content">
                    <textarea class="postcard-back-message" name="message" placeholder="Write your message here…" maxlength="2000"></textarea>
                    <div class="postcard-back-address">
                      <div class="postcard-stamp" aria-hidden="true">
                      <?= ourthology_postcard_stamp_svg($previewPostmarkAngle, date('d M Y')) ?>
                    </div>
                      <div class="postcard-address-lines">
                        <div class="postcard-address-field">
                          <span class="postcard-address-label">To</span>
                          <span class="postcard-address-input" id="postcardToLine" contenteditable="true" data-placeholder="e.g. Mum, The Smiths…"></span>
                          <input type="hidden" name="to_line" id="postcardToLineField">
                        </div>
                        <div class="postcard-address-field">
                          <span class="postcard-address-label">From</span>
                          <span class="postcard-address-input" id="postcardFromLine" contenteditable="true" data-placeholder="Your name"><?= htmlspecialchars(person_display_name($me), ENT_QUOTES) ?></span>
                          <input type="hidden" name="from_line" id="postcardFromLineField">
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="postcard-back-footer">
                    <label><input type="checkbox" name="record_to_timeline" value="1"> Also add this to my own timeline</label>
                    <button type="submit" class="btn-primary postcard-send-btn">Send</button>
                  </div>
                </div>
              </div>
            </div>
          </form>
        <?php endif; ?>
        </div>

        <?php if ($postcardRecipientOptions): ?>
        <div class="letter-mode-panel" id="letterModePanel">
          <button type="button" class="letter-back-link" id="switchToPostcardBtn">← Back to postcard</button>
          <h3 style="margin:0 0 12px;">Send a letter</h3>
          <form method="post" action="/letter.php" id="letterForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="body_html" id="letterBodyHtmlField">
            <input type="hidden" name="to_line" id="letterToLineField">
            <input type="hidden" name="from_line" id="letterFromLineField">

            <div class="letterhead">
              <svg class="letter-brand-mark" width="34" height="34" viewBox="0 0 32 32" aria-hidden="true">
                <circle cx="16" cy="16" r="15" fill="#FBF8F1"/>
                <path d="M16 7 C10 8 6.3 12.6 7.4 17.2 C11.2 16.5 14.7 12.6 16 7 Z" fill="#9A2A2A"/>
                <path d="M16 7 C22 8 25.7 12.6 24.6 17.2 C20.8 16.5 17.3 12.6 16 7 Z" fill="#9A2A2A"/>
                <line x1="16" y1="7.2" x2="16" y2="17" stroke="#FBF8F1" stroke-width="1" stroke-linecap="round"/>
                <line x1="16" y1="17" x2="16" y2="23.2" stroke="#9A2A2A" stroke-width="2.2" stroke-linecap="round"/>
                <line x1="16" y1="23.2" x2="12.6" y2="26.6" stroke="#9A2A2A" stroke-width="1.6" stroke-linecap="round"/>
                <line x1="16" y1="23.2" x2="19.4" y2="26.6" stroke="#9A2A2A" stroke-width="1.6" stroke-linecap="round"/>
              </svg>
              <div class="letterhead-text">
                <p class="letterhead-word">ourthology<span class="tld">.com</span></p>
                <p class="letterhead-meta">From <?= htmlspecialchars(person_display_name($me), ENT_QUOTES) ?> · <?= date('d F Y') ?></p>
              </div>
            </div>
            <div class="letter-sheet">
              <div class="letter-recipient-row">
                <label for="letterRecipientSelect">To</label>
                <select name="recipient_id" id="letterRecipientSelect" required>
                  <?php foreach ($postcardRecipientOptions as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>" data-first-name="<?= htmlspecialchars((string) $opt['first_name'], ENT_QUOTES) ?>"><?= htmlspecialchars(person_display_name($opt), ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <p class="letter-salutation">Dear <span class="letter-name-editable" id="letterSalutationName" contenteditable="true" data-placeholder="name"><?= htmlspecialchars((string) ($postcardRecipientOptions[0]['first_name'] ?? ''), ENT_QUOTES) ?></span>,</p>
              <div class="letter-toolbar">
                <button type="button" class="letter-insert-photo" id="letterInsertPhotoBtn">Insert a photo</button>
                <input type="file" id="letterImageInput" accept="image/*" hidden>
              </div>
              <div class="letter-body-editor" id="letterBodyEditor" contenteditable="true" data-placeholder="Write your letter here…"></div>
              <p class="letter-closing">Best regards,<br><span class="letter-name-editable" id="letterClosingName" contenteditable="true" data-placeholder="your name"><?= htmlspecialchars(person_display_name($me), ENT_QUOTES) ?></span></p>
              <div class="letter-footer">
                <label><input type="checkbox" name="record_to_timeline" value="1"> Also add this to my own timeline</label>
                <button type="submit" class="btn-primary letter-send-btn">Send letter</button>
              </div>
            </div>
          </form>
          <div class="letter-fly-wrap" id="letterFlyWrap" aria-hidden="true">
            <div class="letter-fly-envelope" id="letterFlyEnvelope">
              <span class="letter-fly-flap" aria-hidden="true"></span>
              <span class="letter-fly-stamp" aria-hidden="true"><?= ourthology_postcard_stamp_svg($previewPostmarkAngle, date('d M Y')) ?></span>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </template>
  <script>
    // Phase 48: compose pop-up -- cloned from the <template> above (its
    // recipient checkboxes are already server-rendered, so there's no
    // client-side HTML building or JSON payload needed) and wired up the
    // same way tree.php's invite-draft pop-up is: overlay-click / × /
    // Escape all close it, appended fresh each open so state never lingers
    // between opens.
    (function () {
      var openBtn = document.getElementById("sendPostcardBtn");
      var tpl = document.getElementById("postcardComposeTemplate");
      if (!openBtn || !tpl) return;

      function closeOverlay() {
        var existing = document.getElementById("postcardComposeOverlay");
        if (existing) existing.remove();
        document.removeEventListener("keydown", onEscape);
      }
      function onEscape(evt) {
        if (evt.key !== "Escape") return;
        var existing = document.getElementById("postcardComposeOverlay");
        if (existing && existing.dataset.locked === "1") return; // mid send-animation -- ignore
        closeOverlay();
      }

      // Phase 61: called the instant either form's real submit is
      // intercepted -- stops the × / overlay-click / Escape handlers
      // from tearing the overlay down out from under the animation
      // (closeOverlay() removes it from the DOM, which would kill the
      // in-flight timers below), and blocks any further clicks inside
      // the box while it's mid-toss.
      function lockOverlayForSend(root) {
        root.dataset.locked = "1";
        var box = root.querySelector(".postcard-box");
        if (box) box.classList.add("is-sending");
      }

      function wireForm(root) {
        var audienceRadios = root.querySelectorAll('input[name="audience"]');
        var recipientList = root.querySelector(".postcard-recipient-list");
        audienceRadios.forEach(function (r) {
          r.addEventListener("change", function () {
            if (recipientList) recipientList.classList.toggle("is-open", r.value === "selected" && r.checked);
          });
        });

        var fileInput = root.querySelector(".postcard-image-input");
        var frontFace = root.querySelector(".postcard-face-front");
        var previewImg = root.querySelector(".postcard-front-preview");
        var dropZone = root.querySelector(".postcard-drop-zone");
        var changeBtn = root.querySelector(".postcard-change-photo");
        if (fileInput && frontFace && previewImg && dropZone) {
          function showPreview(files) {
            if (!files || !files[0] || files[0].type.indexOf("image/") !== 0) return;
            var reader = new FileReader();
            reader.onload = function (e) {
              previewImg.src = e.target.result;
              frontFace.classList.add("has-image");
            };
            reader.readAsDataURL(files[0]);
          }
          dropZone.addEventListener("click", function () { fileInput.click(); });
          if (changeBtn) changeBtn.addEventListener("click", function (evt) { evt.stopPropagation(); fileInput.click(); });
          fileInput.addEventListener("change", function () { showPreview(fileInput.files); });
          ["dragover", "dragenter"].forEach(function (evtName) {
            frontFace.addEventListener(evtName, function (evt) {
              evt.preventDefault();
              dropZone.classList.add("is-dragover");
            });
          });
          ["dragleave", "dragend"].forEach(function (evtName) {
            frontFace.addEventListener(evtName, function () { dropZone.classList.remove("is-dragover"); });
          });
          frontFace.addEventListener("drop", function (evt) {
            evt.preventDefault();
            dropZone.classList.remove("is-dragover");
            var files = evt.dataTransfer ? evt.dataTransfer.files : null;
            if (files && files[0]) {
              try {
                var dt = new DataTransfer();
                dt.items.add(files[0]);
                fileInput.files = dt.files;
              } catch (e) { /* older browser -- preview still shows, the drop just won't carry into the form submit */ }
              showPreview(files);
            }
          });
        }

        var flipInner = root.querySelector(".postcard-flip-inner");
        if (flipInner) {
          root.querySelectorAll(".postcard-flip-btn").forEach(function (btn) {
            btn.addEventListener("click", function () { flipInner.classList.toggle("is-flipped"); });
          });
        }

        // Phase 55: To/From are contenteditable spans now (see the CSS
        // comment above .postcard-address-field for why) -- keep them
        // acting like a plain single-line <input> (no Enter-inserted
        // line breaks, paste drops formatting) and copy their text into
        // the real to_line/from_line hidden fields the form actually
        // posts.
        var postcardForm = root.querySelector("#postcardModePanel form");
        var toLineEditable = root.querySelector("#postcardToLine");
        var fromLineEditable = root.querySelector("#postcardFromLine");
        var toLineField = root.querySelector("#postcardToLineField");
        var fromLineField = root.querySelector("#postcardFromLineField");
        [toLineEditable, fromLineEditable].forEach(function (el) {
          if (!el) return;
          el.addEventListener("keydown", function (evt) {
            if (evt.key === "Enter") evt.preventDefault();
          });
          el.addEventListener("paste", function (evt) {
            evt.preventDefault();
            var text = (evt.clipboardData || window.clipboardData).getData("text/plain");
            document.execCommand("insertText", false, text);
          });
        });
        if (postcardForm) {
          // Phase 61: "fly off the screen" then land back on timeline.php
          // -- postcards have no envelope to fold into, so this skips
          // straight to the toss. evt.preventDefault() here is safe to
          // call unconditionally: the later postcardForm.submit() call
          // is the plain DOM method, which (unlike requestSubmit()) never
          // re-fires this "submit" listener, so there's no risk of this
          // handler looping back on itself.
          postcardForm.addEventListener("submit", function (evt) {
            if (toLineField) toLineField.value = (toLineEditable ? toLineEditable.textContent : "").trim();
            if (fromLineField) fromLineField.value = (fromLineEditable ? fromLineEditable.textContent : "").trim();

            evt.preventDefault();
            lockOverlayForSend(root);
            var sendBtn = postcardForm.querySelector(".postcard-send-btn");
            if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = "Sending…"; }
            var flyTarget = root.querySelector(".postcard-flip-scene") || postcardForm;
            flyTarget.classList.add("is-flying");
            window.setTimeout(function () { postcardForm.submit(); }, 620);
          });
        }

        // Phase 53: "need a longer form of message? send a letter
        // instead" -- swaps which panel is visible inside the SAME
        // pop-up rather than opening a second one, so the × / overlay-
        // click / Escape close handling already wired up above just
        // keeps working unchanged.
        var postcardPanel = root.querySelector("#postcardModePanel");
        var letterPanel = root.querySelector("#letterModePanel");
        var toLetterBtn = root.querySelector("#switchToLetterBtn");
        var toPostcardBtn = root.querySelector("#switchToPostcardBtn");
        if (toLetterBtn && letterPanel && postcardPanel) {
          toLetterBtn.addEventListener("click", function () {
            postcardPanel.style.display = "none";
            letterPanel.style.display = "block";
          });
        }
        if (toPostcardBtn && letterPanel && postcardPanel) {
          toPostcardBtn.addEventListener("click", function () {
            letterPanel.style.display = "none";
            postcardPanel.style.display = "";
          });
        }
      }

      // Phase 53: the letter composer -- a recipient <select> that fills
      // in "Dear X,", a contenteditable body an "Insert a photo" button
      // can drop images into at the caret (uploaded immediately via
      // fetch() to letter_image_upload.php, well before the letter
      // itself is ever sent -- see that file's own doc comment), and a
      // submit handler that copies the editor's HTML into the hidden
      // body_html field the form actually posts.
      function wireLetterForm(root) {
        var form = root.querySelector("#letterForm");
        var select = root.querySelector("#letterRecipientSelect");
        var salutationName = root.querySelector("#letterSalutationName");
        var closingName = root.querySelector("#letterClosingName");
        var toLineField = root.querySelector("#letterToLineField");
        var fromLineField = root.querySelector("#letterFromLineField");
        var editor = root.querySelector("#letterBodyEditor");
        var hiddenBody = root.querySelector("#letterBodyHtmlField");
        var insertBtn = root.querySelector("#letterInsertPhotoBtn");
        var imageInput = root.querySelector("#letterImageInput");
        var csrfInput = form ? form.querySelector('input[name="csrf_token"]') : null;
        if (!form || !select || !editor) return;

        // Phase 55: the recipient picker still auto-fills "Dear X," when
        // it changes -- but only until the sender actually types their
        // own shortened name in there, otherwise switching recipients
        // would silently overwrite an edit they just made.
        var salutationTouched = false;
        function updateSalutation() {
          if (salutationTouched || !salutationName) return;
          var opt = select.options[select.selectedIndex];
          var first = opt ? opt.getAttribute("data-first-name") : "";
          salutationName.textContent = first || "there";
        }
        select.addEventListener("change", updateSalutation);
        updateSalutation();

        // Same contenteditable-as-a-plain-single-line-field treatment the
        // postcard's To/From fields use (see wireForm() above): no
        // Enter-inserted line breaks, paste drops formatting.
        [salutationName, closingName].forEach(function (el) {
          if (!el) return;
          el.addEventListener("keydown", function (evt) {
            if (evt.key === "Enter") evt.preventDefault();
          });
          el.addEventListener("paste", function (evt) {
            evt.preventDefault();
            var text = (evt.clipboardData || window.clipboardData).getData("text/plain");
            document.execCommand("insertText", false, text);
          });
        });
        if (salutationName) {
          salutationName.addEventListener("input", function () { salutationTouched = true; });
        }

        var savedRange = null;
        function saveSelectionIfInEditor() {
          var sel = window.getSelection();
          if (sel && sel.rangeCount > 0 && editor.contains(sel.getRangeAt(0).commonAncestorContainer)) {
            savedRange = sel.getRangeAt(0).cloneRange();
          } else {
            savedRange = null;
          }
        }
        function restoreSelectionOrEnd() {
          var sel = window.getSelection();
          sel.removeAllRanges();
          var range = savedRange;
          if (!range) {
            range = document.createRange();
            range.selectNodeContents(editor);
            range.collapse(false);
          }
          sel.addRange(range);
        }

        if (insertBtn && imageInput) {
          insertBtn.addEventListener("click", function () {
            saveSelectionIfInEditor();
            imageInput.click();
          });
          imageInput.addEventListener("change", function () {
            var file = imageInput.files && imageInput.files[0];
            if (!file) return;
            var fd = new FormData();
            fd.append("image", file);
            fd.append("csrf_token", csrfInput ? csrfInput.value : "");
            insertBtn.disabled = true;
            insertBtn.textContent = "Uploading…";
            fetch("/letter_image_upload.php", { method: "POST", body: fd })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                insertBtn.disabled = false;
                insertBtn.textContent = "Insert a photo";
                imageInput.value = "";
                if (!data || !data.ok) {
                  alert((data && data.error) || "Could not upload that photo — please try again.");
                  return;
                }
                editor.focus();
                restoreSelectionOrEnd();
                var ok = false;
                try { ok = document.execCommand("insertHTML", false, '<img src="' + data.url + '">'); } catch (e) { ok = false; }
                if (!ok) {
                  // Fallback for a browser without execCommand support --
                  // appends at the end rather than at the caret, which is
                  // still a correct, working result, just less precise.
                  var img = document.createElement("img");
                  img.src = data.url;
                  editor.appendChild(img);
                }
              })
              .catch(function () {
                insertBtn.disabled = false;
                insertBtn.textContent = "Insert a photo";
                alert("Could not upload that photo — please try again.");
              });
          });
        }

        form.addEventListener("submit", function (evt) {
          if (hiddenBody) hiddenBody.value = editor.innerHTML;
          if (toLineField) toLineField.value = (salutationName ? salutationName.textContent : "").trim();
          if (fromLineField) fromLineField.value = (closingName ? closingName.textContent : "").trim();

          // Phase 61: "folds the page up and puts it into the envelope,
          // and then flies it off the screen ... and then returns the
          // user to the timeline page." Three stages, each timed to the
          // CSS animation it kicks off: fold the letter sheet flat
          // (0.4s), pop it into the little envelope (0.18s), then toss
          // the envelope off screen (0.62s) -- ~1.2s all told, before
          // the real, unmodified letter.php submit finally happens (see
          // the postcard handler's comment on why form.submit() here is
          // safe from re-triggering this same listener).
          evt.preventDefault();
          lockOverlayForSend(root);
          var sendBtn = form.querySelector(".letter-send-btn");
          if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = "Sending…"; }

          form.classList.add("is-folding");
          window.setTimeout(function () {
            form.style.display = "none";
            var wrap = root.querySelector("#letterFlyWrap");
            var env = root.querySelector("#letterFlyEnvelope");
            if (wrap) wrap.style.display = "flex";
            if (env) env.classList.add("is-popping");
            window.setTimeout(function () {
              if (env) env.classList.add("is-flying");
              window.setTimeout(function () { form.submit(); }, 620);
            }, 180);
          }, 400);
        });
      }

      openBtn.addEventListener("click", function () {
        closeOverlay();
        document.body.appendChild(tpl.content.cloneNode(true));
        var overlay = document.getElementById("postcardComposeOverlay");
        overlay.addEventListener("click", function (evt) {
          if (overlay.dataset.locked === "1") return;
          if (evt.target === overlay) closeOverlay();
        });
        document.getElementById("postcardComposeClose").addEventListener("click", function () {
          if (overlay.dataset.locked === "1") return;
          closeOverlay();
        });
        document.addEventListener("keydown", onEscape);
        wireForm(overlay);
        wireLetterForm(overlay);
      });
    })();
  </script>

  <template id="cardComposeTemplate">
    <div class="gcard-overlay" id="gcardComposeOverlay">
      <div class="gcard-box" id="gcardBox">
        <button type="button" class="gcard-close" id="gcardComposeClose" aria-label="Close">×</button>
        <h3 class="gcard-title" id="gcardTitle">Send a card</h3>
        <form method="post" action="/card.php" enctype="multipart/form-data" id="gcardForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="recipient_id" id="gcardRecipientId" value="">
          <input type="hidden" name="event_id" id="gcardEventIdField" value="">
          <input type="hidden" name="cover_message" id="gcardCoverMessageField">
          <input type="hidden" name="to_line" id="gcardToLineField">
          <input type="hidden" name="greeting_line" id="gcardGreetingLineField">
          <input type="hidden" name="message" id="gcardMessageField">
          <input type="hidden" name="from_line" id="gcardFromLineField">

          <!-- Phase 68: a key-date card has no single implied recipient
               (unlike a birthday card, where opening the composer already
               says who it's for) -- shown only for that case; occasion/
               deliver_on are computed server-side from the event itself
               either way, this picker is purely about who receives it. -->
          <div class="gcard-recipient-picker" id="gcardRecipientPicker" style="display:none;">
            <label for="gcardRecipientSelect">Who's this for?</label>
            <select id="gcardRecipientSelect">
              <option value="">Choose a person…</option>
              <?php foreach ($postcardRecipientOptions as $opt): ?>
                <option value="<?= (int) $opt['id'] ?>" data-first-name="<?= htmlspecialchars((string) $opt['first_name'], ENT_QUOTES) ?>"><?= htmlspecialchars(person_display_name($opt), ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="gcard-scene" id="gcardScene">
            <div class="gcard-cover" id="gcardCover">
              <div class="gcard-cover-face gcard-cover-front" id="gcardCoverFront">
                <div class="gcard-drop-zone">
                  <svg width="34" height="34" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.6"/><circle cx="8.5" cy="10" r="1.6" stroke="currentColor" stroke-width="1.4"/><path d="M5 16l4.5-4.5 3 3L16 10l3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  <span>Drop a photo here, paste one, or click to choose one</span>
                </div>
                <img class="gcard-front-preview" alt="">
                <button type="button" class="gcard-change-photo">Change photo</button>
                <input type="file" name="image" class="gcard-image-input" accept="image/*,.heic,.heif" hidden>
                <div class="gcard-cover-message">
                  <span class="gcard-cover-message-text" id="gcardCoverMessageText" contenteditable="true" data-placeholder="Happy Birthday!"></span>
                </div>
              </div>
              <div class="gcard-cover-face gcard-cover-back" aria-hidden="true"></div>
            </div>
            <div class="gcard-inside">
              <div class="gcard-field">
                <span class="gcard-field-label">To.........</span>
                <span class="gcard-field-input" id="gcardToLine" contenteditable="true" data-placeholder="name"></span>
              </div>
              <div class="gcard-field">
                <span class="gcard-field-label">Greeting.......</span>
                <span class="gcard-field-input" id="gcardGreetingLine" contenteditable="true" data-placeholder="Happy Birthday"></span>
              </div>
              <div class="gcard-field gcard-field-message">
                <span class="gcard-field-label">Message.......</span>
                <div class="gcard-field-input gcard-message-input" id="gcardMessage" contenteditable="true" data-placeholder="Write your personal message here…"></div>
              </div>
              <div class="gcard-field">
                <span class="gcard-field-input gcard-closing-input" id="gcardClosing" contenteditable="true" data-placeholder="lots of love, …"></span>
              </div>
            </div>
          </div>

          <div class="gcard-front-footer" id="gcardFrontFooter">
            <button type="button" class="btn-primary gcard-open-btn" id="gcardOpenBtn">Add your message</button>
          </div>
          <div class="gcard-inside-footer" id="gcardInsideFooter" style="display:none;">
            <button type="button" class="gcard-back-link" id="gcardBackLink">← Back to the cover</button>
            <button type="submit" class="btn-primary gcard-send-btn">Send</button>
          </div>
        </form>

        <div class="gcard-envelope-wrap" id="gcardEnvelopeWrap" aria-hidden="true">
          <div class="gcard-envelope" id="gcardEnvelope">
            <span class="gcard-envelope-flap" aria-hidden="true"></span>
            <span class="gcard-envelope-stamp" aria-hidden="true"><?= ourthology_postcard_stamp_svg($previewPostmarkAngle, date('d M Y')) ?></span>
          </div>
        </div>
      </div>
    </div>
  </template>
  <script>
    // Phase 67: the greeting-card composer -- opened from one of the
    // birthday banner's own "Send a card" buttons (each already carrying
    // its recipient's id/name/defaults as data-* attributes -- see the
    // PHP loop over $birthdayCardRows above), not from a single fixed
    // trigger button the way the postcard/letter composer is. Cloned
    // fresh from <template id="cardComposeTemplate"> on every open, same
    // "nothing lingers between opens" convention as that composer.
    (function () {
      var tpl = document.getElementById("cardComposeTemplate");
      if (!tpl) return;
      var myFirstName = <?= json_encode($myFirstName, JSON_UNESCAPED_SLASHES) ?>;

      function closeOverlay() {
        var existing = document.getElementById("gcardComposeOverlay");
        if (existing) existing.remove();
        document.removeEventListener("keydown", onEscape);
      }
      function onEscape(evt) {
        if (evt.key !== "Escape") return;
        var existing = document.getElementById("gcardComposeOverlay");
        if (existing && existing.dataset.locked === "1") return; // mid send-animation -- ignore
        closeOverlay();
      }
      function lockOverlayForSend(root) {
        root.dataset.locked = "1";
        var box = root.querySelector(".gcard-box");
        if (box) box.classList.add("is-sending");
      }

      // Every editable field pastes as plain text (never rich HTML/
      // formatting off the clipboard); the four single-line ones
      // (cover message, To, Greeting, closing) additionally swallow
      // Enter so they can't grow a line break -- same treatment the
      // postcard/letter composer's own To/From/salutation fields get
      // above. The message field allows Enter (a real multi-line note).
      function pasteAsPlainText(el) {
        if (!el) return;
        el.addEventListener("paste", function (evt) {
          evt.preventDefault();
          var text = (evt.clipboardData || window.clipboardData).getData("text/plain");
          document.execCommand("insertText", false, text);
        });
      }
      function preventEnter(el) {
        if (!el) return;
        el.addEventListener("keydown", function (evt) {
          if (evt.key === "Enter") evt.preventDefault();
        });
      }

      // A phone's own camera photo is very often HEIC (iPhone) -- a format
      // no Chromium/Firefox <img> can actually decode. Server-side, that's
      // already handled (store_postcard_image() in includes/media.php
      // converts it on the way in), but the client-side preview here used
      // to just hand a HEIC file straight to FileReader/<img>, which
      // "succeeds" (has-image gets added, hiding the drop zone) while the
      // <img> itself silently renders nothing -- exactly the "I drop or
      // select a photo and it doesn't show up" report this fixes. Same
      // heic2any-based conversion the "add a memory" uploader already uses
      // (see vamHeicToJpegFile() above), duplicated locally rather than
      // shared -- this composer is deliberately self-contained in its own
      // IIFE, same as the postcard/letter composers.
      var GCARD_HEIC_RE = /\.(heic|heif)$/i;
      function gcardLooksLikeHeic(file) {
        return GCARD_HEIC_RE.test(file.name || "") || file.type === "image/heic" || file.type === "image/heif";
      }
      function gcardHeicToJpegFile(file) {
        if (typeof heic2any !== "function") return Promise.reject(new Error("heic2any not available"));
        return heic2any({ blob: file, toType: "image/jpeg", quality: 0.88 }).then(function (result) {
          var blob = Array.isArray(result) ? result[0] : result;
          var newName = file.name.replace(GCARD_HEIC_RE, "") + ".jpg";
          return new File([blob], newName, { type: "image/jpeg" });
        });
      }

      function wireImagePicker(root) {
        var fileInput = root.querySelector(".gcard-image-input");
        var frontFace = root.querySelector("#gcardCoverFront");
        var previewImg = root.querySelector(".gcard-front-preview");
        var dropZone = root.querySelector(".gcard-drop-zone");
        var dropZoneLabel = dropZone ? dropZone.querySelector("span") : null;
        var dropZoneLabelDefault = dropZoneLabel ? dropZoneLabel.textContent : "";
        var changeBtn = root.querySelector(".gcard-change-photo");
        if (!fileInput || !frontFace || !previewImg || !dropZone) return;

        function setDropZoneLabel(text) {
          if (dropZoneLabel) dropZoneLabel.textContent = text;
        }
        function showPreview(files) {
          if (!files || !files[0] || files[0].type.indexOf("image/") !== 0) return;
          var reader = new FileReader();
          reader.onload = function (e) {
            previewImg.src = e.target.result;
            frontFace.classList.add("has-image");
          };
          reader.readAsDataURL(files[0]);
        }
        function setFile(file, skipPreview) {
          if (!file) return;
          try {
            var dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
          } catch (e) { /* older browser -- preview still shows, it just won't carry into the submit */ }
          if (!skipPreview) showPreview([file]);
        }

        function handleIncomingFile(file) {
          if (!file) return;
          if (!gcardLooksLikeHeic(file)) {
            setDropZoneLabel(dropZoneLabelDefault);
            setFile(file);
            return;
          }
          setDropZoneLabel("Converting your photo…");
          gcardHeicToJpegFile(file).then(function (jpegFile) {
            setDropZoneLabel(dropZoneLabelDefault);
            setFile(jpegFile);
          }).catch(function () {
            // Couldn't convert it here to preview it -- it still gets
            // attached and will convert fine server-side when the card's
            // actually sent (same store_postcard_image() path a postcard's
            // photo goes through), there's just nothing to show for it
            // in this pop-up in the meantime.
            setFile(file, true);
            setDropZoneLabel("Photo attached — this one can't preview here, but it'll look right once the card's sent.");
          });
        }

        dropZone.addEventListener("click", function () { fileInput.click(); });
        if (changeBtn) changeBtn.addEventListener("click", function (evt) { evt.stopPropagation(); fileInput.click(); });
        fileInput.addEventListener("change", function () { handleIncomingFile(fileInput.files && fileInput.files[0]); });
        ["dragover", "dragenter"].forEach(function (evtName) {
          frontFace.addEventListener(evtName, function (evt) { evt.preventDefault(); dropZone.classList.add("is-dragover"); });
        });
        ["dragleave", "dragend"].forEach(function (evtName) {
          frontFace.addEventListener(evtName, function () { dropZone.classList.remove("is-dragover"); });
        });
        frontFace.addEventListener("drop", function (evt) {
          evt.preventDefault();
          dropZone.classList.remove("is-dragover");
          var files = evt.dataTransfer ? evt.dataTransfer.files : null;
          if (files && files[0]) handleIncomingFile(files[0]);
        });

        // "the user should be able to drag, paste or select, just like
        // when they create a postcard" -- a clipboard image paste
        // anywhere in the pop-up drops straight onto the front photo.
        // Bubbles up from whichever field (if any) currently has focus,
        // so this only ever fires once per paste regardless of where the
        // cursor is.
        root.addEventListener("paste", function (evt) {
          var items = (evt.clipboardData || window.clipboardData || {}).items;
          if (!items) return;
          for (var i = 0; i < items.length; i++) {
            if (items[i].type && items[i].type.indexOf("image/") === 0) {
              var file = items[i].getAsFile();
              if (file) {
                evt.preventDefault();
                handleIncomingFile(file);
              }
              break;
            }
          }
        });
      }

      // "Make it so this is the first thing the creator sees and add a
      // button that says 'Add your message'. When they click that
      // button, animate the opening of the card" -- the cover is hinged
      // on its left edge (see the CSS) so opening it reads as a book/
      // card cover swinging open rather than a symmetric flip, revealing
      // the inside spread that was sitting there underneath the whole
      // time.
      function wireOpening(root) {
        var cover = root.querySelector("#gcardCover");
        var openBtn = root.querySelector("#gcardOpenBtn");
        var backLink = root.querySelector("#gcardBackLink");
        var frontFooter = root.querySelector("#gcardFrontFooter");
        var insideFooter = root.querySelector("#gcardInsideFooter");
        var toLine = root.querySelector("#gcardToLine");

        function openCard() {
          if (!cover) return;
          cover.classList.add("is-open");
          if (frontFooter) frontFooter.style.display = "none";
          if (insideFooter) insideFooter.style.display = "flex";
          window.setTimeout(function () { if (toLine) toLine.focus(); }, 520);
        }
        function closeCard() {
          if (!cover) return;
          cover.classList.remove("is-open");
          if (insideFooter) insideFooter.style.display = "none";
          if (frontFooter) frontFooter.style.display = "flex";
        }
        if (openBtn) openBtn.addEventListener("click", openCard);
        if (backLink) backLink.addEventListener("click", closeCard);
      }

      // "allow the user to send it and animate the close of the card
      // with the card being added to an envelope ... animate it going
      // off to the recipient ... with more of a flourish." Four stages,
      // each timed to the CSS animation/transition it kicks off: swing
      // the cover shut (reusing its own open/close transition, 0.5s),
      // fold the whole scene down flat (0.45s), pop it into the
      // envelope (0.32s), then toss the envelope off screen with extra
      // wobble (0.85s) -- before the real, unmodified card.php submit
      // finally happens. See the postcard/letter handlers' own comments
      // for why evt.preventDefault() + the later form.submit() (the
      // plain DOM method) is safe from re-triggering this listener.
      function wireSend(root) {
        var form = root.querySelector("#gcardForm");
        if (!form) return;
        var coverText = root.querySelector("#gcardCoverMessageText");
        var toLine = root.querySelector("#gcardToLine");
        var greetingLine = root.querySelector("#gcardGreetingLine");
        var message = root.querySelector("#gcardMessage");
        var closing = root.querySelector("#gcardClosing");

        form.addEventListener("submit", function (evt) {
          var coverField = root.querySelector("#gcardCoverMessageField");
          var toField = root.querySelector("#gcardToLineField");
          var greetField = root.querySelector("#gcardGreetingLineField");
          var msgField = root.querySelector("#gcardMessageField");
          var fromField = root.querySelector("#gcardFromLineField");
          if (coverField) coverField.value = (coverText ? coverText.textContent : "").trim();
          if (toField) toField.value = (toLine ? toLine.textContent : "").trim();
          if (greetField) greetField.value = (greetingLine ? greetingLine.textContent : "").trim();
          if (msgField) msgField.value = message ? (message.innerText || message.textContent || "").trim() : "";
          if (fromField) fromField.value = (closing ? closing.textContent : "").trim();

          evt.preventDefault();
          lockOverlayForSend(root);
          var sendBtn = form.querySelector(".gcard-send-btn");
          if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = "Sending…"; }

          var cover = root.querySelector("#gcardCover");
          var scene = root.querySelector("#gcardScene");
          if (cover) cover.classList.remove("is-open");
          window.setTimeout(function () {
            if (scene) scene.classList.add("is-folding");
            window.setTimeout(function () {
              if (scene) scene.style.display = "none";
              root.querySelectorAll("#gcardFrontFooter, #gcardInsideFooter").forEach(function (f) { f.style.display = "none"; });
              var wrap = root.querySelector("#gcardEnvelopeWrap");
              var env = root.querySelector("#gcardEnvelope");
              if (wrap) wrap.style.display = "flex";
              if (env) env.classList.add("is-popping");
              window.setTimeout(function () {
                if (env) env.classList.add("is-flying");
                window.setTimeout(function () { form.submit(); }, 850);
              }, 320);
            }, 450);
          }, 520);
        });
      }

      // Phase 68: a key-date card has no implied recipient (unlike a
      // birthday card, where opening the composer already says who it's
      // for) -- #gcardRecipientPicker is shown only for that case, and
      // "Add your message" stays disabled until a person's actually
      // chosen there. Mirrors the letter composer's own
      // salutationTouched pattern above: the To......... field still
      // auto-fills from whoever's picked, but only until the sender
      // types their own text in there.
      function wireRecipientPicker(root) {
        var select = root.querySelector("#gcardRecipientSelect");
        var recipientField = root.querySelector("#gcardRecipientId");
        var toLine = root.querySelector("#gcardToLine");
        var openBtn = root.querySelector("#gcardOpenBtn");
        if (!select) return;

        var toLineTouched = false;
        if (toLine) {
          toLine.addEventListener("input", function () { toLineTouched = true; });
        }

        select.addEventListener("change", function () {
          var opt = select.options[select.selectedIndex];
          var chosenId = opt ? opt.value : "";
          if (recipientField) recipientField.value = chosenId;
          if (toLine && !toLineTouched) {
            toLine.textContent = opt ? (opt.getAttribute("data-first-name") || "") : "";
          }
          if (openBtn) openBtn.disabled = chosenId === "";
        });
      }

      window.ourthologyOpenCardComposer = function (data) {
        closeOverlay();
        document.body.appendChild(tpl.content.cloneNode(true));
        var overlay = document.getElementById("gcardComposeOverlay");
        if (!overlay) return;
        overlay.addEventListener("click", function (evt) {
          if (overlay.dataset.locked === "1") return;
          if (evt.target === overlay) closeOverlay();
        });
        document.getElementById("gcardComposeClose").addEventListener("click", function () {
          if (overlay.dataset.locked === "1") return;
          closeOverlay();
        });
        document.addEventListener("keydown", onEscape);

        var isKeyDate = data.kind === "key_date";

        var recipientField = document.getElementById("gcardRecipientId");
        if (recipientField) recipientField.value = isKeyDate ? "" : (data.personId || "");
        var eventField = document.getElementById("gcardEventIdField");
        if (eventField) eventField.value = isKeyDate ? (data.eventId || "") : "";
        var title = document.getElementById("gcardTitle");
        if (title && data.name) {
          title.textContent = isKeyDate ? ("Send a card for " + data.name) : ("Send " + data.name + " a card");
        }

        // A key date isn't "about" any one person, so nothing here is
        // picked yet -- the picker shows and "Add your message" starts
        // disabled; wireRecipientPicker() re-enables it once a recipient
        // is actually chosen.
        var picker = document.getElementById("gcardRecipientPicker");
        if (picker) picker.style.display = isKeyDate ? "" : "none";
        var select = document.getElementById("gcardRecipientSelect");
        if (select) select.value = "";
        var openBtn = document.getElementById("gcardOpenBtn");
        if (openBtn) openBtn.disabled = isKeyDate;

        var coverText = document.getElementById("gcardCoverMessageText");
        if (coverText) coverText.textContent = data.coverDefault || "Happy Birthday!";
        var toLine = document.getElementById("gcardToLine");
        if (toLine) toLine.textContent = isKeyDate ? "" : (data.firstName || "");
        var greetingLine = document.getElementById("gcardGreetingLine");
        if (greetingLine) greetingLine.textContent = data.greetingDefault || "Happy Birthday";
        var message = document.getElementById("gcardMessage");
        var closing = document.getElementById("gcardClosing");
        if (closing) closing.textContent = myFirstName ? ("lots of love, " + myFirstName) : "";

        [coverText, toLine, greetingLine, closing].forEach(preventEnter);
        [coverText, toLine, greetingLine, message, closing].forEach(pasteAsPlainText);

        wireImagePicker(overlay);
        wireOpening(overlay);
        wireRecipientPicker(overlay);
        wireSend(overlay);
      };

      document.querySelectorAll(".birthday-send-card-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
          window.ourthologyOpenCardComposer({
            kind: btn.getAttribute("data-kind"),
            personId: btn.getAttribute("data-person-id"),
            eventId: btn.getAttribute("data-event-id"),
            firstName: btn.getAttribute("data-first-name"),
            name: btn.getAttribute("data-name"),
            coverDefault: btn.getAttribute("data-cover-default"),
            greetingDefault: btn.getAttribute("data-greeting-default")
          });
        });
      });

      // Phase 68: tree.php's and calendar.php's own per-entry "Send a
      // card" links can't host this whole composer themselves -- they
      // just navigate here with ?send_card_to=<person id> or
      // ?send_card_for_event=<event id>, and $directOpenCardRow (looked
      // up server-side above, straight from the database -- never from
      // whatever a rendered banner button happens to carry) hands this
      // everything needed to reopen the same composer for that same
      // birthday or key date, even one further out than this page's own
      // 7-day banner would otherwise show.
      var directOpenRow = <?= $directOpenCardRow !== null ? json_encode($directOpenCardRow) : 'null' ?>;
      if (directOpenRow) {
        window.ourthologyOpenCardComposer(directOpenRow);
      }
    })();
  </script>

  <script src="/date_autotab.js?v=1"></script>
  <?php ourthology_render_tour('timeline', (int) $me['person_id'], $autostartTour); ?>
</body>
</html>
