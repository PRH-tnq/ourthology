<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/graph.php';
require_once __DIR__ . '/media.php';

/**
 * Phase 53: "send a letter" -- a longer-form alternative to a postcard,
 * offered from the same compose pop-up ("need a longer form of message?").
 * Unlike a postcard (one photo, a short note, addressed to one/several/
 * everyone), a letter is addressed to exactly one recipient -- the
 * greeting ("Dear <first name>") only makes sense for one person -- and
 * its body is free-form rich text (a contenteditable field in the
 * browser) that can carry any number of inline photos, not just one, on
 * a single "page" rather than a flipped front/back card.
 *
 * The greeting and closing ("Best regards, <sender>") are NEVER stored --
 * both are generated at render time from sender_person_id/
 * recipient_person_id, exactly like a postcard's sender/recipient names
 * are joined in rather than frozen as text (see fetch_pending_letters_
 * for_person() etc. below). letters.body_html holds only the free-form
 * message the sender actually typed.
 *
 * A letter's inline images are their own letter_images rows (NOT media
 * rows -- same reasoning as a postcard's image in includes/media.php's
 * own header comment: not really "theirs" to keep yet) uploaded one at a
 * time via letter_image_upload.php AS the sender types, each starting
 * with letter_id NULL until create_letter() below claims the ones the
 * final, sanitized body actually references. See
 * ourthology_sanitize_letter_body_html() for why that claim step -- not
 * just the img src pattern -- is what actually proves ownership.
 */

/**
 * Whitelists a letter body's contenteditable-produced HTML down to a
 * small safe subset -- basic formatting tags plus img for inline photos
 * -- stripping every other tag (unwrapped, keeping its text content) and
 * every attribute except a validated img src. This is the only thing
 * standing between whatever a browser's contenteditable (or a client
 * bypassing the JS entirely) submits and what gets stored in
 * letters.body_html and later echoed as real HTML on someone else's
 * page, so nothing here is optional. An img's src is accepted only if it
 * matches this app's own /letter_image.php?id=<int> URL shape exactly --
 * that alone doesn't prove the current sender actually owns that image
 * (anyone could type that src by hand), which is why create_letter()
 * below re-checks real ownership via the letter_images table before
 * claiming an image for the new letter.
 */
function ourthology_sanitize_letter_body_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    // A generous but real cap -- this is rich HTML from a browser, not a
    // plain-text field, so it's naturally larger per character of
    // visible text; 300KB is far more than any genuine letter needs
    // (an inline photo is never inlined as HTML -- it's always a short
    // <img src="/letter_image.php?id=N"> reference) while still refusing
    // an obviously abusive submission outright rather than spending
    // DOMDocument parse time on it.
    if (strlen($html) > 300000) {
        throw new RuntimeException('That letter is too long to send.');
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="ourthology-letter-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $root = $doc->getElementById('ourthology-letter-root');
    if ($root === null) {
        return '';
    }

    $allowedTags = ['p' => true, 'br' => true, 'b' => true, 'strong' => true, 'i' => true, 'em' => true, 'u' => true, 'div' => true, 'img' => true];

    $clean = function (DOMNode $node) use (&$clean, $allowedTags): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!($child instanceof DOMElement)) {
                // Comments, processing instructions, etc. -- never kept.
                $node->removeChild($child);
                continue;
            }
            $tag = strtolower($child->tagName);
            if (!isset($allowedTags[$tag])) {
                // Unwrap: keep whatever text/allowed content was inside,
                // drop only the disallowed wrapper tag itself.
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            if ($tag === 'img') {
                $src = $child->getAttribute('src');
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $child->removeAttribute($attr->name);
                }
                if (preg_match('/^\/letter_image\.php\?id=[1-9][0-9]*$/', $src)) {
                    $child->setAttribute('src', $src);
                    $child->setAttribute('alt', '');
                } else {
                    $node->removeChild($child);
                }
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $child->removeAttribute($attr->name);
            }
            $clean($child);
        }
    };
    $clean($root);

    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= (string) $doc->saveHTML($child);
    }
    return trim($out);
}

