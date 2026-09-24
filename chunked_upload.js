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
        resolve({ status: xhr.status, body: body });
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
})();
