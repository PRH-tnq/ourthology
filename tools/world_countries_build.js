// Phase 100 build: assets/world/countries.json for the life maps' country
// counter and English country labels. Source: Natural Earth 1:10m admin-0
// (public domain) via world-atlas, simplified with mapshaper.
const fs = require('fs');
const topo = require('./t10.json'); // Natural Earth 1:10m, simplified to 10% (mapshaper keep-shapes)
const iso = require('i18n-iso-countries');
const polylabel = require('polylabel');
const { feature } = require('topojson-client');

const NO_ID = { 'Ashmore and Cartier Is.': 'AU-AC', 'Dhekelia': 'GB-DK', 'Akrotiri': 'GB-AK', 'USNB Guantanamo Bay': 'US-GTMO', 'Cyprus U.N. Buffer Zone': 'CY-UN', 'Baikonur': 'KZ-BK', 'Coral Sea Is.': 'AU-CS', 'Spratly Is.': 'SPRATLY', 'Clipperton I.': 'FR-CP', 'Bajo Nuevo Bank': 'BAJO', 'Serranilla Bank': 'SERRANILLA', 'Scarborough Reef': 'SCARBOROUGH', 'Somaliland': 'SO-SL', 'Kosovo': 'XK', 'N. Cyprus': 'CY-N', 'Indian Ocean Ter.': 'AU-IOT', 'Siachen Glacier': 'SIACHEN' };
// English display names where Natural Earth abbreviates
const NAMES = {
  'Marshall Is.': 'Marshall Islands', 'N. Mariana Is.': 'Northern Mariana Islands', 'U.S. Virgin Is.': 'US Virgin Islands',
  'S. Geo. and the Is.': 'South Georgia', 'Br. Indian Ocean Ter.': 'British Indian Ocean Territory', 'Pitcairn Is.': 'Pitcairn Islands',
  'Falkland Is.': 'Falkland Islands', 'Cayman Is.': 'Cayman Islands', 'British Virgin Is.': 'British Virgin Islands',
  'Turks and Caicos Is.': 'Turks and Caicos Islands', 'S. Sudan': 'South Sudan', 'Solomon Is.': 'Solomon Islands',
  'St. Vin. and Gren.': 'St Vincent and the Grenadines', 'St. Kitts and Nevis': 'St Kitts and Nevis', 'Cook Is.': 'Cook Islands',
  'W. Sahara': 'Western Sahara', 'St. Pierre and Miquelon': 'St Pierre and Miquelon', 'Wallis and Futuna Is.': 'Wallis and Futuna',
  'Fr. Polynesia': 'French Polynesia', 'Fr. S. Antarctic Lands': 'French Southern Lands', 'Eq. Guinea': 'Equatorial Guinea',
  'Dominican Rep.': 'Dominican Republic', 'Faeroe Is.': 'Faroe Islands', 'N. Cyprus': 'Northern Cyprus',
  'Dem. Rep. Congo': 'DR Congo', 'Central African Rep.': 'Central African Republic', 'Bosnia and Herz.': 'Bosnia and Herzegovina',
  'Indian Ocean Ter.': 'Christmas & Cocos Islands', 'Heard I. and McDonald Is.': 'Heard and McDonald Islands',
  'Ashmore and Cartier Is.': 'Ashmore and Cartier Islands', 'U.S. Minor Outlying Is.': 'US Minor Outlying Islands', 'Cyprus U.N. Buffer Zone': 'UN Buffer Zone', 'Coral Sea Is.': 'Coral Sea Islands', 'Spratly Is.': 'Spratly Islands', 'Clipperton I.': 'Clipperton Island', 'USNB Guantanamo Bay': 'Guantánamo Bay', 'Antigua and Barb.': 'Antigua and Barbuda',
  'United States of America': 'United States', 'Côte d\'Ivoire': 'Ivory Coast', 'eSwatini': 'Eswatini',
  'Macedonia': 'North Macedonia', 'Czech Rep.': 'Czechia', 'Timor-Leste': 'East Timor', 'Curaçao': 'Curaçao',
  'São Tomé and Principe': 'São Tomé and Príncipe', 'Lao PDR': 'Laos', 'Dem. Rep. Korea': 'North Korea', 'Korea': 'South Korea',
};
// Territories count towards the sovereign country they belong to; null = not counted as a country
const SOV = {
  PR: 'US', GU: 'US', VI: 'US', AS: 'US', MP: 'US', UM: 'US',
  GL: 'DK', FO: 'DK', HK: 'CN', MO: 'CN',
  NC: 'FR', PF: 'FR', WF: 'FR', PM: 'FR', BL: 'FR', MF: 'FR', TF: 'FR',
  GI: 'GB', IM: 'GB', JE: 'GB', GG: 'GB', BM: 'GB', KY: 'GB', VG: 'GB', TC: 'GB', MS: 'GB', AI: 'GB', FK: 'GB', SH: 'GB', PN: 'GB', GS: 'GB', IO: 'GB',
  AW: 'NL', CW: 'NL', SX: 'NL', CK: 'NZ', NU: 'NZ', NF: 'AU', HM: 'AU', CX: 'AU', CC: 'AU', 'AU-IOT': 'AU', 'AU-AC': 'AU', AX: 'FI', 'GB-DK': 'GB', 'GB-AK': 'GB', 'US-GTMO': 'US', 'CY-UN': 'CY', 'KZ-BK': 'KZ', 'AU-CS': 'AU', 'FR-CP': 'FR', SPRATLY: null, BAJO: null, SERRANILLA: null, SCARBOROUGH: null, BQ: 'NL', MF: 'FR', SX: 'NL', BL: 'FR', UM: 'US', NU: 'NZ', TK: 'NZ', YT: 'FR', RE: 'FR', GP: 'FR', MQ: 'FR', GF: 'FR', SJ: 'NO', BV: 'NO',
  'SO-SL': 'SO', 'CY-N': 'CY', AQ: null, EH: null, SIACHEN: null,
};

