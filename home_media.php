<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/places.php';

/**
 * Phase 92: serves one "Places we lived" photo (full size, or ?thumb=1 for
 * a cached preview), re-checking home_can_view() on every request -- the
 * same pattern as media.php. Same 404 whether the photo doesn't exist or
 * the viewer isn't allowed to see it.
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

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$stmt = $pdo->prepare('SELECT * FROM home_media WHERE id = :id');
$stmt->execute(['id' => $id === false ? 0 : (int) $id]);
$media = $stmt->fetch() ?: null;
$home = $media ? fetch_home_row($pdo, (int) $media['home_id']) : null;
$residents = $home ? (fetch_home_residents($pdo, [(int) $home['id']])[(int) $home['id']] ?? []) : [];
if ($media === null || $home === null || !home_can_view($pdo, $home, $residents, $myUserId, $myPersonId, $myGroup)) {
    http_response_code(404);
    exit('Not found.');
}

$path = ourthology_media_dir() . '/' . $media['file_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}
$servePath = $path;
$mimeType = (string) $media['mime_type'];
if (isset($_GET['thumb'])) {
    $thumb = ensure_media_thumbnail((string) $media['file_path'], MEDIA_THUMB_MAX_DIMENSION);
    if ($thumb !== null) {
        $servePath = $thumb;
        $mimeType = 'image/jpeg';
    }
}
send_cacheable_file($servePath, $mimeType);
