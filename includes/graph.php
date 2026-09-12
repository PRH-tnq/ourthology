<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Merge two family groups into one (keeps the lower id, no-op if already
 * equal). Called whenever a CONFIRMED relationship/partnership connects
 * two persons who weren't already in the same group.
 */
function merge_family_groups(PDO $pdo, int $groupA, int $groupB): int
{
    if ($groupA === $groupB) {
        return $groupA;
    }
    $keep = min($groupA, $groupB);
    $drop = max($groupA, $groupB);
    $pdo->prepare('UPDATE persons SET family_group_id = :keep WHERE family_group_id = :drop')
        ->execute(['keep' => $keep, 'drop' => $drop]);
    return $keep;
}

function person_row(PDO $pdo, int $personId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM persons WHERE id = :id');
    $stmt->execute(['id' => $personId]);
    return $stmt->fetch() ?: null;
}

function person_display_name(array $p): string
{
    return trim(($p['first_name'] ?? '') . ' ' . ($p['surname'] ?? ''));
}

/** All persons sharing a family_group_id, plus the confirmed edges among them. */
function fetch_family_graph(PDO $pdo, int $familyGroupId): array
{
    $persons = $pdo->prepare(
        'SELECT id, first_name, middle_name, surname, born, died, claimed_by_user_id
         FROM persons WHERE family_group_id = :gid ORDER BY id'
    );
    $persons->execute(['gid' => $familyGroupId]);
    $personRows = $persons->fetchAll();
    $ids = array_column($personRows, 'id');

    $relRows = [];
    $partRows = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rel = $pdo->prepare(
            "SELECT id, parent_id, child_id, relation_kind FROM relationships
             WHERE status = 'confirmed' AND parent_id IN ($placeholders)"
        );
        $rel->execute($ids);
        $relRows = $rel->fetchAll();

        $part = $pdo->prepare(
            "SELECT id, person_a_id, person_b_id, kind FROM partnerships
             WHERE status = 'confirmed' AND person_a_id IN ($placeholders)"
        );
        $part->execute($ids);
        $partRows = $part->fetchAll();
    }

    return ['persons' => $personRows, 'relationships' => $relRows, 'partnerships' => $partRows];
}

/** True if $personId belongs to the given family group (anchor validation). */
function person_in_group(PDO $pdo, int $personId, int $familyGroupId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM persons WHERE id = :id AND family_group_id = :gid');
    $stmt->execute(['id' => $personId, 'gid' => $familyGroupId]);
    return (bool) $stmt->fetchColumn();
}

function fetch_pending_for_user(PDO $pdo, int $userId): array
{
    $rel = $pdo->prepare(
        "SELECT r.id, r.relation_kind, r.created_at,
                pp.first_name AS parent_first, pp.surname AS parent_surname,
                pc.first_name AS child_first, pc.surname AS child_surname,
                cu.email AS created_by_email
         FROM relationships r
         JOIN persons pp ON pp.id = r.parent_id
         JOIN persons pc ON pc.id = r.child_id
         JOIN users cu ON cu.id = r.created_by_user_id
         WHERE r.status = 'pending_approval' AND r.approving_user_id = :uid
         ORDER BY r.created_at"
    );
    $rel->execute(['uid' => $userId]);

    $part = $pdo->prepare(
        "SELECT p.id, p.kind, p.created_at,
                pa.first_name AS a_first, pa.surname AS a_surname,
                pb.first_name AS b_first, pb.surname AS b_surname,
                cu.email AS created_by_email
         FROM partnerships p
         JOIN persons pa ON pa.id = p.person_a_id
         JOIN persons pb ON pb.id = p.person_b_id
         JOIN users cu ON cu.id = p.created_by_user_id
         WHERE p.status = 'pending_approval' AND p.approving_user_id = :uid
         ORDER BY p.created_at"
    );
    $part->execute(['uid' => $userId]);

    return ['relationships' => $rel->fetchAll(), 'partnerships' => $part->fetchAll()];
}

/** The flip side of fetch_pending_for_user(): requests I SENT that are still
 *  waiting on someone else's approval (so a person isn't left wondering
 *  whether their link request went anywhere). */
