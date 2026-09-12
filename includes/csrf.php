<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function csrf_token(): string
{
    ourthology_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Call at the top of every POST handler. Exits with 400 on mismatch. */
function csrf_check(): void
{
    ourthology_start_session();
    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(400);
        die('Your session expired or this form was submitted incorrectly. Please go back and try again.');
    }
}