/** Every /letter_image.php?id=N reference an already-sanitized body contains, in document order, de-duplicated. */
function ourthology_extract_letter_image_ids(string $sanitizedHtml): array
{
    preg_match_all('/\/letter_image\.php\?id=([1-9][0-9]*)/', $sanitizedHtml, $matches);
    return array_values(array_unique(array_map('intval', $matches[1] ?? [])));
}

/**
 * Plain-text rendering of a letter -- "Dear X, / body / Best regards, Y"
 * -- for the plain timeline_entries.body column a saved copy gets (see
 * save_letter_copy_to_timeline() below). The rich body_html + its inline
 * images stay on the original, never-deleted letters/letter_images rows
 * regardless of status (same "nothing deleted, just marked closed" rule
 * a postcard's own image follows) -- this is only ever a readable
 * summary for timeline card previews, never the letter's canonical copy.
 */
function ourthology_letter_plain_text(string $bodyHtml, string $recipientFirstName, string $senderDisplayName): string
{
    $withBreaks = (string) preg_replace('/<br\s*\/?>/i', "\n", $bodyHtml);
    $withBreaks = (string) preg_replace('/<\/(p|div)>/i', "\n\n", $withBreaks);
    $withBreaks = (string) preg_replace('/<img\b[^>]*>/i', '[photo]', $withBreaks);
    $plain = trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES));
    $plain = (string) preg_replace("/\n{3,}/", "\n\n", $plain);

    $greetName = $recipientFirstName !== '' ? $recipientFirstName : 'you';
    return "Dear {$greetName},\n\n{$plain}\n\nBest regards,\n{$senderDisplayName}";
}

/**
 * Creates one letters row (and, if $recordToOwnTimeline, a real
 * timeline_entries+media copy on the sender's own timeline -- same
 * optional "keep a copy" choice a postcard's compose form offers)
 * inside a single transaction, and reassigns every uploaded image the
 * sanitized body actually references from "uploaded, unattached" to
 * this new letter -- see ourthology_sanitize_letter_body_html()'s own
 * doc comment for why that reassignment, not just the img src pattern,
 * is the real ownership check. $rawBodyHtml is the untrusted
 * contenteditable HTML straight from the request; sanitizing happens
 * here so every caller gets it for free. Returns the new letter's id.
 */
