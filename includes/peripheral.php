<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/graph.php';

/**
 * Phase 58: peripheral trees. On a master tree, someone connected to the
 * family ONLY by partnership (a partner, at any depth — an in-law, a
 * niece's partner, whatever, never a blood relative) isn't allowed to add
 * their OWN antecedents (parents, grandparents...) onto that master tree —
 * that would start mixing two separate families together on one tree.
 * Instead, add_relative.php detects the attempt (ourthology_plan_targets_
 * in_law_antecedent() below) and reroutes to peripheral_tree.php, which
 * explains what's happening and creates that in-law a brand-new,
 * completely separate family tree of their own — a new family_group_id,
 * classed exactly like any other master tree — to build their own family
 * out on, optionally bringing across (only if they say so) the partner
 * and children they already have recorded on the master tree.
 *
 * The in-law's EXISTING node on the master tree is untouched and stays
 * exactly as it was; the new tree gets its own separate "YOU" person row.
 * When the same account can claim both (see ourthology_create_peripheral_
 * tree() below), that's what Phase 58's relaxed persons.claimed_by_user_id
 * constraint (schema.sql) is for — one user, two claimed person rows,
 * switched between via $_SESSION['active_person_id'] (includes/auth.php)
 * and peripheral_tree_links (this table records which master node and
 * which peripheral node belong to the same person).
 */

/**
 * True iff $personId's own blood lineage — everyone reachable from them
 * via relationships edges only, in either direction, transitively, NEVER
 * via a partnership — never reaches this family_group_id's own founding
 * member (the person whose id this family_group_id literally IS: see
 * signup.php and ourthology_create_peripheral_tree() below, which both
 * mint a fresh group as its founder's own id, and merge_family_groups(),
 * which always keeps the lower of two merging groups' ids, so this stays
 * a meaningful signal even after a merge).
 *
 * This has to be a graph-reachability test, not a one-hop check on
 * $personId alone. Two shapes that a one-hop check gets wrong, and this
 * one gets right:
 *  - $personId can easily already have a child of their own recorded
 *    (shared with a blood spouse, or from before) without that child
 *    leading anywhere near the actual family — an in-law with their own
 *    kids already on the master tree is exactly the case this rule
 *    exists for, not an exception to it.
 *  - $personId can be someone ELSE's already-recorded parent (e.g. the
 *    'grandparent' relationship type's own "via" person) — that reaches
 *    straight back into the real family and should never trigger this.
 *
 * Deliberately NOT anchored to any fixed "YOU" person or generation —
 * this equally catches a sibling's partner, a niece's partner, a
 * grandchild's partner, at any depth — the ONLY anchor it uses is the
 * family_group_id's own founder id, which isn't a new stored concept,
 * just the numbering convention this app already keeps.
 *
 * Known, accepted limitation (documented in the Phase 58 addendum): if
 * two already-established family trees are merged by marriage via
 * link_existing.php, and the side whose numbering didn't survive the
 * merge has a founder who's never recorded their OWN parents, that
 * founder would read as in-law-only if they later try to. Narrow, and
 * "forward-only" (Q4) means it only ever affects a fresh attempt, never
 * anything already on the tree — left as a documented edge case rather
 * than adding a stored founder flag purely to close it.
 */
