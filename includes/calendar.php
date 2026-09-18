<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/graph.php';

/**
 * Phase 56: the family calendar -- calendar.php's own logic. Two kinds
 * of entry, blended together on the page: birthdays, auto-derived from
 * persons.born (living people only -- same "born on file, died not set"
 * rule graph_upcoming_birthdays() already uses for the tree page's
 * reminder banner, so the two never disagree about who's "current"), and
 * "key dates" -- anniversaries, memorials, whatever a family member wants
 * to mark -- which anyone in the family group can add to the new
 * calendar_events table.
 *
 * A key date is always an annually-recurring month/day, exactly like a
 * birthday is: there's no "one-off" vs "recurring" flag, since a family
 * calendar's whole point is things that come back every year. The
 * optional event_year exists only so a date that DOES have a natural
 * "since" (a wedding, a move) can show "(12 years)" the way a birthday
 * shows "turns 40" -- leave it blank for a date with no such count (e.g.
 * "First day of summer holidays").
 */

/**
 * The next occurrence (this year, or next if it's already passed) of an
 * annual month/day, plus how many days away it is. Guards a Feb 29 date
 * on a non-leap year by falling back to Feb 28, same as
 * graph_upcoming_birthdays() -- duplicated here (not shared with that
 * function) so this new page can't regress the already-deployed tree.php
 * reminder banner.
 */
function ourthology_next_annual_occurrence(int $month, int $day, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $todayYear = (int) $today->format('Y');

    $occurrence = static function (int $year) use ($month, $day): DateTimeImmutable {
        if (!checkdate($month, $day, $year)) {
            return DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-02-28', $year));
        }
        return DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day));
    };

    $next = $occurrence($todayYear);
    if ($next < $today) {
        $next = $occurrence($todayYear + 1);
    }
    $daysAway = (int) $today->diff($next)->format('%r%a');
    return ['date' => $next, 'days_away' => $daysAway];
}

/**
 * Every birthday entry for the calendar page: everyone in $persons (as
 * fetch_family_graph() returns them) with a birth date on file and no
 * death date. Each entry: ['kind'=>'birthday', 'title', 'person',
 * 'month', 'day', 'next_date' (DateTimeImmutable), 'days_away',
 * 'turning_age'].
 */
function ourthology_calendar_birthdays(array $persons): array
{
    $today = new DateTimeImmutable('today');
    $out = [];
    foreach ($persons as $p) {
        if (empty($p['born']) || !empty($p['died'])) {
            continue;
        }
        $bornStr = substr((string) $p['born'], 0, 10);
        $born = DateTimeImmutable::createFromFormat('!Y-m-d', $bornStr);
        if ($born === false) {
            continue;
        }
        $month = (int) $born->format('m');
        $day = (int) $born->format('d');
        $occ = ourthology_next_annual_occurrence($month, $day, $today);
        $out[] = [
            'kind'        => 'birthday',
            'title'       => person_display_name($p) . "’s birthday",
            'person'      => $p,
            'month'       => $month,
            'day'         => $day,
            'next_date'   => $occ['date'],
            'days_away'   => $occ['days_away'],
            'turning_age' => ((int) $occ['date']->format('Y')) - ((int) $born->format('Y')),
        ];
    }
    return $out;
}

/**
 * Every key date any family member has added, family_group_id-scoped.
 * Each row includes the adder's own name (for a light "added by Mum"
 * attribution) via created_by_person_id -- stored directly rather than
 * joined through users, the same "store the real family member, not
 * just the account" choice postcards.sender_person_id already made.
 */
function fetch_calendar_events_for_group(PDO $pdo, int $familyGroupId): array
{
    $stmt = $pdo->prepare(
        'SELECT ce.id, ce.title, ce.event_month, ce.event_day, ce.event_year, ce.created_at,
                ce.created_by_person_id,
                cp.first_name AS creator_first, cp.surname AS creator_surname
         FROM calendar_events ce
         JOIN persons cp ON cp.id = ce.created_by_person_id
         WHERE ce.family_group_id = :gid
         ORDER BY ce.event_month, ce.event_day'
    );
    $stmt->execute(['gid' => $familyGroupId]);
    return $stmt->fetchAll();
}

/**
 * Turns fetch_calendar_events_for_group()'s rows into the same shape
 * ourthology_calendar_birthdays() returns (kind='key_date' instead),
 * so the page can sort and render both kinds together without a
 * branch at every call site. 'years' is null when event_year wasn't
 * given -- the caller decides whether to print a count at all.
 */
function ourthology_calendar_key_dates(array $eventRows): array
{
    $today = new DateTimeImmutable('today');
    $out = [];
    foreach ($eventRows as $e) {
        $month = (int) $e['event_month'];
        $day = (int) $e['event_day'];
        $occ = ourthology_next_annual_occurrence($month, $day, $today);
        $years = $e['event_year'] !== null ? ((int) $occ['date']->format('Y')) - (int) $e['event_year'] : null;
        $out[] = [
            'kind'        => 'key_date',
            'id'          => (int) $e['id'],
            'title'       => (string) $e['title'],
            'month'       => $month,
            'day'         => $day,
            'next_date'   => $occ['date'],
            'days_away'   => $occ['days_away'],
            'years'       => $years,
            'added_by'    => person_display_name(['first_name' => $e['creator_first'], 'surname' => $e['creator_surname']]),
            'added_by_id' => (int) $e['created_by_person_id'],
        ];
    }
    return $out;
}

/**
 * Adds a key date. $eventYear is null when left blank. Title is
 * capped/trimmed by the caller (calendar.php) before this is called,
 * same "validate at the edge, trust it here" convention every other
 * create_*() function in this app follows. Returns the new row's id.
 */
function create_calendar_event(
    PDO $pdo,
    int $familyGroupId,
    int $createdByPersonId,
    int $createdByUserId,
    string $title,
    int $month,
    int $day,
    ?int $eventYear
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO calendar_events (family_group_id, title, event_month, event_day, event_year, created_by_person_id, created_by_user_id)
         VALUES (:gid, :title, :month, :day, :year, :pid, :uid)'
    );
    $stmt->execute([
        'gid'   => $familyGroupId,
        'title' => $title,
        'month' => $month,
        'day'   => $day,
        'year'  => $eventYear,
        'pid'   => $createdByPersonId,
        'uid'   => $createdByUserId,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Deletes a key date -- but only one belonging to the caller's own
 * family group (the ownership check IS the query, same defensive
 * pattern every other delete/discard in this app uses). "Anyone in the
 * family can add" (Phil's own words) is read here as "anyone in the
 * family can also tidy up" -- there's no per-adder lock, matching how
 * generously this app already treats shared family content (e.g. any
 * family member can edit an unclaimed person's profile).
 */
function delete_calendar_event(PDO $pdo, int $eventId, int $familyGroupId): void
{
    $pdo->prepare('DELETE FROM calendar_events WHERE id = :id AND family_group_id = :gid')
        ->execute(['id' => $eventId, 'gid' => $familyGroupId]);
}
