/*
 * Phase 93: shared location helpers for Places we lived (places.js) and
 * the optional "Where it happened" on a memory (add_entry.php / timeline).
 *
 * window.ourthologyGeo:
 *   countries            [{code:'GB', name:'United Kingdom'}, ...] sorted by name
 *   countryCode(text)    'GB' / 'US' / ... for a typed country name or common
 *                        alias ("UK", "England", "USA", "Holland"), else ""
 *   fillCountryList(el)  populates a <datalist> with every country name
 *   search(parts)        Promise<[{lat,lng,label,bounds}]> -- parts is
 *                        {address, postcode, country, query}. Up to 5
 *                        candidates, best first; [] if nothing was found.
 *                        Rejects only if the lookup services can't be reached.
 *   wrapLng(lng)         -180..180 (a click on a repeated world copy can be
 *                        -200 or 190, which the server rejects)
 *   placeResult(map, marker-setter, result)  zoom to a result's own extent
 *   loadLeaflet()        Promise that resolves once /assets/leaflet is loaded
 *
 * Lookups: a UK postcode (when the country is blank or the UK) goes to
 * postcodes.io first -- exact and free. Everything else, anywhere in the
 * world, goes to OpenStreetMap's Nominatim, restricted to the chosen
 * country when there is one, trying progressively looser versions of the
 * address (the full thing; without a leading house name/flat line;
 * postcode + country; the town + country) with a pause between tries to
 * respect Nominatim's one-request-a-second policy.
 */
