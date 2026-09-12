<?php
declare(strict_types=1);

/**
 * Turns the family graph (persons/relationships/partnerships from
 * fetch_family_graph()) into a laid-out genealogy chart: which generation
 * ("tier") each person sits in, their left-to-right order within that
 * generation, and the parent/partner connectors to draw between them.
 *
 * This is a simplified layered-graph layout (BFS for generation, a
 * barycenter heuristic for left-to-right order) — not a full crossing-
 * minimizing algorithm. For the tangled cases a real family tree can
 * produce (remarriage, half-siblings, adoption across branches) it won't
 * always be perfectly crossing-free, but it always produces a stable,
 * readable, generation-correct chart, and never fails to lay out a person
 * out just because their family history is complicated.
 */

const TREE_NODE_W = 150;
const TREE_NODE_H = 56;
const TREE_NODE_SPACING_X = 190;
const TREE_TIER_SPACING_Y = 150;
const TREE_TOP_PAD = 50;
// Half a node's width plus a little breathing room, so the outermost node
// in the widest row doesn't get clipped flush against the canvas edge.
const TREE_SIDE_PAD = TREE_NODE_W / 2 + 30;

/**
 * BFS out from the viewer: a parent->child edge moves down one tier, a
 * child->parent edge moves up one tier, a partnership keeps the same tier.
 * Every person in the same family_group_id is reachable this way by
 * definition (that's what family_group_id merging means), so this always
 * assigns every person a tier.
 */
function tree_compute_tiers(array $persons, array $childrenOf, array $parentsOf, array $partnersOf, int $startPersonId): array
{
    $tier = [$startPersonId => 0];
    $queue = [$startPersonId];
    while ($queue) {
        $cur = array_shift($queue);
        $t = $tier[$cur];
        foreach ($childrenOf[$cur] ?? [] as $c) {
            if (!isset($tier[$c])) {
                $tier[$c] = $t + 1;
                $queue[] = $c;
            }
        }
        foreach ($parentsOf[$cur] ?? [] as $p) {
            if (!isset($tier[$p])) {
                $tier[$p] = $t - 1;
                $queue[] = $p;
            }
        }
        foreach ($partnersOf[$cur] ?? [] as $ptn) {
            if (!isset($tier[$ptn])) {
                $tier[$ptn] = $t;
                $queue[] = $ptn;
            }
        }
    }
    // Safety net only — shouldn't trigger for anyone actually sharing this
    // family_group_id, since that's defined by reachability via exactly
    // these edges.
    foreach ($persons as $p) {
        $pid = (int) $p['id'];
        if (!isset($tier[$pid])) {
            $tier[$pid] = 0;
        }
    }
    return $tier;
}

/** Average x-slot of a unit's already-positioned parents, or null if none are positioned yet. */
function tree_unit_barycenter(array $unit, array $parentsOf, array $xslot): ?float
{
    $vals = [];
    foreach ($unit as $id) {
        foreach ($parentsOf[$id] ?? [] as $pid) {
            if (isset($xslot[$pid])) {
                $vals[] = $xslot[$pid];
            }
        }
    }
    return $vals ? array_sum($vals) / count($vals) : null;
}

