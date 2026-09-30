/*
 * Phase 91: resumable, chunked uploads for memory attachments.
 *
 * Why: a memory used to be saved as ONE multipart request carrying every
 * attached file at once (up to 25 x 30MB). On an iPad any blip during that
 * one long request -- Wi-Fi dropping for a moment, the screen auto-locking,
 * Safari suspending the tab -- killed the whole save, and Safari reports a
 * connection cut mid-upload as "not connected to the internet" / "the
 * network connection was lost". This sends each file on its own, in small
 * pieces, to /media_upload.php; a failed piece is retried (waiting for the
 * connection / the tab to come back first) and the upload carries on from
 * the last byte the server confirmed, instead of starting again.
 *
 * window.ourthologyChunkedUpload.upload(file, opts) -> Promise<token>
 *   opts.csrf        CSRF token
 *   opts.fields      extra POST fields (purpose, target_person_id / entry_id)
 *   opts.onProgress  function(bytesSent, totalBytes)
 * Rejects with an Error whose .fatal is true when retrying can't help
 * (file rejected, signed out) and false when the connection gave up.
 *
 * window.ourthologyChunkedUpload.supported -- false on a browser too old
 * for any of this; callers then fall back to a plain form submission.
 */
(function () {
  "use strict";

  var ENDPOINT = "/media_upload.php";
  var CHUNK_BYTES = 2 * 1024 * 1024; // small enough to finish quickly even on a weak connection
  var MAX_ATTEMPTS = 8;              // per chunk, with backoff -- roughly two minutes of patience in total
  var REQUEST_TIMEOUT_MS = 120000;

  var supported = typeof XMLHttpRequest === "function" && typeof FormData === "function" &&
    typeof Blob === "function" && typeof Blob.prototype.slice === "function" && typeof Promise === "function";

  function newUploadId() {
    var bytes = new Uint8Array(16);
    if (window.crypto && window.crypto.getRandomValues) {
      window.crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < 16; i++) { bytes[i] = Math.floor(Math.random() * 256); }
    }
    return Array.prototype.map.call(bytes, function (b) { return ("0" + b.toString(16)).slice(-2); }).join("");
  }

  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  // Phase 113: the host's own bot check. On iPads (mobile networks and
  // iCloud Private Relay share addresses, so they get flagged) the web
  // server sometimes answers a request with its "Verifying…" / "waiting to
  // verify" page instead of passing it on to us. A background request like
  // this upload can't complete that check itself -- only a real page visit
  // can -- so it used to just fail over and over. Now: a reply that isn't
  // ours means "let the check run": load a tiny page (/ping.php) in a
  // hidden frame, which the browser treats as an ordinary visit, wait for
  // our own reply to show up in it, then carry on.
  var PING = "/ping.php";
  function isOurs(text) { return typeof text === "string" && text.indexOf('"ourthology":"ping"') !== -1; }
  function pingDirect() {
    return new Promise(function (resolve) {
      var xhr = new XMLHttpRequest();
      xhr.open("POST", PING + "?t=" + Date.now(), true);
      xhr.timeout = 20000;
      xhr.withCredentials = true;
      xhr.onload = function () { resolve(isOurs(xhr.responseText)); };
      xhr.onerror = xhr.ontimeout = xhr.onabort = function () { resolve(false); };
      xhr.send("");
    });
  }
  var clearing = null;
  function clearSecurityCheck(onStatus) {
    if (clearing) return clearing;
    if (onStatus) onStatus("verifying");
    clearing = new Promise(function (resolve) {
      var frame = document.createElement("iframe");
      frame.setAttribute("aria-hidden", "true");
      frame.tabIndex = -1;
      frame.style.cssText = "position:absolute;width:1px;height:1px;left:-9999px;top:0;border:0;opacity:0;";
      var started = Date.now(), done = false;
      function finish(ok) {
        if (done) return;
        done = true;
        clearInterval(timer);
        setTimeout(function () { if (frame.parentNode) frame.parentNode.removeChild(frame); }, 0);
        resolve(ok);
      }
      function inFrameIsOurs() {
        try { var d = frame.contentDocument; return !!(d && d.body && isOurs(d.body.textContent)); } catch (e) { return false; }
      }
      var timer = setInterval(function () {
        if (inFrameIsOurs()) { pingDirect().then(function (ok) { if (ok) finish(true); }); }
        if (Date.now() - started > 45000) finish(false);
      }, 700);
      frame.src = PING + "?frame=1&t=" + Date.now();
      document.body.appendChild(frame);
    }).then(function (ok) {
      clearing = null;
      if (onStatus) onStatus(ok ? "verified" : "blocked");
      return ok;
    });
    return clearing;
  }
  // For a plain form about to be sent (not an upload): make sure the site
  // is answering directly first, so the check can't swallow the form.
  function ensureClear(onStatus) {
    return pingDirect().then(function (ok) { return ok ? true : clearSecurityCheck(onStatus); });
  }

  // Don't burn retries while the device is offline or the tab is in the
  // background (iPadOS pauses background tabs) -- wait until both are back.
  function whenReady() {
    return new Promise(function (resolve) {
      function ok() { return navigator.onLine !== false && document.visibilityState !== "hidden"; }
      if (ok()) { resolve(); return; }
      function check() {
        if (ok()) {
          window.removeEventListener("online", check);
          document.removeEventListener("visibilitychange", check);
          resolve();
        }
      }
      window.addEventListener("online", check);
      document.addEventListener("visibilitychange", check);
    });
  }

  function sendChunk(blob, fields, onChunkProgress) {
    return new Promise(function (resolve) {
      var fd = new FormData();
      Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
      fd.append("chunk", blob, "chunk");
      var xhr = new XMLHttpRequest();
      xhr.open("POST", ENDPOINT, true);
      xhr.timeout = REQUEST_TIMEOUT_MS;
      xhr.withCredentials = true;
      if (xhr.upload && onChunkProgress) {
        xhr.upload.onprogress = function (e) { if (e.lengthComputable) { onChunkProgress(e.loaded); } };
      }
      xhr.onload = function () {
        var body = null;
        try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }
        // an answer that isn't our JSON at all (and not a plain dropped
        // connection) is someone else's page in the way -- the bot check
        resolve({ status: xhr.status, body: body, notOurs: body === null && xhr.status !== 0 });
      };
      xhr.onerror = xhr.ontimeout = xhr.onabort = function () { resolve({ status: 0, body: null }); };
      xhr.send(fd);
    });
  }

  function upload(file, opts) {
    opts = opts || {};
    var total = file.size;
    var uploadId = newUploadId();
    var offset = 0;
    var attempts = 0;
    var securityChecks = 0;
    var report = typeof opts.onProgress === "function" ? opts.onProgress : function () {};

    function fail(message, fatal) {
      var err = new Error(message);
      err.fatal = !!fatal;
      return Promise.reject(err);
    }

    function step() {
      if (total === 0) { return fail('"' + file.name + '" is empty.', true); }
      var end = Math.min(offset + CHUNK_BYTES, total);
      var fields = {
        csrf_token: opts.csrf || "",
        upload_id: uploadId,
        offset: String(offset),
        file_size: String(total),
        file_name: file.name || "file"
      };
      Object.keys(opts.fields || {}).forEach(function (k) { fields[k] = opts.fields[k]; });
      return whenReady().then(function () {
        return sendChunk(file.slice(offset, end), fields, function (loaded) { report(Math.min(total, offset + loaded), total); });
      }).then(function (res) {
        var b = res.body;
        if (res.status === 200 && b && b.ok) {
          attempts = 0;
          offset = b.received;
          report(offset, total);
          if (b.done) { return b.token; }
          return step();
        }
        if (res.status === 409 && b && typeof b.received === "number") {
          offset = b.received; // server already has more (or less) than we thought -- resume there
          return step();
        }
        if (b && b.fatal) {
          return fail(b.error || "That file couldn't be uploaded.", true);
        }
        if (res.notOurs) {
          securityChecks += 1;
          if (securityChecks > 3) {
            return fail("The website's security check (\u201cVerifying\u201d) is holding up \"" + file.name + "\" \u2014 wait a minute, then try again.", false);
          }
          return clearSecurityCheck(opts.onStatus).then(function () { return step(); });
        }
        attempts += 1;
        if (attempts >= MAX_ATTEMPTS) {
          return fail("The connection kept dropping while uploading \"" + file.name + "\".", false);
        }
        return wait(Math.min(30000, 1000 * Math.pow(2, attempts - 1))).then(step);
      });
    }
    return step();
  }

  window.ourthologyChunkedUpload = { supported: supported, upload: upload };
  // Send a form once the site is answering directly (always sends it in
  // the end, even if the check couldn't be confirmed).
  function submitWhenClear(form, onStatus) {
    return ensureClear(onStatus).then(function () { form.submit(); }, function () { form.submit(); });
  }
  window.ourthologySecurityCheck = { ensure: ensureClear, clear: clearSecurityCheck, submit: submitWhenClear };
})();
