<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/graph.php';

/**
 * Phase 40: "you've been tagged in a memory" emails. The one call site is
 * create_memory_tag() (includes/memory_tags.php), right after it inserts
 * a status='pending' row -- i.e. exactly the case where the tagged
 * person is a claimed, logged-in-capable account, and their tag needs
 * an actual approval (an unclaimed person's tag auto-approves with
 * nobody to notify, so that branch never calls this).
 *
 * Every public entry point here is best-effort and never throws: a
 * missing email/SMTP/encryption-key config, an unreachable mail server,
 * or a malformed stored address all just mean the notification quietly
 * doesn't go out (logged via error_log()) -- never a broken memory save
 * for whoever tagged someone.
 */

/**
 * $taggedPerson needs 'id', 'claimed_by_user_id', 'first_name', 'surname'
 * (the shape fetch_family_graph() already returns, which is what both
 * call sites in memory_tags.php pass in).
 */
function ourthology_notify_pending_memory_tag(PDO $pdo, int $timelineEntryId, array $taggedPerson, int $createdByUserId): void
{
    try {
        $taggedUserId = $taggedPerson['claimed_by_user_id'] ?? null;
        if ($taggedUserId === null) {
            return; // unclaimed -- auto-approved elsewhere, nobody to email
        }
        $taggedUserId = (int) $taggedUserId;

        $prefStmt = $pdo->prepare('SELECT notify_pending_tags, notify_email_enc FROM users WHERE id = :id');
        $prefStmt->execute(['id' => $taggedUserId]);
        $prefs = $prefStmt->fetch();
        if ($prefs === null || !$prefs['notify_pending_tags']) {
            return; // opted out (or never opted in) -- the default
        }

        $toEmail = ourthology_decrypt_notify_email($prefs['notify_email_enc']);
        if ($toEmail === null || $toEmail === '') {
            return; // opted in but never actually saved an address
        }

        $entryStmt = $pdo->prepare(
            'SELECT te.title, te.body, te.occurred_on, te.person_id AS owner_person_id,
                    op.first_name AS owner_first, op.surname AS owner_surname
             FROM timeline_entries te JOIN persons op ON op.id = te.person_id
             WHERE te.id = :eid'
        );
        $entryStmt->execute(['eid' => $timelineEntryId]);
        $entry = $entryStmt->fetch();
        if ($entry === null) {
            return;
        }

        $creatorStmt = $pdo->prepare(
            'SELECT p.id, p.first_name, p.surname FROM users u JOIN persons p ON p.id = u.person_id WHERE u.id = :uid'
        );
        $creatorStmt->execute(['uid' => $createdByUserId]);
        $creator = $creatorStmt->fetch();
        $creatorName = $creator ? person_display_name($creator) : 'Someone in your family';
        $creatorIsOwner = $creator !== null && (int) $creator['id'] === (int) $entry['owner_person_id'];

        $taggedFirstName = (string) ($taggedPerson['first_name'] ?? '');
        $ownerName = person_display_name(['first_name' => $entry['owner_first'], 'surname' => $entry['owner_surname']]);

        [$subject, $html, $text] = ourthology_render_pending_tag_email(
            $taggedFirstName,
            $creatorName,
            $ownerName,
            $creatorIsOwner,
            (string) ($entry['title'] ?? ''),
            (string) ($entry['body'] ?? ''),
            $entry['occurred_on'] ?: null
        );

        ourthology_send_email($toEmail, trim($taggedFirstName), $subject, $html, $text);
    } catch (Throwable $e) {
        error_log('ourthology: pending-tag notification failed for entry ' . $timelineEntryId . ': ' . $e->getMessage());
    }
}

/** Absolute https://ourthology.com/... URL, same host-detection pattern claim_link_url() (includes/graph.php) already uses for invite links. */
function ourthology_absolute_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'ourthology.com';
    return "$scheme://$host$path";
}

