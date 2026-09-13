<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Uploaded media lives inside private-media/, a folder within the app's
 * own document root that carries a .htaccess denying ALL direct HTTP
 * access (see private-media/.htaccess) — so the only way to ever read a
 * file's bytes is through media.php, which re-checks visibility/ownership
 * on every single request. A predictable or leaked filename is harmless
 * on its own: the .htaccess rule blocks the request before it ever
 * reaches a static file handler.
 *
 * This lives inside the document root (rather than above it) deliberately:
 * shared hosts commonly restrict each addon domain's PHP processes to an
 * open_basedir scoped to that domain's own docroot, which would silently
 * break reads/writes to a sibling folder outside it. Keeping the storage
 * folder in-tree avoids that whole class of hosting-specific failure while
 * keeping the same access guarantees (deny-all + per-request auth check).
 */
function ourthology_media_dir(): string
{
    return dirname(__DIR__) . '/private-media';
}

const MEDIA_MAX_BYTES = 25 * 1024 * 1024; // 25MB, per file

// A single timeline entry can carry more than one attachment (see
// store_uploaded_media_files() below) — capped so one submission can't be
// used to dump an unbounded number of files on the server in one request.
const MEDIA_MAX_FILES_PER_ENTRY = 10;

/** extension => [allowed mime types] — used to validate the file's REAL content, not the client-supplied name/type. */
const MEDIA_ALLOWED = [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'mp4'  => ['video/mp4'],
    'mov'  => ['video/quicktime'],
    'webm' => ['video/webm'],
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'txt'  => ['text/plain'],
];

/**
 * Validates and stores an uploaded file (from $_FILES[...]) for a person.
 * Returns ['file_path','mime_type','byte_size','width','height'] for the
 * media table, or throws RuntimeException with a user-facing message.
 */
function store_uploaded_media(array $file, int $personId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(match ($file['error'] ?? null) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large for this server to accept.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'Upload failed — please try again.',
        });
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload failed — please try again.');
    }
    if ($file['size'] > MEDIA_MAX_BYTES) {
        throw new RuntimeException('That file is larger than the 25MB limit.');
    }

    // Detect the REAL mime type from file content — never trust the
    // client-supplied filename or Content-Type.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $extension = null;
    foreach (MEDIA_ALLOWED as $ext => $mimes) {
        if (in_array($detectedMime, $mimes, true)) {
            $extension = $ext;
            break;
        }
    }
    if ($extension === null) {
        throw new RuntimeException('That file type is not supported — please use a JPEG, PNG, GIF, WEBP, MP4, MOV, WEBM, PDF, DOC, DOCX, or TXT file.');
    }

    $dir = ourthology_media_dir() . '/' . $personId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not save the file — please try again.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save the file — please try again.');
    }
    chmod($destination, 0640);

    $width = $height = null;
    if (str_starts_with($detectedMime, 'image/')) {
        $dims = @getimagesize($destination);
        if ($dims) {
            [$width, $height] = $dims;
        }
    }

    return [
        'file_path' => $personId . '/' . $filename,
        'mime_type' => $detectedMime,
        'byte_size' => $file['size'],
        'width'     => $width,
        'height'    => $height,
    ];
}

/**
 * Flattens PHP's nested $_FILES structure for a name="media[]" multi-file
 * input into a plain list of individual file arrays (one per selected
 * file, in submitted order), skipping any slot left empty
 * (UPLOAD_ERR_NO_FILE) — normally the JS-managed picker never submits an
 * empty slot, but an empty/absent field is handled the same way so callers
 * don't need their own special case for "nothing chosen".
 */
function normalize_multi_file_upload(array $filesField): array
{
    $names = $filesField['name'] ?? [];
    $count = is_array($names) ? count($names) : 0;
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        if (($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name'     => $filesField['name'][$i] ?? '',
            'type'     => $filesField['type'][$i] ?? '',
            'tmp_name' => $filesField['tmp_name'][$i] ?? '',
            'error'    => $filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $filesField['size'][$i] ?? 0,
        ];
    }
    return $out;
}

