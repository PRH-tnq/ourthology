<?php
declare(strict_types=1);

// LiteSpeed/Apache serve index.php ahead of index.html by default, so this
// takes over the site root now that accounts exist. (Same gotcha as
// thenat1: index.php is what's live, index.html below is stale/unused.)

require_once __DIR__ . '/includes/auth.php';

header('Location: ' . (current_user_id() !== null ? '/timeline.php' : '/login.php'));
exit;
