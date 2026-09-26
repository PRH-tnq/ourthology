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
 * Phase 92: a fifth destination, 'places' (places.php, "Places we lived").
 * Phase 94: removed again -- the homes map now lives on the timeline itself
 * ("Where we've lived" beside River/Rings/Spiral), and places.php is reached
 * from there ("Add or edit homes") or from a home's card, not the nav.
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

    return $html . ourthology_render_mobile_chrome($current, $pendingCount);
}

/**
 * Phase 96: the phone layout (under 700px wide; see styles.css "Phase 96"
 * and mobile_ui.js). Rendered once per page, straight after the primary
 * nav, and invisible on anything wider:
 *  - a fixed bottom tab bar with the same four destinations (the pill nav
 *    above is hidden on a phone);
 *  - a Menu button (top right) that opens a sheet listing the page's own
 *    action buttons, the tour, and Log out -- mobile_ui.js builds that list
 *    from the page's existing buttons, so nothing is duplicated here;
 *  - the tiny script that marks the page as having this chrome, run
 *    inline so the phone layout applies before the first paint.
 * Tab items carry data-tour-for so onboarding-tour steps that point at the
 * (hidden-on-phone) pill links highlight the tab instead (tour.js).
 */
function ourthology_render_mobile_chrome(string $current, int $pendingCount): string
{
    $icons = [
        'tree'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="2.4"/><circle cx="5.5" cy="18.5" r="2.4"/><circle cx="18.5" cy="18.5" r="2.4"/><path d="M12 7.4v4.1M5.5 16.1v-2.6h13v2.6"/></svg>',
        'timeline' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 16c3-6 5-6 7-2s4 4 7-3 4-2 4-2"/><circle cx="10" cy="14" r="1.6"/><circle cx="17" cy="9" r="1.6"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>',
        'pending'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13.5 6.5 5h11L20 13.5V19H4z"/><path d="M4 13.5h5l1 2h4l1-2h5"/></svg>',
    ];
    $tabs = [
        'tree'     => ['/tree.php', 'Tree', '#tourMyTree'],
        'timeline' => ['/timeline.php', 'Timeline', ''],
        'calendar' => ['/calendar.php', 'Calendar', '#tourCalendarLink'],
        'pending'  => ['/pending.php', 'Pending', '#tourPendingLink'],
    ];
    $html = '<script>document.documentElement.classList.add("m-ui");</script>';
    $html .= '<button type="button" class="m-menu-btn" id="mMenuBtn" aria-haspopup="dialog" aria-controls="mSheet" data-tour-fallback>'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg><span>Menu</span></button>';
    $html .= '<nav class="m-tabbar" aria-label="Main">';
    foreach ($tabs as $key => [$href, $label, $tourFor]) {
        $active = $key === $current;
        $badge = ($key === 'pending' && $pendingCount > 0) ? '<span class="m-badge">' . $pendingCount . '</span>' : '';
        $html .= '<a href="' . $href . '"' . ($active ? ' class="active" aria-current="page"' : '')
            . ($tourFor !== '' ? ' data-tour-for="' . $tourFor . '"' : '') . '>'
            . '<span class="m-tab-icon">' . $icons[$key] . $badge . '</span><span class="m-tab-label">' . $label . '</span></a>';
    }
    $html .= '</nav>';
    $html .= '<div class="m-sheet-scrim" id="mSheetScrim" hidden></div>'
        . '<div class="m-sheet" id="mSheet" role="dialog" aria-modal="true" aria-label="Menu" hidden>'
        . '<div class="m-sheet-grip" aria-hidden="true"></div><div class="m-sheet-body" id="mSheetBody"></div>'
        . '<button type="button" class="m-sheet-close" id="mSheetClose">Close</button></div>';
    $html .= '<script src="/mobile_ui.js?v=1" defer></script>';
    return $html;
}