function ourthology_is_in_law_only(PDO $pdo, int $personId): bool
{
    $groupStmt = $pdo->prepare('SELECT family_group_id FROM persons WHERE id = :pid');
    $groupStmt->execute(['pid' => $personId]);
    $groupCol = $groupStmt->fetchColumn();
    if ($groupCol === false) {
        return false;
    }
    $founderId = (int) $groupCol;

    $relStmt = $pdo->prepare(
        "SELECT parent_id, child_id FROM relationships
         WHERE status = 'confirmed' AND parent_id IN (SELECT id FROM persons WHERE family_group_id = :gid)"
    );
    $relStmt->execute(['gid' => $founderId]);
    $adjacency = [];
    foreach ($relStmt->fetchAll() as $r) {
        $p = (int) $r['parent_id'];
        $c = (int) $r['child_id'];
        $adjacency[$p][] = $c;
        $adjacency[$c][] = $p;
    }

    $visited = [$personId => true];
    $queue = [$personId];
    while ($queue) {
        $current = array_pop($queue);
        if ($current === $founderId) {
            return false; // blood-reaches the group's own founder -- not in-law-only
        }
        foreach ($adjacency[$current] ?? [] as $next) {
            if (!isset($visited[$next])) {
                $visited[$next] = true;
                $queue[] = $next;
            }
        }
    }

    // Never blood-reaches the founder -- only counts as in-law-only if
    // actually connected to the group at all via at least one confirmed
    // partnership; otherwise this is just a disconnected placeholder
    // (Phase 45 territory), not an in-law, and not this rule's concern.
    $partStmt = $pdo->prepare(
        "SELECT 1 FROM partnerships WHERE status = 'confirmed' AND (person_a_id = :pid OR person_b_id = :pid2) LIMIT 1"
    );
    $partStmt->execute(['pid' => $personId, 'pid2' => $personId]);
    return (bool) $partStmt->fetchColumn();
}

/**
 * Given a resolve_relationship() result (from add_relative.php, before any
 * insert happens), returns the person id who would gain a brand-new
 * ancestor edge if this plan were applied, or null if this plan doesn't
 * add an ancestor to anyone (most relationship types don't -- only
 * 'parent', 'step-parent' and 'parent-in-law' can ever produce an edge
 * shaped ['parent' => 'NEW', 'child' => <existing person>], which is
 * exactly "someone existing gains a new antecedent"; 'grandparent' also
 * has that shape but its child side is, by construction, always already
 * someone's recorded parent -- so it can never be an in-law-only node and
 * never needs to reach the query below).
 *
 * Doesn't itself decide whether that person IS in-law-only -- call
 * ourthology_is_in_law_only() on the result to decide that (kept as two
 * steps so add_relative.php can log/branch on "no antecedent added here at
 * all" separately from "antecedent added, but to a blood relative").
 */
function ourthology_antecedent_target(array $plan): ?int
{
    foreach ($plan['edges'] ?? [] as $edge) {
        if ($edge['parent'] === 'NEW' && $edge['child'] !== 'NEW') {
            return (int) $edge['child'];
        }
    }
    return null;
}

/**
 * Everyone in $familyGroupId's graph reachable as $personId's own direct
 * partner(s) and children -- exactly the set peripheral_tree.php offers as
 * "bring across" checkboxes (never anyone further out, per Phil: "should
 * only include the children and partner of the creator... but not
 * extended family from the original master"). Each child entry also
 * carries the exact relation_kind recorded on the master tree, so a
 * brought-across mirror can be created with the same genetic/step/adoptive
 * tag rather than defaulting it.
 */
function ourthology_in_law_household(PDO $pdo, int $personId): array
{
    $partners = $pdo->prepare(
        "SELECT p.id, p.first_name, p.middle_name, p.surname, p.born, p.died
         FROM partnerships pt
         JOIN persons p ON p.id = (CASE WHEN pt.person_a_id = :pid THEN pt.person_b_id ELSE pt.person_a_id END)
         WHERE pt.status = 'confirmed' AND (pt.person_a_id = :pid2 OR pt.person_b_id = :pid3)"
    );
    $partners->execute(['pid' => $personId, 'pid2' => $personId, 'pid3' => $personId]);

    $children = $pdo->prepare(
        "SELECT p.id, p.first_name, p.middle_name, p.surname, p.born, p.died, r.relation_kind
         FROM relationships r
         JOIN persons p ON p.id = r.child_id
         WHERE r.parent_id = :pid AND r.status = 'confirmed'"
    );
    $children->execute(['pid' => $personId]);

    return ['partners' => $partners->fetchAll(), 'children' => $children->fetchAll()];
}

