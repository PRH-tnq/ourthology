<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/memory_tags.php';
require_once __DIR__ . '/graph.php'; // person_is_editable_by(), used by fetch_owned_entry() below

/**
 * Timeline entries for one person, filtered by what the viewer is allowed
 * to see: the owner (or anyone who can manage an unclaimed profile — see
 * timeline.php's $canManage) sees everything, anyone else in the same
 * family group sees only visibility='public', and nobody else sees
 * anything (callers should reject the request entirely before calling
 * this in that case).
 *
 * Besides $targetPersonId's own entries, this also pulls in any OTHER
 * person's memory that $targetPersonId has an APPROVED tag on (Phase 17)
 * — the memory is never copied, the same timeline_entries row just also
 * shows up here, subject to the exact same visibility rule as an entry
 * $targetPersonId actually owns. Each returned row carries the owning
 * person's own claimed_by_user_id/family_group_id (as owner_claimed_by /
 * owner_family_group) so a caller can work out, per entry, who if anyone
 * may edit it — that can differ from $targetPersonId's own claim status
 * once tagged-in entries are mixed in.
 */
function fetch_entries_for_person(PDO $pdo, int $targetPersonId, bool $viewerIsOwner): array
{
    $visClause = $viewerIsOwner ? '' : " AND te.visibility = 'public'";
    $sql = "SELECT te.id, te.entry_type, te.title, te.body, te.occurred_on, te.visibility, te.created_at,
                   te.person_id AS owner_person_id, op.claimed_by_user_id AS owner_claimed_by,
                   op.family_group_id AS owner_family_group
            FROM timeline_entries te
            JOIN persons op ON op.id = te.person_id
            WHERE te.person_id = :pid1 $visClause
            UNION
            SELECT te.id, te.entry_type, te.title, te.body, te.occurred_on, te.visibility, te.created_at,
                   te.person_id AS owner_person_id, op.claimed_by_user_id AS owner_claimed_by,
                   op.family_group_id AS owner_family_group
            FROM timeline_entries te
            JOIN persons op ON op.id = te.person_id
            JOIN memory_tags mt ON mt.timeline_entry_id = te.id
            WHERE mt.person_id = :pid2 AND mt.status = 'approved' $visClause
            ORDER BY COALESCE(occurred_on, DATE(created_at)) DESC, id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['pid1' => $targetPersonId, 'pid2' => $targetPersonId]);
    $entries = $stmt->fetchAll();

    if (!$entries) {
        return [];
    }

    $ids = array_column($entries, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $mediaStmt = $pdo->prepare("SELECT id, timeline_entry_id, mime_type FROM media WHERE timeline_entry_id IN ($placeholders)");
    $mediaStmt->execute($ids);
    $mediaByEntry = [];
    foreach ($mediaStmt->fetchAll() as $m) {
        $mediaByEntry[(int) $m['timeline_entry_id']][] = $m;
    }

    $tagsByEntry = fetch_approved_tags_for_entries($pdo, $ids);

    foreach ($entries as &$entry) {
        $entry['media'] = $mediaByEntry[(int) $entry['id']] ?? [];
        $entry['tags'] = $tagsByEntry[(int) $entry['id']] ?? [];
    }

    return $entries;
}

/**
 * One timeline entry, plus enough of its owning person to decide whether
 * $myUserId may edit it — the same person_is_editable_by() rule
 * add_entry.php uses for who may ADD a memory (a memory can be edited by
 * the person it belongs to, or by anyone managing an unclaimed person's
 * profile). Returns null if the entry doesn't exist, belongs to a
 * different family group, or isn't editable by this user — one check,
 * used by add_entry.php's edit mode (Phase 27; formerly edit_entry.php's
 * own fetch_owned_entry()).
 */
function fetch_owned_entry(PDO $pdo, int $entryId, int $myUserId, int $myFamilyGroup): ?array
{
    $stmt = $pdo->prepare(
        'SELECT te.id, te.person_id, te.entry_type, te.title, te.body, te.occurred_on, te.visibility,
                p.claimed_by_user_id, p.family_group_id
         FROM timeline_entries te
         JOIN persons p ON p.id = te.person_id
         WHERE te.id = :id'
    );
    $stmt->execute(['id' => $entryId]);
    $row = $stmt->fetch() ?: null;
    if ($row === null || (int) $row['family_group_id'] !== $myFamilyGroup
        || !person_is_editable_by($row, $myUserId)) {
        return null;
    }
    return $row;
}

/** All media rows for an entry, in the order they were attached. */
function fetch_entry_media(PDO $pdo, int $entryId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, file_path, mime_type, byte_size, width, height
         FROM media WHERE timeline_entry_id = :eid ORDER BY id'
    );
    $stmt->execute(['eid' => $entryId]);
    return $stmt->fetchAll();
}
