/*
 * Phase 93: "Where it happened" on the memory composer (add_entry.php).
 *
 * All optional. The place is a free-text label (kept even with no pin --
 * "Nan's kitchen" is a fine answer) plus an optional map pin in the hidden
 * location_lat / location_lng fields. The pin can come from:
 *   - "Find": a worldwide search on the typed text (geo.js), with the other
 *     matches offered underneath when there's more than one;
 *   - one of the person's own homes from Places we lived ("At home:");
 *   - "Pick on a map": click anywhere, drag to adjust. If nothing has been
 *     typed yet, the nearest address is filled in for you.
 * The map (Leaflet) is only loaded once it's actually needed.
 */
(function () {
  "use strict";
  var labelEl = document.getElementById("locLabel");
  if (!labelEl || !window.ourthologyGeo) return;
  var G = window.ourthologyGeo;
  var latEl = document.getElementById("locLat"), lngEl = document.getElementById("locLng");
  var msg = document.getElementById("locMsg");
  var findBtn = document.getElementById("locFindBtn");
  var mapBtn = document.getElementById("locMapBtn");
  var clearBtn = document.getElementById("locClearBtn");
  var choices = document.getElementById("locChoices");
  var mapEl = document.getElementById("locMap");
  var homes = document.getElementById("locHomes");
  var map = null, marker = null;

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function hasPin() { return latEl.value !== "" && lngEl.value !== ""; }
  function refresh() {
    clearBtn.hidden = !hasPin();
    mapBtn.textContent = mapEl.hidden ? (hasPin() ? "Show on the map" : "Pick on a map") : "Hide map";
    if (!msg.dataset.busy) msg.textContent = hasPin() ? "Pinned on the map." : "";
  }
  function say(t, busy) {
    msg.textContent = t;
    if (busy) msg.dataset.busy = "1"; else delete msg.dataset.busy;
  }

  function ensureMap() {
    if (map) return Promise.resolve(map);
    return G.loadLeaflet().then(function (L) {
      mapEl.hidden = false;
      map = L.map(mapEl).setView([30, 0], 1);
      L.tileLayer(G.TILE_URL, { maxZoom: 19, attribution: G.TILE_ATTR }).addTo(map);
      map.on("click", function (e) {
        setPin(e.latlng.lat, e.latlng.lng, false);
        if (!labelEl.value.trim()) reverseFill(e.latlng.lat, G.wrapLng(e.latlng.lng));
      });
      return map;
    });
  }
  function showMap() {
    return ensureMap().then(function () {
      mapEl.hidden = false;
      map.invalidateSize();
      if (hasPin()) {
        placeMarker(+latEl.value, +lngEl.value);
        if (!map._ourthologyCentred) { map.setView([+latEl.value, +lngEl.value], 14); map._ourthologyCentred = true; }
      }
      refresh();
    }).catch(function () { say("The map couldn't load — the place will still be saved as text."); });
  }
  function placeMarker(lat, lng) {
    if (!map) return;
    if (!marker) {
      marker = window.L.marker([lat, lng], { draggable: true }).addTo(map);
      marker.on("dragend", function () { var p = marker.getLatLng(); setPin(p.lat, p.lng, false); });
    } else {
      marker.setLatLng([lat, lng]);
      if (!map.hasLayer(marker)) marker.addTo(map);
    }
  }
  function setPin(lat, lng, recentre) {
    latEl.value = lat.toFixed(6);
    lngEl.value = G.wrapLng(lng).toFixed(6);
    placeMarker(lat, lng);
    if (recentre && map) map.setView([lat, lng], 14);
    delete msg.dataset.busy;
    refresh();
  }
  function clearPin() {
    latEl.value = ""; lngEl.value = "";
    if (marker && map) map.removeLayer(marker);
    choices.hidden = true; choices.innerHTML = "";
    if (homes) Array.prototype.forEach.call(homes.querySelectorAll("button"), function (b) { b.classList.remove("on"); });
    refresh();
  }

  // Nearest address for a clicked point, only to save typing -- shortened
  // to its first few parts ("12, Rue Cler, Paris").
  function reverseFill(lat, lng) {
    fetch("https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=17&accept-language=en&lat=" + lat + "&lon=" + lng)
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.display_name || labelEl.value.trim()) return;
        labelEl.value = j.display_name.split(",").map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 3).join(", ");
      }).catch(function () {});
  }

  findBtn.addEventListener("click", function () {
    var q = labelEl.value.trim();
    choices.hidden = true; choices.innerHTML = "";
    if (!q) { say("Type a place or address first — or pick it on a map."); labelEl.focus(); return; }
    say("Looking…", true);
    findBtn.disabled = true;
    G.search({ query: q }).then(function (hits) {
      findBtn.disabled = false;
      if (!hits.length) { say("Couldn't find that — try adding the town or country, or pick it on a map."); return; }
      return showMap().then(function () {
        setPin(hits[0].lat, hits[0].lng, false);
        G.placeResult(map, hits[0]);
        say("Found it — drag the pin if it's not quite right.");
        if (hits.length > 1) {
          choices.innerHTML = "<span>Not the right place? Other matches:</span>" + hits.map(function (h, i) {
            return '<button type="button" data-i="' + i + '"' + (i === 0 ? ' class="on"' : "") + ">" + esc(h.label) + "</button>";
          }).join("");
          choices.hidden = false;
          Array.prototype.forEach.call(choices.querySelectorAll("button"), function (b) {
            b.addEventListener("click", function () {
              Array.prototype.forEach.call(choices.querySelectorAll("button"), function (o) { o.classList.remove("on"); });
              b.classList.add("on");
              var h = hits[+b.getAttribute("data-i")];
              setPin(h.lat, h.lng, false);
              G.placeResult(map, h);
            });
          });
        }
      });
    }).catch(function () {
      findBtn.disabled = false;
      say("The map search isn't reachable right now — pick the place on a map instead, or just keep it as text.");
    });
  });
  // Enter in the place box searches rather than submitting the whole memory.
  labelEl.addEventListener("keydown", function (e) {
    if (e.key === "Enter") { e.preventDefault(); findBtn.click(); }
  });

  mapBtn.addEventListener("click", function () {
    if (map && !mapEl.hidden) { mapEl.hidden = true; refresh(); return; }
    showMap().then(function () {
      if (!hasPin()) say("Click the map to drop a pin, then drag it to adjust.", true);
    });
  });
  clearBtn.addEventListener("click", clearPin);

  if (homes) {
    Array.prototype.forEach.call(homes.querySelectorAll("button"), function (b) {
      b.addEventListener("click", function () {
        Array.prototype.forEach.call(homes.querySelectorAll("button"), function (o) { o.classList.remove("on"); });
        b.classList.add("on");
        labelEl.value = b.getAttribute("data-label");
        choices.hidden = true; choices.innerHTML = "";
        var lat = +b.getAttribute("data-lat"), lng = +b.getAttribute("data-lng");
        latEl.value = lat.toFixed(6); lngEl.value = lng.toFixed(6);
        refresh();
        if (map && !mapEl.hidden) setPin(lat, lng, true);
      });
    });
  }

  refresh();
})();
