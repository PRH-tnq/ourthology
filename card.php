<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/postcards.php'; // fetch_postcard_recipient_options() -- the same "everyone living in my family group" pool a card may be addressed to
require_once __DIR__ . '/includes/cards.php';

/**
 * Phase 67: every greeting-card mutation in one file -- send (from the
 * card composer the birthday banner opens on timeline.php), open/save/
 * discard (from the read pop-up on pending.php) -- mirroring letter.php/
 * postcard.php exactly: one file per feature owning every one of its own
 * mutations rather than spreading the logic across the pages that merely
 * trigger it.
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
    $occasion = mb_substr(trim((string) ($_POST['occasion'] ?? 'Birthday')), 0, 60);
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
        if ($recipient === null || empty($recipient['born'])) {
            // Shouldn't happen from the normal flow (the banner only ever
            // offers this for someone graph_upcoming_birthdays() already
            // found a birth date for) -- but the recipient is what
            // deliver_on is computed from below, so refuse rather than
            // guess at a delivery date for someone with no birthday on
            // file.
            $error = "That person's birthday isn't on file, so there's no date to deliver this on.";
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

    if ($error !== null) {
        $_SESSION['flash_card_error'] = $error;
        header('Location: /timeline.php');
        exit;
    }

    // Phase 67: "deliver it to the recipient on the day before their
    // birthday" -- computed here, server-side, from the recipient's own
    // persons.born via the exact same date math the banner itself used
    // to decide this birthday was coming up (ourthology_next_annual_
    // occurrence() in includes/graph.php). Never trusted from anything
    // the browser posted, so there's no way to make a card deliver
    // earlier than it should by tampering with a hidden field.
    $bornStr = substr((string) $recipient['born'], 0, 10);
    $born = DateTimeImmutable::createFromFormat('!Y-m-d', $bornStr);
    $deliverOn = $born !== false
        ? ourthology_graph_next_annual_occurrence((int) $born->format('m'), (int) $born->format('d'))->modify('-1 day')
        : new DateTimeImmutable('today');

    $cardId = create_greeting_card(
        $pdo,
        $myPersonId,
        $myUserId,
        $myGroup,
        (int) $recipientId,
        $storedImage,
        $occasion !== '' ? $occasion : 'Birthday',
        $coverMessage,
        $toLine,
        $greetingLine,
        $message,
        $fromLine,
        $deliverOn
    );

    // Phase 67: deliberately no "you've got mail" email here, unlike
    // postcard.php/letter.php's own send actions -- this app has no
    // background job to fire one on deliver_on itself (everything here
    // is computed fresh on page load, never on a schedule -- see
    // fetch_pending_cards_for_person()'s own doc comment), and emailing
    // the recipient the moment it's SENT would give the birthday surprise
    // away days early. They'll see it land in their own Pending queue
    // (and its banner/count) the next time they visit on or after the
    // delivery date, same as how the birthday banner itself already
    // works with no notification of its own.
    $_SESSION['flash_card_sent'] = 'Card sent — it\'ll land in ' . person_display_name($recipient) . '\'s Pending queue the day before their birthday.';
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
