"use strict";
/*
 * Phase 72: "Wherever there is an option to paste an image, add a
 * specific 'paste' button that does the same thing as Ctrl-V." Ctrl-V
 * only exists on a physical keyboard -- on iOS/Android there's no
 * keyboard shortcut to press, and long-press "Paste" over a bare <div>
 * dropzone (as opposed to a text field) is unreliable across browsers.
 * A real button, wired to the Async Clipboard API, gives touch users
 * an explicit tap that does the exact same thing Ctrl-V already does
 * on desktop: read whatever image is sitting on the clipboard right
 * now and hand it to the same addFiles()-style function the paste
 * *event* handlers already call.
 *
 * Shared across every picker that supports pasting an image --
 * add_entry.php's #photoDrop, timeline.php's memory-viewer "add media"
 * form, the Memory planner's per-event pickers, and the postcard/
 * greeting-card photo pickers -- so the read-clipboard logic and the
 * button's error handling live in one place instead of being retyped
 * per picker.
 */
(function () {
  // Reads every image the clipboard currently holds (normally just one)
  // and resolves them as real File objects, exactly like
  // e.clipboardData.items would for a keyboard Ctrl-V. Rejects when the
  // browser has no Async Clipboard read support at all (Firefox desktop,
  // very old browsers, or a non-HTTPS context) so callers can show a
  // useful message instead of a silent no-op. The resolved array also
  // carries a `hadFileReference` flag (see below) for callers that want
  // a more specific empty-result message than "no image found".
  function readImages() {
    if (!window.isSecureContext || !navigator.clipboard || !navigator.clipboard.read) {
      return Promise.reject(new Error("clipboard-read-unsupported"));
    }
    return navigator.clipboard.read().then(function (items) {
      var files = [];
      var work = [];
      var hadFileReference = false;
      items.forEach(function (item) {
        var imgType = item.types.filter(function (t) { return t.indexOf("image/") === 0; })[0];
        if (imgType) {
          work.push(item.getType(imgType).then(function (blob) {
            var ext = (imgType.split("/")[1] || "png").split("+")[0];
            files.push(new File([blob], "pasted-image." + ext, { type: imgType }));
          }));
          return;
        }
        // Copying a file straight from Windows File Explorer (Ctrl+C on
        // the file itself, not "Copy image" from inside a site or app)
        // puts a file REFERENCE on the OS clipboard, not image bytes --
        // Chromium-based browsers surface that to the page as
        // text/uri-list, which has no pixel data behind it for this API
        // to read. There's no way to pull the bytes from here; only the
        // older paste *event* can (it materializes the referenced file
        // directly, which is why Ctrl-V still works for this case even
        // though the button can't) -- flag it so wire() can say so.
        if (item.types.indexOf("text/uri-list") !== -1) hadFileReference = true;
      });
      return Promise.all(work).then(function () {
        files.hadFileReference = hadFileReference;
        return files;
      });
    });
  }

  // Wires one Paste <button> up to readImages(). `opts.onFiles(files)` is
  // called with at least one File on success. `opts.onMessage(msg)` --
  // pass null/"" to clear -- surfaces "no image found" / permission /
  // unsupported feedback through whichever error or status element that
  // picker already uses, so this never invents new UI chrome of its own.
  // stopPropagation() keeps the tap from also bubbling up into the
  // dropzone's own "click anywhere opens the file picker" handler.
  function wire(btn, opts) {
    if (!btn || !opts || typeof opts.onFiles !== "function") return;
    var onMessage = typeof opts.onMessage === "function" ? opts.onMessage : function () {};
    btn.addEventListener("click", function (evt) {
      evt.preventDefault();
      evt.stopPropagation();
      readImages().then(function (files) {
        if (!files.length) {
          onMessage(files.hadFileReference
            ? "A file copied from File Explorer can’t be pasted this way — press Ctrl+V instead, or use Attach."
            : "No image found on your clipboard — copy one, then tap Paste again, or try Ctrl+V.");
          return;
        }
        onMessage(null);
        opts.onFiles(files);
      }).catch(function (err) {
        if (err && err.name === "NotAllowedError") {
          onMessage("Allow clipboard access to paste an image, or use Attach instead.");
        } else if (err && err.message === "clipboard-read-unsupported") {
          onMessage("Tap-to-paste isn’t supported in this browser — use Attach instead.");
        } else {
          onMessage("Couldn’t read the clipboard — use Attach instead.");
        }
      });
    });
  }

  window.ourthologyClipboardPaste = { readImages: readImages, wire: wire };
})();
