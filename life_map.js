/*
 * Phase 94: the two maps on timeline.php, chosen from the #mapToggle
 * buttons beside River/Rings/Spiral. Choosing one swaps the timeline
 * diagram (#arcWrap) for #lifeMapWrap; choosing River/Rings/Spiral or a
 * zoom again, or the active map button, swaps it back.
 *
 *  - "Where we've lived": the homes this person lived in (Places we lived,
 *    the same homes as the timeline's "Moved to..." cards), as numbered
 *    pins joined by the route between them in the order they were lived in.
 *    Clicking a pin opens its details: photo, dates, who else lived there,
 *    and a link to the full story on places.php.
 *  - "Where we've visited": every memory on this timeline that has a place
 *    pinned on it ("Where it happened"). Memories at the same spot share
 *    one dot with a count; clicking lists them, and each opens in the
 *    timeline's own memory viewer.
 *
 * The map remembers which view was open in the URL (#map=lived / #map=visited)
 * so a reload or the browser's back button lands on the same view.
 */
(function () {
  "use strict";
  var toggle = document.getElementById("mapToggle");
  var wrap = document.getElementById("lifeMapWrap");
  var G = window.ourthologyGeo;
  if (!toggle || !wrap || !G) return;

  function readJson(id) {
    var el = document.getElementById(id);
    try { return el ? JSON.parse(el.textContent || "null") : null; } catch (e) { return null; }
  }
  var homes = readJson("lifeHomesData") || [];
  var visited = (readJson("entriesData") || []).filter(function (e) {
    return e.place && e.place.lat !== null && e.place.lng !== null;
  });

  var mapEl = document.getElementById("lifeMap");
  var noteEl = document.getElementById("lifeMapNote");
  var manageEl = document.getElementById("lifeMapManage");
  var emptyEl = document.getElementById("lifeMapEmpty");
  var layoutToggle = document.getElementById("layoutToggle");
  var zoomToggle = document.getElementById("zoomToggle");

  var map = null, layer = null, current = null, lastFit = null;

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  function fmt(iso) {
    var p = String(iso || "").split("-");
    return p.length === 3 ? (+p[2]) + " " + MONTHS[+p[1] - 1] + " " + p[0] : "";
  }
  function firstImage(e) {
    for (var i = 0; i < (e.media || []).length; i++) {
      if (e.media[i].kind === "image") return e.media[i].url + (e.media[i].url.indexOf("?") >= 0 ? "&" : "?") + "thumb=1";
    }
    return null;
  }

  // ------------------------------------------------------------ mode switch
  function setButtons(which) {
    Array.prototype.forEach.call(toggle.querySelectorAll("button"), function (b) {
      var on = b.getAttribute("data-map") === which;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });
  }
  function setHash(which) {
    try {
      var url = window.location.pathname + window.location.search + (which ? "#map=" + which : "");
      window.history.replaceState(null, "", url);
    } catch (e) { /* ignore */ }
  }

  function show(which) {
    current = which;
    setButtons(which);
    document.body.classList.add("map-mode");
    wrap.hidden = false;
    setHash(which);
    G.loadLeaflet().then(function (L) {
      if (!map) {
        map = L.map(mapEl, { scrollWheelZoom: false, worldCopyJump: false }).setView([30, 0], 2);
        L.tileLayer(G.TILE_URL, { maxZoom: 19, attribution: G.TILE_ATTR }).addTo(map);
        map.on("focus", function () { map.scrollWheelZoom.enable(); });
        map.on("blur", function () { map.scrollWheelZoom.disable(); });
      }
      map.invalidateSize();
      if (layer) { map.removeLayer(layer); }
      layer = L.layerGroup().addTo(map);
      lastFit = null;
      if (which === "lived") drawLived(L); else drawVisited(L);
      lastW = mapEl.clientWidth; lastH = mapEl.clientHeight;
    }).catch(function () {
      emptyEl.innerHTML = "<b>The map couldn't load</b>Check your connection and try again.";
      emptyEl.hidden = false;
    });
  }

  function hide() {
    if (!current) return;
    current = null;
    setButtons(null);
    document.body.classList.remove("map-mode");
    wrap.hidden = true;
    setHash(null);
    if (map) map.closePopup();
    // the diagram was laid out while hidden -- let it redraw at its real size
    try { window.dispatchEvent(new Event("resize")); } catch (e) { /* old browsers */ }
  }

  toggle.addEventListener("click", function (evt) {
    var btn = evt.target.closest("button[data-map]");
    if (!btn) return;
    var which = btn.getAttribute("data-map");
    if (current === which) hide(); else show(which);
  });
  // Choosing any timeline style or zoom brings the timeline back (their own
  // handlers then do the rest).
  [layoutToggle, zoomToggle].forEach(function (el) {
    if (el) el.addEventListener("click", function (evt) { if (evt.target.closest("button")) hide(); }, true);
  });

  function fit(points, single) {
    lastFit = points.length ? { points: points, single: single } : null;
    if (points.length === 1) map.setView(points[0], single || 13);
    else if (points.length > 1) map.fitBounds(points, { padding: [40, 40], maxZoom: 13 });
  }
  // The map can be created before the page has finished laying out (fonts,
  // scrollbars, opening straight onto #map=...), so it would draw for the
  // wrong size -- tiles missing on one side and the route clipped. Re-measure
  // and re-fit whenever its box actually changes size.
  function remeasure() {
    if (!map || !current) return;
    map.invalidateSize();
    if (lastFit) fit(lastFit.points, lastFit.single);
  }
  var lastW = 0, lastH = 0;
  if (window.ResizeObserver) {
    new ResizeObserver(function () {
      var w = mapEl.clientWidth, h = mapEl.clientHeight;
      if (w && h && (w !== lastW || h !== lastH)) { lastW = w; lastH = h; remeasure(); }
    }).observe(mapEl);
  }
  window.addEventListener("load", remeasure);

  // ------------------------------------------------------------ lived
  function drawLived(L) {
    manageEl.hidden = false;
    manageEl.textContent = homes.length ? "Add or edit homes" : "Add a home";
    if (!homes.length) {
      noteEl.textContent = "";
      emptyEl.innerHTML = "<b>No homes on the map yet</b>Add the places you've lived — when you moved in and out, photos, and how they changed." +
        '<br><a href="' + esc(manageEl.getAttribute("href")) + '">Add the first home</a>';
      emptyEl.hidden = false;
      map.setView([30, 0], 2);
      return;
    }
    emptyEl.hidden = true;
    var J = G.journey(homes.map(function (h) { return [h.lat, h.lng]; }));
    if (J.path.length > 1) {
      L.polyline(J.path, { color: "#9A2A2A", weight: 3, opacity: 0.75, dashArray: "8 8" }).addTo(layer);
      J.mids.forEach(function (m) { L.circleMarker(m, { radius: 3, color: "#9A2A2A", fillOpacity: 1 }).addTo(layer); });
    }
    homes.forEach(function (h, i) {
      var icon = L.divIcon({ className: "", html: '<div class="lm-pin"><span>' + (i + 1) + "</span></div>", iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -28] });
      L.marker(J.pins[i], { icon: icon, title: h.label, riseOnHover: true }).addTo(layer).bindPopup(
        '<div class="lm-pop">' + (h.photo ? '<img src="' + esc(h.photo) + '" alt="">' : "") + "<div>" +
        "<b>" + esc(h.label) + "</b>" +
        "<small>" + esc(h.dates || "Dates not added yet") + (h.place && h.place !== h.label ? "<br>" + esc(h.place) : "") + "</small>" +
        (h.with.length ? '<p class="lm-with">Also lived here: ' + esc(h.with.join(", ")) + "</p>" : "") +
        (h.changes ? '<p class="lm-with">' + h.changes + (h.changes === 1 ? " change" : " changes") + " recorded over the years</p>" : "") +
        '<a href="' + esc(h.url) + '">Full details &amp; photos</a></div></div>', { maxWidth: 280 });
    });
    noteEl.textContent = homes.length === 1
      ? "1 home on the map. Click it for the details."
      : homes.length + " homes, numbered in the order they were lived in — the dashed line traces each move. Click a pin for the details.";
    fit(J.pins.concat(J.path));
  }

  // ------------------------------------------------------------ visited
  function drawVisited(L) {
    manageEl.hidden = true;
    if (!visited.length) {
      noteEl.textContent = "";
      emptyEl.innerHTML = "<b>No places on memories yet</b>Add “Where it happened” when you write or edit a memory, and it will appear here.";
      emptyEl.hidden = false;
      map.setView([30, 0], 2);
      return;
    }
    emptyEl.hidden = true;
    // Same spot (to ~10m) = one dot, so a dozen memories at home don't hide each other.
    var groups = {}, order = [];
    visited.slice().sort(function (a, b) { return a.date < b.date ? -1 : a.date > b.date ? 1 : 0; }).forEach(function (e) {
      var key = e.place.lat.toFixed(4) + "," + e.place.lng.toFixed(4);
      if (!groups[key]) { groups[key] = []; order.push(key); }
      groups[key].push(e);
    });
    // Keep dots on the world copy nearest the others (e.g. Fiji and Hawaii side by side).
    var refs = [], pts = [];
    order.forEach(function (key) {
      var list = groups[key], e0 = list[0];
      var lng = refs.length ? G.nearestCopy(e0.place.lng, refs) : e0.place.lng;
      refs.push(lng);
      var many = list.length > 1;
      var icon = L.divIcon({ className: "", html: '<div class="lm-dot' + (many ? " many" : "") + '">' + (many ? list.length : "") + "</div>",
        iconSize: many ? [24, 24] : [16, 16], iconAnchor: many ? [12, 12] : [8, 8], popupAnchor: [0, -8] });
      var m = L.marker([e0.place.lat, lng], { icon: icon, title: e0.place.label || e0.title, riseOnHover: true }).addTo(layer);
      var html;
      if (!many) {
        var img = firstImage(e0);
        html = '<div class="lm-pop">' + (img ? '<img src="' + esc(img) + '" alt="">' : "") + "<div>" +
          "<b>" + esc(e0.title) + "</b><small>" + esc(fmt(e0.date)) + (e0.place.label ? "<br>" + esc(e0.place.label) : "") + "</small>" +
          '<a href="#" data-open="' + esc(e0.id) + '">Open this memory</a></div></div>';
      } else {
        html = '<div class="lm-list"><h4>' + esc(e0.place.label || "This spot") + " · " + list.length + " memories</h4>" +
          list.map(function (e) {
            var img = firstImage(e);
            return '<button type="button" data-open="' + esc(e.id) + '">' + (img ? '<img src="' + esc(img) + '" alt="">' : "") +
              "<span>" + esc(e.title) + "<small>" + esc(fmt(e.date)) + "</small></span></button>";
          }).join("") + "</div>";
      }
      m.bindPopup(html, { maxWidth: 290 });
      pts.push([e0.place.lat, lng]);
    });
    var places = order.length;
    noteEl.textContent = visited.length + (visited.length === 1 ? " memory" : " memories") + " with a place" +
      (places !== visited.length ? ", at " + places + " different spots" : "") + ". Click a dot to see what happened there.";
    fit(pts);
  }

  // Links inside popups open the memory in the timeline's own viewer.
  mapEl.addEventListener("click", function (evt) {
    var a = evt.target.closest("[data-open]");
    if (!a) return;
    evt.preventDefault();
    if (window.ourthologyOpenMemory) window.ourthologyOpenMemory(a.getAttribute("data-open"));
  });

  // #map=lived / #map=visited (reload, back button, links from Places)
  function fromHash() {
    var m = /(?:^|#|&)map=(lived|visited)\b/.exec(window.location.hash || "");
    if (m && m[1] !== current) show(m[1]);
    else if (!m && current) hide();
  }
  // also when only the #fragment changes (a link to #map=... from this same
  // page doesn't reload it)
  window.addEventListener("hashchange", fromHash);
  fromHash();
})();

