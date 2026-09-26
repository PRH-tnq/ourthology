<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/memory_tags.php';
require_once __DIR__ . '/graph.php'; // person_is_editable_by(), used by fetch_owned_entry() below
require_once __DIR__ . '/custom_audience.php'; // the 'custom' visibility clause below (Phase 33)

/**
 * Timeline entries for one person, filtered by what the viewer is allowed
 * to see: the owner (or anyone who can manage an unclaimed profile — see
 * timeline.php's $canManage) sees everything, anyone else in the same
 * family group sees visibility='public' plus any visibility='custom'
 * entry whose OWNING person has put $viewerPersonId on their own
 * custom_memory_audience list (Phase 33 — see includes/custom_audience.php),
 * and nobody else sees anything (callers should reject the request
 * entirely before calling this in that case).
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
function fetch_entries_for_person(PDO $pdo, int $targetPersonId, bool $viewerIsOwner, int $viewerPersonId): array
{
    if ($viewerIsOwner) {
        $visClause1 = '';
        $visClause2 = '';
        $params = ['pid1' => $targetPersonId, 'pid2' => $targetPersonId];
    } else {
        // Named per occurrence (viewer1/viewer2), not reused, matching this
        // codebase's usual style for a value bound more than once in one
        // query (see e.g. the relationships query in edit_person.php).
        $visClause1 = " AND (te.visibility = 'public' OR (te.visibility = 'custom' AND EXISTS (
            SELECT 1 FROM custom_memory_audience ca WHERE ca.person_id = te.person_id AND ca.member_person_id = :viewer1
        )))";
        $visClause2 = " AND (te.visibility = 'public' OR (te.visibility = 'custom' AND EXISTS (
            SELECT 1 FROM custom_memory_audience ca WHERE ca.person_id = te.person_id AND ca.member_person_id = :viewer2
        )))";
        $params = ['pid1' => $targetPersonId, 'pid2' => $targetPersonId, 'viewer1' => $viewerPersonId, 'viewer2' => $viewerPersonId];
    }
    $sql = "SELECT te.id, te.entry_type, te.origin, te.title, te.body, te.occurred_on, te.visibility, te.created_at,
                   te.location_label, te.location_lat, te.location_lng,
                   te.person_id AS owner_person_id, op.claimed_by_user_id AS owner_claimed_by,
                   op.family_group_id AS owner_family_group
            FROM timeline_entries te
            JOIN persons op ON op.id = te.person_id
            WHERE te.person_id = :pid1 $visClause1
            UNION
            SELECT te.id, te.entry_type, te.origin, te.title, te.body, te.occurred_on, te.visibility, te.created_at,
                   te.location_label, te.location_lat, te.location_lng,
                   te.person_id AS owner_person_id, op.claimed_by_user_id AS owner_claimed_by,
                   op.family_group_id AS owner_family_group
            FROM timeline_entries te
            JOIN persons op ON op.id = te.person_id
            JOIN memory_tags mt ON mt.timeline_entry_id = te.id
            WHERE mt.person_id = :pid2 AND mt.status = 'approved' $visClause2
            ORDER BY COALESCE(occurred_on, DATE(created_at)) DESC, id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll();

    if (!$entries) {
        return [];
    }

    $ids = array_column($entries, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $mediaStmt = $pdo->prepare("SELECT id, timeline_entry_id, mime_type FROM media WHERE timeline_entry_id IN ($placeholders) ORDER BY timeline_entry_id, sort_order, id");
    $mediaStmt->execute($ids);
    $mediaByEntry = [];
    foreach ($mediaStmt->fetchAll() as $m) {
        $mediaByEntry[(int) $m['timeline_entry_id']][] = $m;
    }

    $tagsByEntry = fetch_approved_tags_for_entries($pdo, $ids);

    // Phase 69: a trip (origin='trip') carries its own start/finish date
    // span and event count alongside the ordinary entry fields above, for
    // the rail card's "Jun 3 – Jun 10 · 4 events" label and for the click
    // handler to know which trip_plans.id to open the planner pop-up on —
    // looked up once here, the same way media/tags are, rather than a
    // separate round trip per trip card.
    $tripStmt = $pdo->prepare(
        "SELECT tp.timeline_entry_id, tp.id AS trip_plan_id, tp.start_date, tp.finish_date,
                (SELECT COUNT(*) FROM trip_events tev WHERE tev.trip_plan_id = tp.id) AS event_count
         FROM trip_plans tp WHERE tp.timeline_entry_id IN ($placeholders)"
    );
    $tripStmt->execute($ids);
    $tripByEntry = [];
    foreach ($tripStmt->fetchAll() as $t) {
        $tripByEntry[(int) $t['timeline_entry_id']] = $t;
    }

    foreach ($entries as &$entry) {
        $entry['media'] = $mediaByEntry[(int) $entry['id']] ?? [];
        $entry['tags'] = $tagsByEntry[(int) $entry['id']] ?? [];
        $entry['trip'] = $tripByEntry[(int) $entry['id']] ?? null;
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
                te.location_label, te.location_lat, te.location_lng,
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

/** All media rows for an entry, in their display order (Phase 99: set by dragging them in the upload box; upload order until then). */
function fetch_entry_media(PDO $pdo, int $entryId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, file_path, mime_type, byte_size, width, height
         FROM media WHERE timeline_entry_id = :eid ORDER BY sort_order, id'
    );
    $stmt->execute(['eid' => $entryId]);
    return $stmt->fetchAll();
}

/**
 * Phase 93: the optional "where it happened" on a memory, read from the
 * composer's location_label / location_lat / location_lng fields. A pin
 * without a label gets a generic one; a label without a pin is kept as
 * plain text (still shown on the memory, just not on a map); coordinates
 * out of range are dropped rather than failing the save.
 *
 * @return array{label: ?string, lat: ?float, lng: ?float}
 */
function memory_location_from_post(array $post): array
{
    $label = trim(preg_replace('/\s+/u', ' ', (string) ($post['location_label'] ?? '')));
    $label = mb_substr($label, 0, 255);
    $lat = filter_var($post['location_lat'] ?? '', FILTER_VALIDATE_FLOAT);
    $lng = filter_var($post['location_lng'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        $lat = $lng = null;
    } else {
        $lat = round((float) $lat, 6);
        $lng = round((float) $lng, 6);
    }
    if ($label === '' && $lat !== null) {
        $label = 'Pinned on the map';
    }
    return ['label' => $label !== '' ? $label : null, 'lat' => $lat, 'lng' => $lng];
}

