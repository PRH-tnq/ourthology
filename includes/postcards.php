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
    bool $recordToOwnTimeline
): int {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO postcards (sender_person_id, created_by_user_id, family_group_id, image_path, image_mime_type, image_byte_size, image_width, image_height, message, audience)
             VALUES (:sender, :uid, :gid, :path, :mime, :size, :w, :h, :msg, :aud)'
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
                'INSERT INTO timeline_entries (person_id, entry_type, title, body, occurred_on, visibility, created_by_user_id)
                 VALUES (:pid, :type, :title, :body, CURDATE(), :vis, :uid)'
            );
            $entryStmt->execute([
                'pid'   => $senderPersonId,
                'type'  => 'photo',
                'title' => ourthology_postcard_sent_title($pdo, $audience, $recipientPersonIds),
                'body'  => $message !== '' ? $message : null,
                'vis'   => 'private',
                'uid'   => $senderUserId,
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
 * recipient (status 'pending' or 'read') -- one row per still-unresolved
 * recipient, not per postcard, so an "everyone" send shows exactly which
 * people haven't actioned it yet and each one drops off the list
 * independently as they save or discard their copy. Mirrors
 * fetch_outgoing_memory_tags_for_user()'s per-recipient granularity in
 * memory_tags.php rather than collapsing multi-recipient sends into one
 * row.
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
         WHERE pc.sender_person_id = :pid AND pr.status IN ('pending','read')
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
            'INSERT INTO timeline_entries (person_id, entry_type, title, body, occurred_on, visibility, created_by_user_id)
             VALUES (:pid, :type, :title, :body, CURDATE(), :vis, :uid)'
        );
        $stmt->execute([
            'pid'   => $personId,
            'type'  => 'photo',
            'title' => 'Postcard from ' . $senderName,
            'body'  => $recipientRow['message'] !== '' ? $recipientRow['message'] : null,
            'vis'   => 'private',
            'uid'   => $userId,
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
