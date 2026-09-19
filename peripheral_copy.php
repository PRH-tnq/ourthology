<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/peripheral.php';

/**
 * Phase 58: the mutation behind tree.php's "copy across" banner (see
 * ourthology_copy_facility_state() in includes/peripheral.php). POST-only,
 * redirect-based, single-purpose file -- same convention as
 * switch_person.php. direction=to_other copies the CURRENT active
 * person's own entries onto their linked counterpart; direction=
 * from_other copies the counterpart's entries onto the current identity.
 * Either way, only entries not already copied (tracked via
 * timeline_entries.copied_from_entry_id) move -- safe to click more than
 * once, nothing duplicates.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /tree.php');
    exit;
}
csrf_check();

$direction = (string) ($_POST['direction'] ?? '');
$state = ourthology_copy_facility_state($pdo, $myPersonId);

if ($state !== null && in_array($direction, ['to_other', 'from_other'], true)) {
    if ($direction === 'to_other') {
        $count = ourthology_copy_pending_entries($pdo, $myPersonId, $state['counterpart_person_id'], (int) $me['user_id']);
        $_SESSION['flash_peripheral_message'] = $count > 0
            ? "Copied {$count} " . ($count === 1 ? 'entry' : 'entries') . " to " . $state['counterpart_name'] . "'s tree."
            : 'Nothing new to copy.';
    } else {
        $count = ourthology_copy_pending_entries($pdo, $state['counterpart_person_id'], $myPersonId, (int) $me['user_id']);
        $_SESSION['flash_peripheral_message'] = $count > 0
            ? "Copied {$count} " . ($count === 1 ? 'entry' : 'entries') . " from " . $state['counterpart_name'] . "'s tree."
            : 'Nothing new to copy.';
    }
} else {
    $_SESSION['flash_peripheral_message'] = "Couldn't copy those entries — please try again.";
}

header('Location: /tree.php');
exit;
