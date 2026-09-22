<?php
declare(strict_types=1);

/**
 * Phase 90: the first-run welcome video.
 *
 * Two entry points land here, distinguished by ?flow=:
 *  - signup.php redirects new "start a brand-new tree" accounts here with
 *    ?flow=signup — the video plays once, then goes straight to /tree.php
 *    ("My Tree"), no tour prompt (Phil's instruction: a fresh tree has
 *    nothing on it yet, so "My Tree" is the natural first stop, and the
 *    tour question is specific to the claim flow below).
 *  - claim.php redirects a freshly-claimed referral invite here with
 *    ?flow=claim — the video plays once, then asks whether they'd like the
 *    guided tour. Yes lands on /timeline.php, where the existing autostart
 *    (tour_completed_at empty — see timeline.php) picks it up automatically.
 *    No marks the tour dismissed (the same dismiss_tour POST the tour's own
 *    "Skip" button already sends — see tour.js finishTour()) so the
 *    autostart doesn't immediately override their answer, and tells them
 *    the "Take the tour" button is always there when they're ready.
 *
 * Anything else in ?flow (missing, tampered) falls back to the signup
 * behaviour — never assumes a referral relationship that wasn't there.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

require_login();
$me = current_user_with_person();
if ($me === null) {
    logout_user();
    header('Location: /login.php');
    exit;
}

$flow = ($_GET['flow'] ?? '') === 'claim' ? 'claim' : 'signup';
$firstName = trim((string) ($me['first_name'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Welcome — ourthology.com</title>
<link rel="stylesheet" href="/styles.css?v=26">
<style>
  body {
    display: block;
    padding: 0;
    background: #14110E;
    color: var(--card);
    min-height: 100vh;
    overflow-x: hidden;
  }

  .welcome-shell {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px 20px;
    position: relative;
  }

  .welcome-brand {
    position: absolute;
    top: 22px;
    left: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .welcome-brand .wordmark { margin: 0; font-size: 17px; font-weight: 700; color: var(--card); font-family: Georgia, serif; }
  .welcome-brand .wordmark .tld { font-weight: 500; color: #B7AFA0; }

  .skip-btn {
    position: absolute;
    top: 22px;
    right: 24px;
    background: rgba(251,248,241,0.08);
    border: 1px solid rgba(251,248,241,0.28);
    color: var(--card);
    font-size: 13px;
    font-weight: 600;
    padding: 8px 16px;
    border-radius: 999px;
    cursor: pointer;
  }
  .skip-btn:hover { background: rgba(251,248,241,0.16); }

  .welcome-stage {
    width: 100%;
    max-width: 960px;
    transition: opacity .35s ease;
  }
  .welcome-stage.is-hidden { opacity: 0; pointer-events: none; }

  .video-card {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 20px 60px -20px rgba(0,0,0,0.6);
    background: #000;
  }
  .video-card video { width: 100%; height: 100%; display: block; object-fit: cover; }

  .video-controls {
    position: absolute;
    right: 14px;
    bottom: 14px;
    z-index: 2;
  }
  .mute-btn {
    background: rgba(20,17,14,0.55);
    border: 1px solid rgba(251,248,241,0.35);
    color: var(--card);
    font-size: 12px;
    font-weight: 600;
    padding: 7px 14px;
    border-radius: 999px;
    cursor: pointer;
  }
  .mute-btn:hover { background: rgba(20,17,14,0.75); }

  .play-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.4);
    border: 0;
    cursor: pointer;
    z-index: 3;
  }
  .play-overlay[hidden] { display: none; }
  .play-overlay .play-circle {
    width: 72px; height: 72px; border-radius: 50%;
    background: var(--accent);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 10px 30px -8px rgba(0,0,0,0.6);
  }

  .welcome-caption {
    text-align: center;
    color: #C9C0AF;
    font-size: 14px;
    margin: 16px 0 0;
  }

  /* Tour prompt + no-thanks confirmation — both fixed, full-screen,
     fade in over the (now-hidden) video stage. */
  .welcome-overlay {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(20,17,14,0.78);
    opacity: 0;
    transition: opacity .4s ease;
    z-index: 10;
  }
  .welcome-overlay.show { opacity: 1; }
  .welcome-overlay[hidden] { display: none; }

  .welcome-panel {
    background: var(--card);
    color: var(--ink);
    border-radius: 16px;
    padding: 32px;
    max-width: 420px;
    width: 100%;
    text-align: center;
    box-shadow: 0 24px 60px -18px rgba(0,0,0,0.5);
  }
  .welcome-panel h2 {
    margin: 0 0 10px;
    font-family: Georgia, serif;
    font-size: 22px;
    color: var(--ink);
  }
  .welcome-panel p {
    margin: 0 0 22px;
    font-size: 14.5px;
    line-height: 1.5;
    color: var(--ink-soft);
  }
  .welcome-panel-btns {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
  }
  .wbtn {
    flex: 1 1 auto;
    min-width: 140px;
    padding: 11px 16px;
    border-radius: 999px;
    font-size: 14.5px;
    font-weight: 600;
    cursor: pointer;
    border: none;
  }
  .wbtn-primary { background: var(--accent); color: var(--on-accent); }
  .wbtn-primary:hover { background: var(--accent-glow); }
  .wbtn-ghost { background: transparent; color: var(--ink-soft); border: 2px solid var(--line); }
  .wbtn-ghost:hover { background: var(--paper-2); }
  .wbtn:disabled { opacity: .6; cursor: default; }