/**
 * Validates and stores every file from a name="media[]" multi-file upload
 * for a person — one call per timeline entry, so an entry can carry several
 * photos, videos and/or documents together (e.g. a memory with three
 * photos and the scanned certificate that goes with them).
 *
 * All-or-nothing: if any file is invalid, every file already written to
 * disk earlier in this same call is deleted again before the exception
 * propagates, so a rejected submission never leaves an orphaned file
 * behind on disk with no database row pointing at it — the same atomicity
 * the surrounding DB transaction already gives the timeline_entries/media
 * rows themselves.
 *
 * Returns a list of ['file_path','mime_type','byte_size','width','height']
 * (the same shape store_uploaded_media() returns for one file), in
 * submitted order, or throws RuntimeException with a user-facing message
 * that names the offending file when there is more than one.
 */
function store_uploaded_media_files(array $filesField, int $personId): array
{
    $files = normalize_multi_file_upload($filesField);
    if (count($files) > MEDIA_MAX_FILES_PER_ENTRY) {
        throw new RuntimeException('Attach at most ' . MEDIA_MAX_FILES_PER_ENTRY . ' files to one entry.');
    }

    $stored = [];
    $multiple = count($files) > 1;
    foreach ($files as $file) {
        try {
            $stored[] = store_uploaded_media($file, $personId);
        } catch (RuntimeException $e) {
            foreach ($stored as $done) {
                delete_media_file($done['file_path']);
            }
            $label = trim((string) ($file['name'] ?? ''));
            $prefix = ($multiple && $label !== '') ? '"' . $label . '": ' : '';
            throw new RuntimeException($prefix . $e->getMessage());
        }
    }
    return $stored;
}

// A profile photo, not a full-resolution upload — kept deliberately small
// and image-only (no video/document types, unlike a timeline memory's
// attachments), stored separately from a person's timeline media under
// its own avatars/ subfolder of the same private-media/ tree so the two
// never collide and a person's photo isn't tied to any one timeline entry.
const AVATAR_MAX_BYTES = 8 * 1024 * 1024; // 8MB
const AVATAR_ALLOWED = [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
];

/**
 * Validates and stores an uploaded profile photo for a person. Returns
 * ['file_path' => 'avatars/<person_id>/<random>.<ext>', 'mime_type' => ...]
 * or throws RuntimeException with a user-facing message. Mirrors
 * store_uploaded_media() above (real-content sniffing via finfo, never the
 * client-supplied name/type) but with its own, smaller size limit and an
 * images-only extension list, and its own avatars/ subfolder so a photo
 * never collides with — or gets swept up by — code that walks a person's
 * timeline media.
 */
function store_uploaded_avatar(array $file, int $personId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(match ($file['error'] ?? null) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That photo is too large for this server to accept.',
            UPLOAD_ERR_NO_FILE => 'Choose a photo to upload.',
            default => 'Upload failed — please try again.',
        });
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload failed — please try again.');
    }
    if ($file['size'] > AVATAR_MAX_BYTES) {
        throw new RuntimeException('That photo is larger than the 8MB limit.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $extension = null;
    foreach (AVATAR_ALLOWED as $ext => $mimes) {
        if (in_array($detectedMime, $mimes, true)) {
            $extension = $ext;
            break;
        }
    }
    if ($extension === null) {
        throw new RuntimeException('Profile photos must be a JPEG, PNG, GIF, or WEBP image.');
    }

    $dir = ourthology_media_dir() . '/avatars/' . $personId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not save the photo — please try again.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save the photo — please try again.');
    }
    chmod($destination, 0640);

    return [
        'file_path' => 'avatars/' . $personId . '/' . $filename,
        'mime_type' => $detectedMime,
    ];
}

/** Delete a media row's underlying file from disk (call before/alongside deleting the DB row). */
function delete_media_file(string $relativePath): void
{
    $full = ourthology_media_dir() . '/' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
    // Also clean up any cached thumbnail(s) ensure_media_thumbnail() below
    // generated for this file, at any size — otherwise a removed or
    // replaced photo's preview copy would linger on disk forever with
    // nothing left pointing at it.
    foreach ((array) glob(media_thumb_dir() . '/*/' . $relativePath . '.jpg') as $stale) {
        @unlink($stale);
    }
}

