<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/graph.php';
require_once __DIR__ . '/media.php';

/**
 * Phase 48: "send a postcard" -- a lightweight, personal alternative to a
 * tagged memory. A sender picks a photo, writes a message, and addresses
 * it to one person, several, or everyone claimed in their family group;
 * it shows up in each recipient's Pending queue exactly like a
 * relationship/partnership/memory-tag request (fetch_pending_for_user()
 * in graph.php, fetch_pending_memory_tags_for_user() in memory_tags.php).
 * Opening it marks it read; the recipient then either saves it (a real,
 * independent timeline_entries + media row they own, same INSERT shape
 * add_entry.php's own create path uses) or discards it.
 *
 * A postcard's own image lives outside timeline_entries/media entirely
 * until someone actually saves a copy of it -- see
 * store_postcard_image()/store_postcard_copy_as_media() in media.php for
 * why, and postcard_media.php for how it's served in the meantime.
 */

/** Every claimed person in $familyGroupId except $excludePersonId -- the pool a sender may address a postcard to. Ordered by first name for a stable picker list. */
function fetch_postcard_recipient_options(PDO $pdo, int $familyGroupId, int $excludePersonId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, first_name, middle_name, surname
         FROM persons
         WHERE family_group_id = :gid AND claimed_by_user_id IS NOT NULL AND id <> :self
         ORDER BY first_name, surname'
    );
    $stmt->execute(['gid' => $familyGroupId, 'self' => $excludePersonId]);
    return $stmt->fetchAll();
}

/**
 * Creates one postcard plus one postcard_recipients row per recipient
 * (and, if $recordToOwnTimeline, a real timeline_entries+media row on the
 * sender's own timeline) inside a single transaction. $storedImage is the
 * ['file_path','mime_type','byte_size','width','height'] shape
 * store_postcard_image() returns. Returns the postcard's new id.
 */
function create_postcard(
    PDO $pdo,
    int $senderPersonId,
    int $senderUserId,
    int $familyGroupId,
    array $storedImage,
    string $message,
    string $audience,
    array $recipientPersonIds,
    bool $recordToOwnTimeline,
    ?string $toLine = null,
    ?string $fromLine = null
): int {
    // Phase 54: "as if it had been done by hand, at a jaunty angle that
    // changes each time a new postcard is made" -- picked once here, at
    // creation, and stored, so the same postcard always shows the same
    // angle rather than re-rolling on every page load (see the postmark
    // <g transform="rotate(...)"> in both timeline.php's compose preview
    // and pending.php's read view). Range is wide enough to read as
    // "hand-stamped, not machine-straight" without ever landing upside-down.
    $postmarkAngle = random_int(-18, 22);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO postcards (sender_person_id, created_by_user_id, family_group_id, image_path, image_mime_type, image_byte_size, image_width, image_height, message, audience, postmark_angle, to_line, from_line)
             VALUES (:sender, :uid, :gid, :path, :mime, :size, :w, :h, :msg, :aud, :angle, :toln, :froml)'
        );
        $stmt->execute([
            'sender' => $senderPersonId,
            'uid'    => $senderUserId,
            'gid'    => $familyGroupId,
            'path'   => $storedImage['file_path'],
            'mime'   => $storedImage['mime_type'],
            'size'   => $storedImage['byte_size'],
            'w'      => $storedImage['width'],
            'h'      => $storedImage['height'],
            'msg'    => $message,
            'aud'    => $audience,
            'angle'  => $postmarkAngle,
            'toln'   => $toLine !== null && $toLine !== '' ? $toLine : null,
            'froml'  => $fromLine !== null && $fromLine !== '' ? $fromLine : null,
        ]);
        $postcardId = (int) $pdo->lastInsertId();

        $recipStmt = $pdo->prepare(
            'INSERT INTO postcard_recipients (postcard_id, recipient_person_id) VALUES (:pcid, :rid)'
        );
        foreach ($recipientPersonIds as $rid) {
            $recipStmt->execute(['pcid' => $postcardId, 'rid' => $rid]);
        }

        if ($recordToOwnTimeline) {
            $ownCopy = store_postcard_copy_as_media($storedImage['file_path'], $storedImage['mime_type'], $senderPersonId);
            $entryStmt = $pdo->prepare(
                'INSERT INTO timeline_entries (person_id, entry_type, origin, title, body, occurred_on, visibility, created_by_user_id)
                 VALUES (:pid, :type, :origin, :title, :body, CURDATE(), :vis, :uid)'
            );
            $entryStmt->execute([
                'pid'    => $senderPersonId,
                'type'   => 'photo',
                'origin' => 'postcard',
                'title'  => ourthology_postcard_sent_title($pdo, $audience, $recipientPersonIds),
                'body'   => $message !== '' ? $message : null,
                'vis'    => 'private',
                'uid'    => $senderUserId,
            ]);
            $entryId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO media (timeline_entry_id, file_path, mime_type, byte_size, width, height)
                 VALUES (:eid, :path, :mime, :size, :w, :h)'
            )->execute([
                'eid'  => $entryId,
                'path' => $ownCopy['file_path'],
                'mime' => $ownCopy['mime_type'],
                'size' => $ownCopy['byte_size'],
                'w'    => $ownCopy['width'],
                'h'    => $ownCopy['height'],
            ]);
        }

        $pdo->commit();
        return $postcardId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** "Postcard to Jane Hart" / "Postcard to Jane and Tom" / "Postcard to Jane, Tom, and 2 others" / "Postcard to everyone" -- the sender's own timeline title when they choose to record a copy. */