</style>
</head>
<body>
  <div class="welcome-shell">
    <div class="welcome-brand">
      <svg width="30" height="30" viewBox="0 0 32 32" aria-hidden="true" style="flex:none;display:block;">
        <circle cx="16" cy="16" r="15" fill="#9A2A2A"/>
        <path d="M16 7 C10 8 6.3 12.6 7.4 17.2 C11.2 16.5 14.7 12.6 16 7 Z" fill="#FBF8F1"/>
        <path d="M16 7 C22 8 25.7 12.6 24.6 17.2 C20.8 16.5 17.3 12.6 16 7 Z" fill="#FBF8F1"/>
        <line x1="16" y1="7.2" x2="16" y2="17" stroke="#9A2A2A" stroke-width="1" stroke-linecap="round"/>
        <line x1="16" y1="17" x2="16" y2="23.2" stroke="#FBF8F1" stroke-width="2.2" stroke-linecap="round"/>
        <line x1="16" y1="23.2" x2="12.6" y2="26.6" stroke="#FBF8F1" stroke-width="1.6" stroke-linecap="round"/>
        <line x1="16" y1="23.2" x2="19.4" y2="26.6" stroke="#FBF8F1" stroke-width="1.6" stroke-linecap="round"/>
      </svg>
      <p class="wordmark">ourthology<span class="tld">.com</span></p>
    </div>

    <button type="button" class="skip-btn" id="skipIntroBtn">Skip &rarr;</button>

    <div class="welcome-stage" id="welcomeStage">
      <div class="video-card">
        <video id="welcomeVideo" poster="/assets/video/welcome-tour-poster.jpg" playsinline muted>
          <source src="/assets/video/welcome-tour.mp4?v=1" type="video/mp4">
        </video>
        <div class="video-controls">
          <button type="button" class="mute-btn" id="muteBtn">Unmute</button>
        </div>
        <button type="button" class="play-overlay" id="playOverlay" hidden aria-label="Play">
          <span class="play-circle">
            <svg width="26" height="26" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" fill="#FBF8F1"/></svg>
          </span>
        </button>
      </div>
      <p class="welcome-caption"><?= $firstName !== '' ? 'Welcome, ' . htmlspecialchars($firstName, ENT_QUOTES) . '.' : 'Welcome.' ?> Here's a quick look at what you can do here.</p>
    </div>

    <div class="welcome-overlay" id="tourPromptOverlay" hidden>
      <div class="welcome-panel" id="tourPromptPanel">
        <h2>Want a quick tour?</h2>
        <p>We can walk you through adding memories, building the tree, and inviting family — takes about two minutes. You can always start it later too.</p>
        <div class="welcome-panel-btns">
          <button type="button" class="wbtn wbtn-primary" id="tourYesBtn">Yes, take the tour</button>
          <button type="button" class="wbtn wbtn-ghost" id="tourNoBtn">No thanks</button>
        </div>
      </div>

      <div class="welcome-panel" id="noThanksPanel" hidden>
        <h2>No problem</h2>
        <p>You can start the tour any time — just look for the <strong>"Take the tour"</strong> button on your timeline.</p>
        <div class="welcome-panel-btns">
          <button type="button" class="wbtn wbtn-primary" id="continueBtn">Continue to my timeline</button>
        </div>
      </div>
    </div>
  </div>

  <input type="hidden" id="welcomeCsrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
  <script>
  (function () {
    "use strict";
    var flow = <?= json_encode($flow) ?>;

    var video       = document.getElementById("welcomeVideo");
    var skipBtn     = document.getElementById("skipIntroBtn");
    var muteBtn     = document.getElementById("muteBtn");
    var playOverlay = document.getElementById("playOverlay");
    var stage       = document.getElementById("welcomeStage");
    var promptOverlay = document.getElementById("tourPromptOverlay");
    var promptPanel  = document.getElementById("tourPromptPanel");
    var noThanksPanel = document.getElementById("noThanksPanel");
    var tourYesBtn   = document.getElementById("tourYesBtn");
    var tourNoBtn    = document.getElementById("tourNoBtn");
    var continueBtn  = document.getElementById("continueBtn");

    var advanced = false;

    function goToTree() {
      window.location.href = "/tree.php";
    }

    function showTourPrompt() {
      stage.classList.add("is-hidden");
      promptOverlay.hidden = false;
      // one more frame so the [hidden] removal and the opacity transition
      // don't collapse into a single, transition-less paint.
      requestAnimationFrame(function () {
        requestAnimationFrame(function () { promptOverlay.classList.add("show"); });
      });
    }

    function advance() {
      if (advanced) { return; }
      advanced = true;
      try { video.pause(); } catch (e) {}
      if (flow === "claim") {
        showTourPrompt();
      } else {
        goToTree();
      }
    }

    video.addEventListener("ended", advance);
    skipBtn.addEventListener("click", advance);

    // Autoplay muted (allowed by every major browser); offer an unmute
    // toggle once it's actually playing, and fall back to a tap-to-play
    // overlay for the rare browser/setting that blocks even muted autoplay.
    video.muted = true;
    var playPromise = video.play();
    if (playPromise && typeof playPromise.catch === "function") {
      playPromise.catch(function () {
        playOverlay.hidden = false;
      });
    }
    playOverlay.addEventListener("click", function () {
      playOverlay.hidden = true;
      video.muted = false;
      muteBtn.textContent = "Mute";
      video.play().catch(function () {});
    });
    muteBtn.addEventListener("click", function () {
      video.muted = !video.muted;
      muteBtn.textContent = video.muted ? "Unmute" : "Mute";
    });

    tourYesBtn.addEventListener("click", function () {
      window.location.href = "/timeline.php";
    });

    tourNoBtn.addEventListener("click", function () {
      tourYesBtn.disabled = true;
      tourNoBtn.disabled = true;
      // Same dismiss_tour POST the tour's own "Skip" button sends (see
      // tour.js finishTour()) — records that this account doesn't need
      // timeline.php's automatic first-run autostart any more, so
      // declining here actually sticks rather than being immediately
      // overridden the moment they land there. Best-effort: shown either
      // way, since a network hiccup here only means the autostart could
      // fire once more on a future login, same as tour.js's own comment.
      var fd = new FormData();
      fd.append("action", "dismiss_tour");
      fd.append("csrf_token", document.getElementById("welcomeCsrf").value);
      fetch("/timeline.php", { method: "POST", body: fd, credentials: "same-origin" })
        .catch(function () {})
        .then(function () {
          promptPanel.hidden = true;
          noThanksPanel.hidden = false;
        });
    });

    continueBtn.addEventListener("click", function () {
      window.location.href = "/timeline.php";
    });
  })();
  </script>
</body>
</html>