/**
 * Insert one unclaimed mirror person into $groupId, copying just the
 * name/dates a brought-across partner or child shows on the peripheral
 * tree -- a one-time, one-directional copy, never linked back to the
 * master-tree row it was copied from (this app doesn't keep any other
 * records "live-synced" either, e.g. a saved postcard copy). Deliberately
 * left unclaimed even if the master-tree source happens to already be
 * claimed by someone -- Phase 58 doesn't invent tree access nobody asked
 * for; whoever it is can be sent a normal invite link later if wanted.
 */
function ourthology_mirror_person(PDO $pdo, array $source, int $groupId, int $createdByUserId): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO persons (first_name, middle_name, surname, born, died, claimed_by_user_id, created_by_user_id, family_group_id)
         VALUES (:first, :middle, :surname, :born, :died, NULL, :creator, :gid)'
    );
    $stmt->execute([
        'first'   => $source['first_name'],
        'middle'  => $source['middle_name'] !== null && $source['middle_name'] !== '' ? $source['middle_name'] : null,
        'surname' => $source['surname'] !== null && $source['surname'] !== '' ? $source['surname'] : null,
        'born'    => $source['born'] ?: null,
        'died'    => $source['died'] ?: null,
        'creator' => $createdByUserId,
        'gid'     => $groupId,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Does the actual creation, inside a transaction the caller (peripheral_
 * tree.php) begins and commits/rolls back. $inLawPersonId is the in-law's
 * EXISTING node on the master tree (left untouched); $bringPartnerIds/
 * $bringChildIds are subsets of ourthology_in_law_household()'s own
 * results, already re-validated by the caller against current DB state
 * (never trust the submitted checkboxes directly -- same discipline as
 * add_relative.php's own via_id/second_parent_id checks). $antecedentEdge
 * is the single edge from the ORIGINAL resolve_relationship() plan that
 * triggered this (parent='NEW', child=$inLawPersonId, kind=<whatever was
 * chosen>) -- reapplied here against the new peripheral "YOU" node instead.
 *
 * Returns ['peripheral_person_id' => int, 'peripheral_group_id' => int,
 * 'claimed' => bool] -- 'claimed' tells the caller whether the new "YOU"
 * node was claimed immediately (the acting user already owns the master
 * in-law node themselves -- by far the common case, "a partner... tries to
 * add an antecedent... a peripheral tree for THEM to work on") or is still
 * unclaimed and needs the normal invite-link flow (anyone else adding this
 * on an unclaimed or someone-else's-account in-law's behalf).
 */
function ourthology_create_peripheral_tree(
    PDO $pdo,
    array $inLawPerson,
    int $actingUserId,
    array $newAntecedentName,
    array $antecedentEdge,
    array $bringPartners,
    array $bringChildren
): array {
    $inLawPersonId = (int) $inLawPerson['id'];

    // 1) The peripheral "YOU" node -- a mirror of the in-law, in a brand
    // new family_group_id (same "insert then self-reference" convention
    // signup.php uses for a fresh account's own root person).
    $stmt = $pdo->prepare(
        'INSERT INTO persons (first_name, middle_name, surname, born, died, claimed_by_user_id, created_by_user_id, family_group_id)
         VALUES (:first, :middle, :surname, :born, :died, NULL, :creator, 0)'
    );
    $stmt->execute([
        'first'   => $inLawPerson['first_name'],
        'middle'  => $inLawPerson['middle_name'] !== null && $inLawPerson['middle_name'] !== '' ? $inLawPerson['middle_name'] : null,
        'surname' => $inLawPerson['surname'] !== null && $inLawPerson['surname'] !== '' ? $inLawPerson['surname'] : null,
        'born'    => $inLawPerson['born'] ?: null,
        'died'    => $inLawPerson['died'] ?: null,
        'creator' => $actingUserId,
    ]);
    $peripheralPersonId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE persons SET family_group_id = :gid WHERE id = :id')
        ->execute(['gid' => $peripheralPersonId, 'id' => $peripheralPersonId]);
    $peripheralGroupId = $peripheralPersonId;

    // 2) Claim it immediately if the acting user already owns the master
    // in-law node (the expected case); otherwise leave it unclaimed --
    // Phase 58 doesn't force-claim an account on someone else's behalf.
    $claimed = ((int) ($inLawPerson['claimed_by_user_id'] ?? 0) === $actingUserId);
    if ($claimed) {
        $pdo->prepare('UPDATE persons SET claimed_by_user_id = :uid WHERE id = :id')
            ->execute(['uid' => $actingUserId, 'id' => $peripheralPersonId]);
    }

    // 3) Link the two sides.
    $pdo->prepare(
        'INSERT INTO peripheral_tree_links (master_family_group_id, master_person_id, peripheral_family_group_id, peripheral_person_id, created_by_user_id)
         VALUES (:mgid, :mpid, :pgid, :ppid, :uid)'
    )->execute([
        'mgid' => (int) $inLawPerson['family_group_id'],
        'mpid' => $inLawPersonId,
        'pgid' => $peripheralGroupId,
        'ppid' => $peripheralPersonId,
        'uid'  => $actingUserId,
    ]);

    // 4) Bring across whichever partner(s)/children were agreed to.
    foreach ($bringPartners as $partner) {
        $mirrorId = ourthology_mirror_person($pdo, $partner, $peripheralGroupId, $actingUserId);
        create_confirmed_partnership($pdo, $peripheralPersonId, $mirrorId, 'married', $actingUserId);
    }
    foreach ($bringChildren as $child) {
        $mirrorId = ourthology_mirror_person($pdo, $child, $peripheralGroupId, $actingUserId);
        $pdo->prepare(
            "INSERT INTO relationships (parent_id, child_id, relation_kind, status, created_by_user_id)
             VALUES (:p, :c, :kind, 'confirmed', :uid)"
        )->execute([
            'p'    => $peripheralPersonId,
            'c'    => $mirrorId,
            'kind' => $child['relation_kind'] ?? 'genetic',
            'uid'  => $actingUserId,
        ]);
    }

    // 5) Finally, the antecedent the user originally asked to add -- the
    // whole reason this tree exists -- created on the NEW tree, retargeted
    // from the in-law's master-tree node onto their new "YOU" node there.
    $newPersonStmt = $pdo->prepare(
        'INSERT INTO persons (first_name, middle_name, surname, claimed_by_user_id, created_by_user_id, family_group_id)
         VALUES (:first, :middle, :surname, NULL, :creator, :gid)'
    );
    $newPersonStmt->execute([
        'first'   => $newAntecedentName['first'],
        'middle'  => $newAntecedentName['middle'] !== '' ? $newAntecedentName['middle'] : null,
        'surname' => $newAntecedentName['surname'] !== '' ? $newAntecedentName['surname'] : null,
        'creator' => $actingUserId,
        'gid'     => $peripheralGroupId,
    ]);
    $newAntecedentId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO relationships (parent_id, child_id, relation_kind, status, created_by_user_id)
         VALUES (:p, :c, :kind, 'confirmed', :uid)"
    )->execute([
        'p'    => $newAntecedentId,
        'c'    => $peripheralPersonId,
        'kind' => $antecedentEdge['kind'],
        'uid'  => $actingUserId,
    ]);

    return [
        'peripheral_person_id' => $peripheralPersonId,
        'peripheral_group_id'  => $peripheralGroupId,
        'new_antecedent_id'    => $newAntecedentId,
        'claimed'              => $claimed,
    ];
}

/**
 * Phase 58's "optionally...make copies of timeline entries and postcards to
 * the other account", offered on tree.php right after a switch. A saved
 * postcard or letter already becomes an ordinary timeline_entries row
 * (origin='postcard'/'letter', Phase 54), so copying timeline_entries
 * covers both without a separate postcards/letters copy path.
 *
 * Returns null if $personId isn't one half of a peripheral-tree pair at
 * all (nothing to offer); otherwise the other identity's person id/name
 * plus how many entries are waiting to be copied each way, so tree.php
 * only shows the banner when there's actually something pending.
 *
 * Scope, deliberately kept small: only an entry's own text/date/
 * visibility/origin is copied, never its photos or videos -- copying a
 * memory's attached media would mean either duplicating files on disk or
 * having two entries in two different family groups point at the same
 * file, and this feature doesn't need to take that on to do its job.
 * Also never re-copies a COPY (only entries with copied_from_entry_id
 * NULL are eligible), so copying can't ping-pong back and forth.
 */
function ourthology_copy_facility_state(PDO $pdo, int $personId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT master_person_id, peripheral_person_id FROM peripheral_tree_links
         WHERE master_person_id = :pid1 OR peripheral_person_id = :pid2'
    );
    $stmt->execute(['pid1' => $personId, 'pid2' => $personId]);
    $link = $stmt->fetch();
    if (!$link) {
        return null;
    }
    $counterpartId = (int) $link['master_person_id'] === $personId
        ? (int) $link['peripheral_person_id']
        : (int) $link['master_person_id'];

    $counterpart = person_row($pdo, $counterpartId);
    if ($counterpart === null) {
        return null;
    }

    return [
        'counterpart_person_id' => $counterpartId,
        'counterpart_name'      => person_display_name($counterpart),
        'pending_to_other'      => ourthology_count_pending_copies($pdo, $personId, $counterpartId),
        'pending_from_other'    => ourthology_count_pending_copies($pdo, $counterpartId, $personId),
    ];
}

/** How many of $fromPersonId's own original entries haven't yet been copied to $toPersonId. */
function ourthology_count_pending_copies(PDO $pdo, int $fromPersonId, int $toPersonId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM timeline_entries src
         WHERE src.person_id = :from AND src.copied_from_entry_id IS NULL
           AND NOT EXISTS (
             SELECT 1 FROM timeline_entries dst
             WHERE dst.person_id = :to AND dst.copied_from_entry_id = src.id
           )"
    );
    $stmt->execute(['from' => $fromPersonId, 'to' => $toPersonId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Does the actual copy: every one of $fromPersonId's own original entries
 * not yet copied to $toPersonId gets a new timeline_entries row owned by
 * $toPersonId, tagged copied_from_entry_id so a repeat visit to this same
 * facility never duplicates it again. Returns how many were copied.
 */
function ourthology_copy_pending_entries(PDO $pdo, int $fromPersonId, int $toPersonId, int $actingUserId): int
{
    $stmt = $pdo->prepare(
        "SELECT src.id, src.entry_type, src.origin, src.title, src.body, src.occurred_on, src.visibility
         FROM timeline_entries src
         WHERE src.person_id = :from AND src.copied_from_entry_id IS NULL
           AND NOT EXISTS (
             SELECT 1 FROM timeline_entries dst
             WHERE dst.person_id = :to AND dst.copied_from_entry_id = src.id
           )"
    );
    $stmt->execute(['from' => $fromPersonId, 'to' => $toPersonId]);
    $pending = $stmt->fetchAll();

    $insert = $pdo->prepare(
        'INSERT INTO timeline_entries (person_id, entry_type, origin, title, body, occurred_on, visibility, copied_from_entry_id, created_by_user_id)
         VALUES (:pid, :etype, :origin, :title, :body, :occurred, :vis, :copied_from, :uid)'
    );
    foreach ($pending as $src) {
        $insert->execute([
            'pid'         => $toPersonId,
            'etype'       => $src['entry_type'],
            'origin'      => $src['origin'],
            'title'       => $src['title'],
            'body'        => $src['body'],
            'occurred'    => $src['occurred_on'],
            'vis'         => $src['visibility'],
            'copied_from' => $src['id'],
            'uid'         => $actingUserId,
        ]);
    }
    return count($pending);
}