function create_letter(
    PDO $pdo,
    int $senderPersonId,
    int $senderUserId,
    int $familyGroupId,
    int $recipientPersonId,
    string $rawBodyHtml,
    bool $recordToOwnTimeline
): int {
    $bodyHtml = ourthology_sanitize_letter_body_html($rawBodyHtml);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO letters (sender_person_id, created_by_user_id, family_group_id, recipient_person_id, body_html)
             VALUES (:sender, :uid, :gid, :rid, :body)'
        );
        $stmt->execute([
            'sender' => $senderPersonId,
            'uid'    => $senderUserId,
            'gid'    => $familyGroupId,
            'rid'    => $recipientPersonId,
            'body'   => $bodyHtml,
        ]);
        $letterId = (int) $pdo->lastInsertId();

        $imageIds = ourthology_extract_letter_image_ids($bodyHtml);
        if ($imageIds) {
            $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
            $claim = $pdo->prepare(
                "UPDATE letter_images SET letter_id = ? WHERE id IN ($placeholders) AND uploader_person_id = ? AND letter_id IS NULL"
            );
            $claim->execute([$letterId, ...$imageIds, $senderPersonId]);
        }

        if ($recordToOwnTimeline) {
            save_letter_copy_to_timeline($pdo, $letterId, $bodyHtml, $recipientPersonId, $senderPersonId, $senderPersonId, $senderUserId);
        }

        $pdo->commit();
        return $letterId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Copies a letter's body + every attached image into a real, independent
 * timeline_entries + media row set for $ownerPersonId -- the same
 * "an actual, independent copy, not a shared reference" shape
 * store_postcard_copy_as_media() already gives postcards (see that
 * function's own doc comment in includes/media.php, which this reuses
 * as-is -- copying a stored file into a person's own media folder is
 * entirely generic, nothing postcard-specific about it). Does NOT open
 * its own transaction -- every caller already has one open (create_letter()
 * above, or the recipient's own "save" mutation in letter.php), so
 * nesting here would either throw or silently misbehave depending on the
 * PDO driver. Returns the new timeline_entries id.
 */
function save_letter_copy_to_timeline(PDO $pdo, int $letterId, string $bodyHtml, int $recipientPersonId, int $senderPersonId, int $ownerPersonId, int $ownerUserId): int
{
    $senderRow = person_row($pdo, $senderPersonId);
    $recipientRow = person_row($pdo, $recipientPersonId);
    $senderName = $senderRow !== null ? person_display_name($senderRow) : '';
    $recipientFirst = (string) ($recipientRow['first_name'] ?? '');

    $plainBody = ourthology_letter_plain_text($bodyHtml, $recipientFirst, $senderName);
    $title = $ownerPersonId === $senderPersonId
        ? 'Letter to ' . ($recipientRow !== null ? person_display_name($recipientRow) : 'a family member')
        : 'Letter from ' . ($senderName !== '' ? $senderName : 'a family member');

    $stmt = $pdo->prepare(
        'INSERT INTO timeline_entries (person_id, entry_type, title, body, occurred_on, visibility, created_by_user_id)
         VALUES (:pid, :type, :title, :body, CURDATE(), :vis, :uid)'
    );
    $stmt->execute([
        'pid'   => $ownerPersonId,
        'type'  => 'note',
        'title' => $title,
        'body'  => $plainBody,
        'vis'   => 'private',
        'uid'   => $ownerUserId,
    ]);
    $entryId = (int) $pdo->lastInsertId();

    $imgStmt = $pdo->prepare('SELECT file_path, mime_type FROM letter_images WHERE letter_id = :lid ORDER BY id ASC');
    $imgStmt->execute(['lid' => $letterId]);
    foreach ($imgStmt->fetchAll() as $img) {
        $copy = store_postcard_copy_as_media((string) $img['file_path'], (string) $img['mime_type'], $ownerPersonId);
        $pdo->prepare(
            'INSERT INTO media (timeline_entry_id, file_path, mime_type, byte_size, width, height)
             VALUES (:eid, :path, :mime, :size, :w, :h)'
        )->execute([
            'eid'  => $entryId,
            'path' => $copy['file_path'],
            'mime' => $copy['mime_type'],
            'size' => $copy['byte_size'],
            'w'    => $copy['width'],
            'h'    => $copy['height'],
        ]);
    }

    return $entryId;
}

/** Letters addressed to $personId that are still 'pending' or 'read' -- shown on pending.php's "Waiting on you" list, same shape as fetch_pending_postcards_for_person() in includes/postcards.php. */
function fetch_pending_letters_for_person(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare(
        "SELECT l.id AS letter_id, l.status, l.created_at AS received_at,
                sp.first_name AS sender_first, sp.surname AS sender_surname
         FROM letters l
         JOIN persons sp ON sp.id = l.sender_person_id
         WHERE l.recipient_person_id = :pid AND l.status IN ('pending','read')
         ORDER BY l.created_at DESC"
    );
    $stmt->execute(['pid' => $personId]);
    return $stmt->fetchAll();
}

/** Letters $senderPersonId sent that are still waiting on their one recipient -- "Sent by you, waiting on them", mirroring fetch_outgoing_postcards_for_person(). */
function fetch_outgoing_letters_for_person(PDO $pdo, int $senderPersonId): array
{
    $stmt = $pdo->prepare(
        "SELECT l.id AS letter_id, l.status, l.created_at AS sent_at,
                rp.first_name AS recipient_first, rp.surname AS recipient_surname
         FROM letters l
         JOIN persons rp ON rp.id = l.recipient_person_id
         WHERE l.sender_person_id = :pid AND l.status IN ('pending','read')
         ORDER BY l.created_at DESC"
    );
    $stmt->execute(['pid' => $senderPersonId]);
    return $stmt->fetchAll();
}

/** One letter, but ONLY if it's addressed to $recipientPersonId -- the ownership check IS the query, same defensive pattern fetch_postcard_recipient_row() uses. Null if it doesn't exist or isn't theirs. */
function fetch_letter_for_recipient(PDO $pdo, int $letterId, int $recipientPersonId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT l.id AS letter_id, l.status, l.body_html, l.timeline_entry_id, l.created_at,
                l.sender_person_id, l.recipient_person_id,
                sp.first_name AS sender_first, sp.surname AS sender_surname
         FROM letters l
         JOIN persons sp ON sp.id = l.sender_person_id
         WHERE l.id = :id AND l.recipient_person_id = :pid"
    );
    $stmt->execute(['id' => $letterId, 'pid' => $recipientPersonId]);
    return $stmt->fetch() ?: null;
}

