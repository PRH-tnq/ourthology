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

// A memory/diary entry can be edited by the person it belongs to — or, for
// an unclaimed person's entry, by anyone in the family group (the same
// person_is_editable_by() rule add_entry.php already applies to who may
// ADD one, and edit_person.php applies to editing the person's own
// details) — so ownership is checked once here, in the query itself, not
// just by hiding the "Edit" link in timeline.php's viewer.
$entryId = filter_var($_GET['entry_id'] ?? $_POST['entry_id'] ?? '', FILTER_VALIDATE_INT);
if ($entryId === false) {
    http_response_code(404);
    exit('No such entry.');
}

function fetch_owned_entry(PDO $pdo, int $entryId, int $myUserId, int $myFamilyGroup): ?array
{
    $stmt = $pdo->prepare(
        'SELECT te.id, te.person_id, te.entry_type, te.title, te.body, te.occurred_on, te.visibility,
                p.claimed_by_user_id, p.family_group_id
         FROM timeline_entries te
         JOIN persons p ON p.id = te.person_id
         WHERE te.id = :id'
    );
    $stmt->execute(['id' => $entryId]);
    $row = $stmt->fetch() ?: null;
    if ($row === null || (int) $row['family_group_id'] !== $myFamilyGroup
        || !person_is_editable_by($row, $myUserId)) {
        return null;
    }
    return $row;
}

/** All media rows for an entry, in the order they were attached. */
function fetch_entry_media(PDO $pdo, int $entryId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, file_path, mime_type, byte_size, width, height
         FROM media WHERE timeline_entry_id = :eid ORDER BY id'
    );
    $stmt->execute(['eid' => $entryId]);
    return $stmt->fetchAll();
}

$entry = fetch_owned_entry($pdo, $entryId, (int) $me['user_id'], $myGroup);
if ($entry === null) {
    http_response_code(404);
    exit("That entry doesn't exist or isn't yours to edit.");
}
$entryOwnerPersonId = (int) $entry['person_id'];
$editingSomeoneElse = $entryOwnerPersonId !== $myPersonId;

// Everyone else in the family group, for the "tag people in this memory"
// picker — same list add_entry.php offers, pre-checked with however this
// entry is already tagged (at ANY status — pending or approved — so a
// still-pending tag doesn't look untagged just because nobody's approved
// it yet; resubmitting the same checked set leaves an existing tag's
// status/note alone, see sync_memory_tags()).
$familyGraph = fetch_family_graph($pdo, $myGroup);
$familyPersonsById = [];
foreach ($familyGraph['persons'] as $p) {
    $familyPersonsById[(int) $p['id']] = $p;
}
$taggablePeople = taggable_people($familyGraph['persons'], $entryOwnerPersonId);
$currentTagIds = array_map(fn ($t) => (int) $t['person_id'], fetch_tags_for_entry($pdo, $entryId));

$existingMedia = fetch_entry_media($pdo, $entryId);
$existingMediaById = [];
foreach ($existingMedia as $m) {
    $existingMediaById[(int) $m['id']] = $m;
}

$errors = [];

// A request whose total upload size exceeded the server's post_max_size
// arrives with an empty $_POST and $_FILES and no per-file upload error to
// report — PHP discards the whole body rather than truncating it, which
// would otherwise reach csrf_check() below looking exactly like a forged
// or expired request. Caught first, here, so it instead reads as a clear
// "too large" message. (Same as add_entry.php.)
$bodyTooLarge = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
if ($bodyTooLarge) {
    $errors[] = 'Those files are too large to upload together — try attaching fewer at once, or smaller files.';
}

$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && !$bodyTooLarge;

