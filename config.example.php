<?php
/**
 * TEMPLATE ONLY — do not fill this in and do not commit real credentials
 * to git. Copy the contents of this file's `return [...]` block into a
 * NEW file on the server at:
 *
 *   <home>/ourthology-secrets/config.php
 *
 * i.e. one directory ABOVE the ourthology.com document root, created
 * directly in cPanel File Manager — never through git, never through
 * Claude. That keeps the real database password out of GitHub entirely
 * and out of the web-servable folder, same principle as thenat1's
 * secrets-above-webroot setup.
 *
 * db_host is almost always 'localhost' on Krystal shared hosting.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'thenati1_ourthology',
    'db_user' => 'thenati1_PRH_OGY',
    'db_pass' => 'REPLACE_WITH_THE_REAL_PASSWORD',
];
