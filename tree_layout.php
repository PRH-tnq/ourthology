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

        // Co-parents of the same child(ren) should sort next to each
        // other, the same way actual partners already do by being kept in
        // one unit from the pairing step above — most often two people
        // who share children but were never recorded as partners of each
        // other (this app has no way yet to mark two unclaimed
        // placeholders as partners of one another). Without this, a unit
        // with no positioned parents of its own has nothing to compute
        // its own barycenter from, sorts purely by id, and can easily
        // land between two OTHER units — splitting a family unit's two
        // parents apart and running that family's connecting line
        // straight past (or through) whoever landed in the middle.
        // Clustering first, by shared-child overlap, then sorting
        // clusters (falling back to whichever member has a real
        // barycenter, or id order if none do) keeps every such pair
        // adjacent regardless of which one happens to have a computable
        // barycenter of its own.
        $childSetOf = function (array $unit) use ($childrenOf): array {
            $out = [];
            foreach ($unit as $id) {
                foreach ($childrenOf[$id] ?? [] as $c) {
                    $out[$c] = true;
                }
            }
            return array_keys($out);
        };
        $clusterOf = range(0, count($units) - 1);
        $childSets = array_map($childSetOf, $units);
        for ($i = 0; $i < count($units); $i++) {
            if (!$childSets[$i]) {
                continue;
            }
            for ($j = $i + 1; $j < count($units); $j++) {
                if ($clusterOf[$j] === $clusterOf[$i] || !$childSets[$j]) {
                    continue;
                }
                if (array_intersect($childSets[$i], $childSets[$j])) {
                    $old = $clusterOf[$j];
                    $new = $clusterOf[$i];
                    foreach ($clusterOf as $k => $c) {
                        if ($c === $old) {
                            $clusterOf[$k] = $new;
                        }
                    }
                }
            }
        }
        $clusterBarycenter = [];
        $clusterMinId = [];
        foreach ($units as $i => $unit) {
            $c = $clusterOf[$i];
            $b = tree_unit_barycenter($unit, $parentsOf, $xrel);
            if ($b !== null && !isset($clusterBarycenter[$c])) {
                $clusterBarycenter[$c] = $b;
            }
            if (!isset($clusterMinId[$c]) || $unit[0] < $clusterMinId[$c]) {
                $clusterMinId[$c] = $unit[0];
            }
        }

        usort($units, function ($u1, $u2) use ($units, $clusterOf, $clusterBarycenter, $clusterMinId, $parentsOf, $xrel) {
            $i1 = array_search($u1, $units, true);
            $i2 = array_search($u2, $units, true);
            $c1 = $clusterOf[$i1];
            $c2 = $clusterOf[$i2];
            if ($c1 !== $c2) {
                $b1 = $clusterBarycenter[$c1] ?? null;
                $b2 = $clusterBarycenter[$c2] ?? null;
                if ($b1 === null && $b2 === null) {
                    return $clusterMinId[$c1] <=> $clusterMinId[$c2];
                }
                if ($b1 === null) {
                    return 1;
                }
                if ($b2 === null) {
                    return -1;
                }
                return $b1 <=> $b2 ?: ($clusterMinId[$c1] <=> $clusterMinId[$c2]);
            }
            // Same cluster (co-parents, or already a partner unit): order
            // by each unit's own barycenter/id so the result is still
            // stable, without pulling them apart from each other.
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

    $tiersAsc = array_keys($order);
    $minTier = $tiersAsc ? min($tiersAsc) : 0;

    // Turn each tier's order back into units (adjacent partner pairs = one
    // unit) so the centering pass below can move a couple together.
    $unitsByTier = [];
    foreach ($order as $t => $ids) {
        $units = [];
        $seen = [];
        foreach ($ids as $i => $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $next = $ids[$i + 1] ?? null;
            if ($next !== null && ($partnerOf[$id] ?? null) === $next) {
                $units[] = [$id, $next];
                $seen[$id] = true;
                $seen[$next] = true;
            } else {
                $units[] = [$id];
                $seen[$id] = true;
            }
        }
        $unitsByTier[$t] = $units;
    }

    // Final, GLOBALLY comparable x per person. $xrel above restarts at 0
    // independently for every tier, so one tier's own left edge carries no
    // meaning relative to any other tier's — the previous version papered
    // over that by centering each row's overall *bounding box* within the
    // widest row, which usually looked plausible but never actually
    // aligned a specific parent's trunk with a specific child underneath
    // it (an only child, or a whole chain of only-children spanning
    // several generations, would dogleg sideways at every junction purely
    // because each tier's width happened to differ from its neighbors').
    //
    // Instead: the viewer's own tier keeps exactly the spacing/order
    // already computed above (nothing to visually center it on yet, and
    // its own sibling order/spacing is already correct), and every other
    // tier is then pulled — working outward from the viewer's tier in
    // both directions, one tier at a time — toward the average x of
    // whichever already-positioned adjacent tier it connects to:
    // descendants toward their own parents, ancestors toward their own
    // children. A single child then lines up in a dead straight line
    // under its parents' trunk (and a chain of them stays straight across
    // any number of generations), because its x *is* that trunk's x, not
    // a separately-spaced value that merely landed close to it. A unit
    // with nothing to reference in the adjacent tier (the oldest
    // ancestors on record, a marry-in with no represented family of their
    // own) simply takes the next free slot after its left neighbor.
    $finalX = [];
    foreach ($unitsByTier[0] ?? [] as $unit) {
        foreach ($unit as $id) {
            $finalX[$id] = $xrel[$id];
        }
    }

    $placeTierOutward = function (int $t, array $refMap) use (&$finalX, $unitsByTier, $partnerOf) {
        // Two adjacent units that are both parents (or both children) of
        // the exact *same* set of people on the other side — most often
        // two co-parents of the same kids who were never actually
        // recorded as partners of each other, which this app can't always
        // avoid (there's no way yet to mark two unclaimed placeholders as
        // partners — see the Phase 6a note) — get grouped and centered as
        // one pair, rather than each pulled toward the same point
        // independently: pulling them one at a time landed the first
        // exactly on target and shoved the second a full extra gap to the
        // right of it, which is correct (no overlap) but visibly lopsided
        // for something that's conceptually one pair. A formally
        // partnered couple is just the special case where this was
        // already true by construction (partnerOf keeps them adjacent as
        // a single unit to begin with).
        $groups = [];
        $lastKey = null;
        foreach ($unitsByTier[$t] as $unit) {
            $targets = [];
            foreach ($unit as $id) {
                foreach ($refMap[$id] ?? [] as $rid) {
                    $targets[$rid] = true;
                }
            }
            $targetIds = array_keys($targets);
            sort($targetIds);
            $key = $targetIds ? implode(',', $targetIds) : null;
            if ($key !== null && $key === $lastKey) {
                $groups[count($groups) - 1][] = $unit;
            } else {
                $groups[] = [$unit];
            }
            $lastKey = $key;
        }

        $prevRight = null;
        foreach ($groups as $group) {
            // The group's own members, laid out left-to-right at their
            // normal relative spacing (tight within an actual partner
            // pair, the ordinary gap otherwise) — this only determines the
            // group's own internal spacing and total width, never whether
            // it merged with its neighbor, so nothing here changes how far
            // apart two genuine partners, or two merely-adjacent co-
            // parents, are drawn from each other.
            $offsets = [];
            $cum = 0;
            $prevIdInGroup = null;
            foreach ($group as $unit) {
                foreach ($unit as $id) {
                    if ($prevIdInGroup !== null) {
                        $cum += (($partnerOf[$prevIdInGroup] ?? null) === $id) ? TREE_SPOUSE_GAP : TREE_NODE_GAP;
                    }
                    $offsets[$id] = $cum;
                    $prevIdInGroup = $id;
                }
            }
            $groupWidth = $cum;

            $targets = [];
            foreach ($group as $unit) {
                foreach ($unit as $id) {
                    foreach ($refMap[$id] ?? [] as $rid) {
                        if (isset($finalX[$rid])) {
                            $targets[] = $finalX[$rid];
                        }
                    }
                }
            }
            $desiredCenter = $targets ? array_sum($targets) / count($targets) : null;
            if ($desiredCenter === null) {
                $desiredCenter = ($prevRight ?? 0) + $groupWidth / 2;
            }
            $left = $desiredCenter - $groupWidth / 2;
            if ($prevRight !== null && $left < $prevRight) {
                $left = $prevRight;
            }
            foreach ($offsets as $id => $off) {
                $finalX[$id] = $left + $off;
            }
            $prevRight = $left + $groupWidth + TREE_NODE_GAP;
        }
    };

    // Descendant tiers, nearest the viewer outward (1, 2, 3, ...): each
    // pulls toward its own parentsOf, one tier closer to 0 and therefore
    // already placed.
    $descTiers = array_values(array_filter($tiersAsc, fn($t) => $t > 0));
    sort($descTiers);
    foreach ($descTiers as $t) {
        $placeTierOutward($t, $parentsOf);
    }

    // Ancestor tiers, nearest the viewer outward (-1, -2, -3, ...): each
    // pulls toward its own childrenOf, one tier closer to 0.
    $ancTiers = array_values(array_filter($tiersAsc, fn($t) => $t < 0));
    rsort($ancTiers);
    foreach ($ancTiers as $t) {
        $placeTierOutward($t, $childrenOf);
    }

    $allX = array_values($finalX);
    $minX = $allX ? min($allX) : 0;
    $maxX = $allX ? max($allX) : 0;
    $maxRowWidth = $maxX - $minX;

    $pos = []; // person_id => ['x'=>..,'y'=>..,'tier'=>..]
    foreach ($order as $t => $ids) {
        foreach ($ids as $id) {
            $pos[$id] = [
                'x'    => TREE_ROW_LABEL_W + TREE_SIDE_PAD + ($finalX[$id] - $minX),
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