/*
 * Phase 96: the phone-only View / Zoom dropdowns (timeline.php
 * .m-view-controls). They don't do anything themselves -- choosing an
 * option clicks the matching pill (River/Rings/Spiral, a map, a zoom),
 * which is hidden on a phone but still does the real work, and they follow
 * the pills' state back so they always show what's on screen.
 */
(function () {
  "use strict";
  var viewSel = document.getElementById("mViewSelect");
  var zoomSel = document.getElementById("mZoomSelect");
  if (!viewSel || !zoomSel) return;
  function q(sel) { return document.querySelector(sel); }
  function sync() {
    var map = q("#mapToggle button.active");
    var layout = q("#layoutToggle button.active");
    var zoom = q("#zoomToggle button.active");
    viewSel.value = map ? "map:" + map.getAttribute("data-map") : (layout ? layout.getAttribute("data-layout") : "river");
    if (zoom) zoomSel.value = zoom.getAttribute("data-zoom");
    zoomSel.disabled = !!map;
  }
  viewSel.addEventListener("change", function () {
    var v = viewSel.value, b;
    if (v.indexOf("map:") === 0) {
      b = q('#mapToggle button[data-map="' + v.slice(4) + '"]');
      if (b && !b.classList.contains("active")) b.click();
    } else {
      b = q('#layoutToggle button[data-layout="' + v + '"]');
      if (b) b.click();
    }
    sync();
  });
  zoomSel.addEventListener("change", function () {
    var b = q('#zoomToggle button[data-zoom="' + zoomSel.value + '"]');
    if (b) b.click();
    sync();
  });
  if (window.MutationObserver) {
    var mo = new MutationObserver(sync);
    ["#layoutToggle", "#zoomToggle", "#mapToggle"].forEach(function (id) {
      var el = q(id);
      if (el) mo.observe(el, { attributes: true, subtree: true, attributeFilter: ["class"] });
    });
  }
  sync();
})();
