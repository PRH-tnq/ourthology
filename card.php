<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/postcards.php'; // fetch_postcard_recipient_options() -- the same "everyone living in my family group" pool a card may be addressed to
require_once __DIR__ . '/includes/calendar.php'; // Phase 68: fetch_calendar_event_for_group(), ourthology_next_annual_occurrence() -- a key-date card's own date math
require_once __DIR__ . '/includes/cards.php';

/**
 * Phase 67: every greeting-card mutation in one file -- send (from the
 * card composer the birthday banner opens on timeline.php), open/save/
 * discard (from the read pop-up on pending.php) -- mirroring letter.php/
 * postcard.php exactly: one file per feature owning every one of its own
 * mutations rather than spreading the logic across the pages that merely
 * trigger it.
 *
 * Phase 68 widened 'send' to cover two distinct flows sharing one form:
 * a birthday card (recipient implied by which birthday the sender opened
 * the composer from; deliver_on/occasion come from THEIR persons.born)
 * and a key-date card (no implied recipient at all -- the sender picks
 * one from the same pool postcards/letters use; deliver_on/occasion come
 * from the calendar_events row itself). Which flow applies is decided
 * here, server-side, by whether event_id resolves to a real row owned by
 * the sender's own family group -- never by anything else the client
 * posted.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /timeline.php');
    exit;
}
csrf_check();
$action = (string) ($_POST['action'] ?? '');

if ($action === 'send') {
    $recipientId = filter_var($_POST['recipient_id'] ?? '', FILTER_VALIDATE_INT);
    $eventId = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT); // false when absent/blank -- a birthday card has none
    $coverMessage = mb_substr(trim((string) ($_POST['cover_message'] ?? '')), 0, 120);
    $toLine = mb_substr(trim((string) ($_POST['to_line'] ?? '')), 0, 80);
    $greetingLine = mb_substr(trim((string) ($_POST['greeting_line'] ?? '')), 0, 80);
    $message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 2000);
    $fromLine = mb_substr(trim((string) ($_POST['from_line'] ?? '')), 0, 80);

    $options = fetch_postcard_recipient_options($pdo, $myGroup, $myPersonId);
    $validIds = array_map(fn($p) => (int) $p['id'], $options);

    $error = null;
    $recipient = null;
    if ($recipientId === false || !in_array((int) $recipientId, $validIds, true)) {
        $error = 'Choose who this card is for.';
    } else {
        $recipient = person_row($pdo, (int) $recipientId);
        if ($recipient === null) {
            $error = "Couldn't find that person.";
        }
    }

    // Phase 68: a key-date card looks up its own calendar_events row here
    // -- scoped to the sender's own family group, the ownership check IS
    // the query (fetch_calendar_event_for_group()) -- rather than trusting
    // any month/day/title the browser might have posted. $eventId being
    // absent/invalid just means this is the original birthday flow below,
    // not an error on its own.
    $eventRow = null;
    if ($error === null && $eventId !== false) {
        $eventRow = fetch_calendar_event_for_group($pdo, (int) $eventId, $myGroup);
        if ($eventRow === null) {
            $error = "That calendar date couldn't be found.";
        }
    }

    if ($error === null && $coverMessage === '') {
        $error = 'Add a front-of-card message before sending.';
    }

    $storedImage = null;
    if ($error === null) {
        try {
            $storedImage = store_postcard_image($_FILES['image'] ?? [], $myPersonId);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    // Phase 67/68: "deliver it to the recipient on the day before their
    // birthday" -- widened to "the day before whatever's being marked."
    // A key-date card delivers the day before the EVENT's own next
    // occurrence (computed from the calendar_events row just looked up
    // above -- nothing to do with the recipient's own birthday, since
    // they're not necessarily who it's "about"). A birthday card keeps
    // Phase 67's original math, from the recipient's own persons.born.
    // Either way this is computed here, server-side, never trusted from
    // anything the browser posted, so there's no way to make a card
    // deliver earlier than it should by tampering with a hidden field.
    $deliverOn = null;
    $occasion = null;
    if ($error === null) {
        if ($eventRow !== null) {
            $occ = ourthology_next_annual_occurrence((int) $eventRow['event_month'], (int) $eventRow['event_day']);
            $deliverOn = $occ['date']->modify('-1 day');
            $occasion = mb_substr((string) $eventRow['title'], 0, 60);
        } else {
            $bornStr = substr((string) ($recipient['born'] ?? ''), 0, 10);
            $born = $bornStr !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $bornStr) : false;
            if ($born === false) {
                // Shouldn't happen from the normal flow (the banner only
                // ever offers a birthday card for someone
                // ourthology_calendar_reminder_rows() already found a
                // birth date for) -- but the recipient is what deliver_on
                // is computed from here, so refuse rather than guess at a
                // delivery date for someone with no birthday on file.
                $error = "That person's birthday isn't on file, so there's no date to deliver this on.";
            } else {
                $deliverOn = ourthology_graph_next_annual_occurrence((int) $born->format('m'), (int) $born->format('d'))->modify('-1 day');
                $occasion = 'Birthday';
            }
        }
    }

    if ($error !== null) {
        $_SESSION['flash_card_error'] = $error;
        header('Location: /timeline.php');
        exit;
    }

    $cardId = create_greeting_card(
        $pdo,
        $myPersonId,
        $myUserId,
        $myGroup,
        (int) $recipientId,
        $storedImage,
        $occasion,
        $coverMessage,
        $toLine,
        $greetingLine,
        $message,
        $fromLine,
        $deliverOn,
        $eventRow !== null ? (int) $eventRow['id'] : null
    );

    // Phase 67: deliberately no "you've got mail" email here, unlike
    // postcard.php/letter.php's own send actions -- this app has no
    // background job to fire one on deliver_on itself (everything here
    // is computed fresh on page load, never on a schedule -- see
    // fetch_pending_cards_for_person()'s own doc comment), and emailing
    // the recipient the moment it's SENT would give the surprise away
    // days early. They'll see it land in their own Pending queue (and its
    // banner/count) the next time they visit on or after the delivery
    // date, same as how the reminder banner itself already works with no
    // notification of its own.
    $occasionLabel = $eventRow !== null ? $occasion : 'their birthday';
    $_SESSION['flash_card_sent'] = 'Card sent — it\'ll land in ' . person_display_name($recipient) . '\'s Pending queue the day before ' . $occasionLabel . '.';
    header('Location: /timeline.php');
    exit;
}

if ($action === 'open') {
    $cardId = filter_var($_POST['card_id'] ?? '', FILTER_VALIDATE_INT);
    if ($cardId !== false) {
        $row = fetch_card_for_recipient($pdo, (int) $cardId, $myPersonId);
        if ($row !== null) {
            mark_card_read($pdo, (int) $cardId);
            $_SESSION['flash_open_card'] = (int) $cardId;
        }
    }
    header('Location: /pending.php');
    exit;
}

if ($action === 'save') {
    $cardId = filter_var($_POST['card_id'] ?? '', FILTER_VALIDATE_INT);
    if ($cardId !== false) {
        $row = fetch_card_for_recipient($pdo, (int) $cardId, $myPersonId);
        if ($row !== null && in_array($row['status'], ['pending', 'read'], true)) {
            save_card_to_timeline($pdo, $row, $myPersonId, $myUserId);
            $_SESSION['flash_card_notice'] = 'Saved to your timeline.';
        }
    }
    header('Location: /pending.php');
    exit;
}

if ($action === 'discard') {
    $cardId = filter_var($_POST['card_id'] ?? '', FILTER_VALIDATE_INT);
    if ($cardId !== false) {
        $row = fetch_card_for_recipient($pdo, (int) $cardId, $myPersonId);
        if ($row !== null && in_array($row['status'], ['pending', 'read'], true)) {
            discard_card($pdo, (int) $cardId);
            $_SESSION['flash_card_notice'] = 'Card discarded.';
        }
    }
    header('Location: /pending.php');
    exit;
}

header('Location: /timeline.php');
