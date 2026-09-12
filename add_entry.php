<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/media.php';

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Location: /login.php');
    exit;
}
$pdo = ourthology_pdo();
$myPersonId = (int) $me['person_id'];

$errors = [];
$entryKind = 'memory'; // 'memory' or 'diary' — the only two choices shown to the user
$title = '';
$body = '';
$occurredDay = '';
$occurredMonth = '';
$occurredYear = '';
$visibility = 'private';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    $hasFile = isset($_FILES['media']) && ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($entryKind === 'diary' && $body === '') {
        $errors[] = 'Write something for a diary entry.';
    }
    if ($entryKind === 'memory' && $body === '' && !$hasFile) {
        $errors[] = 'Write something for a memory, or attach a photo or video (or both).';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Upload the file (if any) BEFORE inserting the entry row, so
            // that for a "memory" we can look at what was actually
            // uploaded (its real, sniffed content type) and record it as
            // a photo or video entry accordingly. A diary entry keeps its
            // own type regardless of any attachment.
            $stored = null;
            if ($hasFile) {
                $stored = store_uploaded_media($_FILES['media'], $myPersonId); // throws RuntimeException on failure
            }

            if ($entryKind === 'diary') {
                $dbEntryType = 'diary';
            } elseif ($stored !== null) {
                $dbEntryType = str_starts_with($stored['mime_type'], 'video/') ? 'video' : 'photo';
            } else {
                $dbEntryType = 'note';
            }

            $stmt = $pdo->prepare(
                'INSERT INTO timeline_entries (person_id, entry_type, title, body, occurred_on, visibility, created_by_user_id)
                 VALUES (:pid, :type, :title, :body, :occurred, :vis, :uid)'
            );
            $stmt->execute([
                'pid'      => $myPersonId,
                'type'     => $dbEntryType,
                'title'    => $title !== '' ? $title : null,
                'body'     => $body !== '' ? $body : null,
                'occurred' => $occurredOnValue,
                'vis'      => $visibility,
                'uid'      => $me['user_id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();

            if ($stored !== null) {
                $pdo->prepare(
                    'INSERT INTO media (timeline_entry_id, file_path, mime_type, byte_size, width, height)
                     VALUES (:eid, :path, :mime, :size, :w, :h)'
                )->execute([
                    'eid'  => $entryId,
                    'path' => $stored['file_path'],
                    'mime' => $stored['mime_type'],
                    'size' => $stored['byte_size'],
                    'w'    => $stored['width'],
                    'h'    => $stored['height'],
                ]);
            }

            $pdo->commit();
            header('Location: /timeline.php');
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
  textarea { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; font-size:15px; font-family:inherit; background:#fff; color:var(--ink); resize:vertical; }
  .radio-row { display:flex; gap:16px; margin-top:8px; font-size:14px; }
  .radio-row label { text-transform:none; font-weight:400; letter-spacing:normal; display:flex; align-items:center; gap:6px; margin:0; }
  .row-3 { display:grid; grid-template-columns: 4.5em 4.5em 6em; gap:10px; }
  .row-3 input { text-align:center; }
  .date-slot span { display:block; font-size:11px; font-weight:400; text-transform:none; letter-spacing:normal; color:var(--ink-faint); text-align:center; margin-top:4px; }
</style>
</head>
<body>
  <div class="card" style="max-width:460px;">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>

      <label>Type</label>
      <div class="radio-row">
        <label><input type="radio" name="entry_type" value="memory" <?= $entryKind === 'memory' ? 'checked' : '' ?>> Memory</label>
        <label><input type="radio" name="entry_type" value="diary" <?= $entryKind === 'diary' ? 'checked' : '' ?>> Diary entry</label>
      </div>

      <label for="title">Title <span style="text-transform:none;font-weight:400;">(optional)</span></label>
      <input type="text" id="title" name="title" value="<?= htmlspecialchars($title, ENT_QUOTES) ?>" maxlength="255">

      <label for="body">Words <span style="text-transform:none;font-weight:400;">(required for a diary entry; for a memory, add words and/or attach a photo or video)</span></label>
      <textarea id="body" name="body" rows="5"><?= htmlspecialchars($body, ENT_QUOTES) ?></textarea>

      <label for="media">Photo or video <span style="text-transform:none;font-weight:400;">(optional, JPEG/PNG/GIF/WEBP/MP4/MOV/WEBM, up to 25MB)</span></label>
      <input type="file" id="media" name="media" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.webm">

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

      <button type="submit" class="btn-primary">Save entry</button>
    </form>
    <p class="foot-link"><a href="/timeline.php">Back to my timeline</a></p>
  </div>
</body>
</html>