function fetch_outgoing_pending_for_user(PDO $pdo, int $userId): array
{
    $rel = $pdo->prepare(
        "SELECT r.id, r.relation_kind, r.created_at,
                pp.first_name AS parent_first, pp.surname AS parent_surname,
                pc.first_name AS child_first, pc.surname AS child_surname,
                au.email AS approving_email
         FROM relationships r
         JOIN persons pp ON pp.id = r.parent_id
         JOIN persons pc ON pc.id = r.child_id
         JOIN users au ON au.id = r.approving_user_id
         WHERE r.status = 'pending_approval' AND r.created_by_user_id = :uid
         ORDER BY r.created_at"
    );
    $rel->execute(['uid' => $userId]);

    $part = $pdo->prepare(
        "SELECT p.id, p.kind, p.created_at,
                pa.first_name AS a_first, pa.surname AS a_surname,
                pb.first_name AS b_first, pb.surname AS b_surname,
                au.email AS approving_email
         FROM partnerships p
         JOIN persons pa ON pa.id = p.person_a_id
         JOIN persons pb ON pb.id = p.person_b_id
         JOIN users au ON au.id = p.approving_user_id
         WHERE p.status = 'pending_approval' AND p.created_by_user_id = :uid
         ORDER BY p.created_at"
    );
    $part->execute(['uid' => $userId]);

    return ['relationships' => $rel->fetchAll(), 'partnerships' => $part->fetchAll()];
}

/** Coarse, friendly relative time ("just now" / "3 days ago") — good enough
 *  for a pending-requests list, no need for anything more precise. */
function human_time_ago(string $datetime): string
{
    $diff = max(0, time() - strtotime($datetime));
    if ($diff < 60) {
        return 'just now';
    }
    $units = [
        31536000 => 'year',
        2592000  => 'month',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
    ];
    foreach ($units as $seconds => $label) {
        $count = intdiv($diff, $seconds);
        if ($count >= 1) {
            return $count . ' ' . $label . ($count === 1 ? '' : 's') . ' ago';
        }
    }
    return 'just now';
}

/**
 * Plain parent/child/partner adjacency maps built from a fetch_family_graph()
 * result — the shared starting point for anything that needs to reason about
 * "who is whose sibling/aunt/etc." (currently just add_relative.php's
 * expanded relationship picker, mirrored client-side in its own JS so the
 * dynamic "connected through" dropdown and the server-side validation agree
 * on exactly the same definitions).
 */
function graph_build_maps(array $graph): array
{
    $childrenOf = [];
    $parentsOf = [];
    foreach ($graph['relationships'] as $r) {
        $childrenOf[(int) $r['parent_id']][] = (int) $r['child_id'];
        $parentsOf[(int) $r['child_id']][] = (int) $r['parent_id'];
    }
    $partnersOf = [];
    foreach ($graph['partnerships'] as $p) {
        $a = (int) $p['person_a_id'];
        $b = (int) $p['person_b_id'];
        $partnersOf[$a][] = $b;
        $partnersOf[$b][] = $a;
    }
    return ['childrenOf' => $childrenOf, 'parentsOf' => $parentsOf, 'partnersOf' => $partnersOf];
}

/** Other children of any of $personId's own parents — full or half siblings alike. */
function graph_siblings_of(array $maps, int $personId): array
{
    $out = [];
    foreach ($maps['parentsOf'][$personId] ?? [] as $p) {
        foreach ($maps['childrenOf'][$p] ?? [] as $c) {
            if ($c !== $personId) {
                $out[$c] = true;
            }
        }
    }
    return array_keys($out);
}

/** Siblings of any of $personId's own parents — i.e. $personId's aunts/uncles. */
function graph_aunts_uncles_of(array $maps, int $personId): array
{
    $out = [];
    foreach ($maps['parentsOf'][$personId] ?? [] as $p) {
        foreach (graph_siblings_of($maps, $p) as $s) {
            $out[$s] = true;
        }
    }
    return array_keys($out);
}

