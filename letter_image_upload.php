<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/media.php';

/**
 * Phase 53: the letter composer's "insert a photo" button uploads here
 * via fetch(), one image at a time, as the sender is still typing --
 * long before there's a letters row to attach it to (create_letter() in
 * includes/letters.php claims it later, only if the sent letter's body
 * actually still references it). Returns JSON, never a redirect -- same
 * approach timeline.php's own AJAX "Add Media" submission already uses
 * (an isset($_POST['ajax']) branch late in that file), just as this
 * request's entire reason for existing rather than living inline in
 * timeline.php itself: this fires from a plain <input type=file>
 * mid-composition, well before the surrounding <form> is ever submitted.
 */

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please log in again.']);
    exit;
}
$pdo = ourthology_pdo();
$myPersonId = (int) $me['person_id'];
$myGroup = (int) person_row($pdo, $myPersonId)['family_group_id'];

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

ourthology_start_session();
$submitted = (string) ($_POST['csrf_token'] ?? '');
$expected = (string) ($_SESSION['csrf_token'] ?? '');
if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Your session expired — please reload the page and try again.']);
    exit;
}

try {
    $stored = store_letter_image($_FILES['image'] ?? [], $myPersonId);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO letter_images (letter_id, uploader_person_id, family_group_id, file_path, mime_type, byte_size, width, height)
     VALUES (NULL, :uid, :gid, :path, :mime, :size, :w, :h)'
);
$stmt->execute([
    'uid'  => $myPersonId,
    'gid'  => $myGroup,
    'path' => $stored['file_path'],
    'mime' => $stored['mime_type'],
    'size' => $stored['byte_size'],
    'w'    => $stored['width'],
    'h'    => $stored['height'],
]);
$imageId = (int) $pdo->lastInsertId();

echo json_encode(['ok' => true, 'id' => $imageId, 'url' => '/letter_image.php?id=' . $imageId]);
