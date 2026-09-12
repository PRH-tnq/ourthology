<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Timeline entries for one person, filtered by what the viewer is allowed
 * to see: the owner sees everything, anyone else in the same family group
 * sees only visibility='public', and nobody else sees anything (callers
 * should reject the request entirely before calling this in that case).
 */
function fetch_entries_for_person(PDO $pdo, int $targetPersonId, bool $viewerIsOwner): array
{
    $sql = 'SELECT id, entry_type, title, body, occurred_on, visibility, created_at
            FROM timeline_entries WHERE person_id = :pid';
    if (!$viewerIsOwner) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= ' ORDER BY COALESCE(occurred_on, DATE(created_at)) DESC, id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['pid' => $targetPersonId]);
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

    foreach ($entries as &$entry) {
        $entry['media'] = $mediaByEntry[(int) $entry['id']] ?? [];
    }

    return $entries;
}