/**
 * The full relationship vocabulary offered when adding a new relative or
 * attaching an existing person (originally prototypes/timeline.html's
 * RELATIONSHIPS list), grouped the same "older/same/younger generation"
 * way. Shared between add_relative.php (creates a new person) and
 * edit_person.php (attaches an already-existing person) so the two never
 * drift apart on what each relationship type means.
 */
function relationship_options(): array
{
    return [
        'grandparent'    => ['label' => 'Grandparent',                                    'group' => 'Older generation'],
        'parent'         => ['label' => 'Parent',                                         'group' => 'Older generation'],
        'parent-in-law'  => ['label' => 'Parent-in-law',                                  'group' => 'Older generation'],
        'step-parent'    => ['label' => 'Step-parent (married an existing parent)',       'group' => 'Older generation'],
        'aunt-uncle'     => ['label' => 'Aunt / Uncle',                                   'group' => 'Older generation'],
        'sibling'        => ['label' => 'Sibling',                                        'group' => 'Same generation'],
        'spouse'         => ['label' => 'Spouse / Partner',                               'group' => 'Same generation'],
        'sibling-in-law' => ['label' => 'Sibling-in-law (married an existing sibling)',   'group' => 'Same generation'],
        'step-sibling'   => ['label' => 'Step-sibling (child of a step-parent)',          'group' => 'Same generation'],
        'cousin'         => ['label' => 'Cousin',                                         'group' => 'Same generation'],
        'child'          => ['label' => 'Child',                                          'group' => 'Younger generation'],
        'child-in-law'   => ['label' => 'Child-in-law',                                   'group' => 'Younger generation'],
        'step-child'     => ['label' => 'Step-child',                                     'group' => 'Younger generation'],
        'niece-nephew'   => ['label' => 'Niece / Nephew',                                 'group' => 'Younger generation'],
        'grandchild'     => ['label' => 'Grandchild',                                     'group' => 'Younger generation'],
        'other'          => ['label' => 'Other / not connected',                          'group' => 'Other'],
    ];
}

/**
 * Which existing-person set the "connected through" picker offers for each
 * relationship that needs one, and what to say when that set is empty.
 * 'source' names a lookup both the server (candidates_for_source() below)
 * and the page's own client-side JS know how to compute from the same
 * parent/child/partner maps, so the dropdown the user sees and the
 * server-side check that runs on submit are always in agreement.
 */
function relationship_via_needed(): array
{
    return [
        'grandparent'    => ['source' => 'parentsOf',     'prompt' => 'Whose parent are they?',                 'empty' => 'Add one of their parents first, then add a grandparent through them.'],
        'parent-in-law'  => ['source' => 'partnersOf',    'prompt' => 'They are the parent of…',                 'empty' => 'Add their spouse/partner first, then add a parent-in-law through them.'],
        'aunt-uncle'     => ['source' => 'parentsOf',     'prompt' => 'Sibling of which of their parents?',      'empty' => 'Add one of their parents first, then add an aunt or uncle through them.'],
        'sibling-in-law' => ['source' => 'siblingsOf',    'prompt' => 'Spouse of which sibling?',                'empty' => 'Add a sibling first, then add their spouse as a sibling-in-law.'],
        'step-sibling'   => ['source' => 'parentsOf',     'prompt' => 'Step-child of which of their parents?',   'empty' => 'Add one of their parents first, then add a step-sibling through them.'],
        'cousin'         => ['source' => 'auntsUnclesOf', 'prompt' => 'Child of which aunt or uncle?',           'empty' => 'Add an aunt or uncle first, then add their child as a cousin.'],
        'child-in-law'   => ['source' => 'childrenOf',    'prompt' => 'Spouse of which child?',                  'empty' => 'Add a child first, then add their spouse as a child-in-law.'],
        'niece-nephew'   => ['source' => 'siblingsOf',    'prompt' => 'Child of which sibling?',                 'empty' => 'Add a sibling first, then add their child as a niece or nephew.'],
        'grandchild'     => ['source' => 'childrenOf',    'prompt' => 'Child of which child?',                   'empty' => 'Add a child first, then add their child as a grandchild.'],
    ];
}