function ourthology_postcard_sent_title(PDO $pdo, string $audience, array $recipientPersonIds): string
{
    if ($audience === 'everyone') {
        return 'Postcard to everyone';
    }
    if (!$recipientPersonIds) {
        return 'Postcard';
    }
    $placeholders = implode(',', array_fill(0, count($recipientPersonIds), '?'));
    $stmt = $pdo->prepare("SELECT first_name, surname FROM persons WHERE id IN ($placeholders)");
    $stmt->execute(array_values($recipientPersonIds));
    $names = array_map(fn($p) => person_display_name($p), $stmt->fetchAll());
    if (count($names) === 1) {
        return 'Postcard to ' . $names[0];
    }
    if (count($names) === 2) {
        return 'Postcard to ' . $names[0] . ' and ' . $names[1];
    }
    $rest = count($names) - 2;
    return 'Postcard to ' . $names[0] . ', ' . $names[1] . ', and ' . $rest . ' other' . ($rest === 1 ? '' : 's');
}

/** Postcards addressed to $personId that are still 'pending' or 'read' (i.e. not yet resolved) -- shown on pending.php's "Waiting on you" list. */
function fetch_pending_postcards_for_person(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare(
        "SELECT pr.id AS recipient_row_id, pr.postcard_id, pr.status, pr.created_at AS received_at,
                pc.message, pc.image_path, pc.image_mime_type,
                sp.first_name AS sender_first, sp.surname AS sender_surname
         FROM postcard_recipients pr
         JOIN postcards pc ON pc.id = pr.postcard_id
         JOIN persons sp ON sp.id = pc.sender_person_id
         WHERE pr.recipient_person_id = :pid AND pr.status IN ('pending','read')
         ORDER BY pr.created_at DESC"
    );
    $stmt->execute(['pid' => $personId]);
    return $stmt->fetchAll();
}

/** One postcard_recipients row (plus its postcard and sender), but ONLY if it's addressed to $personId -- the ownership check IS the query, same defensive pattern pending.php's relationship/partnership/tag actions already use. Null if it doesn't exist or isn't theirs. */
function fetch_postcard_recipient_row(PDO $pdo, int $recipientRowId, int $personId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT pr.id AS recipient_row_id, pr.postcard_id, pr.status, pr.timeline_entry_id, pr.created_at AS received_at,
                pc.message, pc.image_path, pc.image_mime_type, pc.sender_person_id,
                pc.postmark_angle, pc.to_line, pc.from_line,
                sp.first_name AS sender_first, sp.surname AS sender_surname
         FROM postcard_recipients pr
         JOIN postcards pc ON pc.id = pr.postcard_id
         JOIN persons sp ON sp.id = pc.sender_person_id
         WHERE pr.id = :id AND pr.recipient_person_id = :pid"
    );
    $stmt->execute(['id' => $recipientRowId, 'pid' => $personId]);
    return $stmt->fetch() ?: null;
}

