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

/** Call at the top of any page that requires a logged-in user. */
function require_login(): int
{
    $id = current_user_id();
    if ($id === null) {
        header('Location: /login.php');
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