function candidates_for_source(string $source, int $anchorId, array $maps): array
{
    switch ($source) {
        case 'parentsOf':     return $maps['parentsOf'][$anchorId] ?? [];
        case 'childrenOf':    return $maps['childrenOf'][$anchorId] ?? [];
        case 'partnersOf':    return $maps['partnersOf'][$anchorId] ?? [];
        case 'siblingsOf':    return graph_siblings_of($maps, $anchorId);
        case 'auntsUnclesOf': return graph_aunts_uncles_of($maps, $anchorId);
        default:              return [];
    }
}

/**
 * Independently re-derives what edges a submission means, never trusting
 * the client's dynamically-populated "connected through" list — it's
 * rebuilt here from the current database state. Returns
 * ['ok' => true, 'edges' => [...], 'partnerships' => [...]] (each edge/
 * partnership using the string 'NEW' as a stand-in for the other person —
 * a not-yet-inserted new person in add_relative.php, or an already-
 * existing one being attached in edit_person.php; either way the caller
 * resolves that placeholder to a real id before inserting) or
 * ['ok' => false, 'error' => '...'].
 *
 * $secondParentId/$secondParentKind only apply to the 'child' case: adding
 * a child records the anchor as one parent, but a child almost always has
 * two — this lets the anchor's own current partner be recorded as the
 * second parent in the same submission (genetic, step, or adoptive,
 * independently of the anchor's own kind), rather than requiring a
 * separate trip through edit_person.php's "attach as a relative of
 * someone else" afterward just to add the second, equally normal, parent
 * edge. The caller (add_relative.php) is responsible for having already
 * verified $secondParentId is actually one of the anchor's own partners.
 */
function resolve_relationship(string $rel, int $anchorId, ?int $viaId, string $directKind, array $maps, array $personsById, array $viaNeeded, ?int $secondParentId = null, string $secondParentKind = 'genetic'): array
{
    if (isset($viaNeeded[$rel])) {
        $cfg = $viaNeeded[$rel];
        $candidates = candidates_for_source($cfg['source'], $anchorId, $maps);
        if (!$candidates) {
            return ['ok' => false, 'error' => $cfg['empty']];
        }
        if ($viaId === null || !in_array($viaId, $candidates, true)) {
            return ['ok' => false, 'error' => 'Choose who to connect them through.'];
        }
    }

    switch ($rel) {
        case 'parent':
            return ['ok' => true, 'edges' => [['parent' => 'NEW', 'child' => $anchorId, 'kind' => $directKind]]];
        case 'step-parent':
            return ['ok' => true, 'edges' => [['parent' => 'NEW', 'child' => $anchorId, 'kind' => 'step']]];
        case 'child':
            $edges = [['parent' => $anchorId, 'child' => 'NEW', 'kind' => $directKind]];
            if ($secondParentId !== null) {
                $edges[] = ['parent' => $secondParentId, 'child' => 'NEW', 'kind' => $secondParentKind];
            }
            return ['ok' => true, 'edges' => $edges];
        case 'step-child':
            return ['ok' => true, 'edges' => [['parent' => $anchorId, 'child' => 'NEW', 'kind' => 'step']]];
        case 'spouse':
            return ['ok' => true, 'partnerships' => [['a' => $anchorId, 'b' => 'NEW']]];
        case 'grandparent':
            return ['ok' => true, 'edges' => [['parent' => 'NEW', 'child' => $viaId, 'kind' => 'genetic']]];
        case 'parent-in-law':
            return ['ok' => true, 'edges' => [['parent' => 'NEW', 'child' => $viaId, 'kind' => 'genetic']]];
        case 'aunt-uncle':
            $grandparents = $maps['parentsOf'][$viaId] ?? [];
            if (!$grandparents) {
                $name = isset($personsById[$viaId]) ? person_display_name($personsById[$viaId]) : 'That person';
                return ['ok' => false, 'error' => $name . ' has no parent on record yet — add one first, then add an aunt or uncle through them.'];
            }
            $edges = [];
            foreach ($grandparents as $g) {
                $edges[] = ['parent' => $g, 'child' => 'NEW', 'kind' => 'genetic'];
            }
            return ['ok' => true, 'edges' => $edges];
        case 'sibling':
            $parents = $maps['parentsOf'][$anchorId] ?? [];
            if (!$parents) {
                return ['ok' => false, 'error' => 'Add one of their parents first, then add a sibling through them.'];
            }
            $edges = [];
            foreach ($parents as $p) {
                $edges[] = ['parent' => $p, 'child' => 'NEW', 'kind' => 'genetic'];
            }
            return ['ok' => true, 'edges' => $edges];
        case 'sibling-in-law':
            return ['ok' => true, 'partnerships' => [['a' => $viaId, 'b' => 'NEW']]];
        case 'step-sibling':
            return ['ok' => true, 'edges' => [['parent' => $viaId, 'child' => 'NEW', 'kind' => 'step']]];
        case 'cousin':
            return ['ok' => true, 'edges' => [['parent' => $viaId, 'child' => 'NEW', 'kind' => 'genetic']]];
        case 'child-in-law':
            return ['ok' => true, 'partnerships' => [['a' => $viaId, 'b' => 'NEW']]];
        case 'niece-nephew':
            return ['ok' => true, 'edges' => [['parent' => $viaId, 'child' => 'NEW', 'kind' => 'genetic']]];
        case 'grandchild':
            return ['ok' => true, 'edges' => [['parent' => $viaId, 'child' => 'NEW', 'kind' => 'genetic']]];
        case 'other':
            return ['ok' => true, 'edges' => [], 'partnerships' => []];
        default:
            return ['ok' => false, 'error' => 'Choose a valid relationship.'];
    }
}

