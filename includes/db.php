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