/**
 * Postcards $personId SENT that are still waiting on at least one
 * recipient -- one row per still-unresolved recipient, not per postcard,
 * so an "everyone" send shows exactly which people haven't actioned it
 * yet and each one drops off the list independently. Mirrors
 * fetch_outgoing_memory_tags_for_user()'s per-recipient granularity in
 * memory_tags.php rather than collapsing multi-recipient sends into one
 * row.
 *
 * Phase 55: "when a user opens a postcard I've sent them, immediately
 * delete it from my pending list" -- only status='pending' counts as
 * "waiting on them" now. Previously this also included 'read' (opened
 * but not yet saved/discarded), so a recipient who'd already looked at
 * it still showed up here as outstanding; now a row drops off the
 * sender's list the moment mark_postcard_read() flips it to 'read',
 * rather than waiting for the recipient to also save or discard it.
 */
function fetch_outgoing_postcards_for_person(PDO $pdo, int $senderPersonId): array
{
    $stmt = $pdo->prepare(
        "SELECT pr.id AS recipient_row_id, pr.status, pr.created_at AS sent_at,
                pc.id AS postcard_id,
                rp.first_name AS recipient_first, rp.surname AS recipient_surname
         FROM postcard_recipients pr
         JOIN postcards pc ON pc.id = pr.postcard_id
         JOIN persons rp ON rp.id = pr.recipient_person_id
         WHERE pc.sender_person_id = :pid AND pr.status = 'pending'
         ORDER BY pr.created_at DESC"
    );
    $stmt->execute(['pid' => $senderPersonId]);
    return $stmt->fetchAll();
}

/** Marks a still-pending postcard 'read' (no-op if it's already past that). */
function mark_postcard_read(PDO $pdo, int $recipientRowId): void
{
    $pdo->prepare("UPDATE postcard_recipients SET status = 'read', read_at = NOW() WHERE id = :id AND status = 'pending'")
        ->execute(['id' => $recipientRowId]);
}

