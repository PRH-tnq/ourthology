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

/** Fetch the logged-in user's row + their linked person row, or null. */
function current_user_with_person(): ?array
{
    $userId = current_user_id();
    if ($userId === null) {
        return null;
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
