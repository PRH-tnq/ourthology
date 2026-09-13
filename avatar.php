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
$myGroup = (int) person_row($pdo, (int) $me['person_id'])['family_group_id'];

$personId = filter_var($_GET['person_id'] ?? '', FILTER_VALIDATE_INT);
if ($personId === false) {
    http_response_code(400);
    exit('Bad request.');
}

$person = person_row($pdo, (int) $personId);

// Same response whether the person doesn't exist, has no photo on record,
// or the viewer just isn't in their family group — no reason to let those
// be told apart. Unlike timeline media (private/public per entry), a
// profile photo follows the tree diagram's own visibility rule: anyone in
// the family group can already see this person's name and place in the
// tree, so anyone in the group can see their photo too.
if ($person === null || (int) $person['family_group_id'] !== $myGroup || empty($person['avatar_path'])) {
    http_response_code(404);
    exit('Not found.');
}

$path = ourthology_media_dir() . '/' . $person['avatar_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}

// Phase 27: an avatar is never shown larger than a small circle anywhere
// in the app, so it's always served as a resized, cached preview rather
// than whatever resolution was actually uploaded — falling back to the
// original file untouched if thumbnailing isn't possible for some reason.
$servePath = $path;
$mimeType = null;
$thumbPath = ensure_media_thumbnail((string) $person['avatar_path'], AVATAR_THUMB_MAX_DIMENSION);
if ($thumbPath !== null) {
    $servePath = $thumbPath;
    $mimeType = 'image/jpeg';
} else {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $path);
    finfo_close($finfo);
}

// Safe to cache long and "immutable" (see send_cacheable_file()'s own
// comment in includes/media.php): a re-uploaded photo always gets a new
// random filename AND the <img> tag's own ?v= cache-buster changes with
// it (Phase 22/20), so this exact URL's bytes never change under it.
send_cacheable_file($servePath, (string) $mimeType);
