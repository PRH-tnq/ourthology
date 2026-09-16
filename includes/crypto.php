<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Phase 40: app-layer encryption for the one piece of personal data this
 * app stores beyond what it needs to run itself -- a user's opt-in
 * notification email address (Account Settings -> "Notify me about
 * pending memory approvals"). Encrypted with libsodium's
 * crypto_secretbox (XSalsa20-Poly1305, authenticated symmetric
 * encryption -- bundled with PHP itself since 7.2, no dependency to
 * install) using a key kept in the same above-webroot secrets file as
 * the database password (see includes/db.php's own doc comment) --
 * never in the database itself, and never in git. That keeps the
 * plaintext email out of the database entirely: out of a mysqldump
 * backup, out of anyone who has read access to the database but not the
 * separate secrets file, out of the general query log were it ever
 * turned on. The app can still show the address back to its own owner,
 * since decryption only ever happens server-side, on that account
 * holder's own request (edit_person.php's Account Settings tab) --
 * nothing about the key or the plaintext ever reaches the browser.
 *
 * Storage shape: one VARBINARY column (users.notify_email_enc) holding
 * a random per-row nonce followed immediately by the ciphertext, so
 * encrypting the same address twice never produces the same bytes
 * (standard secretbox practice -- a fixed or reused nonce would leak
 * whether two rows hold the same email).
 */

/** Loads and validates the encryption key once per request; throws with a clear, actionable message rather than silently disabling notifications if it's missing or malformed. */
function ourthology_notify_email_key(): string
{
    static $key = null;
    if ($key === null) {
        $cfg = ourthology_config();
        $encoded = $cfg['notify_email_key'] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException(
                "Missing 'notify_email_key' in the secrets file -- see config.example.php for how to generate one."
            );
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                "'notify_email_key' in the secrets file isn't a valid base64-encoded " . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . "-byte key."
            );
        }
        $key = $decoded;
    }
    return $key;
}

/** Encrypts $email for storage; returns a binary blob (nonce + ciphertext) ready to bind straight into a VARBINARY column. */
function ourthology_encrypt_notify_email(string $email): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($email, $nonce, ourthology_notify_email_key());
    return $nonce . $cipher;
}

/**
 * Reverses ourthology_encrypt_notify_email(). Returns null for empty,
 * too-short, or authentication-failed input rather than throwing -- a
 * NULL column (nobody's set an address yet) or a corrupted/tampered
 * value should degrade to "no notification email on file," not fatal
 * whatever page happened to read it.
 */
function ourthology_decrypt_notify_email(?string $blob): ?string
{
    if ($blob === null || $blob === '') {
        return null;
    }
    $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (strlen($blob) <= $nonceLen) {
        return null;
    }
    $nonce = substr($blob, 0, $nonceLen);
    $cipher = substr($blob, $nonceLen);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, ourthology_notify_email_key());
    return $plain === false ? null : $plain;
}
