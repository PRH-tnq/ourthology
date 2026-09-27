/*
 * Phase 104: "On any image, when I click on it from a memory, scale it to
 * the window." The lightboxes used max-width/max-height, which only ever
 * shrink a picture -- a small photo stayed small in the middle of the
 * screen. fit() sizes a photo (or video) to fill the space its lightbox
 * leaves for it, as large as possible without cropping or stretching,
 * and re-fits when the window changes size (rotating an iPad, say).
 *
 *   ourthologyMediaFit.fit(el, box, { reserveBottom: px })
 *     el   -- the <img> or <video> being shown
 *     box  -- the lightbox element; its own padding is kept clear
 *     reserveBottom -- extra room to leave underneath (e.g. "3 of 7")
 */
(function () {
  "use strict";
  var live = [];

  function natural(el) {
    if (el.tagName === "VIDEO") return { w: el.videoWidth, h: el.videoHeight };
    return { w: el.naturalWidth, h: el.naturalHeight };
  }
  function apply(item) {
    var el = item.el, box = item.box;
    if (!el.isConnected) return false;
    var n = natural(el);
    if (!n.w || !n.h) return true;
    var cs = window.getComputedStyle(box);
    var availW = box.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    var availH = box.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom) - (item.reserveBottom || 0);
    if (availW <= 0 || availH <= 0) return true;
    var scale = Math.min(availW / n.w, availH / n.h);
    el.style.maxWidth = "none";
    el.style.maxHeight = "none";
    el.style.width = Math.floor(n.w * scale) + "px";
    el.style.height = Math.floor(n.h * scale) + "px";
    return true;
  }
  function fit(el, box, opts) {
    if (!el || !box) return;
    // the same <img> can be reused for picture after picture
    var item = el.__ourthologyFit;
    if (!item) {
      item = el.__ourthologyFit = { el: el };
      el.addEventListener(el.tagName === "VIDEO" ? "loadedmetadata" : "load", function () { apply(item); });
    }
    item.box = box;
    item.reserveBottom = (opts && opts.reserveBottom) || 0;
    live = live.filter(function (it) { return it !== item && it.el.isConnected; });
    live.push(item);
    var ready = el.tagName === "VIDEO" ? el.readyState >= 1 : (el.complete && el.naturalWidth);
    if (ready) apply(item);
  }
  var pending = false;
  window.addEventListener("resize", function () {
    if (pending) return;
    pending = true;
    window.requestAnimationFrame(function () {
      pending = false;
      live = live.filter(apply);
    });
  });
  window.ourthologyMediaFit = { fit: fit };
})();
