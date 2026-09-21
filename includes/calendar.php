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
 * Phase 84: "died not set" above really means person_is_deceased() (see
 * includes/graph.php) -- someone recorded as deceased with the year still
 * unknown is excluded from birthdays exactly the same as someone with a
 * full date of death on file.
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
        if (empty($p['born']) || person_is_deceased($p)) {
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
            // Phase 62: the raw year on file (not the computed "years"
            // count below) -- carried through so calendar.php's edit
            // form can pre-fill the "Year (optional)" field with what
            // was actually stored, rather than the derived age/count.
            'event_year'  => $e['event_year'] !== null ? (int) $e['event_year'] : null,
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
 * Phase 68: "extend [send a card] to all pages and for all events that
 * appear on the calendar" -- widens Phase 67's birthday-only banner rows
 * (ourthology_birthday_banner_rows(), graph.php) into one shared list
 * covering BOTH birthdays and key dates within $withinDays, shaped for
 * the card composer wherever it's triggered from: timeline.php's and
 * tree.php's own reminder banners, and this page's (calendar.php's) own
 * "Coming up" list. A birthday row keeps a single implied recipient (the
 * person themselves, same as Phase 67, via person_id) -- a key-date row
 * has no person bound to it at all, so it carries event_id instead, and
 * the composer shows a recipient picker for those rather than a fixed
 * name. Each row's occasion/cover_default/greeting_default default to
 * "Birthday"/"Happy Birthday" for a birthday, or the key date's own title
 * (capped to occasion's own 60-char column width) for anything else --
 * both stay fully editable in the composer either way.
 */
function ourthology_calendar_reminder_rows(PDO $pdo, array $persons, int $familyGroupId, int $withinDays = 7): array
{
    $birthdays = ourthology_calendar_birthdays($persons);
    $keyDates = ourthology_calendar_key_dates(fetch_calendar_events_for_group($pdo, $familyGroupId));
    $all = array_merge($birthdays, $keyDates);
    $all = array_values(array_filter($all, fn(array $e): bool => $e['days_away'] >= 0 && $e['days_away'] <= $withinDays));
    usort($all, fn(array $a, array $b): int => $a['days_away'] <=> $b['days_away']);

    $rows = [];
    foreach ($all as $e) {
        $when = match (true) {
            $e['days_away'] === 0 => 'today',
            $e['days_away'] === 1 => 'tomorrow',
            default => 'in ' . $e['days_away'] . ' days',
        };
        $dateLabel = $e['next_date']->format('D j M');

        if ($e['kind'] === 'birthday') {
            $name = person_display_name($e['person']);
            $rows[] = [
                'kind'             => 'birthday',
                'person_id'        => (int) $e['person']['id'],
                'event_id'         => null,
                'name'             => $name,
                'first_name'       => (string) ($e['person']['first_name'] ?? $name),
                'when'             => $when,
                'date_label'       => $dateLabel,
                'text'             => "{$name} turns {$e['turning_age']} {$when} ({$dateLabel})",
                'occasion'         => 'Birthday',
                'cover_default'    => 'Happy Birthday!',
                'greeting_default' => 'Happy Birthday',
            ];
            continue;
        }

        $title = (string) $e['title'];
        $rows[] = [
            'kind'             => 'key_date',
            'person_id'        => null,
            'event_id'         => (int) $e['id'],
            'name'             => $title,
            'first_name'       => '',
            'when'             => $when,
            'date_label'       => $dateLabel,
            'text'             => $e['years'] !== null
                ? "{$title} {$when} — {$e['years']} years ({$dateLabel})"
                : "{$title} {$when} ({$dateLabel})",
            'occasion'         => mb_substr($title, 0, 60),
            'cover_default'    => mb_substr($title, 0, 60),
            'greeting_default' => mb_substr($title, 0, 60),
        ];
    }
    return $rows;
}

/**
 * One calendar_events row, scoped to $familyGroupId -- the ownership
 * check IS the query, same defensive pattern every other family-scoped
 * fetch in this app uses. Used by card.php's send action to look up a
 * key date's own month/day/title server-side rather than trusting
 * anything posted by the browser -- the same "never trust the client for
 * the date" rule the birthday flow already follows. Null if the id
 * doesn't exist or belongs to a different family group.
 */
function fetch_calendar_event_for_group(PDO $pdo, int $eventId, int $familyGroupId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, title, event_month, event_day, event_year FROM calendar_events WHERE id = :id AND family_group_id = :gid'
    );
    $stmt->execute(['id' => $eventId, 'gid' => $familyGroupId]);
    return $stmt->fetch() ?: null;
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

/**
 * Phase 62: edits a key date already on the calendar -- same ownership-
 * scoped guard as delete_calendar_event() above (the family_group_id in
 * the WHERE clause IS the check, so nobody can edit another family's
 * event by guessing an id), and the same "anyone in the family can tidy
 * up" policy that function's own doc comment explains -- there's no
 * per-adder lock on editing either. $eventYear is null to clear a
 * previously-set year, same convention create_calendar_event() uses.
 * Returns true if a row actually changed -- false means the id didn't
 * exist or didn't belong to this family group, which the caller
 * (calendar.php) turns into its own message rather than silently
 * reporting success for an edit that didn't happen.
 */
function update_calendar_event(
    PDO $pdo,
    int $eventId,
    int $familyGroupId,
    string $title,
    int $month,
    int $day,
    ?int $eventYear
): bool {
    $stmt = $pdo->prepare(
        'UPDATE calendar_events SET title = :title, event_month = :month, event_day = :day, event_year = :year
         WHERE id = :id AND family_group_id = :gid'
    );
    $stmt->execute([
        'title' => $title,
        'month' => $month,
        'day'   => $day,
        'year'  => $eventYear,
        'id'    => $eventId,
        'gid'   => $familyGroupId,
    ]);
    return $stmt->rowCount() > 0;
}
