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

header('Content-Type: ' . $media['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline');
// This content is access-controlled per request — never let a shared cache
// (or the browser, across a later logout/login as someone else) serve it
// to anyone but the person this specific request was authorized for.
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($path);
