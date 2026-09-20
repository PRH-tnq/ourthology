<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/memory_tags.php';
require_once __DIR__ . '/custom_audience.php';

/**
 * Phase 69: Memory Planner. A trip is a companion timeline_entries row
 * (origin='trip') plus a trip_plans row pointing at it, with any number of
 * trip_events underneath carrying their own plan/memory notes and — via
 * trip_event_media — their own plan/memory photos. Every one of those
 * photos is stored as an ORDINARY media row against the trip's shared
 * timeline_entry_id, so media.php's existing access check and thumbnail
 * cache already serve every trip photo with no changes there at all; see
 * db/schema.sql's own Phase 69 block for the full rationale.
 *
 * This file holds the read-side helpers shared by trip_plan.php's GET
 * "load this trip into the planner pop-up" action and by its own save
 * action's ownership check. The save action's actual INSERT/UPDATE work
 * lives directly in trip_plan.php, mirroring add_entry.php rather than
 * postcards.php/letters.php/cards.php — a trip's save is a single-page,
 * single-action mutation with no separate send/open/save/discard
 * lifecycle those three have.
 */

/**
 * True if $viewerPersonId (in $viewerFamilyGroupId) may see a trip whose
 * companion timeline_entries row has these fields — the exact same
 * visibility rule fetch_entries_for_person() (includes/entries.php) and
 * can_view_media() (includes/media.php) already apply to an ordinary
 * memory and its media, just checked once at the entry level rather than
 * once per photo, since every photo in a trip shares this one entry.
 */
function trip_entry_is_viewable_by(PDO $pdo, array $entry, int $viewerPersonId, int $viewerFamilyGroupId): bool
{
    if ((int) $entry['owner_person_id'] === $viewerPersonId) {
        return true;
    }
    if ($entry['visibility'] === 'public' && (int) $entry['owner_family_group_id'] === $viewerFamilyGroupId) {
        return true;
    }
    if ($entry['visibility'] === 'custom' && (int) $entry['owner_family_group_id'] === $viewerFamilyGroupId
        && person_in_custom_audience($pdo, (int) $entry['owner_person_id'], $viewerPersonId)) {
        return true;
    }
    if (person_has_approved_tag($pdo, (int) $entry['id'], $viewerPersonId)) {
        return true;
    }
    return person_has_pending_tag_awaiting_approval($pdo, (int) $entry['id'], $viewerPersonId);
}

/**
 * Full detail for one trip — its own fields, its owning entry's
 * visibility/ownership (for the viewability/edit checks above and in
 * trip_plan.php), every trip_events row in order, and each event's
 * plan/memory media split out by role. Returns null if the trip doesn't
 * exist. Callers must still run trip_entry_is_viewable_by() themselves —
 * this returns the same detail regardless of who's asking.
 */
function fetch_trip_plan_detail(PDO $pdo, int $tripPlanId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT tp.id, tp.person_id, tp.timeline_entry_id, tp.title, tp.start_date, tp.finish_date,
                te.id AS entry_id, te.visibility, te.person_id AS owner_person_id,
                p.family_group_id AS owner_family_group_id, p.claimed_by_user_id AS owner_claimed_by
         FROM trip_plans tp
         JOIN timeline_entries te ON te.id = tp.timeline_entry_id
         JOIN persons p ON p.id = te.person_id
         WHERE tp.id = :id'
    );
    $stmt->execute(['id' => $tripPlanId]);
    $trip = $stmt->fetch() ?: null;
    if ($trip === null) {
        return null;
    }

    $eventsStmt = $pdo->prepare(
        'SELECT id, title, event_date, plan_notes, memory_notes
         FROM trip_events WHERE trip_plan_id = :tpid ORDER BY sort_order, id'
    );
    $eventsStmt->execute(['tpid' => $tripPlanId]);
    $events = $eventsStmt->fetchAll();

    if ($events) {
        $eventIds = array_map('intval', array_column($events, 'id'));
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $mediaStmt = $pdo->prepare(
            "SELECT tem.trip_event_id, tem.role, m.id, m.mime_type
             FROM trip_event_media tem JOIN media m ON m.id = tem.media_id
             WHERE tem.trip_event_id IN ($placeholders)
             ORDER BY tem.role, tem.sort_order, tem.id"
        );
        $mediaStmt->execute($eventIds);
        $mediaByEvent = [];
        foreach ($mediaStmt->fetchAll() as $m) {
            $mime = (string) $m['mime_type'];
            $kind = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : 'file');
            $mediaByEvent[(int) $m['trip_event_id']][(string) $m['role']][] = [
                'id'   => (int) $m['id'],
                'kind' => $kind,
                'url'  => '/media.php?id=' . (int) $m['id'],
            ];
        }
        foreach ($events as &$ev) {
            $eid = (int) $ev['id'];
            $ev['plan_media'] = $mediaByEvent[$eid]['plan'] ?? [];
            $ev['memory_media'] = $mediaByEvent[$eid]['memory'] ?? [];
        }
        unset($ev);
    }
    $trip['events'] = $events;

    $tagsStmt = $pdo->prepare('SELECT person_id, status FROM memory_tags WHERE timeline_entry_id = :eid');
    $tagsStmt->execute(['eid' => (int) $trip['timeline_entry_id']]);
    $trip['tags'] = $tagsStmt->fetchAll();

    return $trip;
}

// Matches MEDIA_MAX_FILES_PER_ENTRY's existing per-entry convention
// (includes/media.php) but applied per event PER SIDE — Phil asked for
// "10 image uploads per event", separately for the Plans side and the
// Memories side, not 10 shared between the two.
const TRIP_MAX_MEDIA_PER_ROLE = 10;

/**
 * Flattens one events[$index][$field][] nested multi-file upload slot
 * (name="events[0][plan_media][]", etc.) into the flat
 * ['name'=>[...], 'type'=>[...], ...] shape
 * normalize_multi_file_upload()/store_uploaded_media_files()
 * (includes/media.php) already expect — those were built for a plain
 * media[] field, so a nested events[N][role][] field needs reshaping into
 * the same flat form before handing it to them, rather than teaching them
 * a second input shape.
 */
function trip_flatten_event_files(array $eventsFiles, int $index, string $field): array
{
    return [
        'name'     => $eventsFiles['name'][$index][$field] ?? [],
        'type'     => $eventsFiles['type'][$index][$field] ?? [],
        'tmp_name' => $eventsFiles['tmp_name'][$index][$field] ?? [],
        'error'    => $eventsFiles['error'][$index][$field] ?? [],
        'size'     => $eventsFiles['size'][$index][$field] ?? [],
    ];
}

/**
 * Phase 82: minimal ownership/context lookup for ONE trip event, keyed by
 * its own id — used by trip_plan.php's add_event_media action, which
 * (unlike detail/save, both keyed by trip_plan_id) is only ever reached
 * with an event id, since that's all a single picker on an already-loaded
 * trip knows about itself. Returns null if the event doesn't exist.
 */
function fetch_trip_event_context(PDO $pdo, int $eventId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT ev.id, ev.trip_plan_id, tp.timeline_entry_id AS entry_id,
                te.person_id AS owner_person_id, p.family_group_id AS owner_family_group_id,
                p.claimed_by_user_id AS owner_claimed_by
         FROM trip_events ev
         JOIN trip_plans tp ON tp.id = ev.trip_plan_id
         JOIN timeline_entries te ON te.id = tp.timeline_entry_id
         JOIN persons p ON p.id = te.person_id
         WHERE ev.id = :id'
    );
    $stmt->execute(['id' => $eventId]);
    return $stmt->fetch() ?: null;
}