/** Returns [subject, htmlBody, textBody] for the "a memory is pending your approval" email, branded to match the site's own paper/ink/accent palette. */
function ourthology_render_pending_tag_email(
    string $taggedFirstName,
    string $creatorName,
    string $ownerName,
    bool $creatorIsOwner,
    string $entryTitle,
    string $entryBody,
    ?string $occurredOn
): array {
    $pendingUrl = ourthology_absolute_url('/pending.php?goto=waiting-on-you');

    $memoryLabel = $entryTitle !== '' ? $entryTitle : 'Untitled memory';
    $snippet = trim(preg_replace('/\s+/', ' ', $entryBody));
    if (mb_strlen($snippet) > 160) {
        $snippet = mb_substr($snippet, 0, 160) . '…';
    }
    $dateLabel = $occurredOn ? date('j M Y', strtotime($occurredOn)) : null;

    $subject = 'A memory is waiting for your OK — ourthology.com';

    $greetName = $taggedFirstName !== '' ? $taggedFirstName : 'there';
    $onWhoseTimeline = $creatorIsOwner ? 'their' : ($ownerName !== '' ? htmlspecialchars($ownerName, ENT_QUOTES) . "'s" : 'the');

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($subject, ENT_QUOTES) ?></title>
</head>
<body style="margin:0; padding:0; background-color:#F1ECDF; font-family:Georgia, 'Times New Roman', serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F1ECDF; padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background-color:#FBF8F1; border:1px solid #e4ddcb; border-radius:14px; overflow:hidden;">
          <tr>
            <td style="background-color:#9A2A2A; padding:22px 28px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="font-family:Georgia, 'Times New Roman', serif; font-size:20px; font-weight:bold; color:#FBF8F1;">
                    ourthology<span style="color:#e8c9c9; font-weight:normal;">.com</span>
                  </td>
                </tr>
                <tr>
                  <td style="font-family:Georgia, 'Times New Roman', serif; font-size:12.5px; font-style:italic; color:#e8c9c9; padding-top:2px;">
                    an anthology of us.
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:32px 28px 8px;">
              <p style="margin:0 0 16px; font-size:16px; line-height:1.5; color:#1a1714;">Hi <?= htmlspecialchars($greetName, ENT_QUOTES) ?>,</p>
              <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#1a1714;">
                <strong><?= htmlspecialchars($creatorName, ENT_QUOTES) ?></strong> tagged you in a memory on <?= $onWhoseTimeline ?> timeline on ourthology.com. It's waiting for your OK before it shows up there.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 28px 8px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F1ECDF; border:1px solid #e4ddcb; border-radius:10px;">
                <tr>
                  <td style="padding:16px 20px;">
                    <p style="margin:0 0 4px; font-size:15px; font-weight:bold; color:#1a1714;"><?= htmlspecialchars($memoryLabel, ENT_QUOTES) ?></p>
                    <?php if ($dateLabel): ?>
                      <p style="margin:0 0 8px; font-size:12.5px; color:#a39c8c;"><?= htmlspecialchars($dateLabel, ENT_QUOTES) ?></p>
                    <?php endif; ?>
                    <?php if ($snippet !== ''): ?>
                      <p style="margin:0; font-size:13.5px; line-height:1.5; color:#6b6357;"><?= htmlspecialchars($snippet, ENT_QUOTES) ?></p>
                    <?php endif; ?>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:28px 28px 8px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="border-radius:999px; background-color:#9A2A2A;">
                    <a href="<?= htmlspecialchars($pendingUrl, ENT_QUOTES) ?>" style="display:inline-block; padding:13px 30px; font-family:Georgia, 'Times New Roman', serif; font-size:15px; font-weight:bold; color:#FBF8F1; text-decoration:none; border-radius:999px;">Review this memory</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 28px 32px;">
              <p style="margin:0; font-size:12px; line-height:1.6; color:#a39c8c;">
                You're getting this because pending-memory-approval emails are turned on in your ourthology.com Account Settings. You can turn them off any time from My tree → Edit → Account Settings.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
    <?php
    $html = (string) ob_get_clean();

    $textLines = [
        "Hi {$greetName},",
        '',
        "{$creatorName} tagged you in a memory on " . ($creatorIsOwner ? 'their' : ($ownerName !== '' ? "{$ownerName}'s" : 'the')) . ' timeline on ourthology.com. It is waiting for your OK before it shows up there.',
        '',
        $memoryLabel . ($dateLabel ? " ({$dateLabel})" : ''),
    ];
    if ($snippet !== '') {
        $textLines[] = $snippet;
    }
    $textLines[] = '';
    $textLines[] = 'Review it here: ' . $pendingUrl;
    $textLines[] = '';
    $textLines[] = "You're getting this because pending-memory-approval emails are turned on in your ourthology.com Account Settings. You can turn them off any time from My tree -> Edit -> Account Settings.";
    $text = implode("\n", $textLines);

    return [$subject, $html, $text];
}

/**
 * Phase 48: "someone sent you a postcard" email -- same best-effort,
 * never-throws shape as ourthology_notify_pending_memory_tag() above, but
 * gated on its own notify_postcards preference rather than
 * notify_pending_tags: a postcard is a different kind of thing than a
 * pending memory-tag approval, so it gets its own opt-in instead of
 * silently riding on that one.
 */
