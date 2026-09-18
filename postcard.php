<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/postcards.php';
require_once __DIR__ . '/includes/notifications.php';

/**
 * Phase 48: all four postcard mutations in one file -- send (from the
 * compose pop-up on timeline.php), open/save/discard (from the read
 * pop-up on pending.php) -- mirroring how add_entry.php owns every
 * timeline-entry mutation and pending.php owns every
 * relationship/partnership/memory-tag one, rather than spreading postcard
 * logic across the two pages that merely trigger it. Every action is
 * POST-only and redirect-based (session flash for the result), the same
 * convention every mutation in this app already follows -- no fetch/AJAX.
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
    $audience = ($_POST['audience'] ?? '') === 'everyone' ? 'everyone' : 'selected';
    $message = trim((string) ($_POST['message'] ?? ''));
    $recordToOwnTimeline = !empty($_POST['record_to_timeline']);
    // Phase 54: "let me edit the To and From lines, I might want to
    // contract my name or the recipient's" -- free text, capped and
    // trimmed, stored as-is; null (not empty string) when left blank so
    // the read view's fallback to the computed sender/recipient name
    // still kicks in (see fetch_postcard_recipient_row() callers).
    $toLine = mb_substr(trim((string) ($_POST['to_line'] ?? '')), 0, 80);
    $fromLine = mb_substr(trim((string) ($_POST['from_line'] ?? '')), 0, 80);

    $options = fetch_postcard_recipient_options($pdo, $myGroup, $myPersonId);
    $validIds = array_map(fn($p) => (int) $p['id'], $options);

    if ($audience === 'everyone') {
        $recipientIds = $validIds;
    } else {
        $submitted = array_map('intval', (array) ($_POST['recipient_ids'] ?? []));
        $recipientIds = array_values(array_intersect($submitted, $validIds));
    }

    $error = null;
    if (!$recipientIds) {
        $error = 'Choose at least one person to send this postcard to.';
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
        $_SESSION['flash_postcard_error'] = $error;
        header('Location: /timeline.php');
        exit;
    }

    create_postcard($pdo, $myPersonId, $myUserId, $myGroup, $storedImage, $message, $audience, $recipientIds, $recordToOwnTimeline, $toLine, $fromLine);

    $senderName = person_display_name(['first_name' => $me['first_name'], 'surname' => $me['surname']]);
    foreach ($recipientIds as $rid) {
        ourthology_notify_postcard_received($pdo, $rid, $senderName);
    }

    $count = count($recipientIds);
    $_SESSION['flash_postcard_sent'] = $audience === 'everyone'
        ? 'Postcard sent to everyone in your family.'
        : ($count === 1 ? 'Postcard sent.' : "Postcard sent to $count people.");
    header('Location: /timeline.php');
    exit;
}

if ($action === 'open') {
    $rowId = filter_var($_POST['recipient_row_id'] ?? '', FILTER_VALIDATE_INT);
    if ($rowId !== false) {
        $row = fetch_postcard_recipient_row($pdo, (int) $rowId, $myPersonId);
        if ($row !== null) {
            mark_postcard_read($pdo, (int) $rowId);
            $_SESSION['flash_open_postcard'] = (int) $rowId;
        }
    }
    header('Location: /pending.php');
    exit;
}

if ($action === 'save') {
    $rowId = filter_var($_POST['recipient_row_id'] ?? '', FILTER_VALIDATE_INT);
    if ($rowId !== false) {
        $row = fetch_postcard_recipient_row($pdo, (int) $rowId, $myPersonId);
        if ($row !== null && in_array($row['status'], ['pending', 'read'], true)) {
            save_postcard_to_timeline($pdo, $row, $myPersonId, $myUserId);
            $_SESSION['flash_postcard_notice'] = 'Saved to your timeline.';
        }
    }
    header('Location: /pending.php');
    exit;
}

if ($action === 'discard') {
    $rowId = filter_var($_POST['recipient_row_id'] ?? '', FILTER_VALIDATE_INT);
    if ($rowId !== false) {
        $row = fetch_postcard_recipient_row($pdo, (int) $rowId, $myPersonId);
        if ($row !== null && in_array($row['status'], ['pending', 'read'], true)) {
            discard_postcard($pdo, (int) $rowId);
            $_SESSION['flash_postcard_notice'] = 'Postcard discarded.';
        }
    }
    header('Location: /pending.php');
    exit;
}

header('Location: /timeline.php');
