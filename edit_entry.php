<?php
declare(strict_types=1);

// Phase 27: edit_entry.php was merged into add_entry.php so editing and
// adding a memory share one composer/pop-up instead of two separately
// maintained forms that had drifted apart (an older single-column layout
// here, a newer 3-column one there). This file stays only so any old
// bookmark or link to it keeps working — see architecture.md's Phase 27
// write-up. All real logic now lives in add_entry.php.
$entryId = (string) ($_GET['entry_id'] ?? $_POST['entry_id'] ?? '');
header('Location: /add_entry.php?entry_id=' . urlencode($entryId));
exit;
