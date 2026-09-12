<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/memory_tags.php';

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Location: /login.php');
    exit;
}
$pdo = ourthology_pdo();
$myPersonId = (int) $me['person_id'];
$myGroup = (int) person_row($pdo, $myPersonId)['family_group_id'];

// Whose timeline this memory is being added to — defaults to yourself, but
// an unclaimed person's profile can be picked instead (a "+ Add a memory
// for them" link from edit_person.php, or the ?person_id= on this page's
// own URL) since nobody is logged in as an unclaimed person to write it
// themselves. person_is_editable_by() is the exact same "unclaimed, or
// your own claimed record" rule Phase 10 already uses for editing a
// person's own details — reused here so who may ADD a memory for someone
// and who may EDIT that person's record are always the same people.
$targetPersonId = filter_var($_GET['person_id'] ?? $_POST['target_person_id'] ?? $myPersonId, FILTER_VALIDATE_INT);
if ($targetPersonId === false) {
    http_response_code(400);
    exit('Bad request.');
}
$targetPerson = person_row($pdo, (int) $targetPersonId);
if ($targetPerson === null || (int) $targetPerson['family_group_id'] !== $myGroup
    || !person_is_editable_by($targetPerson, (int) $me['user_id'])) {
    http_response_code(403);
    exit("You don't have permission to add a memory for that person.");
}
$targetPersonId = (int) $targetPerson['id'];
$addingForSelf = $targetPersonId === $myPersonId;

// Everyone else in the family group, for the "tag people in this memory"
// picker below — never includes the target themselves (they're already
// the memory's owner, tagging them would be meaningless).
$familyGraph = fetch_family_graph($pdo, $myGroup);
$familyPersonsById = [];
foreach ($familyGraph['persons'] as $p) {
    $familyPersonsById[(int) $p['id']] = $p;
}
$taggablePeople = taggable_people($familyGraph['persons'], $targetPersonId);

$errors = [];
$entryKind = 'memory'; // 'memory' or 'diary' — the only two choices shown to the user
$title = '';
$body = '';
$occurredDay = '';
$occurredMonth = '';
$occurredYear = '';
$visibility = 'private';

// Coming from the timeline diagram (clicking a date on the arc) pre-fills
// the date slots, so "click the timeline to add a memory" still works even
// though the actual composer is this separate page rather than an inline one.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['date'])) {
    $prefill = DateTime::createFromFormat('Y-m-d', (string) $_GET['date']);
    if ($prefill !== false) {
        $occurredDay = $prefill->format('d');
        $occurredMonth = $prefill->format('m');
        $occurredYear = $prefill->format('Y');
    }
}

