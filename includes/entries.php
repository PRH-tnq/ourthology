<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/memory_tags.php';

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
