<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/media.php';

/**
 * Phase 48: serves a postcard's front image -- its own small access-check
 * script, deliberately separate from media.php's can_view_media() (which
 * is all about family-group visibility and custom audiences, none of
 * which apply here): a postcard is addressed, not broadcast, so only its
 * sender or one of its addressed recipients may ever view it, for as long
 * as the postcards/postcard_recipients rows exist -- unaffected by
 * whether it's since been read, saved, or discarded.
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

$postcardId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($postcardId === false) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT image_path, image_mime_type, sender_person_id FROM postcards WHERE id = :id');
$stmt->execute(['id' => $postcardId]);
$postcard = $stmt->fetch();
if ($postcard === null) {
    http_response_code(404);
    exit;
}

$allowed = (int) $postcard['sender_person_id'] === $myPersonId;
if (!$allowed) {
    $checkStmt = $pdo->prepare('SELECT 1 FROM postcard_recipients WHERE postcard_id = :pcid AND recipient_person_id = :pid');
    $checkStmt->execute(['pcid' => $postcardId, 'pid' => $myPersonId]);
    $allowed = (bool) $checkStmt->fetchColumn();
}
if (!$allowed) {
    http_response_code(404);
    exit;
}

$full = ourthology_media_dir() . '/' . $postcard['image_path'];
if (!is_file($full)) {
    http_response_code(404);
    exit;
}

// Same resized-preview reuse as media.php/avatar.php (Phase 27) -- the
// small thumbnail shown in the Pending list doesn't need the full-
// resolution original.
if (filter_var($_GET['thumb'] ?? '', FILTER_VALIDATE_INT)) {
    $thumbPath = ensure_media_thumbnail($postcard['image_path'], MEDIA_THUMB_MAX_DIMENSION);
    if ($thumbPath !== null) {
        send_cacheable_file($thumbPath, 'image/jpeg');
        exit;
    }
}

send_cacheable_file($full, $postcard['image_mime_type']);