// --- Performance (Phase 27): cached preview thumbnails + long-lived,
// content-addressed caching for media.php/avatar.php --------------------
//
// Before this, every timeline photo/avatar was served at its original,
// full-resolution size — including for a ~150px card thumbnail — with
// `Cache-Control: max-age=0, must-revalidate` and no ETag/Last-Modified to
// revalidate against, so the browser re-downloaded the full file on every
// single view. Both problems compounded on a photo-heavy timeline. Fixed
// two ways: (1) a resized, re-encoded JPEG copy is generated once per
// file and reused (see ensure_media_thumbnail()), so a small card never
// pulls a multi-megabyte original; (2) both the original and any
// thumbnail are served with a long, "immutable" Cache-Control, since both
// URLs are effectively content-addressed in this app — a media row's file
// never changes in place once created (edit_entry.php's old delete+
// re-insert behaviour, now add_entry.php's edit mode, always writes a
// brand-new row/id for a changed attachment), and an avatar's URL always
// carries a fresh ?v= cache-buster tied to its stored path (Phase 20's
// stylesheet cache-busting, reused) whenever the photo itself changes.

/** Where generated preview thumbnails are cached — a subfolder of the same access-controlled private-media/ tree, so it inherits the same deny-all .htaccess. */
function media_thumb_dir(): string
{
    return ourthology_media_dir() . '/.thumbs';
}

const MEDIA_THUMB_MAX_DIMENSION = 480;  // timeline memory-card previews
const AVATAR_THUMB_MAX_DIMENSION = 240; // profile photos (shown at up to 120px CSS, so 2x for sharp screens)
const MEDIA_THUMB_JPEG_QUALITY = 78;

/**
 * Returns an absolute filesystem path to a cached, resized JPEG preview of
 * an image file at $relativePath (relative to ourthology_media_dir(), the
 * same shape a media/avatar row's file_path already uses), generating it
 * on first request. Returns null — meaning "just serve the original" — if
 * the source isn't a real image GD can decode, if GD isn't available at
 * all, or if generation fails for any reason: a slightly slower but
 * correct full image always beats a broken thumbnail.
 */
function ensure_media_thumbnail(string $relativePath, int $maxDim): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null; // GD not available on this host — fall back everywhere
    }

    $original = ourthology_media_dir() . '/' . $relativePath;
    if (!is_file($original)) {
        return null;
    }

    $thumbPath = media_thumb_dir() . '/' . $maxDim . '/' . $relativePath . '.jpg';
    $srcMtime = filemtime($original);
    if ($srcMtime !== false && is_file($thumbPath) && filemtime($thumbPath) >= $srcMtime) {
        return $thumbPath;
    }

    $dims = @getimagesize($original);
    if (!$dims) {
        return null; // not a real image (or one GD's getimagesize can't read)
    }
    [$srcW, $srcH, $type] = $dims;

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($original),
        IMAGETYPE_PNG  => @imagecreatefrompng($original),
        IMAGETYPE_GIF  => @imagecreatefromgif($original),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($original) : false,
        default        => false,
    };
    if (!$src) {
        return null;
    }

    // Phone photos are very often stored physically landscape with an EXIF
    // orientation tag rather than pre-rotated — browsers already
    // auto-rotate a full-size <img> per spec, so a naively re-encoded
    // thumbnail must apply the same correction itself or it'll show
    // sideways/upside-down next to a correctly-oriented full image.
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($original);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3       => @imagerotate($src, 180, 0),
            6       => @imagerotate($src, -90, 0),
            8       => @imagerotate($src, 90, 0),
            default => false,
        };
        if ($rotated !== false) {
            imagedestroy($src);
            $src = $rotated;
        }
        $srcW = imagesx($src);
        $srcH = imagesy($src);
    }

    $scale = min(1.0, $maxDim / max($srcW, $srcH));
    $dstW = max(1, (int) round($srcW * $scale));
    $dstH = max(1, (int) round($srcH * $scale));

    $dst = imagecreatetruecolor($dstW, $dstH);
    // Thumbnails are always re-encoded as JPEG (smaller, universally
    // supported) — which has no alpha channel — so a transparent PNG/GIF/
    // WEBP is flattened onto white first rather than turning black.
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagedestroy($src);

    $dir = dirname($thumbPath);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        imagedestroy($dst);
        return null;
    }
    $ok = @imagejpeg($dst, $thumbPath, MEDIA_THUMB_JPEG_QUALITY);
    imagedestroy($dst);
    if ($ok) {
        @chmod($thumbPath, 0640);
    }
    return $ok ? $thumbPath : null;
}