const geoms = topo.objects.countries.geometries;
const meta = {};
const seen = {};
geoms.forEach(function (g) {
  const ne = g.properties.name;
  let a2 = NO_ID[ne] || (g.id ? iso.numericToAlpha2(String(g.id).padStart(3, '0')) : null);
  if (!a2) { console.error('NO CODE', g.id, ne); a2 = 'X' + g.id; }
  if (seen[a2]) { console.error('DUP', a2, ne, seen[a2]); }
  seen[a2] = ne;
  const f = feature(topo, g);
  if (!f.geometry) { console.error('NO GEOMETRY', ne); return; }
  // label on the largest polygon, at its "pole of inaccessibility"
  const polys = f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : f.geometry.coordinates;
  let best = null, bestA = -1, total = 0;
  polys.forEach(function (p) {
    const ring = p[0]; let a = 0;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) a += (ring[j][0] + ring[i][0]) * (ring[j][1] - ring[i][1]);
    const lat = ring.reduce((s, c) => s + c[1], 0) / ring.length;
    a = Math.abs(a / 2) * Math.cos(lat * Math.PI / 180);
    total += a;
    if (a > bestA) { bestA = a; best = p; }
  });
  const lab = polylabel(best, 0.05);
  const name = NAMES[ne] || ne;
  const sov = Object.prototype.hasOwnProperty.call(SOV, a2) ? SOV[a2] : a2;
  meta[a2] = { n: name, s: sov, l: [Math.round(lab[1] * 1000) / 1000, Math.round(lab[0] * 1000) / 1000], a: Math.round(total * 100) / 100 };
  g.id = a2;
  delete g.properties;
});
// Too small to survive simplification as a shape -- keep its name/label
if (!meta.VA) meta.VA = { n: 'Vatican City', s: 'VA', l: [41.903, 12.453], a: 0 };
const out = { note: 'Country borders: Natural Earth 1:10m (public domain), simplified. Built by Phase 100 build.js.', topo: topo, meta: meta };
fs.writeFileSync('countries.json', JSON.stringify(out));
// sovereign codes that can be counted
const sovs = Array.from(new Set(Object.values(meta).map(m => m.s).filter(Boolean))).sort();
console.log('features', geoms.length, 'countable sovereigns', sovs.length);
console.log(sovs.join(' '));
