<?php
declare(strict_types=1);

/**
 * Phase 28: the onboarding tour's content, in one place so every page it
 * can appear on (timeline.php, tree.php, add_entry.php, add_relative.php,
 * edit_person.php, and -- Phase 57 -- calendar.php -- see includes/
 * tour_engine.php and the shared /tour.js for how those pages all run the
 * same walkthrough) can never drift onto different scripts.
 *
 * Phase 42: reworked end to end from Phil's own "Edit the tour" notes --
 * bigger, clearer arrows on the timeline river; a real walkthrough of the
 * Add a memory and Add a relative pages instead of just pointing at their
 * buttons; a short dummy-diagram explainer for what a family tree even is;
 * an accurate mobile/tablet note; and a closing walkthrough of a profile's
 * own settings (dates, photo, Custom audience, notifications) in place of
 * the old single "that's the tour" card. Adapted from Phil's original
 * "Welcome training script" walkthrough, keeping its warm, plain-language
 * voice throughout.
 *
 * Phase 57: "add the calendar to the tour, and the letters and postcards
 * -- open each up during the tour and explain all the features and how
 * to use them." Added a full walkthrough of the postcard/letter composer
 * (opened for real on timeline.php via each step's 'action' -- see
 * below) in place of the old single "Send a postcard" step pointing at
 * its button, and a new tree.php -> calendar.php -> tree.php leg
 * alongside the existing tree.php -> add_relative.php -> tree.php one.
 *
 * Each step:
 *   'page'   -- which page it belongs on. The engine navigates there for
 *               real (a full page load, with the in-progress step handed
 *               off via sessionStorage) whenever Next crosses from one
 *               page's steps into another's.
 *   'target' -- a CSS selector for the spotlight, or null to show the step
 *               centered with nothing highlighted. A selector that matches
 *               nothing on the page (for instance a brand-new account with
 *               no tree built yet) degrades gracefully to the same centered
 *               presentation -- see place() in tour.js.
 *   'tab'    -- optional. Only meaningful on edit_person.php, which shows
 *               its Profile and Account Settings fields as two tabs on one
 *               page rather than two separate pages -- set this to the tab
 *               name ("profile" or "account") the target lives on and the
 *               engine clicks that tab before spotlighting it.
 *   'title', 'body' -- the tooltip's text.
 *   'diagram' -- optional. Names a small illustrative diagram the engine
 *               draws inline in the tooltip, below the body text, for a
 *               step that's explaining a concept rather than pointing at a
 *               live part of the screen. Currently just 'tree'.
 *   'arrows' -- optional list of annotation descriptors the engine draws
 *               as animated arrows/labels over the live page once the step
 *               is placed (see renderAnnotations() in tour.js for the
 *               exact fields each type takes):
 *                 'point' -- a single labelled arrow to one spot on the
 *                            highlighted target (fractions of its box).
 *                 'span'  -- a double-headed line spanning two points on
 *                            the highlighted target, with a centered label
 *                            (used for "your whole life, left to right").
 *                 'link'  -- a curved, animated line connecting two
 *                            *different* elements by their own selectors,
 *                            for "these two things are the same thing"
 *                            (the memory cards and their spot on the
 *                            river).
 *                 'drop'  -- a small icon that repeatedly animates from
 *                            the highlighted target towards another
 *                            element, for "this lands over there" (a new
 *                            memory dropping onto the timeline).
 *   'action' -- optional. Names a step in timeline.php's postcard/letter
 *               composer for the engine to run before spotlighting the
 *               step's target -- opening it, flipping the postcard,
 *               switching to the letter panel, or closing it back up
 *               (see TOUR_ACTIONS in /tour.js for the exact list). The
 *               composer is ordinary in-page markup cloned from a
 *               <template> by timeline.php's own script, not a separate
 *               page, so a step can't just navigate to it the way a step
 *               navigates to add_entry.php or add_relative.php -- this is
 *               how the tour instead opens it for real and walks through
 *               it live, rather than just pointing at the button.
 */
