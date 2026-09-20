<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/graph.php';
require_once __DIR__ . '/media.php';

/**
 * Phase 67: "send a card" -- a taller, more ceremonial greeting card,
 * triggered from the birthday reminder banner rather than composed freely
 * the way a postcard/letter is. Modeled on letters.php (one row per card,
 * addressed to exactly one recipient) rather than postcards' multi-
 * recipient junction table -- a card is always for one specific person's
 * one specific occasion, never a broadcast.
 *
 * Phase 68 widened the trigger from "a person's own birthday" to any
 * calendar entry -- a key date (anniversary, etc.) can trigger a card
 * too, on any page that shows one. See
 * ourthology_calendar_reminder_rows() in includes/calendar.php for the
 * shared "what's coming up, and what would a card for it look like" list
 * both kinds now come from, and card.php's send action for how a
 * key-date card's occasion/deliver_on/recipient differ from a birthday
 * card's.
 *
 * Its front image reuses store_postcard_image()/
 * store_postcard_copy_as_media() from media.php as-is: a card's own photo
 * is exactly the same "not really theirs to keep until saved" shape a
 * postcard's photo already has (see that file's own header comment), and
 * there's nothing card-specific about how it's stored or copied.
 *
 * The delivery gate is the whole point of this table over just reusing
 * letters: a card sent today for a birthday six days out shouldn't show
 * up in the recipient's Pending queue today. See
 * fetch_pending_cards_for_person() below for the actual gate, and
 * card.php's send action for how deliver_on is computed (server-side,
 * never trusted from the client either way).
 */

/**
 * Creates one greeting_cards row inside a transaction, claiming
 * $storedImage (the ['file_path','mime_type','byte_size','width','height']
 * shape store_postcard_image() returns) as the card's own front photo.
 * $deliverOn is a DateTimeImmutable computed by the caller (card.php),
 * never derived from anything posted by the browser. Returns the new
 * card's id.
 *
 * Phase 68: $eventId records which calendar_events row (if any) triggered
 * this card -- null for the original birthday flow, where deliver_on
 * comes from the recipient's own persons.born instead and there's no
 * calendar_events row involved at all. See card.php's send action for how
 * the two flows compute occasion/deliver_on differently.
 */
