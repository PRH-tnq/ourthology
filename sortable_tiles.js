/*
 * Phase 99: drag photos/documents within an upload box to change the order
 * they're shown in. Shared by every multi-file picker: the memory composer
 * (add_entry.php), the memory viewer's "add your own" box and the Memory
 * planner's per-event boxes (timeline.php), and Places (places.js).
 *
 *   window.ourthologySortable.attach(container, {
 *     items: ".pick-tile:not(.pick-tile--add)",   // the reorderable tiles
 *     onMove: function (fromIndex, toIndex) {...}  // picker updates its own
 *   });                                            // order, then re-renders
 *
 * Works with whatever the picker renders into `container` at any moment
 * (it listens on the container, so re-rendering the tiles is fine).
 *   - Mouse / pen: press on a tile and drag.
 *   - Touch: press and hold a tile for a moment, then drag -- a quick swipe
 *     still scrolls the page as normal.
 *   - Keyboard: focus a tile, then Ctrl/Alt + arrow keys move it.
 * While dragging, the tile's slot is left as a faded placeholder and the
 * other tiles make room live, so you can see where it will land. Remove
 * buttons (×) never start a drag, and the click that ends a drag is
 * swallowed so it can't also open a file picker or a lightbox.
 */
(function () {
  "use strict";
  var LONG_PRESS_MS = 320;
  var MOUSE_START_PX = 6;
  var TOUCH_SLOP_PX = 10;

  function attach(container, opts) {
    if (!container || container.__ourthologySortable) return;
    container.__ourthologySortable = true;
    var itemSel = opts.items;
    var exclude = opts.exclude || ".pick-remove, .pk-x, button, a, input";

    function items() { return Array.prototype.slice.call(container.querySelectorAll(itemSel)); }

    var st = null; // current gesture
    var swallowClick = false;

    function makeKeyboardFocusable() {
      items().forEach(function (el) {
        if (!el.hasAttribute("tabindex")) el.setAttribute("tabindex", "0");
        el.setAttribute("aria-roledescription", "sortable item");
        if (!el.getAttribute("title")) el.setAttribute("title", "Drag to change the order");
      });
    }
    // tiles are re-rendered by the picker -- keep them focusable
    if (window.MutationObserver) new MutationObserver(makeKeyboardFocusable).observe(container, { childList: true, subtree: true });
    makeKeyboardFocusable();

    // images inside tiles are natively draggable -- which would start a
    // browser file-drag instead of ours
    container.addEventListener("dragstart", function (e) {
      if (e.target.closest && e.target.closest(itemSel)) e.preventDefault();
    });

    container.addEventListener("pointerdown", function (e) {
      if (e.button !== 0 && e.pointerType === "mouse") return;
      var tile = e.target.closest(itemSel);
      if (!tile || !container.contains(tile) || e.target.closest(exclude)) return;
      if (items().length < 2) return;
      st = { tile: tile, id: e.pointerId, type: e.pointerType, x0: e.clientX, y0: e.clientY, x: e.clientX, y: e.clientY, active: false, timer: null };
      if (e.pointerType === "touch") {
        st.timer = setTimeout(function () { if (st && !st.active) begin(); }, LONG_PRESS_MS);
      }
    });

    function begin() {
      var list = items();
      st.from = list.indexOf(st.tile);
      if (st.from < 0) { st = null; return; }
      st.active = true;
      var r = st.tile.getBoundingClientRect();
      st.dx = st.x - r.left; st.dy = st.y - r.top;
      var ghost = st.tile.cloneNode(true);
      ghost.className += " sort-drag-ghost";
      ghost.removeAttribute("id");
      ghost.style.cssText = "position:fixed;left:" + r.left + "px;top:" + r.top + "px;width:" + r.width + "px;height:" + r.height + "px;margin:0;z-index:5000;pointer-events:none;";
      document.body.appendChild(ghost);
      st.ghost = ghost;
      st.tile.classList.add("sort-placeholder");
      container.classList.add("sorting");
      if (navigator.vibrate && st.type === "touch") { try { navigator.vibrate(12); } catch (err) { /* ignore */ } }
    }

    function move(e) {
      if (!st || e.pointerId !== st.id) return;
      st.x = e.clientX; st.y = e.clientY;
      if (!st.active) {
        var d = Math.abs(st.x - st.x0) + Math.abs(st.y - st.y0);
        if (st.type === "touch") { if (d > TOUCH_SLOP_PX) cancel(); return; } // it's a scroll
        if (d < MOUSE_START_PX) return;
        begin();
        if (!st) return;
      }
      e.preventDefault();
      st.ghost.style.left = (st.x - st.dx) + "px";
      st.ghost.style.top = (st.y - st.dy) + "px";
      // which tile is under the pointer? move the placeholder beside it
      var over = document.elementFromPoint(st.x, st.y);
      var target = over && over.closest ? over.closest(itemSel) : null;
      if (!target || target === st.tile || !container.contains(target)) return;
      var tr = target.getBoundingClientRect();
      var after = (st.x > tr.left + tr.width / 2);
      // on a single-column layout use the vertical half instead
      if (tr.width > container.clientWidth * 0.8) after = st.y > tr.top + tr.height / 2;
      var parent = target.parentNode;
      if (after) parent.insertBefore(st.tile, target.nextSibling);
      else parent.insertBefore(st.tile, target);
    }

    function end(e) {
      if (!st || (e && e.pointerId !== st.id)) return;
      if (st.timer) clearTimeout(st.timer);
      if (!st.active) { st = null; return; }
      var to = items().indexOf(st.tile);
      var from = st.from;
      finish();
      swallowClick = true;
      setTimeout(function () { swallowClick = false; }, 60);
      if (to >= 0 && to !== from) opts.onMove(from, to);
      else if (opts.onCancel) opts.onCancel();
    }
    function cancel() {
      if (!st) return;
      if (st.timer) clearTimeout(st.timer);
      var wasActive = st.active;
      finish();
      if (wasActive && opts.onCancel) opts.onCancel();
    }
    function finish() {
      if (st.ghost) st.ghost.remove();
      st.tile.classList.remove("sort-placeholder");
      container.classList.remove("sorting");
      st = null;
    }
    document.addEventListener("pointermove", move, { passive: false });
    document.addEventListener("pointerup", end);
    document.addEventListener("pointercancel", function (e) {
      // the browser took the gesture over (e.g. started scrolling) -- only
      // matters before our drag has begun
      if (st && e.pointerId === st.id) { if (st.active) end(e); else cancel(); }
    });
    // while a touch drag is live, stop the page scrolling underneath it
    container.addEventListener("touchmove", function (e) { if (st && st.active) e.preventDefault(); }, { passive: false });
    // no context menu / text selection from the long press
    container.addEventListener("contextmenu", function (e) { if (st) e.preventDefault(); });
    container.addEventListener("click", function (e) {
      if (swallowClick) { e.preventDefault(); e.stopPropagation(); swallowClick = false; }
    }, true);

    // keyboard: Ctrl/Alt + arrows
    container.addEventListener("keydown", function (e) {
      if (!(e.ctrlKey || e.altKey || e.metaKey)) return;
      var tile = e.target.closest && e.target.closest(itemSel);
      if (!tile) return;
      var list = items(), from = list.indexOf(tile), to = from;
      if (e.key === "ArrowLeft" || e.key === "ArrowUp") to = from - 1;
      else if (e.key === "ArrowRight" || e.key === "ArrowDown") to = from + 1;
      else return;
      e.preventDefault();
      if (to < 0 || to >= list.length) return;
      opts.onMove(from, to);
      var again = items()[to];
      if (again) again.focus();
    });
  }

  /** Move one element of `arr` from index `from` to index `to`, in place. */
  function moveInArray(arr, from, to) {
    var it = arr.splice(from, 1)[0];
    arr.splice(to, 0, it);
    return arr;
  }

  window.ourthologySortable = { attach: attach, move: moveInArray };
})();
