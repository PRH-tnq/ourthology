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
 *
 * Phase 40 added two more groups of keys to this same file, for the
 * "notify me about pending memory approvals" feature -- generate
 * notify_email_key with:
 *
 *   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
 *
 * (run that once, on the server or anywhere PHP is available -- the
 * output is the key itself, never store the command's output
 * anywhere else). It encrypts every user's notification email address
 * before it's ever written to the database (see includes/crypto.php);
 * losing this key makes every already-stored address permanently
 * unreadable (nothing recovers it, by design), so back it up
 * somewhere separate from both git and the database itself -- losing
 * it just means everyone re-enters their notification email once.
 *
 * The smtp_* keys are your outgoing-mail account's own settings
 * (Krystal cPanel -> Email Accounts, or whichever mailbox/relay
 * you're sending through) -- smtp_encryption is 'ssl' for an implicit-
 * TLS port like 465, 'tls' for STARTTLS on 587/25, or 'none' for an
 * unencrypted relay (only ever appropriate for something like
 * localhost). smtp_from_email is normally the same address as
 * smtp_user.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'thenati1_ourthology',
    'db_user' => 'thenati1_PRH_OGY',
    'db_pass' => 'REPLACE_WITH_THE_REAL_PASSWORD',

    'notify_email_key' => 'REPLACE_WITH_A_GENERATED_BASE64_32_BYTE_KEY',

    'smtp_host'       => 'mail.ourthology.com',
    'smtp_port'       => 465,
    'smtp_user'       => 'notifications@ourthology.com',
    'smtp_pass'       => 'REPLACE_WITH_THE_REAL_MAILBOX_PASSWORD',
    'smtp_encryption' => 'ssl',
    'smtp_from_email' => 'notifications@ourthology.com',
    'smtp_from_name'  => 'ourthology.com',
];
