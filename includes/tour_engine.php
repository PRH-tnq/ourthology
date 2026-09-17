<?php
declare(strict_types=1);

require_once __DIR__ . '/tour_steps.php';

/**
 * Phase 42: renders the onboarding tour's shared markup, per-page config,
 * and engine script include -- identical on all five pages the tour can
 * appear on (timeline.php, tree.php, add_entry.php, add_relative.php,
 * edit_person.php). Before this, each page hand-duplicated ~140 lines of
 * markup and engine JS; now every page just calls this once, near the end
 * of <body>. See includes/tour_steps.php for the step content and
 * /tour.js for the engine itself.
 *
 * $page       -- this page's own name, matching a 'page' value used in
 *                includes/tour_steps.php: "timeline", "tree", "add_entry",
 *                "add_relative", or "edit_person".
 * $myPersonId -- the signed-in user's own person id. Needed so a step that
 *                jumps to edit_person.php can link to their own profile,
 *                even from a page (like tree.php) that isn't viewing it.
 * $autostart  -- true only ever passed from timeline.php, for a brand-new
 *                owner who hasn't seen the tour yet. Every other page
 *                only ever resumes a tour already in progress.
 */
function ourthology_render_tour(string $page, int $myPersonId, bool $autostart = false): void
{
    $tourSteps = ourthology_tour_steps();
    $tourStepsJson = json_encode($tourSteps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tourStepsJsonSafe = str_replace('</', '<\/', (string) $tourStepsJson);
    ?>
    <div class="tour-scrim" id="tourScrim">
      <div class="tour-highlight" id="tourHighlight" hidden></div>
      <div class="tour-anno-layer" id="tourAnnoLayer">
        <svg class="tour-anno-svg">
          <defs>
            <marker id="tourArrowHead" markerWidth="8" markerHeight="8" refX="6" refY="3" orient="auto">
              <path d="M0,0 L6,3 L0,6 Z"></path>
            </marker>
          </defs>
        </svg>
      </div>
      <div class="tour-tooltip" id="tourTooltip">
        <h4 id="tourTitle"></h4>
        <p id="tourBody"></p>
        <div class="tour-diagram" id="tourDiagram" hidden></div>
        <div class="tour-footer">
          <button type="button" class="tour-skip" id="tourSkipBtn">Skip tour</button>
          <span class="tour-step-label" id="tourStepLabel"></span>
          <button type="button" class="tour-next" id="tourNextBtn"></button>
        </div>
      </div>
    </div>
    <input type="hidden" id="tourCsrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <script id="tourStepsData" type="application/json"><?= $tourStepsJsonSafe ?></script>
    <script>
    window.OURTHOLOGY_TOUR_PAGE = <?= json_encode($page) ?>;
    window.OURTHOLOGY_AUTOSTART_TOUR = <?= $autostart ? 'true' : 'false' ?>;
    window.OURTHOLOGY_MY_PERSON_ID = <?= $myPersonId ?>;
    </script>
    <script src="/tour.js?v=2"></script>
    <?php
}