(function () {
  "use strict";

  var ISO = ("AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ " +
    "CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR " +
    "GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP " +
    "KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ " +
    "NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW " +
    "SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ " +
    "UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW").split(" ");

  // A fallback for the few browsers without Intl.DisplayNames (older than
  // Safari 14.1) -- the commonest countries only; anything else still works
  // as free text, just without the country restriction on the search.
  var FALLBACK_NAMES = {
    GB: "United Kingdom", IE: "Ireland", US: "United States", CA: "Canada", AU: "Australia", NZ: "New Zealand",
    FR: "France", DE: "Germany", ES: "Spain", PT: "Portugal", IT: "Italy", NL: "Netherlands", BE: "Belgium",
    CH: "Switzerland", AT: "Austria", SE: "Sweden", NO: "Norway", DK: "Denmark", FI: "Finland", PL: "Poland",
    GR: "Greece", CY: "Cyprus", MT: "Malta", ZA: "South Africa", IN: "India", PK: "Pakistan", BD: "Bangladesh",
    LK: "Sri Lanka", HK: "Hong Kong", SG: "Singapore", MY: "Malaysia", JP: "Japan", CN: "China", AE: "United Arab Emirates",
    SA: "Saudi Arabia", KE: "Kenya", NG: "Nigeria", GH: "Ghana", JM: "Jamaica", BB: "Barbados", TT: "Trinidad and Tobago",
    GI: "Gibraltar", JE: "Jersey", GG: "Guernsey", IM: "Isle of Man", MX: "Mexico", BR: "Brazil", AR: "Argentina"
  };

  var names = null;
  try {
    if (window.Intl && Intl.DisplayNames) names = new Intl.DisplayNames(["en"], { type: "region" });
  } catch (e) { names = null; }

  var countries = [];
  ISO.forEach(function (code) {
    var n = null;
    if (names) { try { n = names.of(code); } catch (e) { n = null; } }
    if (!n || n === code) n = FALLBACK_NAMES[code];
    if (n) countries.push({ code: code, name: n });
  });
  countries.sort(function (a, b) { return a.name.localeCompare(b.name); });

  var byName = {};
  countries.forEach(function (c) { byName[c.name.toLowerCase()] = c.code; byName[c.code.toLowerCase()] = c.code; });
  var ALIASES = {
    "uk": "GB", "u.k.": "GB", "great britain": "GB", "britain": "GB", "england": "GB", "scotland": "GB", "wales": "GB",
    "northern ireland": "GB", "cymru": "GB", "united kingdom of great britain and northern ireland": "GB",
    "usa": "US", "u.s.a.": "US", "u.s.": "US", "america": "US", "united states of america": "US",
    "holland": "NL", "the netherlands": "NL", "eire": "IE", "republic of ireland": "IE", "southern ireland": "IE",
    "uae": "AE", "south korea": "KR", "korea": "KR", "north korea": "KP", "russia": "RU", "czech republic": "CZ",
    "czechia": "CZ", "vietnam": "VN", "the gambia": "GM", "ivory coast": "CI", "burma": "MM", "swaziland": "SZ",
    "turkey": "TR", "türkiye": "TR", "macedonia": "MK", "the bahamas": "BS", "deutschland": "DE", "españa": "ES",
    "espana": "ES", "italia": "IT", "rhodesia": "ZW", "ceylon": "LK", "persia": "IR", "zaire": "CD",
    "vatican": "VA", "vatican city": "VA", "holy see": "VA", "the vatican": "VA"
  };

  function countryCode(text) {
    var t = String(text || "").trim().toLowerCase().replace(/\s+/g, " ");
    if (!t) return "";
    return ALIASES[t] || byName[t] || byName[t.replace(/^the /, "")] || "";
  }

  function fillCountryList(el) {
    if (!el || el.options.length) return;
    el.innerHTML = countries.map(function (c) {
      return '<option value="' + c.name.replace(/"/g, "&quot;") + '"></option>';
    }).join("");
  }

  function wrapLng(lng) {
    var x = ((lng + 180) % 360 + 360) % 360 - 180;
    return x === -180 && lng > 0 ? 180 : x;
  }

  var UK_POSTCODE = /^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i;
  var lastNominatim = 0;

  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function nominatim(params) {
    var gap = 1100 - (Date.now() - lastNominatim);
    return wait(gap > 0 ? gap : 0).then(function () {
      lastNominatim = Date.now();
      var qs = Object.keys(params).map(function (k) { return k + "=" + encodeURIComponent(params[k]); }).join("&");
      return fetch("https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&addressdetails=1&accept-language=en&" + qs);
    }).then(function (r) { return r.json(); }).then(function (arr) {
      return (arr || []).map(function (h) {
        var bb = h.boundingbox ? h.boundingbox.map(parseFloat) : null; // [south, north, west, east]
        return {
          lat: parseFloat(h.lat), lng: parseFloat(h.lon), label: h.display_name || "",
          cc: (h.address && h.address.country_code ? String(h.address.country_code).toUpperCase() : ""), // Phase 100
          bounds: bb && bb.every(isFinite) ? [[bb[0], bb[2]], [bb[1], bb[3]]] : null
        };
      }).filter(function (h) { return isFinite(h.lat) && isFinite(h.lng); });
    });
  }

  function search(parts) {
    parts = parts || {};
    var pc = String(parts.postcode || "").trim();
    var country = String(parts.country || "").trim();
    var cc = countryCode(country);
    var lines = String(parts.address || parts.query || "").split(/\n|,/).map(function (s) { return s.trim(); }).filter(Boolean);

    var tryUk = UK_POSTCODE.test(pc) && (!country || cc === "GB" || ["GG", "JE", "IM"].indexOf(cc) >= 0);
    var first = tryUk
      ? fetch("https://api.postcodes.io/postcodes/" + encodeURIComponent(pc)).then(function (r) { return r.json(); }).then(function (j) {
          if (j && j.status === 200 && j.result) {
            var pcc = { "England": "GB", "Scotland": "GB", "Wales": "GB", "Northern Ireland": "GB" }[j.result.country] || "";
            return [{ lat: j.result.latitude, lng: j.result.longitude, label: pc.toUpperCase() + (j.result.admin_district ? ", " + j.result.admin_district : ""), bounds: null, zoom: 16, cc: pcc }];
          }
          return [];
        }).catch(function () { return []; })
      : Promise.resolve([]);

    // Progressively looser attempts, most specific first; duplicates skipped.
    var attempts = [], seen = {};
    function add(params) {
      if (cc) params.countrycodes = cc.toLowerCase();
      var key = JSON.stringify(params);
      if (seen[key]) return;
      seen[key] = true;
      attempts.push(params);
    }
    // Without a recognised country code, a typed country still helps as text.
    function q(arr) { return arr.concat(!cc && country ? [country] : []).filter(Boolean).join(", "); }
    var pcPart = pc ? [pc] : [];
    if (lines.length) add({ q: q(lines.concat(pcPart)) });
    if (lines.length > 1) add({ q: q(lines.slice(1).concat(pcPart)) });           // drop a house-name / flat line
    if (pc) add(!cc && country ? { postalcode: pc, country: country } : { postalcode: pc });
    if (lines.length > 1) add({ q: q([lines[lines.length - 1]]) });              // just the town
    if (!lines.length && !pc && country) add({ q: country });

    var reached = false;
    function next(i) {
      if (i >= attempts.length) return Promise.resolve([]);
      return nominatim(attempts[i]).then(function (hits) {
        reached = true;
        return hits.length ? hits : next(i + 1);
      }, function () {
        return next(i + 1);
      });
    }
    return first.then(function (hits) {
      if (hits.length) return hits;
      return next(0).then(function (hits2) {
        if (!hits2.length && !reached && attempts.length) {
          var err = new Error("unreachable");
          err.unreachable = true;
          throw err;
        }
        return hits2;
      });
    });
  }

  /** Move a map to a search result, zooming to fit the place's own size
   *  (a whole country, a city, a street) rather than one fixed zoom. */
  function placeResult(map, result) {
    if (!map || !result) return;
    if (result.bounds) {
      map.fitBounds(result.bounds, { maxZoom: 17, padding: [10, 10] });
    } else {
      map.setView([result.lat, result.lng], result.zoom || 15);
    }
  }

  // Points along the great circle between two [lat,lng] points, so a move
  // abroad is drawn as the curved route a flight would take (and the
  // longitudes stay continuous across the date line). Short hops within a
  // country come back as a plain straight segment.
  function greatCircle(a, b) {
    var R = Math.PI / 180;
    var la1 = a[0] * R, lo1 = a[1] * R, la2 = b[0] * R, lo2 = b[1] * R;
    var d = 2 * Math.asin(Math.sqrt(Math.pow(Math.sin((la2 - la1) / 2), 2) +
      Math.cos(la1) * Math.cos(la2) * Math.pow(Math.sin((lo2 - lo1) / 2), 2)));
    if (!(d > 0.05)) return [a, b]; // under ~300km: straight is fine
    var steps = Math.min(64, Math.max(8, Math.round(d / 0.05)));
    var out = [], prevLng = a[1];
    for (var i = 0; i <= steps; i++) {
      var f = i / steps;
      var A = Math.sin((1 - f) * d) / Math.sin(d), B = Math.sin(f * d) / Math.sin(d);
      var x = A * Math.cos(la1) * Math.cos(lo1) + B * Math.cos(la2) * Math.cos(lo2);
      var y = A * Math.cos(la1) * Math.sin(lo1) + B * Math.cos(la2) * Math.sin(lo2);
      var z = A * Math.sin(la1) + B * Math.sin(la2);
      var lat = Math.atan2(z, Math.sqrt(x * x + y * y)) / R;
      var lng = Math.atan2(y, x) / R;
      while (lng - prevLng > 180) lng -= 360;
      while (lng - prevLng < -180) lng += 360;
      prevLng = lng;
      out.push([lat, lng]);
    }
    return out;
  }

  /**
   * The route through a list of [lat,lng] points in order (homes in the
   * order they were lived in): each move longer than ~300km follows the
   * great circle, and each point is moved onto whichever copy of the world
   * keeps the line continuous, so California -> Japan crosses the Pacific
   * rather than running back across the whole map.
   * Returns {pins: [[lat,lng]...] (same order as input), path: [...], mids: [...]}.
   */
  function journey(points) {
    var pins = [], path = [], mids = [];
    points.forEach(function (p) {
      var here = [p[0], p[1]];
      if (pins.length) {
        var prev = pins[pins.length - 1];
        var lng = p[1];
        while (lng - prev[1] > 180) lng -= 360;
        while (lng - prev[1] < -180) lng += 360;
        var seg = greatCircle(prev, [p[0], lng]);
        here = [p[0], seg[seg.length - 1][1]];
        seg[seg.length - 1] = here;
        path = path.concat(path.length ? seg.slice(1) : seg);
        mids.push(seg.length === 2
          ? [(seg[0][0] + seg[1][0]) / 2, (seg[0][1] + seg[1][1]) / 2]
          : seg[Math.floor(seg.length / 2)]);
      }
      pins.push(here);
    });
    return { pins: pins, path: path, mids: mids };
  }

  /** lng moved onto the world copy nearest any of refLngs (unchanged if none). */
  function nearestCopy(lng, refLngs) {
    var out = lng, best = Infinity;
    (refLngs || []).forEach(function (r) {
      var cand = lng + 360 * Math.round((r - lng) / 360);
      if (Math.abs(cand - r) < best) { best = Math.abs(cand - r); out = cand; }
    });
    return out;
  }

  var leafletPromise = null;
  function loadLeaflet() {
    if (window.L) return Promise.resolve(window.L);
    if (leafletPromise) return leafletPromise;
    leafletPromise = new Promise(function (resolve, reject) {
      var css = document.createElement("link");
      css.rel = "stylesheet";
      css.href = "/assets/leaflet/leaflet.css?v=1.9.4";
      document.head.appendChild(css);
      var s = document.createElement("script");
      s.src = "/assets/leaflet/leaflet.js?v=1.9.4";
      s.onload = function () { resolve(window.L); };
      s.onerror = function () { leafletPromise = null; reject(new Error("leaflet")); };
      document.head.appendChild(s);
    });
    return leafletPromise;
  }

  // ------------------------------------------------------------------
  // Phase 100: English-language maps, and "which country is this?"
  //
  // OpenStreetMap's own tiles label every country in its local language
  // (日本, Россия, Ελλάδα...). Every map on the site now uses a label-free
  // background (Esri's Light Gray Canvas -- free, no key, warmed slightly
  // to suit the site) with our own English country labels drawn on top,
  // from Natural Earth's public-domain borders (assets/world/countries.json,
  // built by tools/world_countries_build.js). Zoomed in past country level
  // (7+), Esri's matching English reference layer adds towns and streets.
  //
  // The same borders answer "which country is this pin in?" for the life
  // maps' country counter -- worked out in the browser, so it covers every
  // home and memory already saved, with no lookups to any other service.
  var BASE_URL = "https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}";
  var REF_URL = "https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Reference/MapServer/tile/{z}/{y}/{x}";
  var ATTR = 'Tiles &copy; <a href="https://www.esri.com/">Esri</a> &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors &middot; Borders: Natural Earth';
  var COUNTRY_LABEL_MAX_ZOOM = 6;
  var WORLD_COUNTRY_TOTAL = 195; // the 193 UN members plus the Holy See and Palestine

  var worldPromise = null;
  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement("script");
      s.src = src; s.onload = resolve; s.onerror = function () { reject(new Error(src)); };
      document.head.appendChild(s);
    });
  }
  /** Promise<{features: [{key, bbox, polys}], meta: {key: {n, s, l, a}}}> -- loaded once, then cached. */
  function world() {
    if (worldPromise) return worldPromise;
    worldPromise = (window.topojson ? Promise.resolve() : loadScript("/assets/world/topojson-client.min.js?v=3.1.0"))
      .then(function () { return fetch("/assets/world/countries.json?v=2", { credentials: "same-origin" }); })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var fc = window.topojson.feature(data.topo, data.topo.objects.countries);
        var feats = fc.features.filter(function (f) { return f.geometry; }).map(function (f) {
          var polys = f.geometry.type === "Polygon" ? [f.geometry.coordinates] : f.geometry.coordinates;
          var b = [180, 90, -180, -90];
          polys.forEach(function (p) {
            p[0].forEach(function (c) {
              if (c[0] < b[0]) b[0] = c[0]; if (c[1] < b[1]) b[1] = c[1];
              if (c[0] > b[2]) b[2] = c[0]; if (c[1] > b[3]) b[3] = c[1];
            });
          });
          return { key: f.id, bbox: b, polys: polys };
        });
        return { features: feats, meta: data.meta };
      })
      .catch(function (e) { worldPromise = null; throw e; });
    return worldPromise;
  }
  function inRing(x, y, ring) {
    var inside = false;
    for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      var xi = ring[i][0], yi = ring[i][1], xj = ring[j][0], yj = ring[j][1];
      if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) inside = !inside;
    }
    return inside;
  }
  /** The country key ("GB", "US", "CY-N"...) a point falls in, given a loaded world(), or null (sea). */
  function countryAt(w, lat, lng) {
    var x = wrapLng(lng), y = lat, hit = null;
    for (var i = 0; i < w.features.length && !hit; i++) {
      var f = w.features[i], b = f.bbox;
      if (x < b[0] || x > b[2] || y < b[1] || y > b[3]) continue;
      for (var k = 0; k < f.polys.length; k++) {
        var p = f.polys[k];
        if (inRing(x, y, p[0])) {
          var inHole = false;
          for (var h = 1; h < p.length; h++) { if (inRing(x, y, p[h])) { inHole = true; break; } }
          if (!inHole) { hit = f.key; break; }
        }
      }
    }
    if (hit) return hit;
    // A pin on a beach or a small island can land just offshore of the
    // simplified coastline: take the nearest country within ~25km.
    var best = null, bestD = 0.25 * 0.25;
    w.features.forEach(function (f) {
      var b = f.bbox;
      if (x < b[0] - 0.3 || x > b[2] + 0.3 || y < b[1] - 0.3 || y > b[3] + 0.3) return;
      f.polys.forEach(function (p) {
        var ring = p[0];
        for (var i = 0; i < ring.length; i++) {
          var dx = (ring[i][0] - x) * Math.cos(y * Math.PI / 180), dy = ring[i][1] - y, d = dx * dx + dy * dy;
          if (d < bestD) { bestD = d; best = f.key; }
        }
      });
    });
    return best;
  }
  /**
   * Phase 100: the country a hand-dropped pin is in, from OpenStreetMap
   * (country-level reverse lookup, one request, spaced like the searches).
   * Promise<"GB"|"" > -- "" when it's at sea or the service can't be reached.
   */
  function reverseCountry(lat, lng) {
    var gap = 1100 - (Date.now() - lastNominatim);
    return wait(gap > 0 ? gap : 0).then(function () {
      lastNominatim = Date.now();
      return fetch("https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=3&addressdetails=1&accept-language=en&lat=" + lat + "&lon=" + wrapLng(lng));
    }).then(function (r) { return r.json(); }).then(function (j) {
      return j && j.address && j.address.country_code ? String(j.address.country_code).toUpperCase() : "";
    }).catch(function () { return ""; });
  }

  /**
   * The countries (sovereign states -- Hong Kong counts as China, Greenland
   * as Denmark...) a set of places are in. Each place is {lat, lng} and/or
   * {country: typed country name}; a typed country wins over the pin (the
   * person said so; a pin near a border can fall either side of our
   * simplified borders). Returns [{code, name}] sorted by name.
   */
  function countriesFor(w, places) {
    var seen = {};
    (places || []).forEach(function (pl) {
      var key = null;
      var typed = pl.country ? countryCode(pl.country) : "";
      var saved = pl.cc && /^[A-Z]{2}$/.test(pl.cc) ? pl.cc : "";
      if (typed) key = typed;
      else if (saved) key = saved;          // the address lookup's own answer when the pin was placed
      else if (pl.lat !== null && pl.lat !== undefined && pl.lng !== null && pl.lng !== undefined) key = countryAt(w, pl.lat, pl.lng);
      if (!key) return;
      var m = w.meta[key];
      // territories with no outline of their own in the map data
      var extra = { BV: "NO", SJ: "NO", CX: "AU", CC: "AU", GF: "FR", GP: "FR", MQ: "FR", YT: "FR", RE: "FR", TK: "NZ", BQ: "NL" };
      var sov = m ? m.s : (extra[key] || key);
      if (!sov) return;                     // Antarctica, disputed areas
      seen[sov] = true;
    });
    return Object.keys(seen).map(function (code) {
      var m = w.meta[code];
      var n = m ? m.n : null;
      if (!n) { for (var i = 0; i < countries.length; i++) { if (countries[i].code === code) { n = countries[i].name; break; } } }
      return { code: code, name: n || code };
    }).sort(function (a, b) { return a.name.localeCompare(b.name); });
  }

  /**
   * Background + English labels for a Leaflet map. Every map on the site
   * calls this instead of adding a tile layer of its own.
   */
  function addBaseLayers(map) {
    var L = window.L;
    L.tileLayer(BASE_URL, { maxZoom: 19, maxNativeZoom: 16, attribution: ATTR, className: "og-base-tiles" }).addTo(map);
    var ref = L.tileLayer(REF_URL, { maxZoom: 19, maxNativeZoom: 16, className: "og-ref-tiles", pane: "overlayPane" });
    var labels = L.layerGroup();
    var labelData = null;
    function place() {
      var z = map.getZoom();
      if (z > COUNTRY_LABEL_MAX_ZOOM) {
        if (map.hasLayer(labels)) map.removeLayer(labels);
        if (!map.hasLayer(ref)) ref.addTo(map);
        return;
      }
      if (map.hasLayer(ref)) map.removeLayer(ref);
      if (!labelData) return;
      labels.clearLayers();
      if (!map.hasLayer(labels)) labels.addTo(map);
      // bigger countries first; a label is only drawn where it has room
      var minArea = z <= 2 ? 60 : z === 3 ? 12 : z === 4 ? 2 : z === 5 ? 0.3 : 0;
      var shift = 360 * Math.round(map.getCenter().lng / 360); // follow the world copy in view
      var taken = [];
      var bounds = map.getBounds().pad(0.2);
      labelData.forEach(function (c) {
        if (c.a < minArea) return;
        var ll = L.latLng(c.l[0], c.l[1] + shift);
        if (!bounds.contains(ll)) return;
        var pt = map.latLngToContainerPoint(ll);
        // spaced capitals: ~8px a letter at 11px (a bit more for the big ones), plus breathing room
        var w = c.n.length * (c.a > 60 ? 9.2 : 8.2) + 14, h = 18;
        var box = [pt.x - w / 2, pt.y - h / 2, pt.x + w / 2, pt.y + h / 2];
        var size = map.getSize();
        if (box[0] < 2 || box[2] > size.x - 2 || box[1] < 2 || box[3] > size.y - 2) return; // never half cut off at the edge
        for (var i = 0; i < taken.length; i++) {
          var t = taken[i];
          if (box[0] < t[2] && box[2] > t[0] && box[1] < t[3] && box[3] > t[1]) return;
        }
        taken.push(box);
        L.marker(ll, {
          interactive: false, keyboard: false,
          icon: L.divIcon({ className: "og-country-label" + (c.a > 60 ? " big" : ""), html: "<span>" + c.n.replace(/&/g, "&amp;").replace(/</g, "&lt;") + "</span>", iconSize: [w, h], iconAnchor: [w / 2, h / 2] })
        }).addTo(labels);
      });
    }
    map.on("zoomend moveend", place);
    world().then(function (w) {
      labelData = Object.keys(w.meta).map(function (k) { var m = w.meta[k]; return { n: m.n, l: m.l, a: m.a }; })
        .filter(function (c) { return c.n && c.n !== "Siachen Glacier"; })
        .sort(function (a, b) { return b.a - a.a; });
      place();
    }).catch(function () { /* labels are a nicety; the map still works */ });
    place();
    return map;
  }

  window.ourthologyGeo = {
    countries: countries,
    countryCode: countryCode,
    fillCountryList: fillCountryList,
    search: search,
    wrapLng: wrapLng,
    placeResult: placeResult,
    loadLeaflet: loadLeaflet,
    greatCircle: greatCircle,
    journey: journey,
    nearestCopy: nearestCopy,
    addBaseLayers: addBaseLayers,
    world: world,
    countryAt: countryAt,
    countriesFor: countriesFor,
    reverseCountry: reverseCountry,
    WORLD_COUNTRY_TOTAL: WORLD_COUNTRY_TOTAL,
    TILE_URL: BASE_URL,
    TILE_ATTR: ATTR
  };
})();
