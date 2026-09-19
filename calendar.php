<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/calendar.php';
require_once __DIR__ . '/includes/tour_engine.php';

/**
 * Phase 56: the family calendar -- birthdays (auto-populated from
 * persons.born) plus "key dates" any family member can add, laid out as
 * a printable month-by-month list. See includes/calendar.php for all the
 * actual date math and the calendar_events table this owns.
 *
 * Every mutation (add/delete) is handled right here, POST-only,
 * redirect-with-flash -- the same self-contained "one file owns its own
 * feature" shape tree.php already uses for its own get_link POST handler,
 * rather than splitting into a separate mutation file the way postcard.php/
 * letter.php do (those are triggered FROM other pages; this page is its
 * own trigger).
 */

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Location: /login.php');
    exit;
}
$pdo = ourthology_pdo();
$myPersonId = (int) $me['person_id'];
$myUserId = (int) $me['user_id'];
$myGroup = (int) person_row($pdo, $myPersonId)['family_group_id'];

ourthology_start_session();

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 120);
        $month = filter_var($_POST['month'] ?? '', FILTER_VALIDATE_INT);
        $day = filter_var($_POST['day'] ?? '', FILTER_VALIDATE_INT);
        $yearRaw = trim((string) ($_POST['year'] ?? ''));
        $year = $yearRaw === '' ? null : filter_var($yearRaw, FILTER_VALIDATE_INT);

        $error = null;
        if ($title === '') {
            $error = 'Give this date a name.';
        } elseif ($month === false || $day === false || !checkdate((int) $month, (int) $day, 2000)) {
            // 2000 is a leap year, so Feb 29 validates here as a real
            // annual date -- ourthology_next_annual_occurrence() is what
            // handles it gracefully in non-leap years later on.
            $error = "That's not a real date.";
        } elseif ($yearRaw !== '' && ($year === false || $year < 1000 || $year > (int) date('Y'))) {
            $error = 'The year looks off -- leave it blank if it doesn\'t matter.';
        }

        if ($error !== null) {
            $_SESSION['flash_calendar_error'] = $error;
        } else {
            create_calendar_event($pdo, $myGroup, $myPersonId, $myUserId, $title, (int) $month, (int) $day, $year);
            $_SESSION['flash_calendar_notice'] = "Added \"{$title}\" to the family calendar.";
        }
    } elseif ($action === 'edit') {
        // Phase 62: "make it possible to edit calendar items that have
        // been added" -- same validation as 'add' above (deliberately
        // duplicated rather than shared, since the two error-message
        // sets already read naturally standalone and a shared helper
        // would need its own indirection for one three-line block).
        $eventId = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT);
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 120);
        $month = filter_var($_POST['month'] ?? '', FILTER_VALIDATE_INT);
        $day = filter_var($_POST['day'] ?? '', FILTER_VALIDATE_INT);
        $yearRaw = trim((string) ($_POST['year'] ?? ''));
        $year = $yearRaw === '' ? null : filter_var($yearRaw, FILTER_VALIDATE_INT);

        $error = null;
        if ($eventId === false) {
            $error = "Couldn't find that date to update.";
        } elseif ($title === '') {
            $error = 'Give this date a name.';
        } elseif ($month === false || $day === false || !checkdate((int) $month, (int) $day, 2000)) {
            $error = "That's not a real date.";
        } elseif ($yearRaw !== '' && ($year === false || $year < 1000 || $year > (int) date('Y'))) {
            $error = 'The year looks off -- leave it blank if it doesn\'t matter.';
        }

        if ($error !== null) {
            $_SESSION['flash_calendar_error'] = $error;
        } else {
            $updated = update_calendar_event($pdo, (int) $eventId, $myGroup, $title, (int) $month, (int) $day, $year);
            $_SESSION['flash_calendar_notice'] = $updated
                ? "Updated \"{$title}\"."
                : "Couldn't find that date to update.";
        }
    } elseif ($action === 'delete') {
        $eventId = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT);
        if ($eventId !== false) {
            delete_calendar_event($pdo, (int) $eventId, $myGroup);
            $_SESSION['flash_calendar_notice'] = 'Removed from the family calendar.';
        }
    }

    header('Location: /calendar.php');
    exit;
}

