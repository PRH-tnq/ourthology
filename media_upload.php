<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/media.php';

/**
 * Phase 91: resumable, chunked upload endpoint for memory attachments --
 * see the "Chunked, resumable uploads" block at the end of
 * includes/media.php for why. Called only via XHR from
 * /chunked_upload.js; always answers JSON, never a redirect.
 *
 * POST fields: csrf_token, upload_id (32 hex chars the browser picks per
 * file), offset (byte position this chunk starts at), file_size,
 * file_name, purpose ('entry' or 'tagged'), target_person_id (entry) or
 * entry_id (tagged), and the chunk itself as "chunk".
 *
 * Resume protocol: the server appends a chunk only if it starts exactly
 * where the .part file currently ends. A chunk it already has (a retry
 * after a lost response) is acknowledged without being written twice; a
 * chunk from further ahead gets a 409 with "received" so the browser can
 * jump back to the right place. When the last byte arrives the file is
 * validated and stored exactly as a normal upload would be, and a token
 * is returned for the form to submit in place of the file itself.
 */

function mu_reply(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
}

ourthology_start_session();
$userId = current_user_id();
$me = $userId !== null ? current_user_with_person() : null;
if ($me === null) {
    mu_reply(401, ['ok' => false, 'error' => 'You have been signed out — please sign in again.', 'fatal' => true]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mu_reply(405, ['ok' => false, 'error' => 'Invalid request.', 'fatal' => true]);
}
$submitted = (string) ($_POST['csrf_token'] ?? '');
$expected = (string) ($_SESSION['csrf_token'] ?? '');
if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
    mu_reply(400, ['ok' => false, 'error' => 'Your session expired — please reload the page and try again.', 'fatal' => true]);
}
// Nothing below needs the session again -- release its lock now so a
// slow chunk never stalls the user's other tabs/requests.
session_write_close();

$pdo = ourthology_pdo();
$userId = (int) $me['user_id'];
$myPersonId = (int) $me['person_id'];
$myGroup = (int) person_row($pdo, $myPersonId)['family_group_id'];

$uploadId = (string) ($_POST['upload_id'] ?? '');
$offset = filter_var($_POST['offset'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$fileSize = filter_var($_POST['file_size'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fileName = mb_substr(trim((string) ($_POST['file_name'] ?? '')), 0, 200);
$purpose = (string) ($_POST['purpose'] ?? '');
if (!media_staging_id_is_valid($uploadId) || $offset === false || $fileSize === false
    || !in_array($purpose, ['entry', 'tagged'], true)) {
    mu_reply(400, ['ok' => false, 'error' => 'Invalid upload request.', 'fatal' => true]);
}
if ($fileSize > MEDIA_MAX_BYTES) {
    mu_reply(422, ['ok' => false, 'error' => ($fileName !== '' ? '"' . $fileName . '" is' : 'That file is') . ' larger than the 30MB limit.', 'fatal' => true]);
}

// Whose media folder this belongs to -- re-checked on every chunk, the
// same rules the final save applies (add_entry.php for 'entry',
// timeline.php's add_tagged_media for 'tagged').
$entryId = null;
if ($purpose === 'entry') {
    $targetId = filter_var($_POST['target_person_id'] ?? '', FILTER_VALIDATE_INT);
    $target = $targetId !== false ? person_row($pdo, (int) $targetId) : null;
    if ($target === null || (int) $target['family_group_id'] !== $myGroup || !person_is_editable_by($target, $userId)) {
        mu_reply(403, ['ok' => false, 'error' => "You don't have permission to add files for that person.", 'fatal' => true]);
    }
    $storagePersonId = (int) $target['id'];
} else {
    $entryId = filter_var($_POST['entry_id'] ?? '', FILTER_VALIDATE_INT);
    $owner = false;
    if ($entryId !== false && person_has_approved_tag($pdo, (int) $entryId, $myPersonId)) {
        $ownerStmt = $pdo->prepare('SELECT person_id FROM timeline_entries WHERE id = :id');
        $ownerStmt->execute(['id' => (int) $entryId]);
        $owner = $ownerStmt->fetchColumn();
    }
    if ($owner === false) {
        mu_reply(403, ['ok' => false, 'error' => "You don't have an approved tag on that memory.", 'fatal' => true]);
    }
    $entryId = (int) $entryId;
    $storagePersonId = (int) $owner;
}

$chunk = $_FILES['chunk'] ?? null;
if (!is_array($chunk) || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($chunk['tmp_name'])) {
    // A chunk that arrived damaged/partial -- worth retrying, not fatal.
    mu_reply(400, ['ok' => false, 'error' => 'A piece of the upload went missing — retrying.']);
}
$chunkSize = (int) $chunk['size'];
if ($chunkSize < 1 || $chunkSize > MEDIA_UPLOAD_CHUNK_MAX_BYTES || $offset + $chunkSize > $fileSize) {
    mu_reply(400, ['ok' => false, 'error' => 'Invalid upload request.', 'fatal' => true]);
}

$dir = media_staging_dir($userId);
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    mu_reply(500, ['ok' => false, 'error' => 'Could not save the file — please try again.']);
}
if (random_int(1, 20) === 1) {
    ourthology_sweep_stale_staged_media($pdo);
}

$partPath = $dir . '/' . $uploadId . '.part';
clearstatcache(true, $partPath);
$have = is_file($partPath) ? (int) filesize($partPath) : 0;

if ($offset > $have) {
    mu_reply(409, ['ok' => false, 'received' => $have, 'error' => 'Out of step — resuming.']);
}
if ($offset + $chunkSize > $have) {
    // New bytes (possibly overlapping a retried tail): append only the
    // part the server doesn't already have.
    $in = fopen($chunk['tmp_name'], 'rb');
    $out = fopen($partPath, 'ab');
    if ($in === false || $out === false) {
        mu_reply(500, ['ok' => false, 'error' => 'Could not save the file — please try again.']);
    }
    if ($have > $offset) {
        fseek($in, $have - $offset);
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    @chmod($partPath, 0640);
    clearstatcache(true, $partPath);
    $have = (int) filesize($partPath);
}

if ($have < $fileSize) {
    mu_reply(200, ['ok' => true, 'received' => $have, 'done' => false]);
}

// Complete -- validate and store it exactly like an ordinary upload.
try {
    $stored = store_uploaded_media([
        'name'     => $fileName,
        'tmp_name' => $partPath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => $have,
        'staged'   => true,
    ], $storagePersonId);
} catch (RuntimeException $e) {
    @unlink($partPath);
    mu_reply(422, ['ok' => false, 'error' => ($fileName !== '' ? '"' . $fileName . '": ' : '') . $e->getMessage(), 'fatal' => true]);
}
@unlink($partPath); // already moved/converted away; this just covers a HEIC conversion's leftover

$token = media_staging_write_manifest($userId, $stored + [
    'person_id' => $storagePersonId,
    'purpose'   => $purpose,
    'entry_id'  => $entryId,
]);
mu_reply(200, ['ok' => true, 'received' => $have, 'done' => true, 'token' => $token]);