/** Marks a still-pending letter 'read' (no-op if it's already past that). */
function mark_letter_read(PDO $pdo, int $letterId): void
{
    $pdo->prepare("UPDATE letters SET status = 'read', read_at = NOW() WHERE id = :id AND status = 'pending'")
        ->execute(['id' => $letterId]);
}

/** Saves a letter to $personId's own timeline (a real, independent copy -- see save_letter_copy_to_timeline() above) and marks it 'saved'. Returns the new timeline_entries id. */
function save_letter_to_timeline(PDO $pdo, array $letterRow, int $personId, int $userId): int
{
    $pdo->beginTransaction();
    try {
        $entryId = save_letter_copy_to_timeline(
            $pdo,
            (int) $letterRow['letter_id'],
            (string) $letterRow['body_html'],
            (int) $letterRow['recipient_person_id'],
            (int) $letterRow['sender_person_id'],
            $personId,
            $userId
        );
        $pdo->prepare("UPDATE letters SET status = 'saved', timeline_entry_id = :eid, resolved_at = NOW() WHERE id = :id")
            ->execute(['eid' => $entryId, 'id' => $letterRow['letter_id']]);
        $pdo->commit();
        return $entryId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Discards a letter without saving it -- a status flip only, same as discard_postcard(). Nothing is deleted. */
function discard_letter(PDO $pdo, int $letterId): void
{
    $pdo->prepare("UPDATE letters SET status = 'discarded', resolved_at = NOW() WHERE id = :id")
        ->execute(['id' => $letterId]);
}

/** One uploaded-but-not-yet-attached (or already attached) letter image, plus enough of its letter to decide visibility -- for letter_image.php's own access check. Null if it doesn't exist. */
function fetch_letter_image_for_view(PDO $pdo, int $imageId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT li.id, li.file_path, li.mime_type, li.uploader_person_id, li.letter_id,
                l.sender_person_id, l.recipient_person_id
         FROM letter_images li
         LEFT JOIN letters l ON l.id = li.letter_id
         WHERE li.id = :id'
    );
    $stmt->execute(['id' => $imageId]);
    return $stmt->fetch() ?: null;
}

/**
 * True if $viewerPersonId may view this letter image. Deliberately its
 * own small check, same reasoning as postcard_media.php's own inline
 * access check: a letter's images are addressed, not broadcast, so only
 * its sender or its one recipient may ever see them once attached. Before
 * that (letter_id still NULL, mid-composition) only the person who
 * uploaded it may preview their own still-unsent draft.
 */
function can_view_letter_image(array $image, int $viewerPersonId): bool
{
    if ($image['letter_id'] === null) {
        return (int) $image['uploader_person_id'] === $viewerPersonId;
    }
    return (int) $image['sender_person_id'] === $viewerPersonId
        || (int) $image['recipient_person_id'] === $viewerPersonId;
}
