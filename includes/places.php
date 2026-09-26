<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/graph.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/custom_audience.php';
require_once __DIR__ . '/entries.php';

/**
 * Phase 92: "Places we lived".
 *
 * One `homes` row per actual property, shared by everyone who lived there
 * (home_residents -- each with their OWN move-in/move-out dates, since a
 * child born in a house or leaving for university doesn't share their
 * parents' dates). Every resident's own map (places.php?person_id=) shows
 * the same home, same photos, same updates -- entered once, not once per
 * person. home_updates are the Memory-planner-style repeating entries (an
 * extension, a new kitchen, a repaint...), each with their own photos in
 * home_media; home_media rows with update_id NULL are the home's general
 * photos.
 *
 * Dates can be as precise as people actually remember them: a year, a
 * month and year, or a full date (the *_precision columns), since "we
 * moved there in 1974" is the normal case for an old house.
 *
 * Visibility follows the memory rules: public = whole family group,
 * private = only people who can edit the home or live(d) there, custom =
 * the creating person's own Custom Memory Settings audience (Phase 33).
 *
 * Photos are uploaded with the Phase 91 chunked uploader (each file on its
 * own, resumable) into the UPLOADER's own media folder, and served only
 * through home_media.php, which re-checks home_can_view() every request.
 */

const HOME_MAX_PHOTOS_PER_SECTION = 25;

/**
 * Parse optional day/month/year slots into [date, precision]. Year alone,
 * month+year, or a full date are all valid; all blank is "unknown" (null,
 * null); anything else returns ['error' => true].
 */
function places_parse_date(string $day, string $month, string $year): array
{
    $day = trim($day);
    $month = trim($month);
    $year = trim($year);
    if ($day === '' && $month === '' && $year === '') {
        return ['date' => null, 'precision' => null];
    }
    if ($year === '' || !ctype_digit($year) || (int) $year < 1000 || (int) $year > 2200) {
        return ['error' => true];
    }
    if ($month === '') {
        if ($day !== '') {
            return ['error' => true];
        }
        return ['date' => sprintf('%04d-01-01', (int) $year), 'precision' => 'year'];
    }
    if (!ctype_digit($month) || (int) $month < 1 || (int) $month > 12) {
        return ['error' => true];
    }
    if ($day === '') {
        return ['date' => sprintf('%04d-%02d-01', (int) $year, (int) $month), 'precision' => 'month'];
    }
    if (!ctype_digit($day) || !checkdate((int) $month, (int) $day, (int) $year)) {
        return ['error' => true];
    }
    return ['date' => sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day), 'precision' => 'day'];
}

/** "12 Mar 1995" / "Mar 1995" / "1995" / "" -- a date shown only as precisely as it was entered. */
function places_date_label(?string $date, ?string $precision): string
{
    if ($date === null || $date === '') {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return '';
    }
    return match ($precision) {
        'year'  => date('Y', $ts),
        'month' => date('M Y', $ts),
        default => date('j M Y', $ts),
    };
}

/** Split a stored date back into the three form slots, honouring precision. */
function places_date_slots(?string $date, ?string $precision): array
{
    if ($date === null || $date === '') {
        return ['', '', ''];
    }
    [$y, $m, $d] = array_map('intval', explode('-', substr($date, 0, 10)));
    return match ($precision) {
        'year'  => ['', '', (string) $y],
        'month' => ['', (string) $m, (string) $y],
        default => [(string) $d, (string) $m, (string) $y],
    };
}

function fetch_home_row(PDO $pdo, int $homeId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM homes WHERE id = :id');
    $stmt->execute(['id' => $homeId]);
    return $stmt->fetch() ?: null;
}

/** Resident rows for a set of homes (with each resident's name/claim), keyed by home id. */
function fetch_home_residents(PDO $pdo, array $homeIds): array
{
    if (!$homeIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($homeIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT hr.*, p.first_name, p.surname, p.claimed_by_user_id, p.family_group_id
         FROM home_residents hr JOIN persons p ON p.id = hr.person_id
         WHERE hr.home_id IN ($in)
         ORDER BY (COALESCE(hr.moved_in, hr.moved_out) IS NULL), COALESCE(hr.moved_in, hr.moved_out), p.first_name"
    );
    $stmt->execute(array_values($homeIds));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['home_id']][] = $r;
    }
    return $out;
}