// A request whose total upload size exceeded the server's post_max_size
// arrives with an empty $_POST and $_FILES and no per-file upload error to
// report — PHP discards the whole body rather than truncating it, which
// would otherwise reach csrf_check() below looking exactly like a forged
// or expired request. Caught first, here, so it instead reads as a clear
// "too large" message.
$bodyTooLarge = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
if ($bodyTooLarge) {
    $errors[] = 'Those files are too large to upload together — try attaching fewer at once, or smaller files.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bodyTooLarge) {
    csrf_check();

    $entryKind     = (string) ($_POST['entry_type'] ?? 'memory');
    $title         = trim((string) ($_POST['title'] ?? ''));
    $body          = trim((string) ($_POST['body'] ?? ''));
    $occurredDay   = trim((string) ($_POST['occurred_day'] ?? ''));
    $occurredMonth = trim((string) ($_POST['occurred_month'] ?? ''));
    $occurredYear  = trim((string) ($_POST['occurred_year'] ?? ''));
    $visibility    = (string) ($_POST['visibility'] ?? 'private');

    if (!in_array($entryKind, ['memory', 'diary'], true)) {
        $errors[] = 'Choose a valid entry type.';
    }
    if (!in_array($visibility, ['private', 'public'], true)) {
        $errors[] = 'Choose a valid visibility.';
    }

    // Date is entered as three distinct slots (DD / MM / YYYY) rather than
    // one free-text field, so people can't get the format wrong — but all
    // three are still optional together, as long as none is filled in.
    $occurredOnValue = null;
    $dateFilledCount = (int) ($occurredDay !== '') + (int) ($occurredMonth !== '') + (int) ($occurredYear !== '');
    if ($dateFilledCount > 0 && $dateFilledCount < 3) {
        $errors[] = 'Fill in the day, month, and year, or leave all three blank.';
    } elseif ($dateFilledCount === 3) {
        if (!ctype_digit($occurredDay) || !ctype_digit($occurredMonth) || !ctype_digit($occurredYear)
            || !checkdate((int) $occurredMonth, (int) $occurredDay, (int) $occurredYear)) {
            $errors[] = 'Enter a real date (or leave day/month/year all blank).';
        } else {
            $occurredOnValue = sprintf('%04d-%02d-%02d', (int) $occurredYear, (int) $occurredMonth, (int) $occurredDay);
        }
    }

    // name="media[]" (multiple) means $_FILES['media'] is PHP's nested
    // per-field-array form — normalize it once here so the rest of this
    // page just deals with a plain list of selected files.
    $rawMediaField = is_array($_FILES['media'] ?? null)
        ? $_FILES['media']
        : ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    $selectedFiles = normalize_multi_file_upload($rawMediaField);
    $hasFile = count($selectedFiles) > 0;

    // Never trust the submitted tag list blindly — only ids that are
    // actually someone else in this family group (never the target
    // themselves) survive; anything else can only get here via a
    // tampered form, so it's silently dropped rather than erroring.
    $submittedTagIds = array_map('intval', array_filter(
        (array) ($_POST['tag_person_ids'] ?? []),
        fn ($v) => filter_var($v, FILTER_VALIDATE_INT) !== false
    ));
    $taggablePersonIds = array_map(fn ($p) => (int) $p['id'], $taggablePeople);
    $tagPersonIds = array_values(array_intersect($submittedTagIds, $taggablePersonIds));

    if ($entryKind === 'diary' && $body === '') {
        $errors[] = 'Write something for a diary entry.';
    }
    if ($entryKind === 'memory' && $body === '' && !$hasFile) {
        $errors[] = 'Write something for a memory, or attach a photo, video, or document (or a mix).';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Upload every attached file (if any) BEFORE inserting the entry
            // row, so that for a "memory" we can look at what was actually
            // uploaded (their real, sniffed content types) and record it as
            // a photo or video entry accordingly. A diary entry keeps its
            // own type regardless of any attachment. store_uploaded_media_files()
            // is all-or-nothing across the whole batch (see includes/media.php).
            // Filed under the memory's OWNER (the target person), not
            // necessarily whoever's actually clicking "save" — matters once
            // someone else is adding this for an unclaimed person.
            $storedList = $hasFile ? store_uploaded_media_files($rawMediaField, $targetPersonId) : [];

            if ($entryKind === 'diary') {
                $dbEntryType = 'diary';
            } elseif ($storedList) {
                $hasVideo = false;
                $hasPhoto = false;
                foreach ($storedList as $s) {
                    $hasVideo = $hasVideo || str_starts_with($s['mime_type'], 'video/');
                    $hasPhoto = $hasPhoto || str_starts_with($s['mime_type'], 'image/');
                }
                // entry_type only ever distinguishes "diary" from everything
                // else in practice (see Phase 4) — photo/video/note beyond
                // that aren't read anywhere else, so a mixed or document-only
                // attachment set just needs *some* sensible label, picked by
                // priority (video, then photo, then note) rather than a new
                // enum value, keeping this the same deliberately-unmigrated
                // ENUM('note','photo','video','diary') column from Phase 4.
                $dbEntryType = $hasVideo ? 'video' : ($hasPhoto ? 'photo' : 'note');
            } else {
                $dbEntryType = 'note';
            }

            $stmt = $pdo->prepare(
                'INSERT INTO timeline_entries (person_id, entry_type, title, body, occurred_on, visibility, created_by_user_id)
                 VALUES (:pid, :type, :title, :body, :occurred, :vis, :uid)'
            );
            $stmt->execute([
                'pid'      => $targetPersonId,
                'type'     => $dbEntryType,
                'title'    => $title !== '' ? $title : null,
                'body'     => $body !== '' ? $body : null,
                'occurred' => $occurredOnValue,
                'vis'      => $visibility,
                'uid'      => $me['user_id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();

            foreach ($tagPersonIds as $tagId) {
                create_memory_tag($pdo, $entryId, $familyPersonsById[$tagId], (int) $me['user_id']);
            }

            if ($storedList) {
                $mediaStmt = $pdo->prepare(
                    'INSERT INTO media (timeline_entry_id, file_path, mime_type, byte_size, width, height)
                     VALUES (:eid, :path, :mime, :size, :w, :h)'
                );
                foreach ($storedList as $stored) {
                    $mediaStmt->execute([
                        'eid'  => $entryId,
                        'path' => $stored['file_path'],
                        'mime' => $stored['mime_type'],
                        'size' => $stored['byte_size'],
                        'w'    => $stored['width'],
                        'h'    => $stored['height'],
                    ]);
                }
            }

            $pdo->commit();
            header('Location: /timeline.php' . ($addingForSelf ? '' : '?person_id=' . $targetPersonId));
            exit;
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('ourthology add_entry error: ' . $e->getMessage());
            $errors[] = 'Something went wrong saving that entry. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Add a memory — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
<style>
  :root { --accent-bg: #F1DCDC; }
  textarea { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; font-size:15px; font-family:inherit; background:#fff; color:var(--ink); resize:vertical; }
  .radio-row { display:flex; gap:16px; margin-top:8px; font-size:14px; }
  .radio-row label { text-transform:none; font-weight:400; letter-spacing:normal; display:flex; align-items:center; gap:6px; margin:0; }
  .row-3 { display:grid; grid-template-columns: 4.5em 4.5em 6em; gap:10px; }
  .row-3 input { text-align:center; }
  .date-slot span { display:block; font-size:11px; font-weight:400; text-transform:none; letter-spacing:normal; color:var(--ink-faint); text-align:center; margin-top:4px; }

  /* Multi-file attach widget — matching the prototype's own drag-and-drop
     "photo-drop" composer widget: an empty-state dropzone that turns into a
     grid of small thumbnails/icons once one or more files are attached. */
  .photo-drop { border:2px dashed var(--line); border-radius:14px; padding:16px; cursor:pointer; background:var(--paper-2); transition:border-color .15s ease, background .15s ease; }
  .photo-drop:hover, .photo-drop.dragover { border-color:var(--accent); background:var(--accent-bg); }
  .media-picker-empty { display:flex; align-items:center; gap:13px; }
  .media-picker-empty[hidden] { display:none; }
  .photo-drop .thumb { width:44px; height:44px; border-radius:10px; background:var(--paper); display:flex; align-items:center; justify-content:center; overflow:hidden; flex:0 0 auto; color:var(--ink-faint); }
  .photo-drop .thumb svg { width:20px; height:20px; }
  .photo-drop .copy { font-size:13.5px; color:var(--ink-soft); }
  .photo-drop .copy b { color:var(--ink); }
  .photo-drop .copy .paste-hint { display:block; font-size:11.5px; opacity:.75; margin-top:2px; }
  .media-picker-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(72px,1fr)); gap:8px; }
  .media-picker-grid[hidden] { display:none; }
  .pick-tile { position:relative; aspect-ratio:1; border-radius:10px; border:1px solid var(--line); background:var(--paper); overflow:hidden; display:flex; align-items:center; justify-content:center; color:var(--ink-faint); }
  .pick-tile img { width:100%; height:100%; object-fit:cover; }
  .pick-tile.has-video, .pick-tile.has-doc { flex-direction:column; gap:4px; padding:6px 4px; text-align:center; }
  .pick-tile.has-video svg, .pick-tile.has-doc svg { width:22px; height:22px; flex:0 0 auto; }
  .pick-tile .media-tile-badge { font-size:8.5px; font-weight:800; letter-spacing:.04em; color:var(--ink-soft); background:rgba(255,255,255,.75); border-radius:5px; padding:1px 5px; }
  .pick-tile .media-tile-name { font-size:9px; font-weight:700; color:var(--ink-soft); max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; padding:0 2px; }
  .pick-tile--add { border:2px dashed var(--line); background:transparent; cursor:pointer; color:var(--ink-faint); transition:border-color .15s ease, color .15s ease, background .15s ease; }
  .pick-tile--add svg { width:18px; height:18px; }
  .pick-tile--add:hover { border-color:var(--accent); color:var(--accent); background:var(--accent-bg); }
  .pick-remove { position:absolute; top:3px; right:3px; width:18px; height:18px; border-radius:50%; border:none; background:rgba(26,23,20,.7); color:#fff; font-size:13px; line-height:1; cursor:pointer; padding:0; display:flex; align-items:center; justify-content:center; }
  .pick-remove:hover { background:var(--accent); }
  .media-picker-note { display:block; font-size:12px; color:var(--ink-faint); margin-top:6px; }
  .media-picker-error { display:none; font-size:12.5px; color:var(--accent); margin-top:6px; }
  .for-banner { background:var(--paper-2); border:1px solid var(--line); border-radius:10px; padding:8px 12px; font-size:13.5px; color:var(--ink-soft); margin-bottom:14px; }
  .for-banner strong { color:var(--ink); }
  .tag-picker { display:flex; flex-direction:column; gap:6px; max-height:180px; overflow-y:auto; border:1px solid var(--line); border-radius:10px; padding:10px 12px; background:#fff; }
  .tag-picker label { text-transform:none; font-weight:400; letter-spacing:normal; display:flex; align-items:center; gap:8px; margin:0; font-size:14px; }
  .tag-picker-empty { font-size:13px; color:var(--ink-faint); margin:0; }
</style>
</head>
<body>
  <div class="card" style="max-width:460px;">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <?php if (!$addingForSelf): ?>
      <p class="for-banner">Adding a memory for <strong><?= htmlspecialchars(person_display_name($targetPerson), ENT_QUOTES) ?></strong> (not yet claimed).</p>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="target_person_id" value="<?= $targetPersonId ?>">

      <label>Type</label>
      <div class="radio-row">
        <label><input type="radio" name="entry_type" value="memory" <?= $entryKind === 'memory' ? 'checked' : '' ?>> Memory</label>
        <label><input type="radio" name="entry_type" value="diary" <?= $entryKind === 'diary' ? 'checked' : '' ?>> Diary entry</label>
      </div>

      <label for="title">Title <span style="text-transform:none;font-weight:400;">(optional)</span></label>
      <input type="text" id="title" name="title" value="<?= htmlspecialchars($title, ENT_QUOTES) ?>" maxlength="255">

      <label for="body">Words <span style="text-transform:none;font-weight:400;">(required for a diary entry; for a memory, add words and/or attach files)</span></label>
      <textarea id="body" name="body" rows="5"><?= htmlspecialchars($body, ENT_QUOTES) ?></textarea>

      <label>Photos, videos or documents <span style="text-transform:none;font-weight:400;">(optional — up to 25MB each, 10 files max)</span></label>
      <div class="photo-drop media-picker" id="photoDrop" tabindex="0" role="button" aria-label="Attach photos, videos or documents">
        <div class="media-picker-empty" id="photoDropEmpty">
          <div class="thumb">
            <svg viewBox="0 0 20 20" fill="none"><path d="M4 15.5 8 10l3 3 3-4 2 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><rect x="2.5" y="3.5" width="15" height="13" rx="2" stroke="currentColor" stroke-width="1.8"/></svg>
          </div>
          <div class="copy"><b>Click to attach</b> or drop files here<span class="paste-hint">You can also paste from your clipboard, and add more than one</span></div>
        </div>
        <div class="media-picker-grid" id="photoGrid" hidden></div>
        <input type="file" id="photoInput" name="media[]" multiple hidden
          accept="image/*,video/*,application/pdf,.pdf,.doc,.docx,.txt,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain">
      </div>
      <span class="media-picker-note">JPEG, PNG, GIF, WEBP, MP4, MOV, WEBM, PDF, DOC, DOCX, or TXT.</span>
      <p class="media-picker-error" id="mediaError"></p>

      <label>Date it happened <span style="text-transform:none;font-weight:400;">(optional — fill in all three, or leave all three blank)</span></label>
      <div class="row-3">
        <div class="date-slot">
          <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="occurred_day" placeholder="DD" value="<?= htmlspecialchars($occurredDay, ENT_QUOTES) ?>">
          <span>Day</span>
        </div>
        <div class="date-slot">
          <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" name="occurred_month" placeholder="MM" value="<?= htmlspecialchars($occurredMonth, ENT_QUOTES) ?>">
          <span>Month</span>
        </div>
        <div class="date-slot">
          <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" name="occurred_year" placeholder="YYYY" value="<?= htmlspecialchars($occurredYear, ENT_QUOTES) ?>">
          <span>Year</span>
        </div>
      </div>

      <label>Visibility</label>
      <div class="radio-row">
        <label><input type="radio" name="visibility" value="private" <?= $visibility === 'private' ? 'checked' : '' ?>> Private — only me (for now)</label>
        <label><input type="radio" name="visibility" value="public" <?= $visibility === 'public' ? 'checked' : '' ?>> Public — my whole connected family</label>
      </div>

      <?php if ($taggablePeople): ?>
        <label>Tag people in this memory <span style="text-transform:none;font-weight:400;">(optional — a claimed person must approve before it shows on their timeline; an unclaimed one is added right away)</span></label>
        <div class="tag-picker">
          <?php foreach ($taggablePeople as $tp): ?>
            <?php $tpId = (int) $tp['id']; ?>
            <label>
              <input type="checkbox" name="tag_person_ids[]" value="<?= $tpId ?>" <?= in_array($tpId, $tagPersonIds ?? [], true) ? 'checked' : '' ?>>
              <?= htmlspecialchars(person_display_name($tp), ENT_QUOTES) ?>
              <?= $tp['claimed_by_user_id'] ? '' : '<span style="color:var(--ink-faint);">(unclaimed)</span>' ?>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <button type="submit" class="btn-primary">Save entry</button>
    </form>
    <p class="foot-link"><a href="/timeline.php<?= $addingForSelf ? '' : '?person_id=' . $targetPersonId ?>">Back to <?= $addingForSelf ? 'my' : htmlspecialchars(person_display_name($targetPerson), ENT_QUOTES) . "'s" ?> timeline</a></p>
  </div>
  <script>
  (function () {
    var MAX_FILES = 10;
    var MAX_BYTES = 25 * 1024 * 1024;
    var dropzone = document.getElementById('photoDrop');
    var emptyState = document.getElementById('photoDropEmpty');
    var grid = document.getElementById('photoGrid');
    var input = document.getElementById('photoInput');
    var errorEl = document.getElementById('mediaError');
    var pending = []; // { file, kind, url }

    var VIDEO_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2.5" y="4" width="15" height="12" rx="2"/><path d="M8.3 7.6v4.8l4.4-2.4-4.4-2.4Z" fill="currentColor" stroke="none"/></svg>';
    var DOC_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 2.5h6.5L15 6v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1Z" stroke-linejoin="round"/><path d="M11 2.5V6h4" stroke-linejoin="round"/></svg>';
    var ADD_ICON = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 4v12M4 10h12" stroke-linecap="round"/></svg>';

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }
    function extLabel(name) {
      var m = /\.([a-z0-9]+)$/i.exec(name || '');
      return m ? m[1].toUpperCase() : 'FILE';
    }
    function kindOf(file) {
      if (file.type.indexOf('image/') === 0) return 'image';
      if (file.type.indexOf('video/') === 0) return 'video';
      return 'document';
    }
    function tileInnerHtml(item) {
      if (item.kind === 'image') return '<img src="' + item.url + '" alt="">';
      if (item.kind === 'video') return VIDEO_ICON + '<span class="media-tile-badge">Video</span>';
      return DOC_ICON + '<span class="media-tile-name">' + escapeHtml(item.file.name) + '</span><span class="media-tile-badge">' + escapeHtml(extLabel(item.file.name)) + '</span>';
    }
    function showError(msg) {
      errorEl.textContent = msg || '';
      errorEl.style.display = msg ? 'block' : 'none';
    }
    function syncInput() {
      var dt = new DataTransfer();
      pending.forEach(function (item) { dt.items.add(item.file); });
      input.files = dt.files;
    }
    function render() {
      if (!pending.length) {
        emptyState.hidden = false;
        grid.hidden = true;
        grid.innerHTML = '';
        return;
      }
      emptyState.hidden = true;
      grid.hidden = false;
      var tiles = pending.map(function (item, i) {
        var cls = item.kind === 'video' ? ' has-video' : (item.kind !== 'image' ? ' has-doc' : '');
        return '<div class="pick-tile' + cls + '" data-idx="' + i + '">' + tileInnerHtml(item) +
          '<button type="button" class="pick-remove" data-idx="' + i + '" aria-label="Remove">×</button></div>';
      }).join('');
      if (pending.length < MAX_FILES) {
        tiles += '<div class="pick-tile pick-tile--add" data-add="1" title="Add more">' + ADD_ICON + '</div>';
      }
      grid.innerHTML = tiles;
    }
    function addFiles(fileList) {
      var incoming = Array.prototype.slice.call(fileList || []);
      if (!incoming.length) return;
      showError('');
      for (var i = 0; i < incoming.length; i++) {
        if (pending.length >= MAX_FILES) { showError('You can attach at most ' + MAX_FILES + ' files to one entry.'); break; }
        var f = incoming[i];
        if (f.size > MAX_BYTES) { showError('"' + f.name + '" is larger than 25MB and was skipped.'); continue; }
        var kind = kindOf(f);
        pending.push({ file: f, kind: kind, url: kind === 'image' ? URL.createObjectURL(f) : null });
      }
      syncInput();
      render();
    }
    function removeAt(idx) {
      var item = pending[idx];
      if (item && item.url) URL.revokeObjectURL(item.url);
      pending.splice(idx, 1);
      syncInput();
      render();
    }

    dropzone.addEventListener('click', function (e) {
      var removeBtn = e.target.closest('.pick-remove');
      if (removeBtn) { e.stopPropagation(); removeAt(parseInt(removeBtn.getAttribute('data-idx'), 10)); return; }
      if (e.target.closest('.pick-tile') && !e.target.closest('.pick-tile--add')) return;
      input.click();
    });
    dropzone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    input.addEventListener('change', function () {
      addFiles(input.files);
    });
    ['dragenter', 'dragover'].forEach(function (evtName) {
      dropzone.addEventListener(evtName, function (e) { e.preventDefault(); e.stopPropagation(); dropzone.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (evtName) {
      dropzone.addEventListener(evtName, function (e) {
        e.preventDefault(); e.stopPropagation();
        if (evtName === 'dragleave' && e.target !== dropzone) return;
        dropzone.classList.remove('dragover');
      });
    });
    dropzone.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
    });
    document.addEventListener('paste', function (e) {
      if (!e.clipboardData) return;
      var files = [];
      if (e.clipboardData.files && e.clipboardData.files.length) {
        files = Array.prototype.slice.call(e.clipboardData.files);
      } else if (e.clipboardData.items) {
        for (var i = 0; i < e.clipboardData.items.length; i++) {
          if (e.clipboardData.items[i].kind === 'file') {
            var f = e.clipboardData.items[i].getAsFile();
            if (f) files.push(f);
          }
        }
      }
      if (files.length) { e.preventDefault(); addFiles(files); }
    });

    render();
  })();
  </script>
</body>
</html>
