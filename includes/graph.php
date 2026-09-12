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