function compute_tree_layout(array $graph, int $viewerPersonId): array
{
    $persons = $graph['persons'];

    $childrenOf = [];
    $parentsOf = [];
    foreach ($graph['relationships'] as $r) {
        $childrenOf[(int) $r['parent_id']][] = (int) $r['child_id'];
        $parentsOf[(int) $r['child_id']][] = (int) $r['parent_id'];
    }

    $partnersOf = [];
    $partnerOf = []; // first partner only, for keeping couples visually adjacent
    foreach ($graph['partnerships'] as $p) {
        $a = (int) $p['person_a_id'];
        $b = (int) $p['person_b_id'];
        $partnersOf[$a][] = $b;
        $partnersOf[$b][] = $a;
        $partnerOf[$a] = $partnerOf[$a] ?? $b;
        $partnerOf[$b] = $partnerOf[$b] ?? $a;
    }

    $tier = tree_compute_tiers($persons, $childrenOf, $parentsOf, $partnersOf, $viewerPersonId);

    $byTier = [];
    foreach ($persons as $p) {
        $byTier[$tier[(int) $p['id']]][] = (int) $p['id'];
    }
    ksort($byTier);

    $order = [];  // tier => ordered person ids, couples kept adjacent
    $xslot = [];  // person_id => integer slot index within its own tier

    foreach ($byTier as $t => $ids) {
        $seen = [];
        $units = [];
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $partner = $partnerOf[$id] ?? null;
            if ($partner !== null && ($tier[$partner] ?? null) === $t && in_array($partner, $ids, true) && !isset($seen[$partner])) {
                $units[] = [$id, $partner];
                $seen[$id] = true;
                $seen[$partner] = true;
            } else {
                $units[] = [$id];
                $seen[$id] = true;
            }
        }

        usort($units, function ($u1, $u2) use ($parentsOf, $xslot) {
            $b1 = tree_unit_barycenter($u1, $parentsOf, $xslot);
            $b2 = tree_unit_barycenter($u2, $parentsOf, $xslot);
            if ($b1 === null && $b2 === null) {
                return $u1[0] <=> $u2[0];
            }
            if ($b1 === null) {
                return 1;
            }
            if ($b2 === null) {
                return -1;
            }
            return $b1 <=> $b2 ?: ($u1[0] <=> $u2[0]);
        });

        $slot = 0;
        $orderedIds = [];
        foreach ($units as $unit) {
            foreach ($unit as $id) {
                $xslot[$id] = $slot;
                $orderedIds[] = $id;
                $slot++;
            }
        }
        $order[$t] = $orderedIds;
    }

    // Convert slot indices into actual pixel positions, centering every
    // tier's row around a shared horizontal midline regardless of how many
    // people are in it.
    $maxRowWidth = 0;
    foreach ($order as $ids) {
        $rowWidth = (count($ids) - 1) * TREE_NODE_SPACING_X;
        $maxRowWidth = max($maxRowWidth, $rowWidth);
    }

    $tiersAsc = array_keys($order);
    $minTier = $tiersAsc ? min($tiersAsc) : 0;

    $pos = []; // person_id => ['x'=>..,'y'=>..,'tier'=>..]
    foreach ($order as $t => $ids) {
        $rowWidth = (count($ids) - 1) * TREE_NODE_SPACING_X;
        $offset = ($maxRowWidth - $rowWidth) / 2;
        foreach ($ids as $i => $id) {
            $pos[$id] = [
                'x'    => TREE_SIDE_PAD + $offset + $i * TREE_NODE_SPACING_X,
                'y'    => TREE_TOP_PAD + ($t - $minTier) * TREE_TIER_SPACING_Y,
                'tier' => $t,
            ];
        }
    }

    // Group children by their exact set of parents, so half-siblings (who
    // don't share both parents) get their own connector rather than being
    // pulled into the wrong family unit.
    $familyUnits = []; // "sortedParentIds" => ['parents'=>[...], 'children'=>[...]]
    foreach ($parentsOf as $childId => $pids) {
        $key = implode(',', $pids);
        if (!isset($familyUnits[$key])) {
            $familyUnits[$key] = ['parents' => $pids, 'children' => []];
        }
        $familyUnits[$key]['children'][] = $childId;
    }

    $totalWidth = $maxRowWidth + 2 * TREE_SIDE_PAD;
    $totalHeight = TREE_TOP_PAD + (count($order) ? (max($tiersAsc) - $minTier) * TREE_TIER_SPACING_Y : 0) + TREE_NODE_H / 2 + 30;

    return [
        'positions'    => $pos,
        'order'        => $order,
        'partnerOf'    => $partnerOf,
        'familyUnits'  => array_values($familyUnits),
        'width'        => $totalWidth,
        'height'       => $totalHeight,
    ];
}
