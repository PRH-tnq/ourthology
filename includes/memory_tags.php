<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * memory_tags: "this memory is about you too." One row per (timeline
 * entry, tagged person) pair. The memory itself is never copied — tagging
 * and approving both operate on the ONE shared timeline_entries row; see
 * db/schema.sql's own comment on the table for the full model.
 *
 * Lifecycle deliberately mirrors relationships/partnerships (includes/
 * graph.php + pending.php): a tag on an already-claimed person is
 * status='pending' until that person approves it on pending.php, and
 * declining DELETES the row outright (no 'declined' status ever exists).
 * A tag on an unclaimed person auto-approves immediately, since nobody is
 * logged in as them to ask — the same "unclaimed = open to the whole
 * family" rule person_is_editable_by() already applies elsewhere.
 */

/**
 * Create one memory_tags row for $taggedPerson on $timelineEntryId.
 * $taggedPerson needs only 'id' and 'claimed_by_user_id' (a full persons
 * row, or that shape trimmed down, both work).
 */
function create_memory_tag(PDO $pdo, int $timelineEntryId, array $taggedPerson, int $createdByUserId): void
{
    $claimedBy = $taggedPerson['claimed_by_user_id'] ?? null;
    $personId = (int) $taggedPerson['id'];
    if ($claimedBy === null) {
        $pdo->prepare(
            "INSERT INTO memory_tags (timeline_entry_id, person_id, status, created_by_user_id, resolved_at)
             VALUES (:eid, :pid, 'approved', :uid, NOW())"
        )->execute(['eid' => $timelineEntryId, 'pid' => $personId, 'uid' => $createdByUserId]);
    } else {
        $pdo->prepare(
            "INSERT INTO memory_tags (timeline_entry_id, person_id, status, created_by_user_id, approving_user_id)
             VALUES (:eid, :pid, 'pending', :uid, :approver)"
        )->execute(['eid' => $timelineEntryId, 'pid' => $personId, 'uid' => $createdByUserId, 'approver' => (int) $claimedBy]);
    }
}

/**
 * Reconcile an entry's tags with exactly $newPersonIds (add_entry.php's
 * "who's tagged" checkbox list on save, when editing an existing memory).
 * Anyone dropped from the list has
 * their tag row deleted outright — same as a decline, no trace kept,
 * including any note they'd already written. Anyone already tagged (at
 * any status) who's still in the list is left completely alone, so
 * resubmitting the same set never disturbs an existing approval or note.
 * $personsById must map every id in $newPersonIds to a persons row (or
 * the create_memory_tag()-shaped subset of one) — an id missing from it
 * is silently skipped rather than erroring, since it can only happen via
 * a tampered form.
 */
function sync_memory_tags(PDO $pdo, int $timelineEntryId, array $newPersonIds, array $personsById, int $createdByUserId): void
{
    $existing = $pdo->prepare('SELECT person_id FROM memory_tags WHERE timeline_entry_id = :eid');
    $existing->execute(['eid' => $timelineEntryId]);
    $existingIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));

    $newPersonIds = array_values(array_unique(array_map('intval', $newPersonIds)));
    $toRemove = array_diff($existingIds, $newPersonIds);
    $toAdd = array_diff($newPersonIds, $existingIds);

    if ($toRemove) {
        $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
        $params = array_values($toRemove);
        array_unshift($params, $timelineEntryId);
        $pdo->prepare("DELETE FROM memory_tags WHERE timeline_entry_id = ? AND person_id IN ($placeholders)")
            ->execute($params);
    }
    foreach ($toAdd as $pid) {
        if (isset($personsById[$pid])) {
            create_memory_tag($pdo, $timelineEntryId, $personsById[$pid], $createdByUserId);
        }
    }
}

/**
 * All APPROVED tags for a set of timeline entry ids — each with the
 * tagged person's id/name and their own note — grouped by entry id.
 * Used both to render "notes from family" under a memory and, by
 * fetch_entries_for_person(), to find which OTHER people's timelines a
 * memory should also appear on.
 */
function fetch_approved_tags_for_entries(PDO $pdo, array $entryIds): array
{
    if (!$entryIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT mt.timeline_entry_id, mt.person_id, mt.note, p.first_name, p.surname
         FROM memory_tags mt
         JOIN persons p ON p.id = mt.person_id
         WHERE mt.status = 'approved' AND mt.timeline_entry_id IN ($placeholders)
         ORDER BY mt.resolved_at, mt.id"
    );
    $stmt->execute($entryIds);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['timeline_entry_id']][] = [
            'person_id' => (int) $row['person_id'],
            'name'      => trim($row['first_name'] . ' ' . $row['surname']),
            'note'      => $row['note'],
        ];
    }
    return $out;
}

/** Every tag (any status) currently on an entry, plus person_id => status/note — what add_entry.php pre-checks its tag picker from when editing an existing memory. */
function fetch_tags_for_entry(PDO $pdo, int $timelineEntryId): array
{
    $stmt = $pdo->prepare('SELECT person_id, status, note FROM memory_tags WHERE timeline_entry_id = :eid');
    $stmt->execute(['eid' => $timelineEntryId]);
    return $stmt->fetchAll();
}

/** Pending memory-tag requests waiting on $userId's approval — the "Waiting on you" section of pending.php. */
function fetch_pending_memory_tags_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT mt.id, mt.created_at, te.id AS entry_id, te.title, te.body, te.occurred_on,
                op.first_name AS owner_first, op.surname AS owner_surname,
                cu.email AS created_by_email
         FROM memory_tags mt
         JOIN timeline_entries te ON te.id = mt.timeline_entry_id
         JOIN persons op ON op.id = te.person_id
         JOIN users cu ON cu.id = mt.created_by_user_id
         WHERE mt.status = 'pending' AND mt.approving_user_id = :uid
         ORDER BY mt.created_at"
    );
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll();
}

/** Outgoing memory-tag requests I sent that are still waiting on someone else — the "Sent by you" section of pending.php. */
function fetch_outgoing_memory_tags_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT mt.id, mt.created_at, te.id AS entry_id, te.title, te.body, te.occurred_on,
                op.first_name AS owner_first, op.surname AS owner_surname,
                tp.first_name AS tagged_first, tp.surname AS tagged_surname,
                au.email AS approving_email
         FROM memory_tags mt
         JOIN timeline_entries te ON te.id = mt.timeline_entry_id
         JOIN persons op ON op.id = te.person_id
         JOIN persons tp ON tp.id = mt.person_id
         JOIN users au ON au.id = mt.approving_user_id
         WHERE mt.status = 'pending' AND mt.created_by_user_id = :uid
         ORDER BY mt.created_at"
    );
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll();
}