$flashError = $_SESSION['flash_calendar_error'] ?? null;
$flashNotice = $_SESSION['flash_calendar_notice'] ?? null;
unset($_SESSION['flash_calendar_error'], $_SESSION['flash_calendar_notice']);

$pendingCounts = fetch_pending_for_user($pdo, $myUserId);
$pendingCount = count($pendingCounts['relationships']) + count($pendingCounts['partnerships']);

$graph = fetch_family_graph($pdo, $myGroup);
$birthdays = ourthology_calendar_birthdays($graph['persons']);
$keyDates = ourthology_calendar_key_dates(fetch_calendar_events_for_group($pdo, $myGroup));

$allEntries = array_merge($birthdays, $keyDates);

$today = new DateTimeImmutable('today');
$todayMonth = (int) $today->format('n');
$todayDay = (int) $today->format('j');

// "Upcoming" -- within the next month, nearest first. Deliberately a
// longer horizon than tree.php's 7-day birthday banner (that one's a
// quick heads-up in passing; this page is the place to actually plan
// around what's coming).
$upcoming = array_values(array_filter($allEntries, fn(array $e): bool => $e['days_away'] <= 31));
usort($upcoming, fn(array $a, array $b): int => $a['days_away'] <=> $b['days_away']);

// Full year, grouped by calendar month (January first, regardless of
// what month it is now) -- a family calendar reads like a wall calendar,
// not an infinite-scroll feed.
$byMonth = array_fill(1, 12, []);
foreach ($allEntries as $e) {
    $byMonth[$e['month']][] = $e;
}
foreach ($byMonth as $m => $entries) {
    usort($entries, fn(array $a, array $b): int => $a['day'] <=> $b['day']);
    $byMonth[$m] = $entries;
}

