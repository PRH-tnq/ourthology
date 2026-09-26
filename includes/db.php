<?php
declare(strict_types=1);

/**
 * Loads DB credentials from a config file kept ABOVE the web root
 * (never inside this git repo, never inside the public ourthology.com
 * folder). Create that file by hand on the server — see
 * config.example.php at the repo root for the exact format expected.
 *
 * Expected location: <home>/ourthology-secrets/config.php
 * i.e. one level above the ourthology.com document root.
 */
function ourthology_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = dirname(__DIR__, 2) . '/ourthology-secrets/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            error_log('ourthology: missing config file at ' . $path);
            die('Configuration error. (Missing secrets file — see includes/db.php for the expected path.)');
        }
        $config = require $path;
        foreach (['db_host', 'db_name', 'db_user', 'db_pass'] as $key) {
            if (!array_key_exists($key, $config)) {
                http_response_code(500);
                die("Configuration error. (Missing '$key' in secrets file.)");
            }
        }
    }
    return $config;
}

function ourthology_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = ourthology_config();
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_name']);
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * Phase 96: a friendly page instead of a blank one when something fails
 * that nothing else caught. The case that prompted it: new code uploaded
 * before its db/migrations file had been run in phpMyAdmin -- every query
 * touching the new table/column failed, and since the timeline is where
 * everyone lands after logging in, it looked exactly like "login is
 * broken" (a blank white page). Now the visitor gets a plain message and
 * the server's error log says precisely what's missing and what to run.
 * JSON endpoints (the chunked uploader, the pop-ups' fetches) get a JSON
 * error instead, so their own "try again" handling still works.
 */
function ourthology_handle_uncaught(Throwable $e): void
{
    $dbBehind = $e instanceof PDOException && in_array((string) $e->getCode(), ['42S02', '42S22'], true);
    error_log(($dbBehind
        ? 'ourthology: DATABASE IS BEHIND THE CODE -- run the newest file(s) in db/migrations in phpMyAdmin. '
        : 'ourthology uncaught ') . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    $message = $dbBehind
        ? "ourthology is part-way through an update, so this page can't open just yet. Please try again in a few minutes."
        : 'Something went wrong on our side. Please try again in a moment.';

    $wantsJson = false;
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type: application/json') === 0) {
            $wantsJson = true;
        }
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json') || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')) {
        $wantsJson = true;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Cache-Control: no-store');
    }
    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['ok' => false, 'error' => $message]);
        return;
    }
    $safe = htmlspecialchars($message, ENT_QUOTES);
    $fullPage = !headers_sent();
    if ($fullPage) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Back soon — ourthology.com</title><link rel="stylesheet" href="/styles.css?v=28"></head><body><div class="card">';
    } else {
        // part of the page had already been sent -- still say something
        echo '<div class="card" style="margin:24px auto;">';
    }
    echo '<p class="wordmark" style="margin:0 0 14px;">ourthology<span class="tld">.com</span></p>'
        . '<p style="font-size:16px;line-height:1.5;margin:0 0 18px;">' . $safe . '</p>'
        . '<a class="btn-primary" style="display:block;text-align:center;text-decoration:none;" href="' . htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? '/'), ENT_QUOTES) . '">Try again</a>'
        . '</div>';
    if ($fullPage) {
        echo '</body></html>';
    }
}
set_exception_handler('ourthology_handle_uncaught');
