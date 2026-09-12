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