/**
 * May $userId edit this home (details, residents, updates, photos)?
 *  - whoever created it, always;
 *  - once anyone who lived there has CLAIMED their own profile, only the
 *    creator and those account holders themselves (a relative who merely
 *    shares the family group can't rewrite someone else's home, just as
 *    they can't edit someone else's memory);
 *  - a home nobody on it has claimed yet (grandparents' house, say) is
 *    open to the whole family to maintain -- the same "unclaimed = shared"
 *    rule person_is_editable_by() applies to unclaimed profiles.
 */
function home_can_edit(array $home, array $residents, int $userId, int $viewerGroup): bool
{
    if ((int) $home['family_group_id'] !== $viewerGroup) {
        return false;
    }
    if ((int) $home['created_by_user_id'] === $userId) {
        return true;
    }
    $anyClaimed = false;
    foreach ($residents as $r) {
        if (!empty($r['claimed_by_user_id'])) {
            $anyClaimed = true;
            if ((int) $r['claimed_by_user_id'] === $userId) {
                return true;
            }
        }
    }
    return !$anyClaimed && $residents !== [];
}

/** May this viewer see the home at all (map pin, details, photos)? */
function home_can_view(PDO $pdo, array $home, array $residents, int $userId, int $viewerPersonId, int $viewerGroup): bool
{
    if ((int) $home['family_group_id'] !== $viewerGroup) {
        return false;
    }
    if ($home['visibility'] === 'public' || home_can_edit($home, $residents, $userId, $viewerGroup)) {
        return true;
    }
    foreach ($residents as $r) {
        if ((int) $r['person_id'] === $viewerPersonId) {
            return true;
        }
    }
    if ($home['visibility'] === 'custom' && !empty($home['created_by_person_id'])) {
        return person_in_custom_audience($pdo, (int) $home['created_by_person_id'], $viewerPersonId);
    }
    return false;
}

/** Updates and photos for a set of homes: ['updates' => [homeId => [...]], 'media' => [homeId => ['general' => [...], updateId => [...]]]]. */
function fetch_home_updates_and_media(PDO $pdo, array $homeIds): array
{
    $updates = [];
    $media = [];
    if (!$homeIds) {
        return ['updates' => $updates, 'media' => $media];
    }
    $in = implode(',', array_fill(0, count($homeIds), '?'));
    $u = $pdo->prepare("SELECT * FROM home_updates WHERE home_id IN ($in) ORDER BY home_id, sort_order, id");
    $u->execute(array_values($homeIds));
    foreach ($u->fetchAll() as $row) {
        $updates[(int) $row['home_id']][] = $row;
    }
    $m = $pdo->prepare("SELECT id, home_id, update_id, mime_type, file_path FROM home_media WHERE home_id IN ($in) ORDER BY home_id, sort_order, id");
    $m->execute(array_values($homeIds));
    foreach ($m->fetchAll() as $row) {
        $key = $row['update_id'] === null ? 'general' : (int) $row['update_id'];
        $media[(int) $row['home_id']][$key][] = $row;
    }
    return ['updates' => $updates, 'media' => $media];
}

/**
 * Every home $personId lived in that the viewer is allowed to see, each
 * carrying its residents, updates and photos, in the order that person
 * lived in them: their own move-in date, or their move-out date when only
 * that is known (Phase 97 -- "lived there until 1990" used to sink to the
 * bottom); homes with neither go last. Re-sorted on every load, so editing
 * a date moves the home to its new place straight away.
 */
