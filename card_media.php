<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/media.php';

/**
 * Phase 67: serves a greeting card's front image -- same small access-
 * check script as postcard_media.php, extended with the same deliver_on
 * gate fetch_card_for_recipient() enforces: a recipient may not view the
 * image before their card's delivery date arrives, same as they may not
 * open the card itself early. The sender may always view it (it's their
 * own upload, sitting in their own sent history the whole time).
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

$cardId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($cardId === false) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT image_path, image_mime_type, sender_person_id, recipient_person_id, deliver_on FROM greeting_cards WHERE id = :id');
$stmt->execute(['id' => $cardId]);
$card = $stmt->fetch();
if ($card === null) {
    http_response_code(404);
    exit;
}

$allowed = (int) $card['sender_person_id'] === $myPersonId
    || ((int) $card['recipient_person_id'] === $myPersonId && $card['deliver_on'] <= date('Y-m-d'));
if (!$allowed) {
    http_response_code(404);
    exit;
}

$full = ourthology_media_dir() . '/' . $card['image_path'];
if (!is_file($full)) {
    http_response_code(404);
    exit;
}

if (filter_var($_GET['thumb'] ?? '', FILTER_VALIDATE_INT)) {
    $thumbPath = ensure_media_thumbnail($card['image_path'], MEDIA_THUMB_MAX_DIMENSION);
    if ($thumbPath !== null) {
        send_cacheable_file($thumbPath, 'image/jpeg');
        exit;
    }
}

send_cacheable_file($full, $card['image_mime_type']);
