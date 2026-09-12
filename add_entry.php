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
$entryType = 'note';
$title = '';
$body = '';
$occurredOn = '';
$visibility = 'private';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $entryType  = (string) ($_POST['entry_type'] ?? 'note');
    $title      = trim((string) ($_POST['title'] ?? ''));
    $body       = trim((string) ($_POST['body'] ?? ''));
    $occurredOn = trim((string) ($_POST['occurred_on'] ?? ''));
    $visibility = (string) ($_POST['visibility'] ?? 'private');

    if (!in_array($entryType, ['note', 'diary', 'photo', 'video'], true)) {
        $errors[] = 'Choose a valid entry type.';
    }
    if (!in_array($visibility, ['private', 'public'], true)) {
        $errors[] = 'Choose a valid visibility.';
    }
    $occurredOnValue = null;
    if ($occurredOn !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $occurredOn);
        if ($d === false) {
            $errors[] = 'Enter a valid date (or leave it blank).';
        } else {
            $occurredOnValue = $occurredOn;
        }
    }

    $hasFile = isset($_FILES['media']) && ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (in_array($entryType, ['photo', 'video'], true) && !$hasFile) {
        $errors[] = 'A photo or video entry needs a file.';
    }
    if (in_array($entryType, ['note', 'diary'], true) && $body === '') {
        $errors[] = 'Write something for a note or diary entry.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO timeline_entries (person_id, entry_type, title, body, occurred_on, visibility, created_by_user_id)
                 VALUES (:pid, :type, :title, :body, :occurred, :vis, :uid)'
            );
            $stmt->execute([
                'pid'      => $myPersonId,
                'type'     => $entryType,
                'title'    => $title !== '' ? $title : null,
                'body'     => $body !== '' ? $body : null,
                'occurred' => $occurredOnValue,
                'vis'      => $visibility,
                'uid'      => $me['user_id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();

            if ($hasFile) {
                $stored = store_uploaded_media($_FILES['media'], $myPersonId); // throws RuntimeException on failure
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

      <label for="entry_type">Type</label>
      <select id="entry_type" name="entry_type">
        <option value="note" <?= $entryType === 'note' ? 'selected' : '' ?>>Note / memory</option>
        <option value="diary" <?= $entryType === 'diary' ? 'selected' : '' ?>>Diary entry</option>
        <option value="photo" <?= $entryType === 'photo' ? 'selected' : '' ?>>Photo</option>
        <option value="video" <?= $entryType === 'video' ? 'selected' : '' ?>>Video</option>
      </select>

      <label for="title">Title <span style="text-transform:none;font-weight:400;">(optional)</span></label>
      <input type="text" id="title" name="title" value="<?= htmlspecialchars($title, ENT_QUOTES) ?>" maxlength="255">

      <label for="body">Words <span style="text-transform:none;font-weight:400;">(required for a note/diary entry, optional caption for photo/video)</span></label>
      <textarea id="body" name="body" rows="5"><?= htmlspecialchars($body, ENT_QUOTES) ?></textarea>

      <label for="media">Photo or video file <span style="text-transform:none;font-weight:400;">(required for those types, JPEG/PNG/GIF/WEBP/MP4/MOV/WEBM, up to 25MB)</span></label>
      <input type="file" id="media" name="media" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.webm">

      <label for="occurred_on">Date it happened <span style="text-transform:none;font-weight:400;">(optional)</span></label>
      <input type="text" id="occurred_on" name="occurred_on" placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($occurredOn, ENT_QUOTES) ?>">

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
