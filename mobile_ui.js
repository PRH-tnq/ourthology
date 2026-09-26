/*
 * Phase 96: the phone layout's Menu sheet and floating "+" button.
 *
 * Nothing here duplicates a page's own buttons: the sheet lists them and
 * each entry simply clicks the original (which keeps its ids, handlers and
 * tour hooks), so every page's actions keep working exactly as before --
 * they're just gathered into one tidy list on a phone instead of wrapping
 * across two or three rows of pills. On anything wider than 700px none of
 * this is visible (styles.css "Phase 96").
 *
 * The sheet is built from, in order:
 *   1. the page's action group (the .segmented next to the pill nav);
 *   2. anything else on the page marked data-m-menu="Label" (the timeline's
 *      Take the tour / What's new);
 *   3. who's signed in, and Log out.
 * An action marked data-m-fab also gets a floating button of its own
 * ("+ Add a memory", "+ Add a relative") -- the one thing people do most on
 * that page, one tap away.
 */
(function () {
  "use strict";
  var btn = document.getElementById("mMenuBtn");
  var sheet = document.getElementById("mSheet");
  var body = document.getElementById("mSheetBody");
  var scrim = document.getElementById("mSheetScrim");
  if (!btn || !sheet || !body) return;

  function label(el) {
    return (el.getAttribute("data-m-menu") || el.getAttribute("aria-label") || el.textContent || "").replace(/\s+/g, " ").trim();
  }

  function addItem(el, text, extraClass) {
    var item = document.createElement("button");
    item.type = "button";
    item.className = "m-sheet-item" + (extraClass ? " " + extraClass : "");
    item.textContent = text;
    if (el.id) item.setAttribute("data-tour-for", "#" + el.id);
    item.addEventListener("click", function () {
      close(true);
      // an <a> is followed exactly as a click on it would be
      if (el.tagName === "A" && el.href && !el.getAttribute("onclick")) {
        window.location.href = el.href;
      } else {
        el.click();
      }
    });
    body.appendChild(item);
    return item;
  }
  function heading(text) {
    var h = document.createElement("div");
    h.className = "m-sheet-h";
    h.textContent = text;
    body.appendChild(h);
  }

  // 1. the page's own actions
  var navLinks = btn.closest(".nav-links") || document;
  var group = null;
  Array.prototype.forEach.call(navLinks.querySelectorAll(".segmented"), function (g) {
    if (!group && !g.classList.contains("nav-primary")) group = g;
  });
  var fabSource = null;
  if (group) {
    Array.prototype.forEach.call(group.children, function (el) {
      if (!/^(A|BUTTON)$/.test(el.tagName)) return;
      addItem(el, label(el), el.classList.contains("accent-item") ? "accent" : "");
      if (el.hasAttribute("data-m-fab") && !fabSource) fabSource = el;
    });
  }
  // 2. extras marked on the page
  var extras = document.querySelectorAll("[data-m-menu]");
  if (extras.length) {
    if (body.children.length) heading("Help");
    Array.prototype.forEach.call(extras, function (el) { addItem(el, label(el)); });
  }
  // 3. account
  var who = document.querySelector(".whoami");
  var logoutForm = who ? who.querySelector('form[action="/logout.php"]') : null;
  var email = who ? who.querySelector("strong") : null;
  if (email || logoutForm) {
    heading(email ? "Signed in as " + email.textContent.trim() : "Account");
    if (logoutForm) {
      var out = document.createElement("button");
      out.type = "button";
      out.className = "m-sheet-item";
      out.textContent = "Log out";
      out.addEventListener("click", function () { logoutForm.submit(); });
      body.appendChild(out);
    }
  }
  if (!body.children.length) btn.hidden = true;

  // Floating main action
  if (fabSource) {
    var fab = document.createElement("button");
    fab.type = "button";
    fab.className = "m-fab";
    fab.innerHTML = '<span aria-hidden="true">+</span>';
    fab.appendChild(document.createTextNode(" " + label(fabSource).replace(/^\+\s*/, "")));
    if (fabSource.id) fab.setAttribute("data-tour-for", "#" + fabSource.id);
    fab.addEventListener("click", function () {
      if (fabSource.tagName === "A" && fabSource.href) window.location.href = fabSource.href;
      else fabSource.click();
    });
    document.body.appendChild(fab);
    document.documentElement.classList.add("m-has-fab");
  }

  // Open / close
  var lastFocus = null;
  function open() {
    lastFocus = document.activeElement;
    scrim.hidden = false;
    sheet.hidden = false;
    // next frame, so the slide-up transition runs
    requestAnimationFrame(function () { sheet.classList.add("open"); scrim.classList.add("open"); });
    btn.setAttribute("aria-expanded", "true");
    var first = body.querySelector(".m-sheet-item");
    if (first) first.focus();
  }
  function close(immediate) {
    sheet.classList.remove("open");
    scrim.classList.remove("open");
    btn.setAttribute("aria-expanded", "false");
    var done = function () { sheet.hidden = true; scrim.hidden = true; };
    if (immediate) done(); else setTimeout(done, 200);
    if (lastFocus && lastFocus.focus && !immediate) lastFocus.focus();
  }
  btn.setAttribute("aria-expanded", "false");
  btn.addEventListener("click", function () { if (sheet.hidden) open(); else close(); });
  scrim.addEventListener("click", function () { close(); });
  document.getElementById("mSheetClose").addEventListener("click", function () { close(); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !sheet.hidden) close(); });
})();
