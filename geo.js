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
    "espana": "ES", "italia": "IT", "rhodesia": "ZW", "ceylon": "LK", "persia": "IR", "zaire": "CD"
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
      return fetch("https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&accept-language=en&" + qs);
    }).then(function (r) { return r.json(); }).then(function (arr) {
      return (arr || []).map(function (h) {
        var bb = h.boundingbox ? h.boundingbox.map(parseFloat) : null; // [south, north, west, east]
        return {
          lat: parseFloat(h.lat), lng: parseFloat(h.lon), label: h.display_name || "",
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
            return [{ lat: j.result.latitude, lng: j.result.longitude, label: pc.toUpperCase() + (j.result.admin_district ? ", " + j.result.admin_district : ""), bounds: null, zoom: 16 }];
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
    TILE_URL: "https://tile.openstreetmap.org/{z}/{x}/{y}.png",
    TILE_ATTR: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  };
})();
