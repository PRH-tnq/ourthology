/**
 * Phase 28 (single-page) / Phase 42 (this file): the onboarding tour engine.
 *
 * Until Phase 42 this same ~140 lines lived twice over, hand-duplicated
 * inline in timeline.php and tree.php. The tour now reaches five pages
 * (timeline.php, tree.php, add_entry.php, add_relative.php, edit_person.php
 * -- see includes/tour_steps.php for the step content), so it's a single
 * shared file instead, following the precedent already set by styles.css.
 *
 * Every page that runs the tour includes this file plus the same markup
 * block (#tourScrim / #tourHighlight / #tourAnnoLayer / #tourTooltip /
 * #tourCsrf / #tourStepsData) and sets, before this script loads:
 *
 *   window.OURTHOLOGY_TOUR_PAGE        -- "timeline" | "tree" | "add_entry"
 *                                          | "add_relative" | "edit_person"
 *                                          | "calendar"
 *   window.OURTHOLOGY_AUTOSTART_TOUR   -- true only on timeline.php, for a
 *                                          brand-new owner who hasn't seen
 *                                          the tour yet
 *   window.OURTHOLOGY_MY_PERSON_ID     -- the signed-in user's own person
 *                                          id, needed to link to their own
 *                                          edit_person.php profile
 *
 * The walkthrough spans real pages, so its state has to survive a real
 * navigation -- sessionStorage carries the in-progress step across that.
 * Every step still points at a real element via a spotlight box that
 * tracks its live position (or, for a few steps, is centered with nothing
 * highlighted, or shows a small illustrative diagram instead) -- unchanged
 * in spirit from the original single-page version.
 *
 * Phase 57: a few steps live *inside* the postcard/letter composer on
 * timeline.php, which is normal page markup (a <template> cloned into the
 * DOM by its own inline script) rather than a real navigable page -- so
 * a step can't just point at it the way a step points at a real link.
 * Each such step names an 'action' (see TOUR_ACTIONS below); the engine
 * runs it right before placing that step, the same way it already clicks
 * an edit_person.php tab via 'tab'. Every action is idempotent (it checks
 * the composer's current state before doing anything), since the exact
 * same step can be placed more than once -- a window resize re-runs
 * place(), and a tour resumed mid-flow re-enters at whatever step was
 * saved.
 */
