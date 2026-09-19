<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';

/**
 * Phase 58: the one mutation behind both switch icons on tree.php --
 * "switch to your other family" beside an in-law who's created a
 * peripheral tree, and "return to the original tree" beside their "YOU"
 * node on that peripheral tree. POST-only, redirect-based, same
 * convention as postcard.php/letter.php's own single-purpose mutation
 * files. All the actual validation (does target_person_id belong to this
 * user at all?) lives in ourthology_switch_active_person() itself
 * (includes/auth.php) -- this file is just the form target for it.
 */

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Location: /login.php');
    exit;
}
$pdo = ourthology_pdo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /tree.php');
    exit;
}
csrf_check();

$targetPersonId = filter_var($_POST['target_person_id'] ?? '', FILTER_VALIDATE_INT);

if ($targetPersonId !== false && ourthology_switch_active_person($pdo, (int) $me['user_id'], (int) $targetPersonId)) {
    $target = person_row($pdo, (int) $targetPersonId);
    $_SESSION['flash_peripheral_message'] = 'Switched to ' . person_display_name($target) . "'s family tree.";
} else {
    $_SESSION['flash_peripheral_message'] = "Couldn't switch to that tree — please try again.";
}

header('Location: /tree.php');
exit;
