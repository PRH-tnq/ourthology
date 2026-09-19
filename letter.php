<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/letters.php';
require_once __DIR__ . '/includes/postcards.php'; // fetch_postcard_recipient_options() — the same "everyone living in my family group" list a letter may be addressed to
require_once __DIR__ . '/includes/notifications.php';

/**
 * Phase 53: every letter mutation in one file -- send (from the "send a
 * letter instead" panel of the postcard compose pop-up on timeline.php),
 * open/save/discard (from the read pop-up on pending.php) -- mirroring
 * postcard.php exactly, one file per feature owning every one of its own
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
    $bodyHtml = (string) ($_POST['body_html'] ?? '');
    $recordToOwnTimeline = !empty($_POST['record_to_timeline']);
    // Phase 55: "let me edit the To and From names" on letters too, same
    // as postcards got in Phase 54 -- the editable "Dear ___," / "Best
    // regards, ___" names, capped/trimmed. Both fields are always
    // auto-filled with a computed default before the sender ever touches
    // them (unlike a postcard's blank "To"), so this is null only if
    // somehow posted genuinely empty -- otherwise whatever's showing
    // (default or shortened) is what gets frozen into to_line/from_line.
    $toLine = mb_substr(trim((string) ($_POST['to_line'] ?? '')), 0, 80);
    $fromLine = mb_substr(trim((string) ($_POST['from_line'] ?? '')), 0, 80);

    $options = fetch_postcard_recipient_options($pdo, $myGroup, $myPersonId);
    $validIds = array_map(fn($p) => (int) $p['id'], $options);

    $error = null;
    if ($recipientId === false || !in_array((int) $recipientId, $validIds, true)) {
        $error = 'Choose who this letter is for.';
    } elseif (trim(strip_tags($bodyHtml)) === '' && !str_contains($bodyHtml, '<img')) {
        $error = 'Write your letter before sending it.';
    }

    if ($error !== null) {
        $_SESSION['flash_letter_error'] = $error;
        header('Location: /timeline.php');
        exit;
    }

    try {
        $letterId = create_letter($pdo, $myPersonId, $myUserId, $myGroup, (int) $recipientId, $bodyHtml, $recordToOwnTimeline, $toLine, $fromLine);
    } catch (RuntimeException $e) {
        $_SESSION['flash_letter_error'] = $e->getMessage();
        header('Location: /timeline.php');
        exit;
    }

    $senderName = person_display_name(['first_name' => $me['first_name'], 'surname' => $me['surname']]);
    ourthology_notify_letter_received($pdo, (int) $recipientId, $senderName);

    $_SESSION['flash_letter_sent'] = 'Letter sent.';
    header('Location: /timeline.php');
    exit;
}

if ($action === 'open') {
    $letterId = filter_var($_POST['letter_id'] ?? '', FILTER_VALIDATE_INT);
    if ($letterId !== false) {
        $row = fetch_letter_for_recipient($pdo, (int) $letterId, $myPersonId);
        if ($row !== null) {
            mark_letter_read($pdo, (int) $letterId);
            $_SESSION['flash_open_letter'] = (int) $letterId;
        }
    }
    header('Location: /pending.php');
    exit;
}

if ($action === 'save') {
    $letterId = filter_var($_POST['letter_id'] ?? '', FILTER_VALIDATE_INT);
    if ($letterId !== false) {
        $row = fetch_letter_for_recipient($pdo, (int) $letterId, $myPersonId);
        if ($row !== null && in_array($row['status'], ['pending', 'read'], true)) {
            save_letter_to_timeline($pdo, $row, $myPersonId, $myUserId);
            $_SESSION['flash_letter_notice'] = 'Saved to your timeline.';
        }
    }
    header('Location: /pending.php');
    exit;
}

if ($action === 'discard') {
    $letterId = filter_var($_POST['letter_id'] ?? '', FILTER_VALIDATE_INT);
    if ($letterId !== false) {
        $row = fetch_letter_for_recipient($pdo, (int) $letterId, $myPersonId);
        if ($row !== null && in_array($row['status'], ['pending', 'read'], true)) {
            discard_letter($pdo, (int) $letterId);
            $_SESSION['flash_letter_notice'] = 'Letter discarded.';
        }
    }
    header('Location: /pending.php');
    exit;
}

header('Location: /timeline.php');
