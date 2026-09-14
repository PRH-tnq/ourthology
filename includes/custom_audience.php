<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * custom_memory_audience: the "Custom Memory Settings" list edited on
 * edit_person.php's "Account Settings" tab (Phase 33). One row per
 * (person_id, member_person_id) pair — person_id is whoever the setting
 * belongs to (their own memories, when marked visibility='custom', are
 * only ever visible to whoever is on this list); member_person_id is one
 * family member allowed to see them. Checked alongside the existing
 * public/private rules by fetch_entries_for_person() (includes/entries.php)
 * and can_view_media() (includes/media.php).
 */

/** Every member_person_id currently on $personId's custom audience list, in no particular order. */
function fetch_custom_audience_ids(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare('SELECT member_person_id FROM custom_memory_audience WHERE person_id = :pid');
    $stmt->execute(['pid' => $personId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** True if $viewerPersonId is on $ownerPersonId's custom audience list — the gate a 'custom' memory's visibility checks. */
function person_in_custom_audience(PDO $pdo, int $ownerPersonId, int $viewerPersonId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM custom_memory_audience WHERE person_id = :pid AND member_person_id = :vid'
    );
    $stmt->execute(['pid' => $ownerPersonId, 'vid' => $viewerPersonId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Replace $personId's whole custom audience list with exactly
 * $memberPersonIds — a full reconcile (delete what's gone, insert what's
 * new), same shape sync_memory_tags() uses for a memory's tag list.
 * $validMemberIds must be the caller's own "who's actually allowed here"
 * list (everyone else in the family group) — anything in
 * $memberPersonIds that isn't in it, or that IS $personId itself, is
 * silently dropped rather than erroring, since that can only happen via
 * a tampered form.
 */
function set_custom_audience(PDO $pdo, int $personId, array $memberPersonIds, array $validMemberIds): void
{
    $memberPersonIds = array_values(array_unique(array_map('intval', $memberPersonIds)));
    $memberPersonIds = array_values(array_intersect($memberPersonIds, array_map('intval', $validMemberIds)));
    $memberPersonIds = array_values(array_diff($memberPersonIds, [$personId]));

    $pdo->prepare('DELETE FROM custom_memory_audience WHERE person_id = :pid')->execute(['pid' => $personId]);
    if (!$memberPersonIds) {
        return;
    }
    $insert = $pdo->prepare(
        'INSERT INTO custom_memory_audience (person_id, member_person_id) VALUES (:pid, :mid)'
    );
    foreach ($memberPersonIds as $mid) {
        $insert->execute(['pid' => $personId, 'mid' => $mid]);
    }
}
