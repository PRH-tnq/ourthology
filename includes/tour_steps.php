<?php
declare(strict_types=1);

/**
 * Phase 28: the onboarding tour's content, in one place so timeline.php and
 * tree.php (which the tour now moves between — see those files' own tour
 * engine scripts) can never drift onto two different scripts. Adapted from
 * Phil's own "Welcome training script" walkthrough, keeping its structure
 * (welcome, then the timeline, then the family tree) and most of its actual
 * wording, split into short steps each pointing at the real thing on screen
 * it's describing.
 *
 * Each step: 'page' (which page it belongs on — the tour engine navigates
 * there automatically when Next crosses from one page's steps into the
 * other's), 'target' (a CSS selector for the spotlight, or null to show the
 * step centered with nothing highlighted), 'title', 'body'.
 */
function ourthology_tour_steps(): array
{
    return [
        [
            'page' => 'timeline',
            'target' => null,
            'title' => 'Welcome to ourthology',
            'body' => "This site builds a timeline of your life and a family tree that connects you with everyone in it, from birth to today. There are no algorithms and this isn't social media — everything here is private to your family, and even then only once you've both agreed to share it. Let's take a quick tour.",
        ],
        [
            'page' => 'timeline',
            'target' => '#arcWrap',
            'title' => 'Your life, as a river',
            'body' => 'Your birth sits at the far left — a lovely spot for that first baby photo, if you have one — and today is on the right. Click or drag along the river to move through time.',
        ],
        [
            'page' => 'timeline',
            'target' => '#zoomToggle',
            'title' => 'Zoom in — or reset',
            'body' => 'Jump to a single decade or year with these buttons. Lost? "All life" always brings you straight back to the full view.',
        ],
        [
            'page' => 'timeline',
            'target' => '#tourAddMemory',
            'title' => 'Add a memory',
            'body' => 'Click anywhere on the river, or use this button. Fill in what happened — remembering the date, so it lands in the right place — and attach as many photos, videos or documents as you like.',
        ],
        [
            'page' => 'timeline',
            'target' => '#tourAddMemory',
            'title' => 'Tag family, or keep it private',
            'body' => "Tick anyone else who was there and they'll be asked to approve the tag — once they do, the memory joins their timeline too. Prefer to keep something to yourself? Switch it to Private before saving; you can always change your mind later.",
        ],
        [
            'page' => 'timeline',
            'target' => '#rail',
            'title' => 'Memories, as cards',
            'body' => "Whatever period you're viewing appears below as cards — click one to see the full memory, add a comment, or edit it.",
        ],
        [
            'page' => 'tree',
            'target' => '.tree-wrap',
            'title' => 'Your family tree',
            'body' => "This is where things get really useful. If other family members already invited you, most of this may be built already — otherwise, start adding from here.",
        ],
        [
            'page' => 'tree',
            'target' => '.tree-legend',
            'title' => 'Reading the tree',
            'body' => "You sit at the centre, underlined in red. Everyone in black already has an account; anyone in blue hasn't signed up yet — you can freely correct their details until they do.",
        ],
        [
            'page' => 'tree',
            'target' => '#tourAddRelative',
            'title' => 'Add a relative',
            'body' => 'Add as many generations as you like — fill in the pop-up, get the relationship right, and save. We\'ll connect them to the right people automatically. Made a mistake? Double-click their name, or use "Edit a person", to fix it — no drama.',
        ],
        [
            'page' => 'tree',
            'target' => '#unclaimedSection',
            'title' => 'Inviting them properly',
            'body' => 'Every person you add gets their own profile. Scroll down for a "get invite link" button to send them, so they can claim it themselves. Until then you can keep editing it and adding memories — a lovely way to build up a departed relative\'s timeline as you find old photos.',
        ],
        [
            'page' => 'tree',
            'target' => '.tree-help-box',
            'title' => 'Click, or double-click',
            'body' => "A single click opens someone's timeline — you'll see their public memories, just as they'd see yours. Double-click your own name, or anyone not yet claimed, to edit their profile.",
        ],
        [
            'page' => 'tree',
            'target' => '#tourPendingLink',
            'title' => 'Keep an eye on Pending',
            'body' => "When someone tags you in a memory, or wants to connect a relative to your tree, it shows up here. Accept a tag and it joins your own timeline too — or decline if it isn't you.",
        ],
        [
            'page' => 'tree',
            'target' => null,
            'title' => "That's the tour!",
            'body' => 'Jump back in with "My tree" or "My timeline" any time — and you can replay this walkthrough whenever you like from the timeline page.',
        ],
    ];
}
