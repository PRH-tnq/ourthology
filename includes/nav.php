<?php
declare(strict_types=1);

/**
 * Phase 85: the shared "My Tree / My Timeline / Family Calendar / Pending"
 * primary nav -- a .segmented pill group in the same visual format as
 * timeline.php's River/Rings/Spiral toggle. Replaces the old approach
 * where each of tree.php/timeline.php/calendar.php/edit_person.php hand-
 * wrote its own subset of these four links inside .nav-links (typically
 * omitting a link to itself) -- that had drifted inconsistent (timeline.php
 * and edit_person.php were each missing links to two of the other three
 * pages). Here every page always renders all four, with the current page
 * shown as the active red pill instead of being left out.
 *
 * $current is one of: 'tree', 'timeline', 'calendar', 'pending' -- or ''
 * (no match) for a page that isn't itself one of the four destinations
 * (edit_person.php), which just renders all four as plain links.
 *
 * $pendingCount mirrors the "(N)" badge the old Pending link showed --
 * relationships + partnerships only (fetch_pending_for_user()'s two
 * arrays), the same narrower count tree.php's and calendar.php's nav
 * badges always used, not pending.php's own broader on-page counts
 * (which also include memory tags/postcards/letters/cards). Kept the
 * same here so the number in the nav doesn't change depending on which
 * page you're looking at it from.
 *
 * $ids optionally maps a key ('tree'/'timeline'/'calendar'/'pending') to
 * an element id, for the handful of onboarding-tour steps
 * (includes/tour_steps.php) that used to target one of these links
 * directly by id -- #tourMyTree on timeline.php, #tourPendingLink and
 * #tourCalendarLink on tree.php. Only ever needed on the non-active
 * items (the tour never points at the page you're already on), and
 * every other page just omits it.
 */
function ourthology_render_primary_nav(string $current, int $pendingCount, array $ids = []): string
{
    $items = [
        'tree'     => ['href' => '/tree.php',     'label' => 'My tree'],
        'timeline' => ['href' => '/timeline.php', 'label' => 'My timeline'],
        'calendar' => ['href' => '/calendar.php', 'label' => 'Family calendar'],
        'pending'  => ['href' => '/pending.php',  'label' => 'Pending' . ($pendingCount > 0 ? ' (' . $pendingCount . ')' : '')],
    ];

    $html = '<div class="segmented nav-primary">';
    foreach ($items as $key => $item) {
        $label = htmlspecialchars($item['label'], ENT_QUOTES);
        if ($key === $current) {
            $html .= '<span class="active" aria-current="page">' . $label . '</span>';
        } else {
            $pendingClass = ($key === 'pending' && $pendingCount > 0) ? ' has-pending' : '';
            $idAttr = isset($ids[$key]) ? ' id="' . htmlspecialchars($ids[$key], ENT_QUOTES) . '"' : '';
            $html .= '<a href="' . htmlspecialchars($item['href'], ENT_QUOTES) . '"' . $idAttr
                . ($pendingClass !== '' ? ' class="' . ltrim($pendingClass) . '"' : '')
                . '>' . $label . '</a>';
        }
    }
    $html .= '</div>';

    return $html;
}