/** Saves a postcard to $personId's own timeline -- a real, independent entry with its own copy of the image (never a shared reference; see store_postcard_copy_as_media()'s own doc comment) -- and marks the recipient row 'saved'. Returns the new timeline_entries id. */
function save_postcard_to_timeline(PDO $pdo, array $recipientRow, int $personId, int $userId): int
{
    $pdo->beginTransaction();
    try {
        $copy = store_postcard_copy_as_media($recipientRow['image_path'], $recipientRow['image_mime_type'], $personId);
        $senderName = person_display_name(['first_name' => $recipientRow['sender_first'], 'surname' => $recipientRow['sender_surname']]);
        $stmt = $pdo->prepare(
            'INSERT INTO timeline_entries (person_id, entry_type, origin, title, body, occurred_on, visibility, created_by_user_id)
             VALUES (:pid, :type, :origin, :title, :body, CURDATE(), :vis, :uid)'
        );
        $stmt->execute([
            'pid'    => $personId,
            'type'   => 'photo',
            'origin' => 'postcard',
            'title'  => 'Postcard from ' . $senderName,
            'body'   => $recipientRow['message'] !== '' ? $recipientRow['message'] : null,
            'vis'    => 'private',
            'uid'    => $userId,
        ]);
        $entryId = (int) $pdo->lastInsertId();
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
        $pdo->prepare("UPDATE postcard_recipients SET status = 'saved', timeline_entry_id = :eid, resolved_at = NOW() WHERE id = :id")
            ->execute(['eid' => $entryId, 'id' => $recipientRow['recipient_row_id']]);
        $pdo->commit();
        return $entryId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Discards a postcard without saving it -- a status flip only. Nothing is deleted: the sender's original image, and this now-closed recipient row, both stay exactly as they were. */
function discard_postcard(PDO $pdo, int $recipientRowId): void
{
    $pdo->prepare("UPDATE postcard_recipients SET status = 'discarded', resolved_at = NOW() WHERE id = :id")
        ->execute(['id' => $recipientRowId]);
}

/**
 * Phase 54: the postage-stamp + postmark graphic, shared byte-for-byte
 * between timeline.php's compose preview and pending.php's read view (it
 * used to be duplicated inline in both, which is exactly how they drifted
 * out of sync before -- one function, one place to get it right).
 *
 * $angleDeg is the postmark's "hand-stamped" rotation -- the compose
 * preview passes a freshly rolled one (nothing to persist yet), the read
 * view passes the postcard's own stored postmark_angle, so the same
 * postcard always looks the same way once sent. $dateLabel is pre-
 * formatted by the caller (today's date for the preview, the postcard's
 * real received_at once it's actually been sent) since date formatting
 * isn't this function's job.
 *
 * Phase 59: redesigned to lean into the UK theme -- a full Union Jack
 * fills the stamp body (clipped to its rect), the wordmark and "UNITED
 * KINGDOM" sit on solid crimson bands top and bottom, and the quill logo
 * is centred in a plain crimson medallion in the middle. The stamp's
 * outer edge is a pinked/zigzag border (a single hand-drawn <path>, white
 * fill with a thin black outline) replacing the old round-hole
 * perforation mask -- there's nothing per-postcard about the border
 * itself, only the postmark rotates. The postmark now carries two arced
 * texts around its rim, "OURTHOLOGY P.O." over the top and "YOU'RE
 * WELCOME" under the bottom, each on its own semicircular <path> so they
 * both read right-side up.
 *
 * Phase 61: the two arc paths use different radii on purpose, not a
 * mistake -- text-on-a-path always draws on one particular side of its
 * baseline (SVG has no reliably-supported way to flip that), and for
 * this circle's geometry that side is "outward" along the top arc but
 * "inward" along the bottom one. pmArcTop's baseline sits ON the r=25
 * ring itself so its outward-drawing text lands just outside it; pmArc-
 * Bottom's baseline is pushed out to r=31 so its inward-drawing text
 * still lands outside the ring too, rather than inside it -- both texts
 * end up occupying the same outward band around the circle, the way
 * real postmark rim text does.
 */
function ourthology_postcard_stamp_svg(int $angleDeg, string $dateLabel): string
{
    $angle = max(-45, min(45, $angleDeg));
    $date = htmlspecialchars($dateLabel, ENT_QUOTES);
    return <<<SVG
        <svg viewBox="-2 -6 118 84" aria-hidden="true">
          <defs>
            <path id="pmArcTop" d="M 53 36 A 25 25 0 0 1 103 36"/>
            <path id="pmArcBottom" d="M 47 36 A 31 31 0 0 0 109 36"/>
            <clipPath id="stampBody"><rect x="6.5" y="7.5" width="45" height="55"/></clipPath>
          </defs>

          <path d="M 2.0,3.0 L 5.86,4.6 L 9.71,3.0 L 13.57,4.6 L 17.43,3.0 L 21.29,4.6 L 25.14,3.0 L 29.0,4.6 L 32.86,3.0 L 36.71,4.6 L 40.57,3.0 L 44.43,4.6 L 48.29,3.0 L 52.14,4.6 L 56.0,3.0 L 54.4,7.0 L 56.0,11.0 L 54.4,15.0 L 56.0,19.0 L 54.4,23.0 L 56.0,27.0 L 54.4,31.0 L 56.0,35.0 L 54.4,39.0 L 56.0,43.0 L 54.4,47.0 L 56.0,51.0 L 54.4,55.0 L 56.0,59.0 L 54.4,63.0 L 56.0,67.0 L 52.14,65.4 L 48.29,67.0 L 44.43,65.4 L 40.57,67.0 L 36.71,65.4 L 32.86,67.0 L 29.0,65.4 L 25.14,67.0 L 21.29,65.4 L 17.43,67.0 L 13.57,65.4 L 9.71,67.0 L 5.86,65.4 L 2.0,67.0 L 3.6,63.0 L 2.0,59.0 L 3.6,55.0 L 2.0,51.0 L 3.6,47.0 L 2.0,43.0 L 3.6,39.0 L 2.0,35.0 L 3.6,31.0 L 2.0,27.0 L 3.6,23.0 L 2.0,19.0 L 3.6,15.0 L 2.0,11.0 L 3.6,7.0 Z" fill="#FBF8F1" stroke="#1a1a1a" stroke-width="0.35" stroke-linejoin="round"/>

          <g clip-path="url(#stampBody)">
            <rect x="6.5" y="7.5" width="45" height="55" fill="#00247d"/>
            <path d="M6.5,7.5 L51.5,62.5 M51.5,7.5 L6.5,62.5" stroke="#fff" stroke-width="8"/>
            <path d="M6.5,7.5 L51.5,62.5 M51.5,7.5 L6.5,62.5" stroke="#cf142b" stroke-width="3.6"/>
            <path d="M29,7.5 V62.5 M6.5,35 H51.5" stroke="#fff" stroke-width="13"/>
            <path d="M29,7.5 V62.5 M6.5,35 H51.5" stroke="#cf142b" stroke-width="7.4"/>
          </g>

          <rect x="9" y="9.5" width="39" height="8.5" rx="1" fill="#9A2A2A" opacity="0.92"/>
          <text x="29" y="15.3" text-anchor="middle" font-family="Georgia, 'Times New Roman', serif" font-size="4" fill="#FBF8F1" letter-spacing="0.3">OURTHOLOGY</text>

          <circle cx="29" cy="36" r="11.5" fill="#9A2A2A" stroke="#FBF8F1" stroke-width="1"/>
          <g transform="translate(13.8,20) scale(0.95)">
            <path d="M16 7 C10 8 6.3 12.6 7.4 17.2 C11.2 16.5 14.7 12.6 16 7 Z" fill="#FBF8F1"/>
            <path d="M16 7 C22 8 25.7 12.6 24.6 17.2 C20.8 16.5 17.3 12.6 16 7 Z" fill="#FBF8F1"/>
            <line x1="16" y1="7.2" x2="16" y2="17" stroke="#9A2A2A" stroke-width="1.1" stroke-linecap="round"/>
            <line x1="16" y1="17" x2="16" y2="23.2" stroke="#FBF8F1" stroke-width="2.4" stroke-linecap="round"/>
            <line x1="16" y1="23.2" x2="12.6" y2="26.6" stroke="#FBF8F1" stroke-width="1.8" stroke-linecap="round"/>
            <line x1="16" y1="23.2" x2="19.4" y2="26.6" stroke="#FBF8F1" stroke-width="1.8" stroke-linecap="round"/>
          </g>

          <rect x="7.5" y="54.5" width="43" height="7.5" rx="1" fill="#9A2A2A" opacity="0.92"/>
          <text x="29" y="59.7" text-anchor="middle" font-family="Georgia, 'Times New Roman', serif" font-size="3.6" fill="#FBF8F1" letter-spacing="0.15">UNITED KINGDOM</text>

          <g opacity="0.78" transform="rotate({$angle} 78 36)">
            <circle cx="78" cy="36" r="25" fill="none" stroke="#29456e" stroke-width="1.6"/>
            <circle cx="78" cy="36" r="19" fill="none" stroke="#29456e" stroke-width="1"/>
            <text font-family="Georgia, 'Times New Roman', serif" font-size="5.2" fill="#29456e" letter-spacing="0.3">
              <textPath href="#pmArcTop" startOffset="50%" text-anchor="middle">OURTHOLOGY P.O.</textPath>
            </text>
            <text font-family="Georgia, 'Times New Roman', serif" font-size="5.2" fill="#29456e" letter-spacing="0.3">
              <textPath href="#pmArcBottom" startOffset="50%" text-anchor="middle">YOU'RE WELCOME</textPath>
            </text>
            <text x="78" y="39" text-anchor="middle" font-family="Georgia, 'Times New Roman', serif" font-size="6.2" fill="#29456e" letter-spacing="0.4">{$date}</text>
            <line x1="78" y1="7" x2="78" y2="1" stroke="#29456e" stroke-width="1.2" stroke-linecap="round"/>
            <line x1="60" y1="13" x2="57" y2="8" stroke="#29456e" stroke-width="1.2" stroke-linecap="round"/>
            <line x1="96" y1="13" x2="99" y2="8" stroke="#29456e" stroke-width="1.2" stroke-linecap="round"/>
          </g>
        </svg>
        SVG;
}

/**
 * Phase 59: letters have no stored postmark_angle column the way
 * postcards do (see postcards.php's INSERT above) -- there's no per-
 * letter row to persist a cosmetic tilt into, so this derives a small,
 * stable one from the letter's own id instead. Deterministic (the same
 * letter always tilts the same way, on every page load, in every place
 * its envelope is shown) and needs no migration.
 */
function ourthology_letter_postmark_angle(int $letterId): int
{
    return ($letterId * 47) % 33 - 16;
}
