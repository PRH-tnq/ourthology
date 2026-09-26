/*
 * Phase 92: "Places we lived" (places.php) -- the per-person master map,
 * the expanding home cards, the photo lightbox, and the add/edit pop-up
 * (with Memory-planner-style repeating "changes", each with photos).
 * Photos upload one at a time in resumable chunks (chunked_upload.js,
 * Phase 91) before the form itself is submitted.
 */
(function () {
  "use strict";

  var DATA = JSON.parse(document.getElementById("placesData").textContent || "{}");
  var homes = DATA.homes || [];
  var family = DATA.family || [];
  var MAP_PERSON = DATA.mapPersonId;

  var TILE_URL = "https://tile.openstreetmap.org/{z}/{x}/{y}.png";
  var TILE_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
  var UK_CENTRE = [54.5, -3.2];

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function homeLabel(h) {
    if (h.name) return h.name;
    var first = (h.address || "").split(/\n|,/)[0].trim();
    return first || h.postcode || "A home";
  }
  function datesLabel(inL, outL) {
    if (inL && outL) return inL + " – " + outL;
    if (inL) return "From " + inL;
    if (outL) return "Until " + outL;
    return "Dates not added yet";
  }
  var VIS_LABEL = { "public": "Family", "private": "Only residents", "custom": "Custom" };

  // ---------------------------------------------------------------- map
  var map = null, markers = {}, pinNumbers = {};
  var mapEl = document.getElementById("placesMap");
  var mapNote = document.getElementById("mapNote");
  if (window.L && mapEl) {
    map = L.map(mapEl, { scrollWheelZoom: false }).setView(UK_CENTRE, 5);
    L.tileLayer(TILE_URL, { maxZoom: 19, attribution: TILE_ATTR }).addTo(map);
    map.on("focus", function () { map.scrollWheelZoom.enable(); });
    map.on("blur", function () { map.scrollWheelZoom.disable(); });
  }

  function pinIcon(n, active) {
    return L.divIcon({
      className: "",
      html: '<div class="pl-pin' + (active ? " active" : "") + '"><span>' + n + "</span></div>",
      iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -28]
    });
  }

  // Phase 93: memories that have a place pinned on them (add_entry.php's
  // "Where it happened"), as small gold dots under the numbered homes. Each
  // dot goes on whichever world copy puts it nearest one of the homes, so a
  // memory in Kyoto sits beside the Kyoto home even when the path has been
  // unwrapped across the date line.
  var memories = DATA.memories || [];
  var memLayer = null, memMarkers = {};
  function drawMemories(homeLngs) {
    if (!memories.length) return [];
    memLayer = L.layerGroup().addTo(map);
    var out = [];
    memories.forEach(function (mm) {
      var lng = window.ourthologyGeo.nearestCopy(mm.lng, homeLngs);
      var icon = L.divIcon({ className: "", html: '<div class="mem-pin"></div>', iconSize: [14, 14], iconAnchor: [7, 7], popupAnchor: [0, -6] });
      var m = L.marker([mm.lat, lng], { icon: icon, title: mm.title, zIndexOffset: -500 }).addTo(memLayer);
      m.bindPopup('<div class="mem-pop">' + (mm.thumb ? '<img src="' + esc(mm.thumb) + '" alt="">' : "") +
        "<div><b>" + esc(mm.title) + "</b><small>" + esc(mm.dateLabel) + (mm.place ? " · " + esc(mm.place) : "") + "</small>" +
        '<a href="/timeline.php?person_id=' + MAP_PERSON + "&entry=" + mm.id + '">Open this memory</a></div></div>');
      memMarkers[mm.id] = m;
      out.push([mm.lat, lng]);
    });
    document.getElementById("memToggleWrap").hidden = false;
    document.getElementById("memCount").textContent = "(" + memories.length + ")";
    document.getElementById("memToggle").addEventListener("change", function () {
      if (this.checked) memLayer.addTo(map); else map.removeLayer(memLayer);
    });
    return out;
  }

  function drawMap() {
    if (!map) return;
    // Shared with the timeline's "Where we've lived" map (geo.js journey()):
    // great-circle arcs, each pin on the world copy that keeps the line
    // continuous across the date line.
    var located = [], n = 0;
    homes.forEach(function (h) {
      n += 1;
      pinNumbers[h.id] = n;
      if (h.lat != null && h.lng != null) located.push({ h: h, n: n });
    });
    var J = window.ourthologyGeo.journey(located.map(function (x) { return [x.h.lat, x.h.lng]; }));
    var pts = J.pins, path = J.path;
    located.forEach(function (x, i) {
      var h = x.h;
      var m = L.marker(pts[i], { icon: pinIcon(x.n, false), title: homeLabel(h), riseOnHover: true }).addTo(map);
      m.bindPopup("<b>" + esc(homeLabel(h)) + "</b><br>" + esc(datesLabel(h.inLabel, h.outLabel)));
      m.on("click", function () { openCard(h.id, false); });
      markers[h.id] = m;
    });
    if (path.length > 1) {
      L.polyline(path, { color: "#9A2A2A", weight: 3, opacity: 0.75, dashArray: "8 8" }).addTo(map);
      // a dot halfway along each move marks it
      J.mids.forEach(function (mid) { L.circleMarker(mid, { radius: 3, color: "#9A2A2A", fillOpacity: 1 }).addTo(map); });
    }
    var memPts = drawMemories(pts.map(function (q) { return q[1]; }));
    var all = pts.concat(path, memPts);
    if (all.length === 1) map.setView(all[0], 13);
    else if (all.length > 1) map.fitBounds(all, { padding: [40, 40], maxZoom: 13 });
    var missing = homes.length - pts.length;
    mapNote.textContent = homes.length
      ? (missing ? missing + (missing === 1 ? " home isn't" : " homes aren't") + " on the map yet — edit it and use “Find on map”." : "Numbers follow the order the homes were lived in; the dashed line traces each move.")
      : "";
  }

  // ----------------------------------------------------------- the list
  var list = document.getElementById("homeList");
  var gallery = []; // for the lightbox

  function photoStrip(photos, caption) {
    if (!photos || !photos.length) return "";
    return '<div class="photo-strip">' + photos.map(function (p) {
      var idx = gallery.push({ url: p.url, caption: caption }) - 1;
      return '<button type="button" data-lb="' + idx + '"><img src="' + p.url + '&thumb=1" alt="" loading="lazy" decoding="async"></button>';
    }).join("") + "</div>";
  }

  function renderList() {
    gallery = [];
    if (!homes.length) {
      list.innerHTML = '<div class="empty-places">No homes on this map yet.<br>Add the first one — even just a town and a year is a start.</div>';
      return;
    }
    list.innerHTML = homes.map(function (h) {
      var n = pinNumbers[h.id] || "";
      var thumb = h.photos.length ? h.photos[0].url : (h.updates.reduce(function (a, u) { return a || (u.photos[0] && u.photos[0].url); }, null));
      var residents = h.residents.map(function (r) {
        var link = r.personId === MAP_PERSON ? esc(r.name) : '<a href="/places.php?person_id=' + r.personId + '">' + esc(r.name) + "</a>";
        return "<li>" + link + "<span>" + esc(datesLabel(r.inLabel, r.outLabel)) + "</span></li>";
      }).join("");
      var updates = h.updates.map(function (u) {
        return '<div class="update-item">' +
          (u.dateLabel ? '<div class="u-date">' + esc(u.dateLabel) + "</div>" : "") +
          '<div class="u-title">' + esc(u.title) + "</div>" +
          (u.notes ? '<div class="u-notes">' + esc(u.notes) + "</div>" : "") +
          photoStrip(u.photos, homeLabel(h) + " — " + u.title) + "</div>";
      }).join("");
      var addr = [h.address, h.postcode, h.country].filter(Boolean).join("\n");
      var actions = "";
      if (h.canEdit) {
        actions += '<button type="button" class="pl-btn small" data-edit="' + h.id + '">Edit this home</button>';
        actions += '<button type="button" class="pl-btn ghost small" data-delete="' + h.id + '">Delete</button>';
      }
      if (h.iLivedHere && !h.canEdit) {
        actions += '<button type="button" class="pl-btn ghost small" data-removeme="' + h.id + '">Remove from my map</button>';
      } else if (h.iLivedHere && h.residents.length > 1) {
        actions += '<button type="button" class="pl-btn ghost small" data-removeme="' + h.id + '">Take me off this home</button>';
      }
      return '<div class="home-card" id="home-' + h.id + '" data-home="' + h.id + '">' +
        '<button type="button" class="home-head" aria-expanded="false">' +
          '<span class="home-num' + (h.lat == null ? " nomap" : "") + '">' + n + "</span>" +
          '<span class="home-thumb" style="' + (thumb ? "background-image:url('" + thumb + "&thumb=1')" : "") + '"></span>' +
          "<span><div class=\"home-title\">" + esc(homeLabel(h)) + '<span class="vis-tag">' + VIS_LABEL[h.visibility] + "</span></div>" +
          '<div class="home-dates">' + esc(datesLabel(h.inLabel, h.outLabel)) + "</div></span>" +
          '<svg class="home-chev" width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 8l5 5 5-5"/></svg>' +
        "</button>" +
        '<div class="home-body">' +
          (addr ? '<div class="home-addr">' + esc(addr) + "</div>" : "") +
          photoStrip(h.photos, homeLabel(h)) +
          "<h4>Who lived here</h4><ul class=\"residents-list\">" + residents + "</ul>" +
          (h.notes ? "<h4>About this home</h4><div class=\"home-notes\">" + esc(h.notes) + "</div>" : "") +
          (updates ? "<h4>How it changed</h4>" + updates : "") +
          (actions ? '<div class="home-actions">' + actions + "</div>" : "") +
        "</div></div>";
    }).join("");
  }

  var openId = null;
  function openCard(id, fromList) {
    var card = document.getElementById("home-" + id);
    if (!card) return;
    var wasOpen = card.classList.contains("open");
    Array.prototype.forEach.call(list.querySelectorAll(".home-card.open"), function (c) {
      c.classList.remove("open");
      c.querySelector(".home-head").setAttribute("aria-expanded", "false");
    });
    Object.keys(markers).forEach(function (k) { markers[k].setIcon(pinIcon(pinNumbers[k], false)); });
    if (wasOpen && fromList) { openId = null; return; }
    card.classList.add("open");
    card.querySelector(".home-head").setAttribute("aria-expanded", "true");
    openId = id;
    var m = markers[id];
    if (m && map) {
      m.setIcon(pinIcon(pinNumbers[id], true));
      map.flyTo(m.getLatLng(), Math.max(map.getZoom(), 12), { duration: 0.6 });
      m.openPopup();
    }
    if (!fromList || window.innerWidth <= 900) card.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }

  list.addEventListener("click", function (e) {
    var t = e.target;
    var lb = t.closest("[data-lb]");
    if (lb) { openLightbox(parseInt(lb.getAttribute("data-lb"), 10)); return; }
    var ed = t.closest("[data-edit]");
    if (ed) { openEditor(findHome(parseInt(ed.getAttribute("data-edit"), 10))); return; }
    var del = t.closest("[data-delete]");
    if (del) {
      if (confirm("Delete this home, its photos and its changes for everyone who lived there? This can't be undone.")) {
        submitAction("delete_home", del.getAttribute("data-delete"));
      }
      return;
    }
    var rm = t.closest("[data-removeme]");
    if (rm) {
      if (confirm("Take yourself off this home? It stays exactly as it is for everyone else who lived there.")) {
        submitAction("remove_me", rm.getAttribute("data-removeme"));
      }
      return;
    }
    var head = t.closest(".home-head");
    if (head) openCard(parseInt(head.parentElement.getAttribute("data-home"), 10), true);
  });

  function submitAction(action, homeId) {
    document.getElementById("aAction").value = action;
    document.getElementById("aHomeId").value = homeId;
    document.getElementById("actionForm").submit();
  }
  function findHome(id) {
    for (var i = 0; i < homes.length; i++) { if (homes[i].id === id) return homes[i]; }
    return null;
  }

  document.getElementById("personPicker").addEventListener("change", function () {
    window.location.href = "/places.php?person_id=" + encodeURIComponent(this.value);
  });

  // ------------------------------------------------------------ lightbox
  var lb = document.getElementById("lightbox"), lbImg = document.getElementById("lbImg"), lbCap = document.getElementById("lbCap");
  var lbIdx = 0;
  function openLightbox(i) {
    lbIdx = i;
    lbImg.src = gallery[i].url;
    lbCap.textContent = gallery[i].caption + "  ·  " + (i + 1) + " of " + gallery.length;
    lb.classList.add("open");
  }
  function lbStep(d) { if (gallery.length) openLightbox((lbIdx + d + gallery.length) % gallery.length); }
  document.getElementById("lbClose").addEventListener("click", function () { lb.classList.remove("open"); });
  document.getElementById("lbPrev").addEventListener("click", function () { lbStep(-1); });
  document.getElementById("lbNext").addEventListener("click", function () { lbStep(1); });
  lb.addEventListener("click", function (e) { if (e.target === lb) lb.classList.remove("open"); });

  // -------------------------------------------------------------- editor
  var overlay = document.getElementById("editOverlay");
  var form = document.getElementById("homeForm");
  var resList = document.getElementById("resList");
  var updRows = document.getElementById("updRows");
  var formError = document.getElementById("formError");
  var formProgress = document.getElementById("formProgress");
  var saveBtn = document.getElementById("saveBtn");
  var editMap = null, editMarker = null, pickers = [], updCounter = 0, uploading = false;

  function trio(prefix, vals, label) {
    vals = vals || ["", "", ""];
    return "<div><small>" + label + '</small><div class="date-trio">' +
      '<div class="date-slot"><input type="text" inputmode="numeric" maxlength="2" placeholder="DD" name="' + prefix.replace("%", "day") + '" value="' + esc(vals[0]) + '"></div>' +
      '<div class="date-slot"><input type="text" inputmode="numeric" maxlength="2" placeholder="MM" name="' + prefix.replace("%", "month") + '" value="' + esc(vals[1]) + '"></div>' +
      '<div class="date-slot"><input type="text" inputmode="numeric" maxlength="4" placeholder="YYYY" name="' + prefix.replace("%", "year") + '" value="' + esc(vals[2]) + '"></div>' +
      "</div></div>";
  }

  // A small photo picker: existing (kept) photos + newly chosen files,
  // drag/drop or click; each can be removed before saving.
  // Phase 98: each photo box takes photos three ways, like the memory
  // composer's: the Add tile (file picker), dragging photos onto the box,
  // and pasting -- the Paste button (touch screens have no Ctrl+V; see
  // clipboard_paste.js) or Ctrl+V anywhere in the editor, which goes to the
  // box last used (the home's own photos until another box is touched).
  var activePicker = null;
  function makePicker(el, existing) {
    var st = { el: el, kept: (existing || []).slice(), pending: [] };
    el.innerHTML =
      '<div class="pk-grid"></div>' +
      '<div class="pk-bar"><span class="pk-hint">Drag photos here, or</span>' +
      '<button type="button" class="pk-paste"><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="5" y="3.5" width="10" height="13" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M7.5 3.5V3a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v.5" stroke="currentColor" stroke-width="1.4"/></svg>Paste</button>' +
      '<span class="pk-msg" role="status"></span></div>';
    var grid = el.querySelector(".pk-grid");
    var msg = el.querySelector(".pk-msg");
    var input = document.createElement("input");
    input.type = "file"; input.accept = "image/*,.heic,.heif"; input.multiple = true; input.hidden = true;
    el.appendChild(input);
    function say(t) { msg.textContent = t || ""; }
    function render() {
      grid.innerHTML = st.kept.map(function (p, i) {
        return '<div class="pk-tile"><img src="' + p.url + '&thumb=1" alt=""><button type="button" class="pk-x" data-k="' + i + '" aria-label="Remove">×</button>' +
          '<input type="hidden" name="' + el.getAttribute("data-kept-name") + '" value="' + p.id + '"></div>';
      }).join("") + st.pending.map(function (p, i) {
        return '<div class="pk-tile"><img src="' + p.url + '" alt=""><button type="button" class="pk-x" data-p="' + i + '" aria-label="Remove">×</button></div>';
      }).join("") + '<button type="button" class="pk-add" aria-label="Add photos">+<small>Add photos</small></button>';
    }
    function add(files) {
      var added = 0, skipped = [];
      Array.prototype.forEach.call(files || [], function (f) {
        if (f.type && f.type.indexOf("image/") !== 0 && !/\.(heic|heif)$/i.test(f.name)) { skipped.push(f.name || "that file"); return; }
        if (f.size > 30 * 1024 * 1024) { skipped.push(f.name + " (over 30MB)"); return; }
        if (st.kept.length + st.pending.length >= 25) { skipped.push(f.name + " (25 photos max)"); return; }
        st.pending.push({ file: f, url: URL.createObjectURL(f), token: null });
        added++;
      });
      say(skipped.length ? "Skipped: " + skipped.join(", ") + " — photos only, up to 30MB each." : "");
      render();
      return added;
    }
    st.add = add;
    st.say = say;
    function activate() { activePicker = st; }
    el.addEventListener("click", function (e) {
      activate();
      var x = e.target.closest(".pk-x");
      if (x) {
        if (x.hasAttribute("data-k")) st.kept.splice(parseInt(x.getAttribute("data-k"), 10), 1);
        else st.pending.splice(parseInt(x.getAttribute("data-p"), 10), 1);
        render(); return;
      }
      if (e.target.closest(".pk-add")) input.click();
    });
    el.addEventListener("focusin", activate);
    input.addEventListener("change", function () { add(input.files); input.value = ""; });
    var depth = 0; // dragenter/leave fire for every child tile -- count them
    el.addEventListener("dragenter", function (e) { e.preventDefault(); depth++; el.classList.add("dragover"); });
    el.addEventListener("dragover", function (e) { e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = "copy"; });
    el.addEventListener("dragleave", function () { depth = Math.max(0, depth - 1); if (!depth) el.classList.remove("dragover"); });
    el.addEventListener("drop", function (e) {
      e.preventDefault(); e.stopPropagation();
      depth = 0; el.classList.remove("dragover");
      activate();
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) add(e.dataTransfer.files);
      else say("That didn't include a photo file — try saving the picture first, then drag it in.");
    });
    if (window.ourthologyClipboardPaste) {
      window.ourthologyClipboardPaste.wire(el.querySelector(".pk-paste"), {
        onFiles: function (files) { activate(); add(files); },
        onMessage: say
      });
    } else {
      el.querySelector(".pk-paste").hidden = true;
    }
    render();
    pickers.push(st);
    if (!activePicker) activePicker = st;
    return st;
  }

  // Ctrl+V anywhere in the open editor (except while typing in a text box)
  // adds the pasted picture(s) to the photo box last used.
  document.addEventListener("paste", function (e) {
    if (!overlay.classList.contains("open") || !e.clipboardData) return;
    var files = [];
    Array.prototype.forEach.call(e.clipboardData.items || [], function (it) {
      if (it.kind === "file") { var f = it.getAsFile(); if (f) files.push(f); }
    });
    if (!files.length) return; // plain text: let it paste into the field as normal
    e.preventDefault();
    var target = (activePicker && pickers.indexOf(activePicker) >= 0) ? activePicker : pickers[0];
    if (target) {
      target.add(files);
      target.el.scrollIntoView({ block: "nearest" });
    }
  });
  // A photo dropped on the editor but just outside a box shouldn't make the
  // browser open the picture (and throw away everything typed so far) --
  // it goes into the home's own photos instead.
  overlay.addEventListener("dragover", function (e) { e.preventDefault(); });
  overlay.addEventListener("drop", function (e) {
    e.preventDefault();
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length && pickers[0]) pickers[0].add(e.dataTransfer.files);
  });

  function addUpdateRow(u) {
    u = u || {};
    var i = updCounter++;
    var row = document.createElement("div");
    row.className = "upd-row";
    row.innerHTML =
      '<input type="hidden" name="updates[' + i + '][id]" value="' + (u.id || "") + '">' +
      '<div class="upd-top"><div class="grow"><label>What changed</label><input type="text" maxlength="160" name="updates[' + i + '][title]" placeholder="e.g. Built the extension" value="' + esc(u.title || "") + '"></div>' +
      '<div class="res-dates" style="display:flex;margin:0;">' + trio("updates[" + i + "][%]", u.date, "When") + "</div>" +
      '<button type="button" class="upd-rm">Remove</button></div>' +
      '<label style="margin-top:8px;">Notes</label><textarea rows="2" maxlength="5000" name="updates[' + i + '][notes]">' + esc(u.notes || "") + "</textarea>" +
      '<div class="pk" data-kept-name="updates[' + i + '][kept_media_ids][]" data-staged-name="updates[' + i + '][staged_media][]"></div>';
    updRows.appendChild(row);
    var picker = makePicker(row.querySelector(".pk"), u.photos || []);
    row.querySelector(".upd-rm").addEventListener("click", function () {
      if ((picker.kept.length || u.id) && !confirm("Remove this change and its photos when you save?")) return;
      pickers.splice(pickers.indexOf(picker), 1);
      row.remove();
    });
  }

  function renderResidents(h) {
    var chosen = {};
    if (h) h.residents.forEach(function (r) { chosen[r.personId] = r; });
    else chosen[MAP_PERSON] = { in: ["", "", ""], out: ["", "", ""] };
    // people already on the home first, then everyone else alphabetically
    var ordered = family.slice().sort(function (a, b) { return (chosen[b.id] ? 1 : 0) - (chosen[a.id] ? 1 : 0); });
    resList.innerHTML = ordered.map(function (p) {
      var c = chosen[p.id];
      return '<div class="res-row' + (c ? " on" : "") + '"><label><input type="checkbox" name="residents[' + p.id + '][on]" value="1"' + (c ? " checked" : "") + "> " + esc(p.name) + (p.id === DATA.myPersonId ? " (you)" : "") + "</label>" +
        '<div class="res-dates">' + trio("residents[" + p.id + "][in_%]", c ? c.in : null, "Moved in") + trio("residents[" + p.id + "][out_%]", c ? c.out : null, "Moved out") + "</div></div>";
    }).join("");
  }
  // Phase 97: tagging someone else onto a home fills in the dates of
  // whoever is making the record -- you, if you're ticked and have dates;
  // otherwise the first ticked person with dates -- since people who lived
  // somewhere together usually moved in and out together. A row filled in
  // that way keeps following those dates while you're still typing them
  // (so the order you fill things in doesn't matter), until you change that
  // person's own dates, which makes them theirs. places.php applies the
  // same default on save for anyone newly ticked with no dates at all.
  function dateInputs(row) { return row ? row.querySelectorAll(".res-dates input") : []; }
  function hasDates(row) { return Array.prototype.some.call(dateInputs(row), function (i) { return i.value.trim() !== ""; }); }
  function rowFor(pid) {
    var cb = resList.querySelector('input[name="residents[' + pid + '][on]"]');
    return cb ? cb.closest(".res-row") : null;
  }
  function sourceRow(except) {
    var me = rowFor(DATA.myPersonId);
    if (me && me !== except && me.classList.contains("on") && hasDates(me)) return me;
    var rows = resList.querySelectorAll(".res-row.on");
    for (var i = 0; i < rows.length; i++) {
      if (rows[i] !== except && !rows[i].hasAttribute("data-follow") && hasDates(rows[i])) return rows[i];
    }
    return null;
  }
  function copyInto(dst, src) {
    var s = dateInputs(src), d = dateInputs(dst);
    Array.prototype.forEach.call(d, function (inp, k) { inp.value = s[k] ? s[k].value : ""; });
  }
  function setFollow(row, on) {
    if (on) row.setAttribute("data-follow", "1"); else row.removeAttribute("data-follow");
    var note = row.querySelector(".res-follow-note");
    if (on && !note) {
      note = document.createElement("div");
      note.className = "res-follow-note";
      row.querySelector(".res-dates").appendChild(note);
    }
    if (note) {
      note.hidden = !on;
      var src = on ? sourceRow(row) : null;
      var who = src ? src.querySelector("label").textContent.replace(/\s*\(you\)\s*$/, "").trim() : "";
      note.textContent = on ? "Same dates as " + (src && src === rowFor(DATA.myPersonId) ? "you" : (who || "above")) + " — change them if theirs were different." : "";
    }
  }
  resList.addEventListener("change", function (e) {
    if (e.target.type !== "checkbox") return;
    var row = e.target.closest(".res-row");
    row.classList.toggle("on", e.target.checked);
    if (e.target.checked) {
      if (!hasDates(row)) {
        var src = sourceRow(row);
        if (src) copyInto(row, src);
        setFollow(row, true); // keeps following even if the source dates are typed after ticking
      }
    } else if (row.hasAttribute("data-follow")) {
      setFollow(row, false);
    }
  });
  resList.addEventListener("input", function (e) {
    var row = e.target.closest(".res-row");
    if (!row || !e.target.closest(".res-dates")) return;
    if (row.hasAttribute("data-follow")) {
      setFollow(row, false); // their own dates now
      return;
    }
    // typing the record-maker's dates: rows still following them update too
    if (row === sourceRow(null)) {
      Array.prototype.forEach.call(resList.querySelectorAll('.res-row.on[data-follow]'), function (f) {
        copyInto(f, row);
        setFollow(f, true);
      });
    }
  });

  function setPin(lat, lng, zoom) {
    // A click on a repeated copy of the world (scrolled past the date line)
    // gives a longitude beyond +/-180 -- store the real one.
    document.getElementById("fLat").value = lat.toFixed(6);
    document.getElementById("fLng").value = window.ourthologyGeo.wrapLng(lng).toFixed(6);
    if (!editMap) return;
    if (!editMarker) {
      editMarker = L.marker([lat, lng], { draggable: true }).addTo(editMap);
      editMarker.on("dragend", function () { var p = editMarker.getLatLng(); setPin(p.lat, p.lng); });
    } else {
      editMarker.setLatLng([lat, lng]);
    }
    if (zoom) editMap.setView([lat, lng], zoom);
  }

  function openEditor(h) {
    form.reset();
    pickers = []; activePicker = null; updCounter = 0; updRows.innerHTML = "";
    formError.textContent = ""; formProgress.textContent = "";
    document.getElementById("editTitle").textContent = h ? "Edit " + homeLabel(h) : "Add a home";
    document.getElementById("fHomeId").value = h ? h.id : "";
    document.getElementById("fName").value = h ? h.name : "";
    document.getElementById("fAddress").value = h ? h.address : "";
    document.getElementById("fPostcode").value = h ? h.postcode : "";
    document.getElementById("fCountry").value = h ? h.country : "";
    document.getElementById("fNotes").value = h ? h.notes : "";
    document.getElementById("fLat").value = ""; document.getElementById("fLng").value = "";
    document.getElementById("geoChoices").innerHTML = ""; document.getElementById("geoChoices").hidden = true;
    Array.prototype.forEach.call(form.querySelectorAll('input[name="visibility"]'), function (r) { r.checked = r.value === (h ? h.visibility : "public"); });
    renderResidents(h);
    makePicker(document.getElementById("generalPicker"), h ? h.photos : []);
    (h ? h.updates : []).forEach(addUpdateRow);
    overlay.classList.add("open");
    overlay.setAttribute("aria-hidden", "false");
    if (window.L) {
      if (!editMap) {
        editMap = L.map("editMap").setView(UK_CENTRE, 5);
        L.tileLayer(TILE_URL, { maxZoom: 19, attribution: TILE_ATTR }).addTo(editMap);
        editMap.on("click", function (e) { setPin(e.latlng.lat, e.latlng.lng); document.getElementById("geoMsg").textContent = "Pin placed — drag it to fine-tune."; });
      }
      if (editMarker) { editMap.removeLayer(editMarker); editMarker = null; }
      setTimeout(function () {
        editMap.invalidateSize();
        if (h && h.lat != null) setPin(h.lat, h.lng, 15); else editMap.setView(UK_CENTRE, 5);
      }, 50);
    }
  }
  function closeEditor() {
    if (uploading) return;
    overlay.classList.remove("open");
    overlay.setAttribute("aria-hidden", "true");
  }
  document.getElementById("addHomeBtn").addEventListener("click", function () { openEditor(null); });
  document.getElementById("addUpdBtn").addEventListener("click", function () { addUpdateRow(null); });
  document.getElementById("editClose").addEventListener("click", closeEditor);
  document.getElementById("editCancel").addEventListener("click", closeEditor);
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") { if (lb.classList.contains("open")) lb.classList.remove("open"); else closeEditor(); }
    if (lb.classList.contains("open") && e.key === "ArrowLeft") lbStep(-1);
    if (lb.classList.contains("open") && e.key === "ArrowRight") lbStep(1);
  });

  // Find on map: works anywhere in the world (see geo.js) -- a UK postcode
  // via postcodes.io, everything else via OpenStreetMap's Nominatim,
  // restricted to the chosen country. When the search finds more than one
  // match, the others are offered underneath so the right one can be picked.
  var geoChoices = document.getElementById("geoChoices");
  window.ourthologyGeo.fillCountryList(document.getElementById("countryList"));
  function showChoice(hit) {
    setPin(hit.lat, hit.lng);
    if (editMap) window.ourthologyGeo.placeResult(editMap, hit);
  }
  document.getElementById("geoBtn").addEventListener("click", function () {
    var msg = document.getElementById("geoMsg");
    var btn = this;
    geoChoices.innerHTML = "";
    geoChoices.hidden = true;
    var parts = {
      address: document.getElementById("fAddress").value,
      postcode: document.getElementById("fPostcode").value,
      country: document.getElementById("fCountry").value
    };
    if (!parts.address.trim() && !parts.postcode.trim() && !parts.country.trim()) {
      msg.textContent = "Type an address, postcode or country first — or click the map.";
      return;
    }
    msg.textContent = "Looking…";
    btn.disabled = true;
    window.ourthologyGeo.search(parts).then(function (hits) {
      btn.disabled = false;
      if (!hits.length) { msg.textContent = "Couldn't find that — check the country, or click the map to drop the pin yourself."; return; }
      showChoice(hits[0]);
      msg.textContent = "Found it — drag the pin if it's not quite right.";
      if (hits.length > 1) {
        geoChoices.innerHTML = '<span class="geo-choices-h">Not the right place? Other matches:</span>' + hits.map(function (h, i) {
          return '<button type="button" data-i="' + i + '"' + (i === 0 ? ' class="on"' : "") + ">" + esc(h.label) + "</button>";
        }).join("");
        geoChoices.hidden = false;
        Array.prototype.forEach.call(geoChoices.querySelectorAll("button"), function (b) {
          b.addEventListener("click", function () {
            Array.prototype.forEach.call(geoChoices.querySelectorAll("button"), function (o) { o.classList.remove("on"); });
            b.classList.add("on");
            showChoice(hits[+b.getAttribute("data-i")]);
          });
        });
      }
    }).catch(function () {
      btn.disabled = false;
      msg.textContent = "The map search isn't reachable right now — click the map to drop the pin yourself.";
    });
  });

  // ---- save: quick checks, then upload new photos, then submit tokens
  function quickCheck() {
    var name = document.getElementById("fName").value.trim(), addr = document.getElementById("fAddress").value.trim(), pc = document.getElementById("fPostcode").value.trim();
    if (!name && !addr && !pc) return "Give the home a name or an address.";
    if (!resList.querySelector("input[type=checkbox]:checked")) return "Tick at least one person who lived there.";
    return null;
  }
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (uploading) return;
    var problem = quickCheck();
    if (problem) { formError.textContent = problem; return; }
    formError.textContent = "";
    var uploader = window.ourthologyChunkedUpload;
    var jobs = [];
    pickers.forEach(function (p) { p.pending.forEach(function (it) { if (!it.token) jobs.push(it); }); });
    var csrf = form.querySelector('input[name="csrf_token"]').value;
    uploading = true;
    saveBtn.disabled = true;
    var chain = Promise.resolve();
    if (jobs.length && !(uploader && uploader.supported)) {
      formError.textContent = "This browser can't upload photos here — please try a newer browser.";
      uploading = false; saveBtn.disabled = false; return;
    }
    jobs.forEach(function (it, i) {
      chain = chain.then(function () {
        return uploader.upload(it.file, {
          csrf: csrf,
          fields: { purpose: "entry", target_person_id: String(DATA.myPersonId) },
          onProgress: function (sent, total) { formProgress.textContent = "Uploading photo " + (i + 1) + " of " + jobs.length + " — " + Math.round(sent / total * 100) + "%"; }
        }).then(function (tok) { it.token = tok; });
      });
    });
    chain.then(function () {
      Array.prototype.forEach.call(form.querySelectorAll(".pl-staged"), function (el) { el.remove(); });
      pickers.forEach(function (p) {
        p.pending.forEach(function (it) {
          var h = document.createElement("input");
          h.type = "hidden"; h.className = "pl-staged";
          h.name = p.el.getAttribute("data-staged-name"); h.value = it.token;
          form.appendChild(h);
        });
      });
      formProgress.textContent = "Saving…";
      form.submit();
    }).catch(function (err) {
      uploading = false; saveBtn.disabled = false; formProgress.textContent = "";
      formError.textContent = ((err && err.message) || "The upload stopped.") +
        ((err && err.fatal) ? "" : " The connection dropped for a moment — press Save again and it will carry on from where it stopped.");
    });
  });

  // ---------------------------------------------------------------- go
  drawMap();
  renderList();
  if (DATA.openHomeId && findHome(DATA.openHomeId)) {
    setTimeout(function () { openCard(DATA.openHomeId, false); }, 150);
  } else if (DATA.openMemoryId && memMarkers[DATA.openMemoryId]) {
    // ?memory=ID (from "See it on the map" in the timeline's memory viewer)
    setTimeout(function () {
      var m = memMarkers[DATA.openMemoryId];
      map.setView(m.getLatLng(), 14);
      m.openPopup();
      mapEl.scrollIntoView({ block: "center" });
    }, 150);
  }
  window.ourthologyOpenHomeEditor = function () { openEditor(null); };
})();