function ourthology_tour_steps(): array
{
    return [
        // ---------------------------------------------------------- timeline
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
            'body' => 'Your birth sits at the far left and today is on the right — everything in between is the story so far. Click or drag along the river to move through time.',
            'arrows' => [
                ['type' => 'point', 'text' => 'birth', 'ax' => 0.03, 'ay' => 0.5, 'ldx' => 0, 'ldy' => 54],
                ['type' => 'point', 'text' => 'today', 'ax' => 0.97, 'ay' => 0.5, 'ldx' => 0, 'ldy' => 54],
                ['type' => 'span', 'text' => 'your whole life, left to right', 'x1' => 0.08, 'x2' => 0.92, 'y' => -0.55, 'labelDy' => -14],
            ],
        ],
        [
            'page' => 'timeline',
            'target' => '#zoomToggle',
            'title' => 'Zoom in — or reset',
            'body' => 'Jump to a single decade or year with these buttons. Or scroll with your mouse wheel and it\'ll auto-zoom as far as you like. Lost? "All life" always brings you straight back to the full view.',
        ],
        [
            'page' => 'timeline',
            'target' => '#tourAddMemory',
            'title' => 'Add a memory',
            'body' => "Click anywhere on the river, or use this button, whenever something's worth keeping. Let's open it and see how it works.",
            'arrows' => [
                ['type' => 'drop', 'text' => 'drops onto your timeline', 'ax' => 0.5, 'ay' => 1, 'to' => '#arcWrap', 'toXFrac' => 0.5, 'toYFrac' => 0.5],
            ],
        ],

        // ------------------------------------------------------- add_entry
        [
            'page' => 'add_entry',
            'target' => '#photoDrop',
            'title' => 'Attach photos, videos or documents',
            'body' => 'Click to browse, or just drop files straight in — up to 10 files, 25MB each. This is optional; words alone are a memory too.',
        ],
        [
            'page' => 'add_entry',
            'target' => '#entryDetailsCol',
            'title' => 'Say what happened',
            'body' => "Give it a title if you like, then the story in your own words. Remember the date if you can — that's what places it correctly on the river.",
        ],
        [
            'page' => 'add_entry',
            'target' => '#visibilityBlock',
            'title' => 'Public, private, or custom',
            'body' => "Public shares it with your whole connected family; Private keeps it just for you. Custom shares it with exactly the people you choose — set that list once in your profile and reuse it here.",
        ],
        [
            'page' => 'add_entry',
            'target' => '.tag-picker',
            'title' => 'Tag family, or keep it private',
            'body' => "Tick anyone else who was there and they'll be asked to approve the tag — once they do, the memory joins their timeline too. Didn't tag anyone? It's just for you, and you can always change your mind later.",
            'arrows' => [
                ['type' => 'point', 'text' => "they'll need to approve it", 'ax' => 0.06, 'ay' => 0.12, 'ldx' => 90, 'ldy' => -34],
            ],
        ],
        [
            'page' => 'add_entry',
            'target' => '#entrySubmitBtn',
            'title' => 'Save it',
            'body' => "Save whenever you're ready. You can always come back and add more photos later — even to a memory someone else was tagged in, once they've approved it.",
        ],

        // --------------------------------------------------------- timeline
        [
            'page' => 'timeline',
            'target' => '#rail',
            'title' => 'Memories, as cards',
            'body' => "Whatever period you're viewing appears below as cards, each one tied to its own spot on the river above — click a card to see the full memory, add a comment, or edit it.",
            'arrows' => [
                ['type' => 'link', 'text' => 'same memory, two views', 'from' => '#rail', 'fromXFrac' => 0.5, 'fromYFrac' => 0, 'to' => '#arcWrap', 'toXFrac' => 0.5, 'toYFrac' => 0.85],
            ],
        ],
        [
            'page' => 'timeline',
            'target' => '#sendPostcardBtn',
            'title' => 'Send a postcard',
            'body' => "Not every memory needs to go on the record. Send a postcard — a photo and a short note — to any family member or a group you pick. It's yours to keep light: nothing joins anyone's timeline unless you choose to keep your own copy when you send it, or they choose to save theirs after reading it. Otherwise it's just a quick hello, not a permanent record. Let's open it and see how it works.",
        ],
        [
            'page' => 'timeline',
            'action' => 'open_postcard_composer',
            'target' => '.postcard-audience',
            'title' => 'Choose who sees it',
            'body' => "Send it to everyone in your family in one go, or choose people individually — tick as many as you like from the list.",
        ],
        [
            'page' => 'timeline',
            'target' => '.postcard-face-front',
            'title' => 'Add a photo',
            'body' => "Drop a photo straight in, or click to choose one from your device. This is the front of the card — flip it over whenever you're ready to write.",
            'arrows' => [
                ['type' => 'point', 'text' => 'flips it over', 'ax' => 0.9, 'ay' => 0.05, 'ldx' => -20, 'ldy' => -40],
            ],
        ],
        [
            'page' => 'timeline',
            'action' => 'flip_postcard',
            'target' => '.postcard-back-message',
            'title' => 'Write your message',
            'body' => "Up to 2,000 characters — plenty for a quick hello, or a longer catch-up if you're in the mood.",
        ],
        [
            'page' => 'timeline',
            'target' => '.postcard-address-lines',
            'title' => 'Make it personal',
            'body' => "Both of these fill in automatically, but they're yours to change — write \"Mum\" instead of a full name, or sign off however feels right to you.",
        ],
        [
            'page' => 'timeline',
            'target' => '.postcard-back-footer',
            'title' => 'Keep a copy, then send',
            'body' => "Tick the box if you'd like this postcard on your own timeline too — otherwise it's just between you and whoever you send it to, exactly like a real one. Then send it on its way.",
        ],
        [
            'page' => 'timeline',
            'action' => 'switch_to_letter',
            'target' => '.letter-recipient-row',
            'title' => 'Need more room? Send a letter instead',
            'body' => "Same idea, more space — a proper heading, a longer story, and a single recipient rather than a group. Pick who it's going to here.",
        ],
        [
            'page' => 'timeline',
            'target' => '#letterBodyEditor',
            'title' => 'Write it, add a photo if you like',
            'body' => "\"Dear ___,\" fills in from your pick above, and you can shorten or change it right there, the same as a postcard's names. Write as much as you'd like below, and drop in a photo from the toolbar whenever you like.",
        ],
        [
            'page' => 'timeline',
            'target' => '.letter-footer',
            'title' => 'Sign off and send',
            'body' => "\"Best regards, ___\" is editable too. Tick the box to keep your own copy, then send — just like a postcard, it stays private until they choose to save it too.",
        ],
        [
            'page' => 'timeline',
            'action' => 'close_postcard_composer',
            'target' => null,
            'title' => 'On to your family tree',
            'body' => "Everyone in your family has their own timeline, just like this one. You can open any of them from your family tree — let's take a look at that now.",
        ],

        // -------------------------------------------------------------- tree
        [
            'page' => 'tree',
            'target' => null,
            'title' => 'What is a family tree?',
            'body' => 'It maps how everyone connects — parents, children, siblings, partners — as far back as you like. A line joins a parent to a child; a double line marks a partnership. Here\'s a small example.',
            'diagram' => 'tree',
        ],
        [
            'page' => 'tree',
            'target' => '.tree-wrap',
            'title' => 'Your family tree',
            'body' => "Here's yours. If other family members already invited you, much of it may be built already — otherwise, you'll start adding people from here.",
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
            'body' => "Add as many generations as you like. Let's open it and walk through adding someone.",
        ],

        // ---------------------------------------------------------- add_relative
        [
            'page' => 'add_relative',
            'target' => '#anchor_id',
            'title' => 'Connected to',
            'body' => 'Start with who the new person connects to — this defaults to you, but pick anyone already in your tree.',
            'arrows' => [
                ['type' => 'point', 'text' => 'defaults to you', 'ax' => 0.5, 'ay' => 0, 'ldx' => 0, 'ldy' => -40],
            ],
        ],
        [
            'page' => 'add_relative',
            'target' => '#relationship',
            'title' => 'Their relationship',
            'body' => "Choose what the new person is to that anchor — parent, child, sibling, partner, and more. A few relationships ask a follow-up question or two, like which side of the family, or whether it's genetic, step, or adoptive.",
        ],
        [
            'page' => 'add_relative',
            'target' => '#first_name',
            'title' => 'Their name',
            'body' => "First name is the only one that's required — add a surname and middle name too if you know them. Already in your tree? The page skips this and asks who to attach instead.",
        ],
        [
            'page' => 'add_relative',
            'target' => '#addRelativeSubmitBtn',
            'title' => 'Add them',
            'body' => "Save, and we'll connect them to the right people automatically — no need to add the same relationship twice from both sides. Made a mistake? Double-click their name afterwards to fix it, no drama.",
        ],

        // -------------------------------------------------------------- tree
        [
            'page' => 'tree',
            'target' => '#unclaimedSection',
            'title' => 'Inviting them properly',
            'body' => "Every person you add gets their own profile. This list is still part of your tree page — just scroll down past the tree diagram and its legend, and you'll find it underneath. Look for an \"Invite this person\" button next to their name to send them a link, so they can claim it themselves. Until then you can keep editing it and adding memories — a lovely way to build up a departed relative's timeline as you find old photos.",
            'arrows' => [
                ['type' => 'point', 'text' => 'same page, just below your tree', 'ax' => 0.5, 'ay' => 0, 'ldx' => 0, 'ldy' => -40],
            ],
        ],
        [
            'page' => 'tree',
            'target' => '.tree-help-box',
            'title' => 'Click, or double-click',
            'body' => "A single click opens someone's timeline — you'll see their public memories, just as they'd see yours. Double-click your own name, or anyone not yet claimed, to edit their profile. On a phone or tablet, tap the small pencil icon in the corner of their box instead.",
        ],
        [
            'page' => 'tree',
            'target' => '#tourPendingLink',
            'title' => 'Keep an eye on Pending',
            'body' => "When someone tags you in a memory, or wants to connect a relative to your tree, it shows up here. Accept a tag and it joins your own timeline too — or decline if it isn't you. Prefer email? Turn on notifications from your profile and we'll let you know automatically.",
        ],
        [
            'page' => 'tree',
            'target' => '#tourCalendarLink',
            'title' => 'A family calendar too',
            'body' => "Birthdays fill in on their own — nothing to set up — and anyone in the family can add other dates worth marking, anniversaries and memorials included. Let's take a look.",
        ],

        // ----------------------------------------------------------- calendar
        [
            'page' => 'calendar',
            'target' => '.cal-upcoming',
            'title' => 'Birthdays, right away',
            'body' => "As soon as a birth date is on file for someone, their birthday's here — nothing to add by hand. Anything happening in the next month shows up here first, nearest one at the top.",
        ],
        [
            'page' => 'calendar',
            'target' => '.cal-add-card',
            'title' => 'Add your own key dates',
            'body' => "Anniversaries, memorials, the first day of the school holidays — anyone in the family can add one. Give it a name and a date, and it'll come back every year from then on.",
        ],
        [
            'page' => 'calendar',
            'target' => '.cal-year',
            'title' => 'The whole year at a glance',
            'body' => "Every month is here, birthdays and key dates side by side. This month is outlined so it's easy to find, and — added a date by mistake? — anyone can remove a key date with the Remove link next to it.",
        ],
        [
            'page' => 'calendar',
            'target' => '#printCalendarBtn',
            'title' => 'Print it out',
            'body' => "Handy for the fridge door, or to bring to a family gathering — this prints cleanly as a simple month-by-month list.",
        ],

        // ---------------------------------------------------------------- tree
        [
            'page' => 'tree',
            'target' => '#tourEditMe',
            'title' => 'One last thing — your profile',
            'body' => "Every profile — yours included — has its own settings for editing details, adding a photo, and how memories reach you. This \"Edit me\" button always jumps straight to yours, without hunting for yourself in the tree first. Let's take a quick look.",
        ],

        // ------------------------------------------------------- edit_person
        [
            'page' => 'edit_person',
            'tab' => 'profile',
            'target' => '#profileFieldsBlock',
            'title' => 'Edit the basics',
            'body' => "Fix a name, or add birth and death dates — dates are what let the timeline place someone's whole life correctly.",
        ],
        [
            'page' => 'edit_person',
            'tab' => 'profile',
            'target' => '#avatarSectionBlock',
            'title' => 'Add a photo',
            'body' => "A profile photo shows up everywhere this person appears — their tree node, their timeline, and anyone else's memories they're tagged in.",
        ],
        [
            'page' => 'edit_person',
            'tab' => 'account',
            'target' => '#customAudienceBlock',
            'title' => 'Choose who sees Custom memories',
            'body' => "Build a standing list here, then set any memory to Custom to share it with exactly this group — handy for something not quite public, not quite private.",
        ],
        [
            'page' => 'edit_person',
            'tab' => 'account',
            'target' => '#notificationsBlock',
            'title' => 'Get notified',
            'body' => "Add your email and tick the box, and we'll let you know the moment someone tags you in a memory that needs your OK — no need to keep checking Pending yourself.",
        ],
        [
            'page' => 'edit_person',
            'target' => null,
            'title' => "That's the tour!",
            'body' => 'Jump back in with "My tree" or "My timeline" any time — and you can replay this walkthrough whenever you like from the timeline page.',
        ],
    ];
}
