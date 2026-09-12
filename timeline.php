<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/entries.php';
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
$myPerson = person_row($pdo, $myPersonId);
$myGroup = (int) $myPerson['family_group_id'];

$targetId = filter_var($_GET['person_id'] ?? $myPersonId, FILTER_VALIDATE_INT);
if ($targetId === false) {
    $targetId = $myPersonId;
}
$target = person_row($pdo, (int) $targetId);

if ($target === null) {
    http_response_code(404);
    exit('No such person.');
}

$isOwner = (int) $target['id'] === $myPersonId;
$sameGroup = (int) $target['family_group_id'] === $myGroup;

if (!$isOwner && !$sameGroup) {
    http_response_code(403);
    exit("You don't have access to this person's timeline.");
}

$notice = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_entry') {
    csrf_check();
    $entryId = filter_var($_POST['entry_id'] ?? '', FILTER_VALIDATE_INT);
    if ($entryId !== false) {
        // Ownership check happens in the query itself, not just in the UI —
        // this only matches (and only deletes) a row that both has this id
        // AND belongs to me.
        $stmt = $pdo->prepare('SELECT id FROM timeline_entries WHERE id = :id AND person_id = :pid');
        $stmt->execute(['id' => $entryId, 'pid' => $myPersonId]);
        if ($stmt->fetch() ?: null) {
            $mediaStmt = $pdo->prepare('SELECT file_path FROM media WHERE timeline_entry_id = :eid');
            $mediaStmt->execute(['eid' => $entryId]);
            foreach ($mediaStmt->fetchAll() as $m) {
                delete_media_file($m['file_path']);
            }
            $pdo->prepare('DELETE FROM timeline_entries WHERE id = :id')->execute(['id' => $entryId]);
            $notice = 'Entry deleted.';
        } else {
            $errors[] = "That entry doesn't exist or isn't yours to delete.";
        }
    }
}

$entries = fetch_entries_for_person($pdo, (int) $target['id'], $isOwner);
$targetName = person_display_name($target);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($targetName, ENT_QUOTES) ?> — timeline — ourthology.com</title>
<link rel="stylesheet" href="/styles.css">
<style>
  body { align-items: flex-start; }
  .wide { max-width: 600px; }
  .nav { display:flex; gap:10px; flex-wrap:wrap; margin: 18px 0 4px; }
  .nav a { font-size:13px; padding:7px 12px; border-radius:999px; border:1px solid var(--line); color:var(--ink-soft); text-decoration:none; background:#fff; }
  .entry { border:1px solid var(--line); border-radius:10px; padding:14px; margin-top:14px; background:#fff; }
  .entry-meta { font-size:12px; color:var(--ink-faint); margin-bottom:6px; }
  .tag { display:inline-block; font-size:11px; text-transform:uppercase; letter-spacing:.03em; padding:2px 6px; border-radius:4px; margin-left:6px; }
  .tag.private { background:var(--error-bg); color:var(--accent); }
  .tag.public { background:#e2ecdf; color:#3a6b4f; }
  .entry img, .entry video { max-width:100%; border-radius:8px; margin-top:8px; }
  .del-btn { font-size:12px; background:transparent; border:none; color:var(--ink-faint); cursor:pointer; text-decoration:underline; padding:0; margin-top:8px; }
</style>
</head>
<body>
  <div class="card wide">
    <p class="wordmark">ourthology<span class="tld">.com</span></p>
    <p class="subtitle">an anthology of us.</p>

    <div class="nav">
      <a href="/dashboard.php">Dashboard</a>
      <a href="/tree.php">My tree</a>
      <?php if ($isOwner): ?><a href="/add_entry.php">+ Add a memory</a><?php endif; ?>
    </div>

    <h3 style="margin-bottom:2px;">
      <?= $isOwner ? 'My timeline' : htmlspecialchars($targetName, ENT_QUOTES) . "'s timeline" ?>
    </h3>
    <?php if (!$isOwner): ?>
      <p style="font-size:13px;color:var(--ink-faint);margin-top:0;">Showing public entries only.</p>
    <?php endif; ?>

    <?php if ($notice): ?><p style="color:var(--accent);font-weight:600;"><?= htmlspecialchars($notice, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($errors): ?>
      <div class="error"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if (!$entries): ?>
      <p style="color:var(--ink-faint);margin-top:16px;">Nothing here yet.</p>
    <?php endif; ?>

    <?php foreach ($entries as $entry): ?>
      <div class="entry">
        <div class="entry-meta">
          <?= $entry['occurred_on'] ? htmlspecialchars($entry['occurred_on'], ENT_QUOTES) : htmlspecialchars(substr($entry['created_at'], 0, 10), ENT_QUOTES) ?>
          · <?= htmlspecialchars(ucfirst($entry['entry_type']), ENT_QUOTES) ?>
          <?php if ($isOwner): ?>
            <span class="tag <?= $entry['visibility'] ?>"><?= htmlspecialchars($entry['visibility'], ENT_QUOTES) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($entry['title']): ?><strong><?= htmlspecialchars($entry['title'], ENT_QUOTES) ?></strong><?php endif; ?>
        <?php if ($entry['body']): ?><p style="white-space:pre-wrap;margin:6px 0;"><?= htmlspecialchars($entry['body'], ENT_QUOTES) ?></p><?php endif; ?>
        <?php foreach ($entry['media'] as $m): ?>
          <?php if (str_starts_with($m['mime_type'], 'video/')): ?>
            <video controls src="/media.php?id=<?= (int) $m['id'] ?>"></video>
          <?php else: ?>
            <img src="/media.php?id=<?= (int) $m['id'] ?>" alt="">
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($isOwner): ?>
          <form method="post" onsubmit="return confirm('Delete this entry?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_entry">
            <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
            <button type="submit" class="del-btn">Delete</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</body>
</html>