$pendingCount = $pendingCount; // keep parity with tree.php's nav badge naming
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Family calendar — ourthology.com</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,600;0,700;0,800;1,600&family=Newsreader:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css?v=26">
<style>
  body { align-items: flex-start; }
  .wide { max-width: min(95vw, 1100px); }
  .nav { display:flex; gap:10px 16px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin: 18px 0 4px; }
  .nav-links { display:flex; gap:10px; flex-wrap:wrap; }
  .nav a { font-size:13px; padding:7px 12px; border-radius:999px; border:1px solid var(--line); color:var(--ink-soft); text-decoration:none; background:#fff; }
  .nav a.badge { background: var(--error-bg); border-color: var(--error-bg); color: var(--accent); font-weight:600; }
  .nav .linklet-btn { font-size:13px; font-weight:600; padding:7px 14px; border-radius:999px; border:1px solid var(--accent); color:var(--on-accent); background:var(--accent); cursor:pointer; font-family:inherit; }
  .nav .linklet-btn:hover { background:var(--accent-glow); border-color:var(--accent-glow); }
  .whoami { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12.5px; color:var(--ink-faint); }
  .whoami strong { color:var(--ink-soft); font-weight:600; }
  .whoami form { display:inline; }
  .whoami .linklet { font-size:12.5px; }
  .linklet { font-size:12px; background:transparent; border:none; color:var(--accent); cursor:pointer; padding:0; text-decoration:underline; }
  h1.page-title { font-family:"Fraunces",Georgia,serif; font-size:26px; margin:18px 0 4px; }
  .page-sub { color:var(--ink-soft); font-size:14px; margin:0 0 18px; }
  .flash { word-break:break-all; font-size:13px; background:#fff; border:1px solid var(--line); border-radius:6px; padding:8px 12px; margin:8px 0 16px; }
  .flash.notice-good { border-color:var(--accent); color:var(--accent); }

  /* ---------- "Add a key date" form ---------- */
  .cal-add-card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px 20px; margin:0 0 22px; }
  .cal-add-card h2 { font-family:"Fraunces",Georgia,serif; font-size:16px; margin:0 0 4px; }
  .cal-add-card p.hint { margin:0 0 14px; font-size:12.5px; color:var(--ink-faint); }
  .cal-add-row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
  .cal-add-field { display:flex; flex-direction:column; }
  .cal-add-field label { margin:0 0 6px; }
  .cal-add-field.grow { flex:1 1 220px; }
  .cal-add-field select, .cal-add-field input[type="number"] { padding:10px 12px; border:1px solid var(--line); border-radius:8px; font-size:14px; background:#fff; color:var(--ink); font-family:inherit; }
  .cal-add-field.year input { width:110px; }
  .cal-add-row button.btn-primary { width:auto; margin:0; padding:10px 20px; white-space:nowrap; }

  /* ---------- Upcoming ---------- */
  .cal-upcoming { margin:0 0 26px; }
  .cal-upcoming h2 { font-family:"Fraunces",Georgia,serif; font-size:16px; margin:0 0 10px; }
  .cal-upcoming-list { display:flex; flex-direction:column; gap:8px; }
  .cal-upcoming-item { display:flex; align-items:center; gap:12px; padding:10px 14px; border:1px solid #C2790F; background:#F3DFB8; border-radius:10px; font-size:13.5px; color:#6B4A0A; }
  .cal-upcoming-item .cal-when { flex:0 0 auto; font-weight:700; color:#8A5A0A; min-width:72px; }
  .cal-upcoming-item .cal-what { flex:1 1 auto; }
  .cal-upcoming-empty { color:var(--ink-faint); font-size:13.5px; }

  /* ---------- month-by-month list ---------- */
  .cal-year { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px,1fr)); gap:16px; }
  .cal-month { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:14px 16px; }
  .cal-month.is-current { border-color:var(--accent); box-shadow:0 0 0 2px rgba(154,42,42,.12); }
  .cal-month h3 { font-family:"Fraunces",Georgia,serif; font-size:15px; margin:0 0 8px; display:flex; align-items:center; justify-content:space-between; }
  .cal-month h3 .cal-today-chip { font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--accent); background:var(--error-bg); border-radius:999px; padding:2px 8px; }
  .cal-month-empty { color:var(--ink-faint); font-size:12.5px; margin:0; }
  .cal-entry { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid var(--line); font-size:13.5px; }
  .cal-entry:last-child { border-bottom:none; }
  .cal-entry.is-today { background:var(--error-bg); margin:0 -8px; padding:6px 8px; border-radius:8px; border-bottom-color:transparent; }
  .cal-entry-day { flex:0 0 auto; font-weight:700; color:var(--ink-soft); min-width:22px; text-align:right; }
  .cal-entry-body { flex:1 1 auto; min-width:0; }
  .cal-entry-title { color:var(--ink); }
  .cal-entry-meta { display:block; font-size:11.5px; color:var(--ink-faint); margin-top:1px; }
  .cal-entry-remove { flex:0 0 auto; display:flex; align-items:center; gap:8px; }
  .cal-entry-remove button { font-size:11px; }

  /* ---------- Phase 62: inline "Edit" form for a key date ---------- */
  .cal-entry-edit { display:none; padding:10px 8px 14px; margin:0 0 4px; border-bottom:1px solid var(--line); }
  .cal-entry-edit.is-open { display:block; }
  .cal-entry-edit .cal-add-row { gap:8px; }
  .cal-entry-edit .cal-add-field select, .cal-entry-edit .cal-add-field input { padding:7px 9px; font-size:13px; }
  .cal-entry-edit .cal-add-field.year input { width:90px; }
  .cal-entry-edit .btn-primary { width:auto; margin:0; padding:7px 16px; font-size:13px; }
  .cal-edit-cancel { font-size:12px; }

  @media print {
    .nav, .flash, .cal-add-card, .cal-entry-remove, .cal-entry-edit, .whoami { display:none !important; }
    body { padding:0; }
    .cal-year { grid-template-columns:repeat(3, 1fr); }
    .cal-month { break-inside:avoid; border-color:#999; }
  }
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
        <a href="/timeline.php">My timeline</a>
        <a href="/tree.php">My tree</a>
        <a href="/pending.php" class="<?= $pendingCount ? 'badge' : '' ?>">Pending<?= $pendingCount ? " ($pendingCount)" : '' ?></a>
        <button type="button" id="printCalendarBtn" class="linklet-btn" onclick="window.print()">Print calendar</button>
      </div>
      <div class="whoami">
        Signed in as <strong><?= htmlspecialchars($me['email'], ENT_QUOTES) ?></strong>
        <form method="post" action="/logout.php"><button type="submit" class="linklet">Log out</button></form>
      </div>
    </div>

    <h1 class="page-title">Family calendar</h1>
    <p class="page-sub">Birthdays fill in automatically. Anyone in the family can add another date worth marking.</p>

    <?php if ($flashError): ?><div class="flash"><?= htmlspecialchars($flashError, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($flashNotice): ?><div class="flash notice-good"><?= htmlspecialchars($flashNotice, ENT_QUOTES) ?></div><?php endif; ?>

    <div class="cal-add-card">
      <h2>Add a key date</h2>
      <p class="hint">Anniversaries, memorials, the first day of the school holidays — whatever's worth marking. It'll come back every year.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="cal-add-row">
          <div class="cal-add-field grow">
            <label for="calTitle">What is it?</label>
            <input type="text" id="calTitle" name="title" maxlength="120" placeholder="e.g. Mum &amp; Dad's anniversary" required>
          </div>
          <div class="cal-add-field">
            <label for="calMonth">Month</label>
            <select id="calMonth" name="month" required>
              <?php foreach ($monthNames as $num => $name): ?>
                <option value="<?= $num ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="cal-add-field">
            <label for="calDay">Day</label>
            <select id="calDay" name="day" required>
              <?php for ($d = 1; $d <= 31; $d++): ?>
                <option value="<?= $d ?>"><?= $d ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="cal-add-field year">
            <label for="calYear">Year (optional)</label>
            <input type="number" id="calYear" name="year" min="1000" max="<?= (int) date('Y') ?>" placeholder="e.g. 1998">
          </div>
          <button type="submit" class="btn-primary">Add to calendar</button>
        </div>
      </form>
    </div>

    <div class="cal-upcoming">
      <h2>Coming up</h2>
      <?php if (!$upcoming): ?>
        <p class="cal-upcoming-empty">Nothing in the next month.</p>
      <?php else: ?>
        <div class="cal-upcoming-list">
          <?php foreach ($upcoming as $e): ?>
            <div class="cal-upcoming-item">
              <span class="cal-when"><?= $e['days_away'] === 0 ? 'Today' : ($e['days_away'] === 1 ? 'Tomorrow' : 'In ' . $e['days_away'] . ' days') ?></span>
              <span class="cal-what">
                <?= htmlspecialchars($e['title'], ENT_QUOTES) ?><?php if ($e['kind'] === 'birthday'): ?> — turning <?= (int) $e['turning_age'] ?><?php elseif ($e['years'] !== null): ?> — <?= (int) $e['years'] ?> years<?php endif; ?>
                <span style="opacity:.75;"> (<?= htmlspecialchars($e['next_date']->format('D j M'), ENT_QUOTES) ?>)</span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="cal-year">
      <?php foreach ($monthNames as $num => $name): ?>
        <?php $isCurrentMonth = $num === $todayMonth; ?>
        <div class="cal-month<?= $isCurrentMonth ? ' is-current' : '' ?>">
          <h3><?= htmlspecialchars($name, ENT_QUOTES) ?><?php if ($isCurrentMonth): ?><span class="cal-today-chip">This month</span><?php endif; ?></h3>
          <?php if (!$byMonth[$num]): ?>
            <p class="cal-month-empty">Nothing marked.</p>
          <?php else: ?>
            <?php foreach ($byMonth[$num] as $e): ?>
              <?php $isToday = $e['month'] === $todayMonth && $e['day'] === $todayDay; ?>
              <div class="cal-entry<?= $isToday ? ' is-today' : '' ?>">
                <span class="cal-entry-day"><?= (int) $e['day'] ?></span>
                <span class="cal-entry-body">
                  <span class="cal-entry-title"><?= htmlspecialchars($e['title'], ENT_QUOTES) ?></span>
                  <?php if ($e['kind'] === 'birthday'): ?>
                    <span class="cal-entry-meta">turns <?= (int) $e['turning_age'] ?></span>
                  <?php else: ?>
                    <span class="cal-entry-meta"><?= $e['years'] !== null ? ((int) $e['years']) . ' years · ' : '' ?>added by <?= htmlspecialchars($e['added_by'], ENT_QUOTES) ?></span>
                  <?php endif; ?>
                </span>
                <?php if ($e['kind'] === 'key_date'): ?>
                  <span class="cal-entry-remove">
                    <button type="button" class="linklet cal-edit-toggle" data-target="calEdit<?= (int) $e['id'] ?>">Edit</button>
                    <form method="post" onsubmit="return confirm('Remove this date from the family calendar?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="event_id" value="<?= (int) $e['id'] ?>">
                      <button type="submit" class="linklet">Remove</button>
                    </form>
                  </span>
                <?php endif; ?>
              </div>
              <?php if ($e['kind'] === 'key_date'): ?>
                <div class="cal-entry-edit" id="calEdit<?= (int) $e['id'] ?>">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="event_id" value="<?= (int) $e['id'] ?>">
                    <div class="cal-add-row">
                      <div class="cal-add-field grow">
                        <label for="calEditTitle<?= (int) $e['id'] ?>">What is it?</label>
                        <input type="text" id="calEditTitle<?= (int) $e['id'] ?>" name="title" maxlength="120" value="<?= htmlspecialchars($e['title'], ENT_QUOTES) ?>" required>
                      </div>
                      <div class="cal-add-field">
                        <label for="calEditMonth<?= (int) $e['id'] ?>">Month</label>
                        <select id="calEditMonth<?= (int) $e['id'] ?>" name="month" required>
                          <?php foreach ($monthNames as $mNum => $mName): ?>
                            <option value="<?= $mNum ?>"<?= $mNum === $e['month'] ? ' selected' : '' ?>><?= htmlspecialchars($mName, ENT_QUOTES) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="cal-add-field">
                        <label for="calEditDay<?= (int) $e['id'] ?>">Day</label>
                        <select id="calEditDay<?= (int) $e['id'] ?>" name="day" required>
                          <?php for ($d = 1; $d <= 31; $d++): ?>
                            <option value="<?= $d ?>"<?= $d === $e['day'] ? ' selected' : '' ?>><?= $d ?></option>
                          <?php endfor; ?>
                        </select>
                      </div>
                      <div class="cal-add-field year">
                        <label for="calEditYear<?= (int) $e['id'] ?>">Year (optional)</label>
                        <input type="number" id="calEditYear<?= (int) $e['id'] ?>" name="year" min="1000" max="<?= (int) date('Y') ?>" value="<?= $e['event_year'] !== null ? (int) $e['event_year'] : '' ?>">
                      </div>
                      <button type="submit" class="btn-primary">Save changes</button>
                      <button type="button" class="linklet cal-edit-cancel" data-target="calEdit<?= (int) $e['id'] ?>">Cancel</button>
                    </div>
                  </form>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <script>
    // Phase 62: "Edit" reveals the matching .cal-entry-edit form inline
    // (event-delegated rather than one listener per row, since a full
    // year's worth of key dates could mean dozens of these); "Cancel"
    // hides it again without submitting. No fetch/AJAX -- Save still
    // does a plain POST + redirect, same as every other mutation here.
    document.addEventListener("click", function (evt) {
      var btn = evt.target.closest(".cal-edit-toggle, .cal-edit-cancel");
      if (!btn) return;
      var target = document.getElementById(btn.dataset.target);
      if (!target) return;
      var opening = !target.classList.contains("is-open");
      target.classList.toggle("is-open", opening);
      if (opening) {
        var titleField = target.querySelector('input[name="title"]');
        if (titleField) titleField.focus();
      }
    });
  </script>

  <?php ourthology_render_tour('calendar', $myPersonId); ?>
</body>
</html>