function fetch_homes_for_person(PDO $pdo, int $personId, int $userId, int $viewerPersonId, int $viewerGroup): array
{
    $stmt = $pdo->prepare(
        'SELECT h.*, hr.moved_in AS my_in, hr.moved_in_precision AS my_in_p,
                hr.moved_out AS my_out, hr.moved_out_precision AS my_out_p
         FROM homes h JOIN home_residents hr ON hr.home_id = h.id
         WHERE hr.person_id = :pid AND h.family_group_id = :gid
         ORDER BY (COALESCE(hr.moved_in, hr.moved_out) IS NULL), COALESCE(hr.moved_in, hr.moved_out), (hr.moved_out IS NULL), hr.moved_out, h.id'
    );
    $stmt->execute(['pid' => $personId, 'gid' => $viewerGroup]);
    $homes = $stmt->fetchAll();
    $ids = array_map(fn ($h) => (int) $h['id'], $homes);
    $residents = fetch_home_residents($pdo, $ids);
    $extra = fetch_home_updates_and_media($pdo, $ids);

    $out = [];
    foreach ($homes as $h) {
        $hid = (int) $h['id'];
        $res = $residents[$hid] ?? [];
        if (!home_can_view($pdo, $h, $res, $userId, $viewerPersonId, $viewerGroup)) {
            continue;
        }
        $h['residents'] = $res;
        $h['updates'] = $extra['updates'][$hid] ?? [];
        $h['media'] = $extra['media'][$hid] ?? [];
        $h['can_edit'] = home_can_edit($h, $res, $userId, $viewerGroup);
        $out[] = $h;
    }
    return $out;
}

/**
 * Delete homes that no longer have anyone living in them -- used after a
 * resident is removed (remove_me) or erased (account/person deletion) --
 * returning the photo files to delete once the caller's transaction has
 * committed.
 */
function places_delete_empty_homes(PDO $pdo): array
{
    $ids = $pdo->query(
        'SELECT h.id FROM homes h LEFT JOIN home_residents hr ON hr.home_id = h.id WHERE hr.id IS NULL'
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        return [];
    }
    return places_delete_homes($pdo, array_map('intval', $ids));
}

/** Delete homes (rows cascade) and return their photo file paths for deletion after commit. */
function places_delete_homes(PDO $pdo, array $homeIds): array
{
    if (!$homeIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($homeIds), '?'));
    $f = $pdo->prepare("SELECT file_path FROM home_media WHERE home_id IN ($in)");
    $f->execute(array_values($homeIds));
    $files = $f->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare("DELETE FROM homes WHERE id IN ($in)")->execute(array_values($homeIds));
    return $files;
}

/** A home's display name: its own name, else the first address line, else its postcode. */
function places_home_label(array $h, string $fallback = 'A home'): string
{
    $label = trim((string) ($h['name'] ?? ''));
    if ($label === '') {
        $label = trim(preg_split('/[\n,]/', (string) ($h['address'] ?? ''))[0] ?? '');
    }
    if ($label === '') {
        $label = trim((string) ($h['postcode'] ?? ''));
    }
    return $label !== '' ? $label : $fallback;
}

/**
 * Phase 93: memories on $personId's timeline that have a map pin, for the
 * "memories" layer on their Places map -- exactly the set (own + approved
 * tags, same visibility rule) the timeline itself would show this viewer.
 */
function places_memory_pins(PDO $pdo, int $personId, bool $canManage, int $viewerPersonId): array
{
    $out = [];
    foreach (fetch_entries_for_person($pdo, $personId, $canManage, $viewerPersonId) as $e) {
        if ($e['location_lat'] === null || $e['location_lng'] === null) {
            continue;
        }
        $date = !empty($e['occurred_on']) ? (string) $e['occurred_on'] : substr((string) $e['created_at'], 0, 10);
        $thumb = null;
        foreach ($e['media'] as $m) {
            if (str_starts_with((string) $m['mime_type'], 'image/')) {
                $thumb = '/media.php?id=' . (int) $m['id'] . '&thumb=1';
                break;
            }
        }
        $title = trim((string) ($e['title'] ?? ''));
        $out[] = [
            'id'    => (int) $e['id'],
            'title' => $title !== '' ? $title : ($e['entry_type'] === 'diary' ? 'Diary entry' : 'A memory'),
            'date'  => $date,
            'dateLabel' => date('j M Y', strtotime($date)),
            'place' => (string) ($e['location_label'] ?? ''),
            'lat'   => (float) $e['location_lat'],
            'lng'   => (float) $e['location_lng'],
            'thumb' => $thumb,
        ];
    }
    usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));
    return $out;
}