/**
 * Sends a file with a long-lived, "immutable" Cache-Control plus
 * conditional-GET support (a 304 with no body when the browser's cached
 * copy is still current) — shared by media.php and avatar.php, whose URLs
 * are both effectively content-addressed (see the block comment above).
 * Still `private` rather than a shared/public cache, since access here is
 * per-request authorization, not a property of the URL alone — the caller
 * must already have finished its own visibility/ownership check before
 * calling this.
 */
function send_cacheable_file(string $path, string $mimeType): void
{
    // PHP's session machinery (started by require_login() earlier in every
    // caller) sends its own Expires/Pragma/Cache-Control headers the
    // moment session_start() runs — meant for the app's actual HTML pages,
    // where "never cache" is exactly right for private family data, but
    // directly fighting the long-lived caching this function exists to
    // add for media/avatar bytes. Cleared here, right before setting the
    // real headers, rather than by changing the session cache limiter
    // globally (session.cache_limiter), which would take the safe
    // never-cache default away from every other page too.
    header_remove('Expires');
    header_remove('Pragma');
    header('Cache-Control: private, max-age=31536000, immutable');
    $mtime = @filemtime($path);
    if ($mtime !== false) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
        if ($ifModifiedSince !== null && @strtotime($ifModifiedSince) >= $mtime) {
            http_response_code(304);
            return;
        }
    }
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}

/**
 * Fetch one media row plus enough of its owning entry/person to decide
 * visibility, or null if it doesn't exist.
 */
function fetch_media_for_view(PDO $pdo, int $mediaId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT m.id, m.file_path, m.mime_type, m.byte_size,
                te.id AS entry_id, te.visibility, te.person_id AS owner_person_id,
                p.family_group_id AS owner_family_group_id
         FROM media m
         JOIN timeline_entries te ON te.id = m.timeline_entry_id
         JOIN persons p ON p.id = te.person_id
         WHERE m.id = :id'
    );
    $stmt->execute(['id' => $mediaId]);
    return $stmt->fetch() ?: null;
}

/**
 * True if $viewerPersonId / $viewerFamilyGroupId may view this media row.
 * $pdo is only used for the tagged-viewer exception below — pass the
 * live connection, not a cached one, since this is a per-request security
 * check.
 */
function can_view_media(array $media, int $viewerPersonId, int $viewerFamilyGroupId, PDO $pdo): bool
{
    if ((int) $media['owner_person_id'] === $viewerPersonId) {
        return true;
    }
    if ($media['visibility'] === 'public' && (int) $media['owner_family_group_id'] === $viewerFamilyGroupId) {
        return true;
    }
    // A memory tagged-and-approved for the viewer shows on their own
    // timeline (Phase 17) even when it's marked private — the approval
    // itself is what grants them access, same as it would be their own
    // entry, so its media must be reachable for them too, not just the
    // entry's text.
    require_once __DIR__ . '/memory_tags.php';
    return person_has_approved_tag($pdo, (int) $media['entry_id'], $viewerPersonId);
}

/** True if $personId has an approved tag on $timelineEntryId — the gate that lets a tagged person view an otherwise-private memory (and, via can_view_media() above, its attached media) wherever it's shared. */
function person_has_approved_tag(PDO $pdo, int $timelineEntryId, int $personId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM memory_tags WHERE timeline_entry_id = :eid AND person_id = :pid AND status = 'approved'"
    );
    $stmt->execute(['eid' => $timelineEntryId, 'pid' => $personId]);
    return (bool) $stmt->fetchColumn();
}