if ($posted) {
    csrf_check();

    $entryKind     = (string) ($_POST['entry_type'] ?? 'memory');
    $title         = trim((string) ($_POST['title'] ?? ''));
    $body          = trim((string) ($_POST['body'] ?? ''));
    $occurredDay   = trim((string) ($_POST['occurred_day'] ?? ''));
    $occurredMonth = trim((string) ($_POST['occurred_month'] ?? ''));
    $occurredYear  = trim((string) ($_POST['occurred_year'] ?? ''));
    $visibility    = (string) ($_POST['visibility'] ?? 'private');

    // Never trust the submitted id list blindly — only ids that are
    // actually this entry's own media rows can ever be "kept"; anything
    // else submitted is silently ignored rather than erroring, since it
    // can only get here via a tampered form.
    $submittedKeptIds = array_map('intval', array_filter(
        (array) ($_POST['existing_media_ids'] ?? []),
        fn ($v) => filter_var($v, FILTER_VALIDATE_INT) !== false
    ));
    $keptExistingIds = array_values(array_intersect($submittedKeptIds, array_keys($existingMediaById)));
    $removedExistingIds = array_values(array_diff(array_keys($existingMediaById), $keptExistingIds));

    // Same tampering guard add_entry.php uses: only someone actually
    // taggable on this entry (anyone else in the family group) survives.
    $submittedTagIds = array_map('intval', array_filter(
        (array) ($_POST['tag_person_ids'] ?? []),
        fn ($v) => filter_var($v, FILTER_VALIDATE_INT) !== false
    ));
    $taggablePersonIds = array_map(fn ($p) => (int) $p['id'], $taggablePeople);
    $tagPersonIds = array_values(array_intersect($submittedTagIds, $taggablePersonIds));

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
    // page just deals with a plain list of newly-selected files.
    $rawMediaField = is_array($_FILES['media'] ?? null)
        ? $_FILES['media']
        : ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    $selectedFiles = normalize_multi_file_upload($rawMediaField);
    $hasNewFiles = count($selectedFiles) > 0;
    $hasAnyMedia = $hasNewFiles || count($keptExistingIds) > 0;

    if (count($keptExistingIds) + count($selectedFiles) > MEDIA_MAX_FILES_PER_ENTRY) {
        $errors[] = 'Attach at most ' . MEDIA_MAX_FILES_PER_ENTRY . ' files to one entry.';
    }

    if ($entryKind === 'diary' && $body === '') {
        $errors[] = 'Write something for a diary entry.';
    }
    if ($entryKind === 'memory' && $body === '' && !$hasAnyMedia) {
        $errors[] = 'Write something for a memory, or keep or attach a photo, video, or document.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Upload every newly-attached file BEFORE touching any row, for
            // the same reason add_entry.php does: so a bad file in the batch
            // is caught (and, via store_uploaded_media_files()'s own
            // all-or-nothing cleanup, leaves nothing new written to disk)
            // before any existing file is actually deleted below.
            $storedList = $hasNewFiles ? store_uploaded_media_files($rawMediaField, $entryOwnerPersonId) : [];

            if ($entryKind === 'diary') {
                $dbEntryType = 'diary';
            } else {
                // Same priority rule add_entry.php uses (see Phase 4/14):
                // entry_type never distinguishes anything beyond diary vs.
                // not in practice, so a mixed final set just needs some
                // sensible label — computed from the FINAL set of media
                // this entry will have once kept + newly-stored files are
                // both accounted for, not just whichever changed.
                $hasVideo = false;
                $hasPhoto = false;
                foreach ($keptExistingIds as $kid) {
                    $mime = $existingMediaById[$kid]['mime_type'];
                    $hasVideo = $hasVideo || str_starts_with($mime, 'video/');
                    $hasPhoto = $hasPhoto || str_starts_with($mime, 'image/');
                }
                foreach ($storedList as $s) {
                    $hasVideo = $hasVideo || str_starts_with($s['mime_type'], 'video/');
                    $hasPhoto = $hasPhoto || str_starts_with($s['mime_type'], 'image/');
                }
                $dbEntryType = $hasVideo ? 'video' : ($hasPhoto ? 'photo' : 'note');
            }

            // Ownership re-checked in the WHERE clause itself, not just by
            // having already loaded the row above — the same defensive
            // pattern edit_person.php's mutating actions use.
            $pdo->prepare(
                'UPDATE timeline_entries
                 SET entry_type = :type, title = :title, body = :body,
                     occurred_on = :occurred, visibility = :vis
                 WHERE id = :id AND person_id = :pid'
            )->execute([
                'type'     => $dbEntryType,
                'title'    => $title !== '' ? $title : null,
                'body'     => $body !== '' ? $body : null,
                'occurred' => $occurredOnValue,
                'vis'      => $visibility,
                'id'       => $entryId,
                'pid'      => $entryOwnerPersonId,
            ]);

            sync_memory_tags($pdo, $entryId, $tagPersonIds, $familyPersonsById, (int) $me['user_id']);

            if ($removedExistingIds) {
                foreach ($removedExistingIds as $rid) {
                    delete_media_file($existingMediaById[$rid]['file_path']);
                }
                $placeholders = implode(',', array_fill(0, count($removedExistingIds), '?'));
                $pdo->prepare("DELETE FROM media WHERE timeline_entry_id = ? AND id IN ($placeholders)")
                    ->execute(array_merge([$entryId], $removedExistingIds));
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
            header('Location: /timeline.php' . ($editingSomeoneElse ? '?person_id=' . $entryOwnerPersonId : ''));
            exit;
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('ourthology edit_entry error: ' . $e->getMessage());
            $errors[] = 'Something went wrong saving those changes. Please try again.';
        }
    }

    // On a failed submission, the media grid re-renders with whatever was
    // actually kept/removed in that attempt (new file selections can't be
    // restored — the same pre-existing limitation add_entry.php has, since
    // browsers won't let a page set a file input's value for security
    // reasons) rather than snapping back to every original file.
    $displayMediaIds = $keptExistingIds;
} else {
    // Fresh GET: prefill everything from the stored entry, nothing removed yet.
    $tagPersonIds = $currentTagIds;
    $entryKind = $entry['entry_type'] === 'diary' ? 'diary' : 'memory';
    $title = (string) ($entry['title'] ?? '');
    $body = (string) ($entry['body'] ?? '');
    $occurredDay = $occurredMonth = $occurredYear = '';
    if (!empty($entry['occurred_on'])) {
        [$oy, $om, $od] = array_map('intval', explode('-', (string) $entry['occurred_on']));
        $occurredDay = sprintf('%02d', $od);
        $occurredMonth = sprintf('%02d', $om);
        $occurredYear = (string) $oy;
    }
    $visibility = $entry['visibility'];
    $displayMediaIds = array_keys($existingMediaById);
}