function create_greeting_card(
    PDO $pdo,
    int $senderPersonId,
    int $senderUserId,
    int $familyGroupId,
    int $recipientPersonId,
    array $storedImage,
    string $occasion,
    string $coverMessage,
    string $toLine,
    string $greetingLine,
    string $message,
    string $fromLine,
    DateTimeImmutable $deliverOn,
    ?int $eventId = null
): int {
    // Phase 67: same "hand-stamped, not machine-straight" postmark angle
    // a postcard gets (see create_postcard() in postcards.php) -- the
    // card's envelope carries a postmark too once it's opened/read.
    $postmarkAngle = random_int(-18, 22);

    $stmt = $pdo->prepare(
        'INSERT INTO greeting_cards
            (sender_person_id, created_by_user_id, family_group_id, recipient_person_id, event_id,
             occasion, image_path, image_mime_type, image_byte_size, image_width, image_height,
             cover_message, to_line, greeting_line, message, from_line, postmark_angle, deliver_on)
         VALUES
            (:sender, :uid, :gid, :rid, :eid,
             :occasion, :path, :mime, :size, :w, :h,
             :cover, :toln, :greet, :msg, :froml, :angle, :deliver)'
    );
    $stmt->execute([
        'sender'   => $senderPersonId,
        'uid'      => $senderUserId,
        'gid'      => $familyGroupId,
        'rid'      => $recipientPersonId,
        'eid'      => $eventId,
        'occasion' => $occasion,
        'path'     => $storedImage['file_path'],
        'mime'     => $storedImage['mime_type'],
        'size'     => $storedImage['byte_size'],
        'w'        => $storedImage['width'],
        'h'        => $storedImage['height'],
        'cover'    => $coverMessage,
        'toln'     => $toLine !== '' ? $toLine : null,
        'greet'    => $greetingLine !== '' ? $greetingLine : null,
        'msg'      => $message,
        'froml'    => $fromLine !== '' ? $fromLine : null,
        'angle'    => $postmarkAngle,
        'deliver'  => $deliverOn->format('Y-m-d'),
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Cards addressed to $personId that are still 'pending' or 'read' AND
 * whose deliver_on has actually arrived -- the delivery gate. A card sent
 * today for a birthday six days out is real, sitting in the database,
 * showing in the SENDER's own outgoing/sent lists below -- it just isn't
 * returned here (so it doesn't show in the recipient's Pending queue, and
 * card.php's ownership-checked fetches below refuse to open it) until
 * CURDATE() reaches deliver_on. No cron job: this is computed fresh on
 * every page load, exactly like graph_upcoming_birthdays() itself.
 */
function fetch_pending_cards_for_person(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare(
        "SELECT gc.id AS card_id, gc.status, gc.occasion, gc.created_at AS received_at,
                sp.first_name AS sender_first, sp.surname AS sender_surname
         FROM greeting_cards gc
         JOIN persons sp ON sp.id = gc.sender_person_id
         WHERE gc.recipient_person_id = :pid AND gc.status IN ('pending','read') AND gc.deliver_on <= CURDATE()
         ORDER BY gc.created_at DESC"
    );
    $stmt->execute(['pid' => $personId]);
    return $stmt->fetchAll();
}

/**
 * One card, but ONLY if it's addressed to $recipientPersonId AND its
 * delivery date has arrived -- the ownership check IS the query, same
 * defensive pattern fetch_letter_for_recipient()/
 * fetch_postcard_recipient_row() use, extended with the deliver_on gate
 * so there's no way to open a card early by guessing/posting its id
 * before the real Pending queue would ever surface it. Null if it
 * doesn't exist, isn't theirs, or isn't due yet.
 */
function fetch_card_for_recipient(PDO $pdo, int $cardId, int $recipientPersonId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT gc.id AS card_id, gc.status, gc.occasion, gc.image_path, gc.image_mime_type,
                gc.cover_message, gc.to_line, gc.greeting_line, gc.message, gc.from_line,
                gc.postmark_angle, gc.timeline_entry_id, gc.created_at, gc.deliver_on,
                gc.sender_person_id, gc.recipient_person_id,
                sp.first_name AS sender_first, sp.surname AS sender_surname
         FROM greeting_cards gc
         JOIN persons sp ON sp.id = gc.sender_person_id
         WHERE gc.id = :id AND gc.recipient_person_id = :pid AND gc.deliver_on <= CURDATE()"
    );
    $stmt->execute(['id' => $cardId, 'pid' => $recipientPersonId]);
    return $stmt->fetch() ?: null;
}

/**
 * Cards $senderPersonId sent that are still waiting on their one
 * recipient -- "Sent by you, waiting on them", mirroring
 * fetch_outgoing_letters_for_person(). Deliberately NOT gated by
 * deliver_on: a card is genuinely "sent, waiting" the moment it's
 * created, whether or not the recipient could see it yet -- the delivery
 * gate only controls when THEY can open it, not whether it counts as
 * outstanding on the sender's own list.
 */
function fetch_outgoing_cards_for_person(PDO $pdo, int $senderPersonId): array
{
    $stmt = $pdo->prepare(
        "SELECT gc.id AS card_id, gc.status, gc.occasion, gc.created_at AS sent_at, gc.deliver_on,
                rp.first_name AS recipient_first, rp.surname AS recipient_surname
         FROM greeting_cards gc
         JOIN persons rp ON rp.id = gc.recipient_person_id
         WHERE gc.sender_person_id = :pid AND gc.status = 'pending'
         ORDER BY gc.created_at DESC"
    );
    $stmt->execute(['pid' => $senderPersonId]);
    return $stmt->fetchAll();
}

/**
 * Phase 67: the permanent sent-history counterpart to
 * fetch_sent_letters_for_person()/fetch_sent_postcards_for_person() --
 * every card $senderPersonId has ever sent, regardless of status, newest
 * first, capped at $limit. See count_sent_greeting_cards_for_person()
 * below for the true total.
 */
function fetch_sent_greeting_cards_for_person(PDO $pdo, int $senderPersonId, int $limit = 60): array
{
    $stmt = $pdo->prepare(
        "SELECT gc.id AS card_id, gc.status, gc.occasion, gc.created_at AS sent_at,
                rp.first_name AS recipient_first, rp.surname AS recipient_surname
         FROM greeting_cards gc
         JOIN persons rp ON rp.id = gc.recipient_person_id
         WHERE gc.sender_person_id = :pid
         ORDER BY gc.created_at DESC
         LIMIT :lim"
    );
    $stmt->bindValue('pid', $senderPersonId, PDO::PARAM_INT);
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** The true count behind fetch_sent_greeting_cards_for_person() above, unaffected by its $limit. */
function count_sent_greeting_cards_for_person(PDO $pdo, int $senderPersonId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM greeting_cards WHERE sender_person_id = :pid');
    $stmt->execute(['pid' => $senderPersonId]);
    return (int) $stmt->fetchColumn();
}

/** Marks a still-pending card 'read' (no-op if it's already past that). */
function mark_card_read(PDO $pdo, int $cardId): void
{
    $pdo->prepare("UPDATE greeting_cards SET status = 'read', read_at = NOW() WHERE id = :id AND status = 'pending'")
        ->execute(['id' => $cardId]);
}

/** Plain-text rendering of a card -- for the timeline_entries.body a saved copy gets, same reasoning as ourthology_letter_plain_text(). */
function ourthology_card_plain_text(array $cardRow): string
{
    $greetName = ($cardRow['to_line'] ?? '') !== '' ? $cardRow['to_line'] : 'you';
    $greeting = ($cardRow['greeting_line'] ?? '') !== '' ? $cardRow['greeting_line'] : (string) $cardRow['occasion'];
    $message = trim((string) $cardRow['message']);
    $closing = ($cardRow['from_line'] ?? '') !== '' ? $cardRow['from_line'] : '';
    $lines = ["To {$greetName},", '', $greeting, ''];
    if ($message !== '') {
        $lines[] = $message;
        $lines[] = '';
    }
    if ($closing !== '') {
        $lines[] = $closing;
    }
    return trim(implode("\n", $lines));
}

/** Saves a card to $personId's own timeline -- a real, independent entry with its own copy of the front photo -- and marks it 'saved'. Returns the new timeline_entries id. */
function save_card_to_timeline(PDO $pdo, array $cardRow, int $personId, int $userId): int
{
    $pdo->beginTransaction();
    try {
        $copy = store_postcard_copy_as_media((string) $cardRow['image_path'], (string) $cardRow['image_mime_type'], $personId);
        $senderName = person_display_name(['first_name' => $cardRow['sender_first'], 'surname' => $cardRow['sender_surname']]);
        $stmt = $pdo->prepare(
            'INSERT INTO timeline_entries (person_id, entry_type, origin, title, body, occurred_on, visibility, created_by_user_id)
             VALUES (:pid, :type, :origin, :title, :body, CURDATE(), :vis, :uid)'
        );
        $stmt->execute([
            'pid'    => $personId,
            'type'   => 'photo',
            'origin' => 'card',
            'title'  => ($cardRow['occasion'] ?: 'Greeting') . ' card from ' . $senderName,
            'body'   => ourthology_card_plain_text($cardRow),
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
        $pdo->prepare("UPDATE greeting_cards SET status = 'saved', timeline_entry_id = :eid, resolved_at = NOW() WHERE id = :id")
            ->execute(['eid' => $entryId, 'id' => $cardRow['card_id']]);
        $pdo->commit();
        return $entryId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Discards a card without saving it -- a status flip only, same as discard_postcard()/discard_letter(). Nothing is deleted. */
function discard_card(PDO $pdo, int $cardId): void
{
    $pdo->prepare("UPDATE greeting_cards SET status = 'discarded', resolved_at = NOW() WHERE id = :id")
        ->execute(['id' => $cardId]);
}
