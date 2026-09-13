<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/graph.php';

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Location: /login.php');
    exit;
}
$pdo = ourthology_pdo();
$myPersonId = (int) $me['person_id'];
$myGroup = (int) person_row($pdo, $myPersonId)['family_group_id'];

$mediaId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($mediaId === false) {
    http_response_code(400);
    exit('Bad request.');
}

$media = fetch_media_for_view($pdo, (int) $mediaId);
if ($media === null || !can_view_media($media, $myPersonId, $myGroup, $pdo)) {
    // Same response whether it doesn't exist or you're just not allowed to
    // see it — no reason to let someone tell the two apart.
    http_response_code(404);
    exit('Not found.');
}

$path = ourthology_media_dir() . '/' . $media['file_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}

// Phase 27: ?thumb=1 (used by the timeline's memory-card rail and by the
// media picker's "kept file" tiles) asks for a small, cached, re-encoded
// preview instead of the original — the card was previously downloading
// and decoding the full original upload (which can be several MB straight
// off a phone camera) just to show a ~150px tile. Falls back to the
// original file untouched if it isn't an image, or if generation fails
// for any reason. The full-resolution original is still what the opened
// memory viewer (and any non-image file) serves.
$servePath = $path;
$mimeType = (string) $media['mime_type'];
if (isset($_GET['thumb'])) {
    $thumbPath = ensure_media_thumbnail((string) $media['file_path'], MEDIA_THUMB_MAX_DIMENSION);
    if ($thumbPath !== null) {
        $servePath = $thumbPath;
        $mimeType = 'image/jpeg';
    }
}

// This content is access-controlled per request (the can_view_media()
// check above) — `private` so no shared cache serves it to anyone but the
// person this specific request was authorized for, but otherwise safe to
// cache aggressively: a media row's file is immutable once created (see
// send_cacheable_file()'s own comment in includes/media.php).
send_cacheable_file($servePath, $mimeType);