(function () {
  "use strict";

  var TOUR_PAGE = window.OURTHOLOGY_TOUR_PAGE;
  var stepsEl = document.getElementById("tourStepsData");
  var scrim = document.getElementById("tourScrim");
  if (!TOUR_PAGE || !stepsEl || !scrim) { return; } // a page forgot to wire this up -- fail quiet, not loud

  var TOUR_URLS = {
    timeline: "/timeline.php",
    tree: "/tree.php",
    add_entry: "/add_entry.php",
    add_relative: "/add_relative.php",
    edit_person: window.OURTHOLOGY_MY_PERSON_ID
      ? "/edit_person.php?person_id=" + encodeURIComponent(String(window.OURTHOLOGY_MY_PERSON_ID)) + "&tab=profile"
      : null,
    calendar: "/calendar.php",
  };

  var TOUR_STEPS = JSON.parse(stepsEl.textContent);

  var step = -1;
  var highlight = document.getElementById("tourHighlight");
  var tooltip = document.getElementById("tourTooltip");
  var titleEl = document.getElementById("tourTitle");
  var bodyEl = document.getElementById("tourBody");
  var diagramEl = document.getElementById("tourDiagram");
  var stepLabel = document.getElementById("tourStepLabel");
  var nextBtn = document.getElementById("tourNextBtn");
  var skipBtn = document.getElementById("tourSkipBtn");
  var replayBtn = document.getElementById("tourReplayBtn"); // only present on timeline.php
  var annoLayer = document.getElementById("tourAnnoLayer");
  // Phase 44: the post-tour "get started" nudge and its dismiss button --
  // like replayBtn above, these only exist on timeline.php's own markup,
  // so every other page just leaves them null (see the null checks below).
  var postTourNudge = document.getElementById("postTourNudge");
  var postTourNudgeClose = document.getElementById("postTourNudgeClose");
  if (postTourNudgeClose) {
    postTourNudgeClose.addEventListener("click", function () { postTourNudge.hidden = true; });
  }
  var annoSvg = annoLayer ? annoLayer.querySelector("svg.tour-anno-svg") : null;

  function saveState(i) {
    try {
      sessionStorage.setItem("ourthologyTourStep", String(i));
      sessionStorage.setItem("ourthologyTourActive", "1");
    } catch (e) { /* private browsing etc — the tour just won't survive a page change */ }
  }
  function clearState() {
    try {
      sessionStorage.removeItem("ourthologyTourStep");
      sessionStorage.removeItem("ourthologyTourActive");
    } catch (e) {}
  }

  // ---------------------------------------------------------------------
  // Annotations: small animated arrows/labels drawn over the live page for
  // a step that wants to call out specific spots rather than just outline
  // one box. See includes/tour_steps.php's own doc comment for the full
  // field list of each type.
  // ---------------------------------------------------------------------

  var SVG_NS = "http://www.w3.org/2000/svg";
  function svgEl(tag) { return document.createElementNS(SVG_NS, tag); }

  function clearAnnotations() {
    if (annoLayer) {
      var stale = annoLayer.querySelectorAll(".tour-anno-label, .tour-anno-drop");
      for (var i = 0; i < stale.length; i++) { stale[i].remove(); }
    }
    if (annoSvg) {
      var paths = annoSvg.querySelectorAll("path.tour-anno-path");
      for (var j = 0; j < paths.length; j++) { paths[j].remove(); }
    }
  }

  function pointOn(rect, xFrac, yFrac) {
    return { x: rect.left + rect.width * xFrac, y: rect.top + rect.height * yFrac };
  }

  function makeLabel(text) {
    var el = document.createElement("div");
    el.className = "tour-anno-label";
    el.textContent = text;
    annoLayer.appendChild(el);
    return el;
  }

  function placeLabelAt(label, cx, cy) {
    // Measure after the label is in the DOM (its size depends on its
    // text), then center it on (cx, cy).
    requestAnimationFrame(function () {
      var r = label.getBoundingClientRect();
      label.style.left = (cx - r.width / 2) + "px";
      label.style.top = (cy - r.height / 2) + "px";
    });
  }

  function drawPath(x1, y1, x2, y2, opts) {
    opts = opts || {};
    var path = svgEl("path");
    var mx = (x1 + x2) / 2;
    var my = (y1 + y2) / 2 - (opts.curve === false ? 0 : 22);
    path.setAttribute("d", "M" + x1 + "," + y1 + " Q" + mx + "," + my + " " + x2 + "," + y2);
    path.setAttribute("class", "tour-anno-path" + (opts.traveling ? " tour-anno-path-traveling" : ""));
    path.setAttribute("marker-end", "url(#tourArrowHead)");
    annoSvg.appendChild(path);
    if (!opts.traveling) {
      // Draw-in: start with the whole stroke hidden as one dash, then
      // animate its offset to 0 so it looks hand-drawn rather than just
      // appearing. Traveling (linked) paths skip this — they use their
      // own always-on dashed/animated stroke instead (see the CSS), and
      // setting an inline dasharray here would fight that.
      var len = path.getTotalLength();
      path.style.strokeDasharray = String(len);
      path.style.strokeDashoffset = String(len);
      requestAnimationFrame(function () {
        path.style.transition = "stroke-dashoffset .5s ease .15s";
        path.style.strokeDashoffset = "0";
      });
    }
    return path;
  }

  function drawPointAnnotation(rect, a) {
    var anchor = pointOn(rect, a.ax, a.ay);
    var ldx = a.ldx || 0, ldy = (a.ldy == null) ? -50 : a.ldy;
    var label = makeLabel(a.text);
    label.classList.add("tour-anno-pulse");
    placeLabelAt(label, anchor.x + ldx, anchor.y + ldy);
    requestAnimationFrame(function () {
      var r = label.getBoundingClientRect();
      var lcx = r.left + r.width / 2;
      var lcy = ldy < 0 ? (r.top + r.height) : r.top; // connect from the label's near edge
      drawPath(lcx, lcy, anchor.x, anchor.y, {});
    });
  }

  function drawSpanAnnotation(rect, a) {
    var y = rect.top + rect.height * a.y;
    var x1 = rect.left + rect.width * a.x1;
    var x2 = rect.left + rect.width * a.x2;
    drawPath(x1, y, x2, y, { curve: false });
    var label = makeLabel(a.text);
    placeLabelAt(label, (x1 + x2) / 2, y + (a.labelDy == null ? -20 : a.labelDy));
  }

  function drawLinkAnnotation(a) {
    var fromEl = document.querySelector(a.from);
    var toEl = document.querySelector(a.to);
    if (!fromEl || !toEl) { return; } // one side of the link isn't on screen right now — say nothing rather than draw half an arrow
    var fr = fromEl.getBoundingClientRect(), tr = toEl.getBoundingClientRect();
    var p1 = pointOn(fr, a.fromXFrac == null ? 0.5 : a.fromXFrac, a.fromYFrac == null ? 0.5 : a.fromYFrac);
    var p2 = pointOn(tr, a.toXFrac == null ? 0.5 : a.toXFrac, a.toYFrac == null ? 0.5 : a.toYFrac);
    drawPath(p1.x, p1.y, p2.x, p2.y, { traveling: true });
    if (a.text) {
      var label = makeLabel(a.text);
      placeLabelAt(label, (p1.x + p2.x) / 2, (p1.y + p2.y) / 2 - 18);
    }
  }

  function drawDropAnnotation(rect, a) {
    var start = pointOn(rect, a.ax == null ? 0.5 : a.ax, a.ay == null ? 1 : a.ay);
    var toEl = a.to ? document.querySelector(a.to) : null;
    var end;
    if (toEl) {
      var tr = toEl.getBoundingClientRect();
      end = pointOn(tr, a.toXFrac == null ? 0.5 : a.toXFrac, a.toYFrac == null ? 0.5 : a.toYFrac);
    } else {
      end = { x: start.x, y: start.y + 90 };
    }
    var icon = document.createElement("div");
    icon.className = "tour-anno-drop";
    icon.style.left = start.x + "px";
    icon.style.top = start.y + "px";
    icon.style.setProperty("--tour-dx", (end.x - start.x) + "px");
    icon.style.setProperty("--tour-dy", (end.y - start.y) + "px");
    icon.innerHTML = '<svg viewBox="0 0 20 20" width="22" height="22" fill="none" aria-hidden="true">'
      + '<rect x="2.5" y="3.5" width="15" height="13" rx="2.5" stroke="currentColor" stroke-width="1.8"/>'
      + '<circle cx="7.3" cy="8.3" r="1.6" stroke="currentColor" stroke-width="1.6"/>'
      + '<path d="M4 15 8 10.5l3 3 3-4 2 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
      + '</svg>';
    annoLayer.appendChild(icon);
    if (a.text) {
      var label = makeLabel(a.text);
      placeLabelAt(label, start.x, start.y - 34);
    }
  }

  function renderAnnotations(rect, list) {
    clearAnnotations();
    if (!annoLayer || !annoSvg || !list || !list.length) { return; }
    for (var i = 0; i < list.length; i++) {
      var a = list[i];
      if (a.type === "link") {
        drawLinkAnnotation(a); // doesn't need the highlighted target's own rect
      } else if (rect) {
        if (a.type === "span") { drawSpanAnnotation(rect, a); }
        else if (a.type === "drop") { drawDropAnnotation(rect, a); }
        else { drawPointAnnotation(rect, a); }
      }
    }
  }

  // A small, static, illustrative diagram for a step that's explaining a
  // concept rather than pointing at something real on screen. Not meant to
  // resemble the actual tree renderer pixel-for-pixel — just enough of the
  // same idiom (circles for people, a red underline for "you", a line for
  // a parent/child link, a double line for a partnership) to make the idea
  // click before the real thing appears on the next step.
  var DIAGRAMS = {
    tree: '<svg viewBox="0 0 220 130" class="tour-diagram-svg" aria-hidden="true">'
      + '<line x1="70" y1="34" x2="70" y2="60" class="tour-diagram-line"/>'
      + '<line x1="150" y1="34" x2="150" y2="60" class="tour-diagram-line"/>'
      + '<line x1="70" y1="60" x2="110" y2="86" class="tour-diagram-line"/>'
      + '<line x1="150" y1="60" x2="110" y2="86" class="tour-diagram-line"/>'
      + '<line x1="66" y1="24" x2="150" y2="24" class="tour-diagram-line"/>'
      + '<line x1="66" y1="28" x2="150" y2="28" class="tour-diagram-line"/>'
      + '<circle cx="70" cy="20" r="16" class="tour-diagram-node"/>'
      + '<circle cx="150" cy="20" r="16" class="tour-diagram-node"/>'
      + '<circle cx="70" cy="70" r="16" class="tour-diagram-node"/>'
      + '<circle cx="150" cy="70" r="16" class="tour-diagram-node"/>'
      + '<circle cx="110" cy="110" r="18" class="tour-diagram-node tour-diagram-node-me"/>'
      + '<text x="70" y="24" class="tour-diagram-label">P</text>'
      + '<text x="150" y="24" class="tour-diagram-label">P</text>'
      + '<text x="70" y="74" class="tour-diagram-label">S</text>'
      + '<text x="150" y="74" class="tour-diagram-label">S</text>'
      + '<text x="110" y="114" class="tour-diagram-label">You</text>'
      + '</svg>'
      + '<p class="tour-diagram-caption">Partners (double line) · parent-to-child · you, underlined in red</p>',
  };

  // ---------------------------------------------------------------------

  function clickTabIfNeeded(s) {
    if (!s.tab) { return; }
    var btn = document.querySelector('.tab-btn[data-tab="' + s.tab + '"]');
    if (btn && !btn.classList.contains("is-active")) { btn.click(); }
  }

  // Phase 57: named steps into the postcard/letter composer on
  // timeline.php. Each function checks the composer's current state
  // before acting (so re-running it -- a resize, a resumed tour -- is a
  // safe no-op) and returns true only when it actually changed something,
  // which is what tells place() below whether it needs to wait out an
  // animation before measuring the target's position.
  var TOUR_ACTIONS = {
    open_postcard_composer: function () {
      var btn = document.getElementById("sendPostcardBtn");
      if (btn && !document.getElementById("postcardComposeOverlay")) { btn.click(); return true; }
      return false;
    },
    flip_postcard: function () {
      var btn = document.querySelector(".postcard-face-front .postcard-flip-btn");
      var inner = document.querySelector(".postcard-flip-inner");
      if (btn && inner && !inner.classList.contains("is-flipped")) { btn.click(); return true; }
      return false;
    },
    switch_to_letter: function () {
      var btn = document.getElementById("switchToLetterBtn");
      var letterPanel = document.getElementById("letterModePanel");
      if (btn && letterPanel && letterPanel.style.display !== "block") { btn.click(); return true; }
      return false;
    },
    close_postcard_composer: function () {
      var btn = document.getElementById("postcardComposeClose");
      if (btn) { btn.click(); return true; }
      return false;
    },
  };

  // Only the flip genuinely animates (.postcard-flip-inner's 0.7s CSS
  // transition) -- measuring the target immediately after toggling its
  // class would catch it mid-turn and the highlight/tooltip would jump
  // once the transition finished. Every other action is an instant style/
  // DOM change, safe to measure right away.
  var ACTION_SETTLE_MS = { flip_postcard: 760 };

  function place() {
    var s = TOUR_STEPS[step];
    titleEl.textContent = s.title;
    bodyEl.textContent = s.body;
    if (diagramEl) {
      var svg = s.diagram && DIAGRAMS[s.diagram];
      diagramEl.innerHTML = svg || "";
      diagramEl.hidden = !svg;
    }
    stepLabel.textContent = (step + 1) + " of " + TOUR_STEPS.length;
    nextBtn.textContent = (step === TOUR_STEPS.length - 1) ? "Done" : "Next";

    clickTabIfNeeded(s);

    var acted = false;
    if (s.action && TOUR_ACTIONS[s.action]) { acted = TOUR_ACTIONS[s.action](); }

    var settleMs = acted ? (ACTION_SETTLE_MS[s.action] || 0) : 0;
    if (settleMs) {
      var thisStep = step;
      setTimeout(function () {
        if (step === thisStep) { positionForStep(s); }
      }, settleMs);
    } else {
      positionForStep(s);
    }
  }

  function positionForStep(s) {
    var target = s.target ? document.querySelector(s.target) : null;
    if (!target) {
      highlight.hidden = true;
      tooltip.classList.add("tour-centered");
      renderAnnotations(null, s.arrows);
      return;
    }
    tooltip.classList.remove("tour-centered");
    if (typeof target.scrollIntoView === "function") {
      // "auto" (instant), not "smooth" — a smooth scroll is still
      // animating when the getBoundingClientRect() below runs, so the
      // highlight and tooltip would be positioned from the target's
      // pre-scroll spot. Instant scroll reflows synchronously, so the very
      // next measurement is the real, post-scroll position.
      target.scrollIntoView({ block: "center", inline: "nearest", behavior: "auto" });
    }
    var r = target.getBoundingClientRect();
    var pad = 8;
    highlight.hidden = false;
    highlight.style.left = (r.left - pad) + "px";
    highlight.style.top = (r.top - pad) + "px";
    highlight.style.width = (r.width + pad * 2) + "px";
    highlight.style.height = (r.height + pad * 2) + "px";

    var tooltipW = 280, tooltipH = tooltip.offsetHeight || 160;
    var spaceBelow = window.innerHeight - r.bottom;
    var top = (spaceBelow > tooltipH + 24) ? (r.bottom + pad + 14) : Math.max(14, r.top - pad - 14 - tooltipH);
    var left = Math.min(Math.max(14, r.left), window.innerWidth - tooltipW - 14);
    tooltip.style.top = top + "px";
    tooltip.style.left = left + "px";

    renderAnnotations(r, s.arrows);
  }

  function open_() {
    scrim.classList.add("open");
    place();
  }

  // Phase 44: a few bounces of a small arrow above a real element (used
  // below to call out "+ Add a memory" once the tour is done), fully
  // separate from the tour's own highlight/annotation machinery above --
  // that all lives inside #tourScrim, which darkens and captures clicks
  // across the whole page while .open, and this needs to run with the
  // page otherwise fully usable underneath it.
  function flashArrowAt(target) {
    var r = target.getBoundingClientRect();
    var wrap = document.createElement("div");
    wrap.className = "post-tour-arrow";
    wrap.style.left = (r.left + r.width / 2) + "px";
    wrap.style.top = r.top + "px";
    var inner = document.createElement("div");
    inner.className = "post-tour-arrow-inner";
    inner.innerHTML = '<svg viewBox="0 0 30 34" width="26" height="30" aria-hidden="true">'
      + '<path d="M15 2 L15 24 M15 24 L7 16 M15 24 L23 16" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>';
    wrap.appendChild(inner);
    document.body.appendChild(wrap);
    inner.addEventListener("animationend", function (e) {
      if (e.animationName === "postTourArrowFadeOut") { wrap.remove(); }
    });
  }

  // Phase 44: shown once, right after the tour finishes (Done or Skip --
  // see finishTour() below, which sets the sessionStorage flag this reads,
  // and the resume check at the bottom of this file, which calls this on
  // the fresh page load that flag survives into).
  function showPostTourNudge() {
    try { sessionStorage.removeItem("ourthologyShowPostTourNudge"); } catch (e) {}
    if (postTourNudge) { postTourNudge.hidden = false; }
    var addMemoryLink = document.getElementById("tourAddMemory");
    if (addMemoryLink) { flashArrowAt(addMemoryLink); }
  }

  function finishTour() {
    clearState();
    clearAnnotations();
    scrim.classList.remove("open");
    var fd = new FormData();
    fd.append("action", "dismiss_tour");
    fd.append("csrf_token", document.getElementById("tourCsrf").value);
    // Best-effort, and always fired (replay or first run alike) — it just
    // records "this account doesn't need the automatic first-run tour any
    // more," which stays true either way. A network hiccup here only means
    // the automatic tour could show once more on a future login. Always
    // posted to timeline.php regardless of which of the tour's five pages
    // we finish on — that's the one page whose PHP handles dismiss_tour.
    // sendBeacon (not fetch) because Phase 44 below follows this with a
    // real navigation to timeline.php -- a fetch() issued right before a
    // navigation can be cancelled by the browser before it completes,
    // while sendBeacon is built specifically to survive that.
    if (navigator.sendBeacon) {
      navigator.sendBeacon("/timeline.php", fd);
    } else {
      fetch("/timeline.php", { method: "POST", body: fd, credentials: "same-origin" }).catch(function () {});
    }

    // Phase 44: land back on the timeline with the "get started" nudge --
    // whether the tour was finished or skipped, it's over either way, and
    // the nudge is useful regardless. The flag survives a real navigation
    // the same way an in-progress tour step already does (sessionStorage);
    // already being on timeline.php (e.g. finishing a replay) skips the
    // navigation and just shows it in place.
    try { sessionStorage.setItem("ourthologyShowPostTourNudge", "1"); } catch (e) {}
    if (TOUR_PAGE === "timeline") {
      showPostTourNudge();
    } else {
      window.location.href = "/timeline.php";
    }
  }

  function goToStep(i) {
    if (i >= TOUR_STEPS.length) { finishTour(); return; }
    var s = TOUR_STEPS[i];
    if (s.page !== TOUR_PAGE) {
      // The next step lives on another page — hand off via sessionStorage
      // and navigate there for real; that page's own copy of this same
      // engine picks the tour back up on load (see the resume check
      // below). A page this account can't reach yet (edit_person.php with
      // no known person id, in the unlikely case OURTHOLOGY_MY_PERSON_ID
      // wasn't set) just ends the tour rather than navigating to "null".
      var url = TOUR_URLS[s.page];
      if (!url) { finishTour(); return; }
      saveState(i);
      window.location.href = url;
      return;
    }
    step = i;
    saveState(i);
    open_();
  }

  nextBtn.addEventListener("click", function () { goToStep(step + 1); });
  skipBtn.addEventListener("click", finishTour);
  window.addEventListener("resize", function () { if (step >= 0) place(); });
  if (replayBtn) {
    replayBtn.addEventListener("click", function () { goToStep(0); });
  }

  var resumeActive = false;
  try { resumeActive = sessionStorage.getItem("ourthologyTourActive") === "1"; } catch (e) {}
  if (resumeActive) {
    var savedStep = 0;
    try { savedStep = parseInt(sessionStorage.getItem("ourthologyTourStep") || "0", 10); } catch (e) {}
    if (TOUR_STEPS[savedStep] && TOUR_STEPS[savedStep].page === TOUR_PAGE) {
      step = savedStep;
      saveState(step);
      open_();
    }
  } else if (window.OURTHOLOGY_AUTOSTART_TOUR) {
    goToStep(0);
  }

  // Phase 44: independent of the resume/autostart check above -- this
  // fires on the fresh timeline.php load finishTour() just navigated to.
  var showNudge = false;
  try { showNudge = sessionStorage.getItem("ourthologyShowPostTourNudge") === "1"; } catch (e) {}
  if (showNudge && TOUR_PAGE === "timeline") {
    showPostTourNudge();
  }
})();
