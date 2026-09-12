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
 *
 * Sizing/spacing mirrors the original prototype's tree (same node envelope
 * and gaps: prototypes/timeline.html's TREE_NODE_W/H, TREE_NODE_GAP,
 * TREE_TIER_GAP, TREE_SPOUSE_GAP), since the point of this pass is to look
 * and feel like that tree even though the layout algorithm underneath is
 * new (the prototype's was built around a different, single-user model).
 */

const TREE_NODE_W = 118;
const TREE_NODE_H = 62;
const TREE_ME_W = 96;
const TREE_ME_H = 50;
// Widened from the first pass (150/120) after screenshots showed a pair of
// long (truncated-at-18-char) names sitting close enough to visually touch,
// especially for the tighter spouse gap — these give real names room to
// breathe on both sides without losing the "spouses read as a pair" effect.
const TREE_NODE_GAP = 172;   // horizontal distance between ordinary adjacent people
const TREE_SPOUSE_GAP = 142; // tighter — keeps a partner visually paired
const TREE_TIER_GAP = 148;   // vertical distance between generations
const TREE_TOP_PAD = 60;
const TREE_ROW_LABEL_W = 150; // left margin reserved for "PARENTS" / "3× GREAT-GRANDPARENTS" etc.
// Half the widest node's width plus a little breathing room, so the
// outermost node in the widest row doesn't get clipped flush against the
// canvas edge.
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

/** Average relative x of a unit's already-positioned parents, or null if none are positioned yet. */
function tree_unit_barycenter(array $unit, array $parentsOf, array $xrel): ?float
{
    $vals = [];
    foreach ($unit as $id) {
        foreach ($parentsOf[$id] ?? [] as $pid) {
            if (isset($xrel[$pid])) {
                $vals[] = $xrel[$pid];
            }
        }
    }
    return $vals ? array_sum($vals) / count($vals) : null;
}

/** "PARENTS" / "GRANDPARENTS" / "3× GREAT-GRANDCHILDREN" etc., or null for the viewer's own row. */
function tree_tier_label(int $offsetFromViewer): ?string
{
    if ($offsetFromViewer === 0) {
        return null;
    }
    $abs = abs($offsetFromViewer);
    $down = $offsetFromViewer > 0;
    if ($abs === 1) {
        return $down ? 'CHILDREN' : 'PARENTS';
    }
    if ($abs === 2) {
        return $down ? 'GRANDCHILDREN' : 'GRANDPARENTS';
    }
    $greats = $abs - 2; // "great" steps beyond grandparent/grandchild
    $word = $down ? 'GRANDCHILDREN' : 'GRANDPARENTS';
    $prefix = $greats === 1 ? 'GREAT-' : $greats . '× GREAT-';
    return $prefix . $word;
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
    $xrel  = [];  // person_id => relative x within its own tier (0-based, gap-weighted)

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

        usort($units, function ($u1, $u2) use ($parentsOf, $xrel) {
            $b1 = tree_unit_barycenter($u1, $parentsOf, $xrel);
            $b2 = tree_unit_barycenter($u2, $parentsOf, $xrel);
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

        // Cumulative placement: a bonded couple sits TREE_SPOUSE_GAP apart
        // (tight, reads as one unit), everyone else TREE_NODE_GAP apart —
        // matching the prototype's own spacing choices exactly.
        $cum = 0;
        $orderedIds = [];
        $prevId = null;
        foreach ($units as $unit) {
            foreach ($unit as $id) {
                if ($prevId === null) {
                    $xrel[$id] = 0;
                } else {
                    $gap = (($partnerOf[$prevId] ?? null) === $id) ? TREE_SPOUSE_GAP : TREE_NODE_GAP;
                    $cum += $gap;
                    $xrel[$id] = $cum;
                }
                $orderedIds[] = $id;
                $prevId = $id;
            }
        }
        $order[$t] = $orderedIds;
    }

    // Center every tier's row around a shared horizontal midline regardless
    // of how many people (or how tightly spaced) are in it.
    $rowWidth = [];
    foreach ($order as $t => $ids) {
        $rowWidth[$t] = $ids ? max($xrel[end($ids)], 0) : 0;
    }
    $maxRowWidth = $rowWidth ? max($rowWidth) : 0;

    $tiersAsc = array_keys($order);
    $minTier = $tiersAsc ? min($tiersAsc) : 0;

    $pos = []; // person_id => ['x'=>..,'y'=>..,'tier'=>..]
    foreach ($order as $t => $ids) {
        $offset = ($maxRowWidth - $rowWidth[$t]) / 2;
        foreach ($ids as $id) {
            $pos[$id] = [
                'x'    => TREE_ROW_LABEL_W + TREE_SIDE_PAD + $offset + $xrel[$id],
                'y'    => TREE_TOP_PAD + ($t - $minTier) * TREE_TIER_GAP,
                'tier' => $t,
            ];
        }
    }

    // Group children by their exact set of parents, so half-siblings (who
    // don't share both parents) get their own connector rather than being
    // pulled into the wrong family unit. Sorted before keying: parentsOf[]
    // is built by iterating relationship rows in whatever order they were
    // created/fetched, so two full siblings recorded via different flows
    // (e.g. one added directly as a parent's child, the other added later
    // via "sibling", which walks the anchor's parents in map order) can
    // end up with the *same set* of parent ids in a *different order* —
    // "2,5" vs "5,2" — which, unsorted, produced two distinct string keys
    // and therefore two separate, needlessly-duplicated trunks for
    // siblings who share both parents. Sorting first makes the key depend
    // only on the actual set of parents, not the order they happened to be
    // recorded in.
    $familyUnits = []; // "sortedParentIds" => ['parents'=>[...], 'children'=>[...]]
    foreach ($parentsOf as $childId => $pids) {
        $sortedPids = $pids;
        sort($sortedPids);
        $key = implode(',', $sortedPids);
        if (!isset($familyUnits[$key])) {
            $familyUnits[$key] = ['parents' => $sortedPids, 'children' => []];
        }
        $familyUnits[$key]['children'][] = $childId;
    }

    // Row labels ("PARENTS", "GRANDCHILDREN", ...) — one per tier, at that
    // tier's y, skipping the viewer's own row (the highlighted "you" node
    // already marks it).
    $rowLabels = [];
    foreach ($tiersAsc as $t) {
        $label = tree_tier_label($t);
        if ($label !== null) {
            $rowLabels[] = ['y' => TREE_TOP_PAD + ($t - $minTier) * TREE_TIER_GAP, 'text' => $label];
        }
    }

    $totalWidth = TREE_ROW_LABEL_W + $maxRowWidth + 2 * TREE_SIDE_PAD;
    $totalHeight = TREE_TOP_PAD + (count($order) ? (max($tiersAsc) - $minTier) * TREE_TIER_GAP : 0) + TREE_NODE_H / 2 + 40;

    return [
        'positions'    => $pos,
        'order'        => $order,
        'partnerOf'    => $partnerOf,
        'familyUnits'  => array_values($familyUnits),
        'rowLabels'    => $rowLabels,
        'width'        => $totalWidth,
        'height'       => $totalHeight,
    ];
}