function ourthology_notify_postcard_received(PDO $pdo, int $recipientPersonId, string $senderName): void
{
    try {
        $stmt = $pdo->prepare('SELECT claimed_by_user_id FROM persons WHERE id = :id');
        $stmt->execute(['id' => $recipientPersonId]);
        $recipientUserId = $stmt->fetchColumn();
        if (!$recipientUserId) {
            return; // unclaimed recipient (Phase 66: postcards/letters can now be addressed to anyone living, claimed or not) -- no account, so nowhere to email
        }
        $recipientUserId = (int) $recipientUserId;

        $prefStmt = $pdo->prepare('SELECT notify_postcards, notify_email_enc FROM users WHERE id = :id');
        $prefStmt->execute(['id' => $recipientUserId]);
        $prefs = $prefStmt->fetch();
        if ($prefs === null || !$prefs['notify_postcards']) {
            return; // opted out (or never opted in) -- the default
        }

        $toEmail = ourthology_decrypt_notify_email($prefs['notify_email_enc']);
        if ($toEmail === null || $toEmail === '') {
            return; // opted in but never actually saved an address
        }

        $recipStmt = $pdo->prepare('SELECT first_name FROM persons WHERE id = :id');
        $recipStmt->execute(['id' => $recipientPersonId]);
        $recipientFirstName = (string) ($recipStmt->fetchColumn() ?: '');

        [$subject, $html, $text] = ourthology_render_postcard_email($recipientFirstName, $senderName);
        ourthology_send_email($toEmail, trim($recipientFirstName), $subject, $html, $text);
    } catch (Throwable $e) {
        error_log('ourthology: postcard notification failed for person ' . $recipientPersonId . ': ' . $e->getMessage());
    }
}

/** Returns [subject, htmlBody, textBody] for the "you've got a postcard" email, same branded shell as ourthology_render_pending_tag_email() above. */
function ourthology_render_postcard_email(string $recipientFirstName, string $senderName): array
{
    $pendingUrl = ourthology_absolute_url('/pending.php?goto=waiting-on-you');
    $subject = "$senderName sent you a postcard — ourthology.com";
    $greetName = $recipientFirstName !== '' ? $recipientFirstName : 'there';

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($subject, ENT_QUOTES) ?></title>
</head>
<body style="margin:0; padding:0; background-color:#F1ECDF; font-family:Georgia, 'Times New Roman', serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F1ECDF; padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background-color:#FBF8F1; border:1px solid #e4ddcb; border-radius:14px; overflow:hidden;">
          <tr>
            <td style="background-color:#9A2A2A; padding:22px 28px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="font-family:Georgia, 'Times New Roman', serif; font-size:20px; font-weight:bold; color:#FBF8F1;">
                    ourthology<span style="color:#e8c9c9; font-weight:normal;">.com</span>
                  </td>
                </tr>
                <tr>
                  <td style="font-family:Georgia, 'Times New Roman', serif; font-size:12.5px; font-style:italic; color:#e8c9c9; padding-top:2px;">
                    an anthology of us.
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:32px 28px 8px;">
              <p style="margin:0 0 16px; font-size:16px; line-height:1.5; color:#1a1714;">Hi <?= htmlspecialchars($greetName, ENT_QUOTES) ?>,</p>
              <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#1a1714;">
                <strong><?= htmlspecialchars($senderName, ENT_QUOTES) ?></strong> just sent you a postcard on ourthology.com — a photo and a note, waiting for you to open.
              </p>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:8px 28px 8px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="border-radius:999px; background-color:#9A2A2A;">
                    <a href="<?= htmlspecialchars($pendingUrl, ENT_QUOTES) ?>" style="display:inline-block; padding:13px 30px; font-family:Georgia, 'Times New Roman', serif; font-size:15px; font-weight:bold; color:#FBF8F1; text-decoration:none; border-radius:999px;">Open your postcard</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 28px 32px;">
              <p style="margin:0; font-size:12px; line-height:1.6; color:#a39c8c;">
                You're getting this because postcard emails are turned on in your ourthology.com Account Settings. You can turn them off any time from My tree → Edit → Account Settings.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
    <?php
    $html = (string) ob_get_clean();

    $text = implode("\n", [
        "Hi {$greetName},",
        '',
        "{$senderName} just sent you a postcard on ourthology.com — a photo and a note, waiting for you to open.",
        '',
        'Open it here: ' . $pendingUrl,
        '',
        "You're getting this because postcard emails are turned on in your ourthology.com Account Settings. You can turn them off any time from My tree -> Edit -> Account Settings.",
    ]);

    return [$subject, $html, $text];
}

/**
 * Phase 53: "someone sent you a letter" email -- same best-effort,
 * never-throws shape as ourthology_notify_postcard_received() just
 * above, and deliberately gated on the SAME notify_postcards preference
 * rather than a second checkbox of its own: Phil's own request for this
 * feature was explicit that the existing "email me about postcards"
 * setting should cover letters too (see edit_person.php's checkbox
 * label, and the ALTER TABLE comment in db/schema.sql).
 */