/** Extension (uppercased, no dot) parsed from a stored file_path — used as the tile badge for an existing document. */
function ourthology_media_ext(string $filePath): string
{
    $m = [];
    return preg_match('/\.([a-z0-9]+)$/i', $filePath, $m) ? strtoupper($m[1]) : 'FILE';
}

$existingForDisplay = [];
foreach ($displayMediaIds as $id) {
    if (!isset($existingMediaById[$id])) {
        continue;
    }
    $m = $existingMediaById[$id];
    $mime = (string) $m['mime_type'];
    $kind = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : 'document');
    $existingForDisplay[] = [
        'id'    => (int) $m['id'],
        'kind'  => $kind,
        'url'   => '/media.php?id=' . (int) $m['id'],
        'badge' => ourthology_media_ext((string) $m['file_path']),
    ];
}
$existingForDisplayJson = json_encode($existingForDisplay, JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit entry — ourthology.com</title>
<link rel="stylesheet" href="/styles.css?v=20">
<style>
  :root { --accent-bg: #F1DCDC; }
  /* Phase 25: same red-accent pop-up-dialog border as add_entry.php (its
     sibling composer) and the tree's edit-person pop-up overlay, rather
     than the plain --line border every other page's .card uses. */
  .card { border:2px solid var(--accent); }
  textarea { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; font-size:15px; font-family:inherit; background:#fff; color:var(--ink); resize:vertical; }
  .radio-row { display:flex; gap:16px; margin-top:8px; font-size:14px; }
  .radio-row label { text-transform:none; font-weight:400; letter-spacing:normal; display:flex; align-items:center; gap:6px; margin:0; }
  .row-3 { display:grid; grid-template-columns: 4.5em 4.5em 6em; gap:10px; }
  .row-3 input { text-align:center; }
  .date-slot span { display:block; font-size:11px; font-weight:400; text-transform:none; letter-spacing:normal; color:var(--ink-faint); text-align:center; margin-top:4px; }

  /* Multi-file attach widget — matching the prototype's own drag-and-drop
     "photo-drop" composer widget: an empty-state dropzone that turns into a
     grid of small thumbnails/icons once one or more files are attached.
     Here the grid can start pre-populated with the entry's existing files. */
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
</style>
</head>
<body>
  <div class="card" style="max-width:460px;">
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

    <?php if ($editingSomeoneElse): ?>
      <p class="for-banner">Editing a memory belonging to <strong><?= htmlspecialchars(person_display_name($familyPersonsById[$entryOwnerPersonId] ?? []), ENT_QUOTES) ?></strong> (not yet claimed).</p>
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
      <input type="hidden" name="entry_id" value="<?= (int) $entryId ?>">
      <div id="keptMediaInputs"></div>

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
        <div class="media-picker-empty" id="photoDropEmpty" hidden>
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
              <input type="checkbox" name="tag_person_ids[]" value="<?= $tpId ?>" <?= in_array($tpId, $tagPersonIds, true) ? 'checked' : '' ?>>
              <?= htmlspecialchars(person_display_name($tp), ENT_QUOTES) ?>
              <?= $tp['claimed_by_user_id'] ? '' : '<span style="color:var(--ink-faint);">(unclaimed)</span>' ?>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <button type="submit" class="btn-primary">Save changes</button>
    </form>
    <p class="foot-link"><a href="/timeline.php<?= $editingSomeoneElse ? '?person_id=' . $entryOwnerPersonId : '' ?>">Cancel</a></p>
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
    var keptInputsContainer = document.getElementById('keptMediaInputs');

    // Two backing lists merge into one visual grid: "kept" existing
    // server-side files (each just an id + display info — no File object,
    // since it's already stored) and "pending" newly-picked files (real
    // File objects, kept in sync with the real hidden <input> below via
    // DataTransfer so a removed one is truly excluded on submit).
    var kept = <?= $existingForDisplayJson ?>;
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
    function kindOfFile(file) {
      if (file.type.indexOf('image/') === 0) return 'image';
      if (file.type.indexOf('video/') === 0) return 'video';
      return 'document';
    }
    function existingTileHtml(item) {
      if (item.kind === 'image') return '<img src="' + item.url + '" alt="">';
      if (item.kind === 'video') return VIDEO_ICON + '<span class="media-tile-badge">Video</span>';
      return DOC_ICON + '<span class="media-tile-badge">' + escapeHtml(item.badge) + '</span>';
    }
    function pendingTileHtml(item) {
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
    function syncKeptInputs() {
      keptInputsContainer.innerHTML = kept.map(function (item) {
        return '<input type="hidden" name="existing_media_ids[]" value="' + item.id + '">';
      }).join('');
    }
    function totalCount() { return kept.length + pending.length; }
    function render() {
      syncKeptInputs();
      if (!totalCount()) {
        emptyState.hidden = false;
        grid.hidden = true;
        grid.innerHTML = '';
        return;
      }
      emptyState.hidden = true;
      grid.hidden = false;
      var tiles = kept.map(function (item, i) {
        var cls = item.kind === 'video' ? ' has-video' : (item.kind !== 'image' ? ' has-doc' : '');
        return '<div class="pick-tile' + cls + '" data-existing-idx="' + i + '">' + existingTileHtml(item) +
          '<button type="button" class="pick-remove" data-existing-idx="' + i + '" aria-label="Remove">×</button></div>';
      }).join('');
      tiles += pending.map(function (item, i) {
        var cls = item.kind === 'video' ? ' has-video' : (item.kind !== 'image' ? ' has-doc' : '');
        return '<div class="pick-tile' + cls + '" data-pending-idx="' + i + '">' + pendingTileHtml(item) +
          '<button type="button" class="pick-remove" data-pending-idx="' + i + '" aria-label="Remove">×</button></div>';
      }).join('');
      if (totalCount() < MAX_FILES) {
        tiles += '<div class="pick-tile pick-tile--add" data-add="1" title="Add more">' + ADD_ICON + '</div>';
      }
      grid.innerHTML = tiles;
    }
    function addFiles(fileList) {
      var incoming = Array.prototype.slice.call(fileList || []);
      if (!incoming.length) return;
      showError('');
      for (var i = 0; i < incoming.length; i++) {
        if (totalCount() >= MAX_FILES) { showError('You can attach at most ' + MAX_FILES + ' files to one entry.'); break; }
        var f = incoming[i];
        if (f.size > MAX_BYTES) { showError('"' + f.name + '" is larger than 25MB and was skipped.'); continue; }
        var kind = kindOfFile(f);
        pending.push({ file: f, kind: kind, url: kind === 'image' ? URL.createObjectURL(f) : null });
      }
      syncInput();
      render();
    }
    function removeExistingAt(idx) {
      kept.splice(idx, 1);
      render();
    }
    function removePendingAt(idx) {
      var item = pending[idx];
      if (item && item.url) URL.revokeObjectURL(item.url);
      pending.splice(idx, 1);
      syncInput();
      render();
    }

    dropzone.addEventListener('click', function (e) {
      var removeBtn = e.target.closest('.pick-remove');
      if (removeBtn) {
        e.stopPropagation();
        if (removeBtn.hasAttribute('data-existing-idx')) {
          removeExistingAt(parseInt(removeBtn.getAttribute('data-existing-idx'), 10));
        } else {
          removePendingAt(parseInt(removeBtn.getAttribute('data-pending-idx'), 10));
        }
        return;
      }
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
