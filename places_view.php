<?php
declare(strict_types=1);
// Phase 92: the HTML/JS for places.php, split out only to keep that file
// readable -- never reached directly (it has no data of its own).
if (!isset($jsHomes, $family, $mapPersonId)) {
    http_response_code(404);
    exit('Not found.');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Places we lived — ourthology.com</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,600;0,700;0,800;1,600&family=Newsreader:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css?v=27">
<!-- Leaflet (map library) is served from this site rather than a CDN, so
     the map never depends on a third-party script host. Map tiles come
     from OpenStreetMap. -->
<link rel="stylesheet" href="/assets/leaflet/leaflet.css?v=1.9.4">
<script src="/assets/leaflet/leaflet.js?v=1.9.4"></script>
<script src="/chunked_upload.js?v=1"></script>
<style>
  body { align-items:flex-start; }
  .wide { max-width:min(95vw,1700px); }
  .nav { display:flex; gap:10px 16px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin:18px 0 4px; }
  .nav-links { display:flex; gap:10px; flex-wrap:wrap; }
  .whoami { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12.5px; color:var(--ink-faint); }
  .whoami strong { color:var(--ink-soft); font-weight:600; }
  .whoami form { display:inline; }
  .linklet { font-size:12.5px; background:transparent; border:none; color:var(--accent); cursor:pointer; padding:0; text-decoration:underline; }
  h1.page-title { font-family:"Fraunces",Georgia,serif; font-size:26px; margin:18px 0 4px; }
  .page-sub { color:var(--ink-soft); font-size:14px; margin:0 0 16px; }
  .flash { font-size:13px; background:#fff; border:1px solid var(--line); border-radius:8px; padding:8px 12px; margin:8px 0 14px; }
  .flash.err { border-color:var(--error-bg); background:var(--error-bg); color:var(--accent); }
  .flash.good { border-color:var(--accent); color:var(--accent); }

  .places-toolbar { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin:0 0 14px; }
  .places-toolbar label { margin:0; font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--ink-faint); }
  .places-toolbar select { width:auto; min-width:220px; }
  .pl-btn { font-size:13.5px; font-weight:700; padding:8px 16px; border-radius:999px; border:1px solid var(--accent); background:var(--accent); color:var(--on-accent); cursor:pointer; font-family:inherit; }
  .pl-btn:hover { background:var(--accent-glow); }
  .pl-btn.ghost { background:#fff; color:var(--accent); }
  .pl-btn.small { font-size:12.5px; padding:6px 12px; }

  .places-layout { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(320px,1fr); gap:18px; align-items:start; }
  @media (max-width:900px) { .places-layout { grid-template-columns:1fr; } }
  #placesMap { height:560px; border-radius:18px; border:2px solid var(--accent); box-shadow:var(--shadow); background:var(--paper-2); position:sticky; top:12px; z-index:1; }
  @media (max-width:900px) { #placesMap { height:360px; position:relative; top:0; } }
  .map-note { font-size:12px; color:var(--ink-faint); margin:6px 2px 0; }

  .home-list { display:flex; flex-direction:column; gap:12px; }
  .home-card { background:var(--card); border:1px solid var(--line); border-radius:14px; overflow:hidden; transition:border-color .15s, box-shadow .15s; }
  .home-card.open { border-color:var(--accent); box-shadow:var(--shadow); }
  .home-head { display:flex; gap:12px; align-items:center; padding:12px 14px; cursor:pointer; background:transparent; border:0; width:100%; text-align:left; font-family:inherit; color:inherit; }
  .home-num { flex:none; width:30px; height:30px; border-radius:50%; background:var(--accent); color:var(--on-accent); font-weight:800; display:flex; align-items:center; justify-content:center; font-size:13px; }
  .home-num.nomap { background:var(--ink-faint); }
  .home-thumb { flex:none; width:56px; height:56px; border-radius:10px; background:var(--paper-2) center/cover no-repeat; border:1px solid var(--line); }
  .home-title { font-family:"Fraunces",Georgia,serif; font-weight:700; font-size:16px; line-height:1.2; }
  .home-dates { font-size:12.5px; color:var(--ink-soft); margin-top:3px; }
  .home-chev { margin-left:auto; color:var(--ink-faint); transition:transform .2s; }
  .home-card.open .home-chev { transform:rotate(180deg); }
  .home-body { display:none; padding:0 16px 16px; border-top:1px solid var(--line); }
  .home-card.open .home-body { display:block; }
  .home-body h4 { font-size:11.5px; text-transform:uppercase; letter-spacing:.07em; color:var(--ink-faint); margin:14px 0 6px; }
  .home-addr { white-space:pre-line; font-size:14px; margin-top:12px; }
  .home-notes { white-space:pre-line; font-size:14px; color:var(--ink-soft); }
  .home-body .photo-strip { margin-top:10px; }
  .photo-strip { display:grid; grid-template-columns:repeat(auto-fill,minmax(92px,1fr)); gap:8px; }
  .photo-strip button { padding:0; border:1px solid var(--line); border-radius:10px; overflow:hidden; aspect-ratio:1; background:var(--paper-2); cursor:zoom-in; }
  .photo-strip img { width:100%; height:100%; object-fit:cover; display:block; }
  .residents-list { list-style:none; padding:0; margin:0; font-size:13.5px; }
  .residents-list li { padding:4px 0; border-bottom:1px dashed var(--line); display:flex; justify-content:space-between; gap:10px; }
  .residents-list a { color:var(--ink); }
  .residents-list span { color:var(--ink-faint); font-size:12.5px; white-space:nowrap; }
  .update-item { border-left:3px solid var(--accent); padding:4px 0 6px 12px; margin:10px 0; }
  .update-item .u-date { font-size:12px; color:var(--ink-faint); }
  .update-item .u-title { font-weight:700; font-size:14.5px; }
  .update-item .u-notes { font-size:13.5px; color:var(--ink-soft); white-space:pre-line; margin:3px 0 6px; }
  .home-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; }
  .vis-tag { display:inline-block; font-size:11px; padding:2px 8px; border-radius:999px; background:var(--paper-2); color:var(--ink-soft); margin-left:6px; vertical-align:middle; }
  .empty-places { background:var(--paper-2); border:1px dashed var(--line); border-radius:14px; padding:22px; text-align:center; color:var(--ink-soft); font-size:14px; }

  /* numbered map pins */
  .pl-pin { width:30px; height:30px; border-radius:50% 50% 50% 0; background:var(--accent); transform:rotate(-45deg); border:2px solid #fff; box-shadow:0 3px 8px rgba(0,0,0,.35); display:flex; align-items:center; justify-content:center; }
  .pl-pin span { transform:rotate(45deg); color:#fff; font-weight:800; font-size:12.5px; font-family:system-ui,sans-serif; }
  .pl-pin.active { background:#1f2a44; }
  /* Phase 93: memories with a place -- small gold dots, a second layer under the numbered homes. */
  .mem-pin { width:14px; height:14px; border-radius:50%; background:#C98A1B; border:2px solid #fff; box-shadow:0 1px 4px rgba(0,0,0,.4); }
  .pl-back { margin-left:auto; font-size:13.5px; font-weight:700; color:var(--accent); }
  .mem-toggle { display:flex; align-items:center; gap:7px; font-size:13px; color:var(--ink-soft); margin-top:4px; text-transform:none; font-weight:400; letter-spacing:normal; cursor:pointer; }
  .mem-toggle[hidden] { display:none; }
  .mem-dot { width:11px; height:11px; border-radius:50%; background:#C98A1B; border:2px solid #fff; box-shadow:0 0 0 1px #C98A1B; }
  .mem-pop { display:flex; gap:10px; align-items:flex-start; max-width:240px; }
  .mem-pop img { width:56px; height:56px; object-fit:cover; border-radius:8px; flex:none; }
  .mem-pop b { display:block; font-size:13.5px; }
  .mem-pop small { display:block; color:#6b625a; margin:2px 0 4px; }
  .leaflet-popup-content { font-family:inherit; font-size:13px; margin:10px 12px; }
  .leaflet-popup-content b { font-family:"Fraunces",Georgia,serif; font-size:14.5px; }

  /* editor */
  .pl-overlay { position:fixed; inset:0; background:rgba(20,17,14,.55); z-index:1000; display:none; align-items:flex-start; justify-content:center; overflow:auto; padding:24px 12px; }
  .pl-overlay.open { display:flex; }
  .pl-box { background:var(--card); border:2px solid var(--accent); border-radius:18px; width:min(980px,100%); padding:22px 24px; position:relative; box-shadow:0 24px 60px -18px rgba(0,0,0,.5); }
  .pl-close { position:absolute; top:10px; right:14px; background:transparent; border:0; font-size:26px; cursor:pointer; color:var(--ink-soft); }
  .pl-box h2 { font-family:"Fraunces",Georgia,serif; margin:0 0 12px; font-size:22px; }
  .pl-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
  @media (max-width:760px) { .pl-grid { grid-template-columns:1fr; } }
  .pl-box label { display:block; }
  .pl-box textarea { width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:8px; font:inherit; font-size:14px; resize:vertical; background:#fff; }
  #editMap { height:260px; border-radius:12px; border:1px solid var(--line); margin-top:8px; }
  .geo-row { display:flex; gap:8px; align-items:center; margin-top:8px; flex-wrap:wrap; }
  .geo-msg { font-size:12.5px; color:var(--ink-soft); }
  .geo-choices { display:flex; flex-direction:column; gap:4px; margin-top:6px; }
  .geo-choices-h { font-size:12px; color:var(--ink-faint); }
  .geo-choices button { text-align:left; font:inherit; font-size:12.5px; line-height:1.35; padding:6px 9px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--ink-soft); cursor:pointer; }
  .geo-choices button:hover { border-color:var(--accent); }
  .geo-choices button.on { border-color:var(--accent); background:#F1DCDC; color:var(--ink); }
  .date-trio { display:grid; grid-template-columns:3.4em 3.4em 5em; gap:6px; }
  .date-trio input { text-align:center; padding:7px 4px; }
  .res-list { max-height:280px; overflow:auto; border:1px solid var(--line); border-radius:10px; padding:4px 10px; background:#fff; }
  .res-row { border-bottom:1px dashed var(--line); padding:7px 0; }
  .res-row:last-child { border-bottom:0; }
  .res-row > label { display:flex; gap:8px; align-items:center; text-transform:none; letter-spacing:normal; font-size:14px; font-weight:600; margin:0; color:var(--ink); }
  .res-dates { display:none; gap:14px; flex-wrap:wrap; margin:6px 0 2px 24px; }
  .res-row.on .res-dates { display:flex; }
  .res-dates small { display:block; font-size:11px; color:var(--ink-faint); margin-bottom:3px; text-transform:uppercase; letter-spacing:.05em; }
  .vis-row { display:flex; gap:14px; flex-wrap:wrap; font-size:14px; }
  .vis-row label { display:flex; gap:6px; align-items:center; text-transform:none; letter-spacing:normal; margin:0; font-weight:500; }
  .hint { font-size:12px; color:var(--ink-faint); margin:4px 0 0; }
  .pk { display:grid; grid-template-columns:repeat(auto-fill,minmax(78px,1fr)); gap:8px; margin-top:6px; }
  .pk-tile { position:relative; aspect-ratio:1; border-radius:10px; overflow:hidden; border:1px solid var(--line); background:var(--paper-2); }
  .pk-tile img { width:100%; height:100%; object-fit:cover; display:block; }
  .pk-tile .pk-x { position:absolute; top:3px; right:3px; width:22px; height:22px; border-radius:50%; border:0; background:rgba(0,0,0,.6); color:#fff; cursor:pointer; line-height:22px; font-size:15px; padding:0; }
  .pk-add { aspect-ratio:1; border:2px dashed var(--line); border-radius:10px; background:#fff; color:var(--accent); font-size:26px; cursor:pointer; display:flex; align-items:center; justify-content:center; }
  .pk-add small { display:block; font-size:10.5px; color:var(--ink-faint); }
  .pk.dragover .pk-add { border-color:var(--accent); background:var(--paper-2); }
  .upd-row { border:1px solid var(--line); border-radius:12px; padding:12px; margin-top:10px; background:var(--paper); }
  .upd-top { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
  .upd-top .grow { flex:1 1 220px; }
  .upd-rm { margin-left:auto; background:transparent; border:0; color:var(--accent); cursor:pointer; font-size:13px; text-decoration:underline; }
  .pl-footer { display:flex; gap:10px; align-items:center; justify-content:flex-end; margin-top:18px; flex-wrap:wrap; }
  .pl-error { color:var(--accent); font-size:13.5px; margin-right:auto; }
  .pl-progress { font-size:13px; color:var(--ink-soft); margin-right:auto; }

  .pl-lightbox { position:fixed; inset:0; background:rgba(10,8,6,.88); z-index:1600; display:none; align-items:center; justify-content:center; }
  .pl-lightbox.open { display:flex; }
  .pl-lightbox img { max-width:92vw; max-height:86vh; border-radius:8px; }
  .pl-lightbox button { position:absolute; background:rgba(255,255,255,.15); border:0; color:#fff; font-size:30px; width:48px; height:48px; border-radius:50%; cursor:pointer; }
  .pl-lb-close { top:16px; right:16px; } .pl-lb-prev { left:16px; } .pl-lb-next { right:16px; }
  .pl-lb-cap { position:absolute; bottom:18px; color:#ddd; font-size:13px; }
</style>
</head>
<body>
  <div class="card wide">
    <div class="brand" style="display:flex;align-items:center;gap:14px;margin:0 0 22px;">
      <svg class="brand-mark" width="44" height="44" viewBox="0 0 32 32" aria-hidden="true" style="flex:none;display:block;">
        <circle cx="16" cy="16" r="15" fill="#9A2A2A"/>
        <path d="M16 7 C10 8 6.3 12.6 7.4 17.2 C11.2 16.5 14.7 12.6 16 7 Z" fill="#FBF8F1"/>
        <path d="M16 7 C22 8 25.7 12.6 24.6 17.2 C20.8 16.5 17.3 12.6 16 7 Z" fill="#FBF8F1"/>
        <line x1="16" y1="7.2" x2="16" y2="17" stroke="#9A2A2A" stroke-width="1" stroke-linecap="round"/>
        <line x1="16" y1="17" x2="16" y2="23.2" stroke="#FBF8F1" stroke-width="2.2" stroke-linecap="round"/>
        <line x1="16" y1="23.2" x2="12.6" y2="26.6" stroke="#FBF8F1" stroke-width="1.6" stroke-linecap="round"/>
        <line x1="16" y1="23.2" x2="19.4" y2="26.6" stroke="#FBF8F1" stroke-width="1.6" stroke-linecap="round"/>
      </svg>
      <div class="brand-text" style="display:flex;flex-direction:column;">
        <p class="wordmark" style="margin:0;">ourthology<span class="tld">.com</span></p>
        <p class="subtitle" style="margin:3px 0 0;">an anthology of us.</p>
      </div>
    </div>

    <div class="nav">
      <div class="nav-links">
        <?= ourthology_render_primary_nav('', $pendingCount) ?>
      </div>
      <div class="whoami">
        Signed in as <strong><?= htmlspecialchars($me['email'], ENT_QUOTES) ?></strong>
        <form method="post" action="/logout.php"><button type="submit" class="linklet">Log out</button></form>
      </div>
    </div>

    <h1 class="page-title"><?= $isMine ? 'Places I\'ve lived' : 'Places ' . htmlspecialchars($mapPersonName, ENT_QUOTES) . ' lived' ?></h1>
    <p class="page-sub">Every home on one map, joined up in the order it was lived in. Click a pin or a home to open its story — who lived there and when, photos, and how it changed over the years.</p>

    <?php if ($flashError): ?><div class="flash err"><?= htmlspecialchars($flashError, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($flashNotice): ?><div class="flash good"><?= htmlspecialchars($flashNotice, ENT_QUOTES) ?></div><?php endif; ?>

    <div class="places-toolbar">
      <label for="personPicker">Whose map</label>
      <select id="personPicker">
        <?php foreach ($family as $f): ?>
          <option value="<?= $f['id'] ?>" <?= $f['id'] === $mapPersonId ? 'selected' : '' ?>><?= htmlspecialchars($f['name'], ENT_QUOTES) ?><?= $f['id'] === $myPersonId ? ' (you)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="pl-btn" id="addHomeBtn">+ Add a home</button>
      <a class="pl-back" href="/timeline.php<?= $isMine ? '' : '?person_id=' . $mapPersonId ?>#map=lived">&larr; Back to the map on <?= $isMine ? 'my' : htmlspecialchars($mapPersonName, ENT_QUOTES) . '&rsquo;s' ?> timeline</a>
    </div>

    <div class="places-layout">
      <div>
        <div id="placesMap" role="region" aria-label="Map of homes"></div>
        <p class="map-note" id="mapNote"></p>
        <label class="mem-toggle" id="memToggleWrap" hidden><input type="checkbox" id="memToggle" checked> <span class="mem-dot" aria-hidden="true"></span> Show memories with a place <span id="memCount"></span></label>
      </div>
      <div class="home-list" id="homeList"></div>
    </div>
  </div>

  <!-- editor -->
  <div class="pl-overlay" id="editOverlay" aria-hidden="true">
    <div class="pl-box" role="dialog" aria-modal="true" aria-labelledby="editTitle">
      <button type="button" class="pl-close" id="editClose" aria-label="Close">×</button>
      <h2 id="editTitle">Add a home</h2>
      <form method="post" action="/places.php" id="homeForm" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="home_id" id="fHomeId" value="">
        <input type="hidden" name="map_person_id" value="<?= $mapPersonId ?>">
        <input type="hidden" name="lat" id="fLat" value="">
        <input type="hidden" name="lng" id="fLng" value="">
        <div class="pl-grid">
          <div>
            <label for="fName">Name <span style="text-transform:none;font-weight:400;">(optional — e.g. "Rose Cottage" or "Our first flat")</span></label>
            <input type="text" id="fName" name="name" maxlength="120">
            <label for="fAddress" style="margin-top:12px;">Address</label>
            <textarea id="fAddress" name="address" rows="3" maxlength="1000"></textarea>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">
              <div><label for="fPostcode">Postcode / ZIP</label><input type="text" id="fPostcode" name="postcode" maxlength="20" autocomplete="off"></div>
              <div><label for="fCountry">Country</label><input type="text" id="fCountry" name="country" maxlength="80" list="countryList" autocomplete="off" placeholder="Start typing…"><datalist id="countryList"></datalist></div>
            </div>
            <div class="geo-row">
              <button type="button" class="pl-btn ghost small" id="geoBtn">Find on map</button>
              <span class="geo-msg" id="geoMsg">Anywhere in the world — or click the map to drop the pin yourself, and drag it to adjust.</span>
            </div>
            <div class="geo-choices" id="geoChoices" hidden></div>
            <div id="editMap"></div>
          </div>
          <div>
            <label>Who lived here, and when?</label>
            <div class="res-list" id="resList"></div>
            <p class="hint">Dates can be just a year, a month and year, or the full date — whatever you remember. The home appears on the map of everyone ticked.</p>
            <label style="margin-top:14px;">Who can see it</label>
            <div class="vis-row">
              <label><input type="radio" name="visibility" value="public" checked> Family</label>
              <label><input type="radio" name="visibility" value="private"> Only people who lived here</label>
              <label><input type="radio" name="visibility" value="custom"> Custom</label>
            </div>
            <label for="fNotes" style="margin-top:14px;">About this home <span style="text-transform:none;font-weight:400;">(optional)</span></label>
            <textarea id="fNotes" name="notes" rows="3" maxlength="5000" placeholder="What you remember — the garden, the neighbours, why you moved…"></textarea>
          </div>
        </div>

        <label style="margin-top:16px;">Photos of the home</label>
        <div class="pk" id="generalPicker" data-kept-name="kept_media_ids[]" data-staged-name="staged_media[]"></div>

        <label style="margin-top:18px;">How it changed <span style="text-transform:none;font-weight:400;">(an extension, a new kitchen, a repaint… each with its own date and photos)</span></label>
        <div id="updRows"></div>
        <button type="button" class="pl-btn ghost small" id="addUpdBtn" style="margin-top:10px;">+ Add a change</button>

        <div class="pl-footer">
          <span class="pl-error" id="formError"></span>
          <span class="pl-progress" id="formProgress"></span>
          <button type="button" class="pl-btn ghost" id="editCancel">Cancel</button>
          <button type="submit" class="pl-btn" id="saveBtn">Save home</button>
        </div>
      </form>
    </div>
  </div>

  <form method="post" action="/places.php" id="actionForm" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="action" id="aAction" value="">
    <input type="hidden" name="home_id" id="aHomeId" value="">
    <input type="hidden" name="map_person_id" value="<?= $mapPersonId ?>">
  </form>

  <div class="pl-lightbox" id="lightbox" aria-hidden="true">
    <img id="lbImg" alt="">
    <button type="button" class="pl-lb-prev" id="lbPrev" aria-label="Previous">‹</button>
    <button type="button" class="pl-lb-next" id="lbNext" aria-label="Next">›</button>
    <button type="button" class="pl-lb-close" id="lbClose" aria-label="Close">×</button>
    <div class="pl-lb-cap" id="lbCap"></div>
  </div>

  <script type="application/json" id="placesData"><?= json_encode([
      'homes' => $jsHomes, 'family' => $family, 'mapPersonId' => $mapPersonId,
      'myPersonId' => $myPersonId, 'openHomeId' => $openHomeId !== false ? (int) $openHomeId : null,
      'memories' => $jsMemories, 'openMemoryId' => $openMemoryId !== false ? (int) $openMemoryId : null,
  ], $jsonFlags) ?></script>
  <script src="/date_autotab.js?v=1"></script>
  <script src="/geo.js?v=2"></script>
  <script src="/places.js?v=3"></script>
  <?php ourthology_render_tour('places', $myPersonId); ?>
</body>
</html>
