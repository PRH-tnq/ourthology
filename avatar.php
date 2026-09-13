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

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $path);
finfo_close($finfo);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($path);