/**
 * Insert a confirmed partnership between two people already in the same
 * family group. Shared by add_relative.php's "attach an existing person"
 * mode and edit_person.php's own "add a partnership" action, so the two
 * can't drift apart on the duplicate-pair check.
 *
 * Throws RuntimeException('duplicate_partnership') if a confirmed
 * partnership between this exact pair already exists — partnerships has
 * no unique constraint on the pair the way relationships does
 * (uniq_parent_child), so this guard has to be explicit.
 */
function create_confirmed_partnership(PDO $pdo, int $aId, int $bId, string $kind, int $createdByUserId): void
{
    $lo = min($aId, $bId);
    $hi = max($aId, $bId);
    $exists = $pdo->prepare(
        'SELECT 1 FROM partnerships WHERE status = :status AND person_a_id = :a AND person_b_id = :b'
    );
    $exists->execute(['status' => 'confirmed', 'a' => $lo, 'b' => $hi]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('duplicate_partnership');
    }
    $pdo->prepare(
        "INSERT INTO partnerships (person_a_id, person_b_id, kind, status, created_by_user_id)
         VALUES (:a, :b, :kind, 'confirmed', :uid)"
    )->execute(['a' => $lo, 'b' => $hi, 'kind' => $kind, 'uid' => $createdByUserId]);
}

/**
 * Can $viewerUserId edit $person's own record (name/dates) and the
 * relationships/partnerships shown on their edit_person.php page? True
 * for any unclaimed person (a placeholder anyone in the family group can
 * correct) or for your own claimed record — false for anyone else's
 * claimed record, which only that account holder may change.
 */
function person_is_editable_by(array $person, int $viewerUserId): bool
{
    $claimedBy = $person['claimed_by_user_id'] ?? null;
    return $claimedBy === null || (int) $claimedBy === $viewerUserId;
}

function create_claim_token(PDO $pdo, int $personId, int $createdByUserId): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare(
        'INSERT INTO claim_tokens (person_id, token, created_by_user_id, expires_at)
         VALUES (:pid, :token, :uid, DATE_ADD(NOW(), INTERVAL 30 DAY))'
    )->execute(['pid' => $personId, 'token' => $token, 'uid' => $createdByUserId]);
    return $token;
}

function claim_link_url(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'ourthology.com';
    return "$scheme://$host/claim.php?token=" . urlencode($token);
}
