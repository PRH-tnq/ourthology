<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Phase 40: a small, dependency-free SMTP client -- this repo has no
 * composer/vendor directory (see architecture.md: no build tooling, just
 * upload-to-GitHub-then-to-Krystal), so rather than hand-vendoring a
 * third-party mailer library, this talks raw SMTP over a PHP stream
 * socket. It supports exactly what Phil's own mailbox/relay needs:
 * implicit TLS (port 465) or STARTTLS (587/25), AUTH PLAIN, and a
 * multipart/alternative (HTML + plain text) message. Nothing more --
 * no attachments, no CC/BCC, no connection pooling -- this app sends
 * exactly one kind of email (a pending-memory-tag notice) to exactly
 * one recipient at a time.
 *
 * Credentials live in the same above-webroot secrets file as the
 * database password (see includes/db.php) -- see config.example.php for
 * the exact keys expected. Every public entry point here is
 * best-effort: on any failure (missing config, connection refused, SMTP
 * error response) it logs via error_log() and returns false rather than
 * throwing, so a mail hiccup never turns into a broken page for
 * whatever triggered the send.
 */

/** Loads and lightly validates the SMTP config block once per request. */
function ourthology_smtp_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $full = ourthology_config();
        $required = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $full)) {
                throw new RuntimeException("Missing '$key' in the secrets file -- see config.example.php for the SMTP keys expected.");
            }
        }
        if (!in_array($full['smtp_encryption'], ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException("'smtp_encryption' in the secrets file must be 'tls', 'ssl', or 'none'.");
        }
        $cfg = [
            'host'       => (string) $full['smtp_host'],
            'port'       => (int) $full['smtp_port'],
            'user'       => (string) $full['smtp_user'],
            'pass'       => (string) $full['smtp_pass'],
            'encryption' => (string) $full['smtp_encryption'],
            'from_email' => (string) $full['smtp_from_email'],
            'from_name'  => (string) $full['smtp_from_name'],
        ];
    }
    return $cfg;
}

/**
 * Sends one HTML email (with a plain-text alternative) to a single
 * recipient. Returns true on a confirmed SMTP accept, false on any
 * failure (logged via error_log(), message included) -- callers should
 * treat this as best-effort and never let a false stop whatever
 * database change triggered the send.
 */
function ourthology_send_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool
{
    try {
        $cfg = ourthology_smtp_config();
    } catch (Throwable $e) {
        error_log('ourthology mailer: ' . $e->getMessage());
        return false;
    }

    $socket = null;
    try {
        $socket = ourthology_smtp_connect($cfg);
        ourthology_smtp_send_message($socket, $cfg, $toEmail, $toName, $subject, $htmlBody, $textBody);
        ourthology_smtp_command($socket, "QUIT\r\n", null); // best-effort; response (if any) isn't checked
        return true;
    } catch (Throwable $e) {
        error_log('ourthology mailer: failed to send to ' . $toEmail . ': ' . $e->getMessage());
        return false;
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}

/** Opens the socket, does STARTTLS if requested, and authenticates. Throws RuntimeException on any step that doesn't get the SMTP response class it expected. */
function ourthology_smtp_connect(array $cfg)
{
    $transport = $cfg['encryption'] === 'ssl' ? 'ssl' : 'tcp';
    $remote = sprintf('%s://%s:%d', $transport, $cfg['host'], $cfg['port']);
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
        throw new RuntimeException("Couldn't connect to $cfg[host]:$cfg[port] ($errstr)");
    }
    stream_set_timeout($socket, 15);

    ourthology_smtp_read($socket, 220); // server greeting
    $localName = $_SERVER['SERVER_NAME'] ?? 'ourthology.com';
    ourthology_smtp_command($socket, "EHLO $localName\r\n", 250);

    if ($cfg['encryption'] === 'tls') {
        ourthology_smtp_command($socket, "STARTTLS\r\n", 220);
        $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            throw new RuntimeException('STARTTLS negotiation failed');
        }
        // Servers commonly require re-greeting after the TLS handshake.
        ourthology_smtp_command($socket, "EHLO $localName\r\n", 250);
    }

    $authToken = base64_encode("\0" . $cfg['user'] . "\0" . $cfg['pass']);
    ourthology_smtp_command($socket, "AUTH PLAIN $authToken\r\n", 235);

    return $socket;
}

/** Builds the multipart/alternative MIME message and runs the MAIL FROM / RCPT TO / DATA sequence. */
function ourthology_smtp_send_message($socket, array $cfg, string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): void
{
    ourthology_smtp_command($socket, 'MAIL FROM:<' . $cfg['from_email'] . ">\r\n", 250);
    ourthology_smtp_command($socket, 'RCPT TO:<' . $toEmail . ">\r\n", [250, 251]);
    ourthology_smtp_command($socket, "DATA\r\n", 354);

    $boundary = 'ourthology-' . bin2hex(random_bytes(12));
    $messageId = '<' . bin2hex(random_bytes(16)) . '@ourthology.com>';
    $date = gmdate('D, d M Y H:i:s') . ' +0000';

    $headers = [
        'Date: ' . $date,
        'Message-ID: ' . $messageId,
        'From: ' . ourthology_encode_header($cfg['from_name']) . ' <' . $cfg['from_email'] . '>',
        'To: ' . ourthology_encode_header($toName) . ' <' . $toEmail . '>',
        'Subject: ' . ourthology_encode_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $body = '';
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($textBody) . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($htmlBody) . "\r\n";
    $body .= "--$boundary--\r\n";

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    // Dot-stuffing: a line consisting of (or starting with) a single "."
    // would otherwise be read by the server as the end-of-DATA marker.
    $message = preg_replace('/^\./m', '..', $message);

    ourthology_smtp_write($socket, $message . "\r\n.\r\n");
    ourthology_smtp_read($socket, 250);
}

/** RFC 2047-encodes a header value only when it actually contains non-ASCII text; left plain otherwise so simple headers stay readable in transit. */
function ourthology_encode_header(string $value): string
{
    if ($value === '' || mb_check_encoding($value, 'ASCII')) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function ourthology_smtp_write($socket, string $data): void
{
    if (@fwrite($socket, $data) === false) {
        throw new RuntimeException('SMTP write failed');
    }
}

/** Sends one command line and checks the response against $expectCode (an int, an array of acceptable ints, or null to skip checking). */
function ourthology_smtp_command($socket, string $command, $expectCode): string
{
    ourthology_smtp_write($socket, $command);
    if ($expectCode === null) {
        return '';
    }
    return ourthology_smtp_read($socket, $expectCode);
}

/** Reads one SMTP response (possibly multi-line, "250-...\r\n250 ...\r\n") and throws unless its leading code matches $expectCode. */
function ourthology_smtp_read($socket, $expectCode): string
{
    $expected = is_array($expectCode) ? $expectCode : [$expectCode];
    $full = '';
    while (true) {
        $line = fgets($socket, 1024);
        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            $why = !empty($meta['timed_out']) ? 'timed out' : 'connection closed';
            throw new RuntimeException("SMTP read failed ($why) after: " . trim($full));
        }
        $full .= $line;
        // "250-" continues; "250 " (a space) is the final line of the response.
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    $code = (int) substr($full, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('Unexpected SMTP response: ' . trim($full));
    }
    return $full;
}
