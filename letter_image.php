<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/letters.php';

/**
 * Phase 53: serves one of a letter's inline images -- its own small
 * access-check script, same shape and same reasoning as
 * postcard_media.php: a letter is addressed, not broadcast, so only its
 * sender or its one recipient may ever view an attached image, for as
 * long as the letters/letter_images rows exist (unaffected by whether
 * it's since been read, saved, or discarded). Before a letter is even
 * sent (letter_id still NULL on the image row), only the person who
 * uploaded it may preview their own draft -- see can_view_letter_image()
 * in includes/letters.php.
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

$imageId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($imageId === false) {
    http_response_code(404);
    exit;
}

$image = fetch_letter_image_for_view($pdo, (int) $imageId);
if ($image === null || !can_view_letter_image($image, $myPersonId)) {
    http_response_code(404);
    exit;
}

$full = ourthology_media_dir() . '/' . $image['file_path'];
if (!is_file($full)) {
    http_response_code(404);
    exit;
}

// Same resized-preview reuse as media.php/postcard_media.php (Phase 27,
// Phase 48) -- a letter's own read/compose view shows these inline at a
// modest size, never the full-resolution original.
if (filter_var($_GET['thumb'] ?? '', FILTER_VALIDATE_INT)) {
    $thumbPath = ensure_media_thumbnail($image['file_path'], MEDIA_THUMB_MAX_DIMENSION);
    if ($thumbPath !== null) {
        send_cacheable_file($thumbPath, 'image/jpeg');
        exit;
    }
}

send_cacheable_file($full, $image['mime_type']);