function ourthology_notify_letter_received(PDO $pdo, int $recipientPersonId, string $senderName): void
{
    try {
        $stmt = $pdo->prepare('SELECT claimed_by_user_id FROM persons WHERE id = :id');
        $stmt->execute(['id' => $recipientPersonId]);
        $recipientUserId = $stmt->fetchColumn();
        if (!$recipientUserId) {
            return; // unclaimed recipient (Phase 66: postcards/letters can now be addressed to anyone living, claimed or not) -- no account, so nowhere to email
        }
        $recipientUserId = (int) $recipientUserId;

        $prefStmt = $pdo->prepare('SELECT notify_postcards, notify_email_enc FROM users WHERE id = :id');
        $prefStmt->execute(['id' => $recipientUserId]);
        $prefs = $prefStmt->fetch();
        if ($prefs === null || !$prefs['notify_postcards']) {
            return; // opted out (or never opted in) -- the default
        }

        $toEmail = ourthology_decrypt_notify_email($prefs['notify_email_enc']);
        if ($toEmail === null || $toEmail === '') {
            return; // opted in but never actually saved an address
        }

        $recipStmt = $pdo->prepare('SELECT first_name FROM persons WHERE id = :id');
        $recipStmt->execute(['id' => $recipientPersonId]);
        $recipientFirstName = (string) ($recipStmt->fetchColumn() ?: '');

        [$subject, $html, $text] = ourthology_render_letter_email($recipientFirstName, $senderName);
        ourthology_send_email($toEmail, trim($recipientFirstName), $subject, $html, $text);
    } catch (Throwable $e) {
        error_log('ourthology: letter notification failed for person ' . $recipientPersonId . ': ' . $e->getMessage());
    }
}

/** Returns [subject, htmlBody, textBody] for the "you've got a letter" email, same branded shell as ourthology_render_postcard_email() above. */
function ourthology_render_letter_email(string $recipientFirstName, string $senderName): array
{
    $pendingUrl = ourthology_absolute_url('/pending.php?goto=waiting-on-you');
    $subject = "$senderName sent you a letter — ourthology.com";
    $greetName = $recipientFirstName !== '' ? $recipientFirstName : 'there';

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($subject, ENT_QUOTES) ?></title>
</head>
<body style="margin:0; padding:0; background-color:#F1ECDF; font-family:Georgia, 'Times New Roman', serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F1ECDF; padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background-color:#FBF8F1; border:1px solid #e4ddcb; border-radius:14px; overflow:hidden;">
          <tr>
            <td style="background-color:#9A2A2A; padding:22px 28px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="font-family:Georgia, 'Times New Roman', serif; font-size:20px; font-weight:bold; color:#FBF8F1;">
                    ourthology<span style="color:#e8c9c9; font-weight:normal;">.com</span>
                  </td>
                </tr>
                <tr>
                  <td style="font-family:Georgia, 'Times New Roman', serif; font-size:12.5px; font-style:italic; color:#e8c9c9; padding-top:2px;">
                    an anthology of us.
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:32px 28px 8px;">
              <p style="margin:0 0 16px; font-size:16px; line-height:1.5; color:#1a1714;">Hi <?= htmlspecialchars($greetName, ENT_QUOTES) ?>,</p>
              <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#1a1714;">
                <strong><?= htmlspecialchars($senderName, ENT_QUOTES) ?></strong> just sent you a letter on ourthology.com — a longer note, waiting for you to read.
              </p>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:8px 28px 8px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="border-radius:999px; background-color:#9A2A2A;">
                    <a href="<?= htmlspecialchars($pendingUrl, ENT_QUOTES) ?>" style="display:inline-block; padding:13px 30px; font-family:Georgia, 'Times New Roman', serif; font-size:15px; font-weight:bold; color:#FBF8F1; text-decoration:none; border-radius:999px;">Read your letter</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 28px 32px;">
              <p style="margin:0; font-size:12px; line-height:1.6; color:#a39c8c;">
                You're getting this because postcard and letter emails are turned on in your ourthology.com Account Settings. You can turn them off any time from My tree → Edit → Account Settings.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
    <?php
    $html = (string) ob_get_clean();

    $text = implode("\n", [
        "Hi {$greetName},",
        '',
        "{$senderName} just sent you a letter on ourthology.com — a longer note, waiting for you to read.",
        '',
        'Read it here: ' . $pendingUrl,
        '',
        "You're getting this because postcard and letter emails are turned on in your ourthology.com Account Settings. You can turn them off any time from My tree -> Edit -> Account Settings.",
    ]);

    return [$subject, $html, $text];
}
