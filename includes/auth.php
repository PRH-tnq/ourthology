<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ourthology_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,   // ourthology.com is served over HTTPS (Let's Encrypt) — cookie never sent in the clear
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function current_user_id(): ?int
{
    ourthology_start_session();
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Phase 40: true only for a same-site relative path this app can safely
 * redirect to -- used both to build the ?next= a login bounce carries,
 * and to validate ?next= (and login.php's own hidden field) before ever
 * putting it in a Location header, since that value round-trips through
 * the browser and a crafted one ("https://evil.example", "//evil.example")
 * is exactly how an open-redirect vulnerability happens.
 */
function ourthology_safe_redirect_target(?string $raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if ($raw[0] !== '/' || (isset($raw[1]) && $raw[1] === '/')) {
        return null; // not "/...", or protocol-relative "//..."
    }
    if (str_contains($raw, "\r") || str_contains($raw, "\n")) {
        return null; // header-injection guard, belt and braces
    }
    return $raw;
}

/**
 * Call at the top of any page that requires a logged-in user. Bounces to
 * /login.php?next=<here>, so a deep link clicked while logged out --
 * a pending-approval notification email, say -- lands back on the page
 * it was meant for once the user's signed in, rather than dumping them
 * on the generic timeline.
 */
function require_login(): int
{
    $id = current_user_id();
    if ($id === null) {
        $next = ourthology_safe_redirect_target($_SERVER['REQUEST_URI'] ?? null);
        header('Location: /login.php' . ($next !== null ? '?next=' . rawurlencode($next) : ''));
        exit;
    }
    return $id;
}

function login_user(int $userId): void
{
    ourthology_start_session();
    // Regenerate the session id on privilege change (login) to prevent
    // session fixation — a fresh id is issued, old one invalidated.
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    // Phase 58: always start a fresh login on the account's home identity,
    // never a peripheral-tree override left over from an earlier session
    // on this browser (e.g. a shared computer, or logging back in after
    // logout without an explicit switch back).
    unset($_SESSION['active_person_id']);
}

function logout_user(): void
{
    ourthology_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Fetch the logged-in user's row + their CURRENTLY ACTIVE person row, or
 * null. For almost every user this is simply their one home person (the
 * account's users.person_id) -- but Phase 58 lets an in-law who's created
 * a peripheral tree switch which of their two claimed person rows is
 * active for the rest of the session (switch_person.php), and every page
 * already builds $myPersonId/$myGroup from this one function's result, so
 * that's all it takes for the switch to act like a genuinely separate
 * identity everywhere: tree, timeline, postcards, letters, calendar,
 * pending queue, all of it.
 *
 * $_SESSION['active_person_id'], when set, names that override. It's
 * trusted only after re-checking persons.claimed_by_user_id = $userId on
 * every call (cheap, and the one check that matters -- a tampered or
 * stale value can never read as someone else's identity); anything else
 * falls back to the home identity and clears the bad override.
 */
function current_user_with_person(): ?array
{
    $userId = current_user_id();
    if ($userId === null) {
        return null;
    }
    $activePersonId = isset($_SESSION['active_person_id']) ? (int) $_SESSION['active_person_id'] : null;
    if ($activePersonId !== null) {
        $stmt = ourthology_pdo()->prepare(
            'SELECT u.id AS user_id, u.email, u.tour_completed_at, p.id AS person_id, p.first_name, p.middle_name, p.surname
             FROM users u JOIN persons p ON p.claimed_by_user_id = u.id
             WHERE u.id = :id AND p.id = :pid'
        );
        $stmt->execute(['id' => $userId, 'pid' => $activePersonId]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        unset($_SESSION['active_person_id']);
    }
    $stmt = ourthology_pdo()->prepare(
        'SELECT u.id AS user_id, u.email, u.tour_completed_at, p.id AS person_id, p.first_name, p.middle_name, p.surname
         FROM users u JOIN persons p ON p.id = u.person_id
         WHERE u.id = :id'
    );
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Phase 58: every person row this account can currently act as -- its
 * home identity (users.person_id) plus, for anyone who's created or been
 * given a peripheral tree, the "YOU" node there too. Row order puts the
 * home identity first. Used by tree.php to decide which of the two
 * switch icons (if either) to render beside a person, and by
 * switch_person.php to validate a switch target.
 */
function ourthology_my_identities(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.id AS person_id, p.first_name, p.middle_name, p.surname, p.family_group_id,
                (p.id = u.person_id) AS is_home
         FROM persons p
         JOIN users u ON u.id = :uid
         WHERE p.claimed_by_user_id = :uid
         ORDER BY is_home DESC, p.id ASC'
    );
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Switch the session's active identity to $personId, but only when it's
 * actually claimed by $userId (their home person, or a peripheral "YOU"
 * node they've claimed) -- returns false and changes nothing otherwise,
 * so a forged or stale target can never switch into someone else's
 * identity. Switching TO the home identity clears the override rather
 * than storing a value that happens to equal it, so "is an override set"
 * stays a clean, cheap signal for callers like tree.php's return-icon.
 */
function ourthology_switch_active_person(PDO $pdo, int $userId, int $personId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM persons WHERE id = :pid AND claimed_by_user_id = :uid');
    $stmt->execute(['pid' => $personId, 'uid' => $userId]);
    if (!$stmt->fetchColumn()) {
        return false;
    }
    ourthology_start_session();
    $homeStmt = $pdo->prepare('SELECT person_id FROM users WHERE id = :uid');
    $homeStmt->execute(['uid' => $userId]);
    $homePersonId = (int) $homeStmt->fetchColumn();
    if ($personId === $homePersonId) {
        unset($_SESSION['active_person_id']);
    } else {
        $_SESSION['active_person_id'] = $personId;
    }
    return true;
}
