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

// Phase 36: iPhone/Samsung/etc. photos are commonly HEIC/HEIF, not
// JPEG -- rather than add them as a stored format in their own right,
// every HEIC/HEIF upload is converted to JPEG (see
// ourthology_convert_heic_to_jpeg() below) so MEDIA_ALLOWED/AVATAR_ALLOWED
// above never need a 'heic'/'heif' entry -- what actually lands on disk
// is always one of the formats already listed there. The browser does
// this client-side first when it can (see add_entry.php/edit_person.php's
// heic2any-based conversion); this is the server-side safety net for
// whatever a browser couldn't or didn't convert -- an old browser with no
// JS conversion support, a script blocker, or a direct API upload.
const MEDIA_HEIC_MIME_TYPES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

// The ISOBMFF 'ftyp' box's 4-byte brand code, for the handful of brands
// that mean HEIC/HEIF specifically -- checked independently of finfo's
// mime-type guess (see ourthology_looks_like_heic() below) since a
// shared host's libmagic version is not something this app controls or
// can assume is current. Deliberately excludes 'avif'/'avis' (AVIF is
// the same ISOBMFF container family but a different, already-supported-
// by-browsers format this app has no reason to convert).
const HEIC_HEIF_FTYP_BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1'];

/**
 * Reads just the first 12 bytes of a file and checks for the ISOBMFF
 * 'ftyp' box (offset 4) followed by a known HEIC/HEIF brand code (offset
 * 8) -- a cheap, dependency-free fallback for when finfo/libmagic on this
 * particular host doesn't recognise HEIC/HEIF and reports something
 * generic instead (seen in the wild as application/octet-stream).
 */
function ourthology_sniff_heic_heif(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = fread($handle, 12);
    fclose($handle);
    if ($header === false || strlen($header) < 12) {
        return false;
    }
    if (substr($header, 4, 4) !== 'ftyp') {
        return false;
    }
    return in_array(substr($header, 8, 4), HEIC_HEIF_FTYP_BRANDS, true);
}

/** True if finfo's detected mime type OR the raw magic bytes say this file is HEIC/HEIF. */
function ourthology_looks_like_heic(string $path, ?string $detectedMime): bool
{
    if ($detectedMime !== null && in_array($detectedMime, MEDIA_HEIC_MIME_TYPES, true)) {
        return true;
    }
    return ourthology_sniff_heic_heif($path);
}

/**
 * Converts a HEIC/HEIF file at $sourcePath to a JPEG written directly
 * into $destDir (already inside private-media/, so this never touches
 * anywhere outside the app's own document root -- see the open_basedir
 * note in this file's own header comment), returning the new file's
 * full path, or null if conversion isn't possible on this host.
 *
 * Feature-detected, never assumed: this app runs on shared hosting whose
 * Imagick build (if Imagick is even present at all) may or may not carry
 * the HEIF delegate (libheif) that decoding requires. Both are checked
 * at runtime, and any failure -- missing extension, missing delegate, a
 * corrupt or unusual HEIC file Imagick can't parse -- falls back to
 * returning null rather than throwing, so the caller can show one clear,
 * user-facing message instead of a raw server error.
 */
function ourthology_convert_heic_to_jpeg(string $sourcePath, string $destDir): ?string
{
    if (!class_exists('Imagick')) {
        return null; // Imagick extension not installed on this host
    }
    try {
        $formats = Imagick::queryFormats('HEI*');
    } catch (\Throwable $e) {
        return null;
    }
    if (!in_array('HEIC', $formats, true) && !in_array('HEIF', $formats, true)) {
        return null; // Imagick present, but not built with the HEIF delegate
    }

    $destination = $destDir . '/' . bin2hex(random_bytes(16)) . '.jpg';
    try {
        $image = new Imagick();
        // '[0]' -- a HEIC file can hold a burst/Live Photo sequence of
        // several images; only the first (the actual photo) is kept.
        $image->readImage($sourcePath . '[0]');
        $image->autoOrient();
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(88);
        $ok = $image->writeImage($destination);
        $image->clear();
        $image->destroy();
        if (!$ok || !is_file($destination)) {
            return null;
        }
        return $destination;
    } catch (\Throwable $e) {
        @unlink($destination);
        return null;
    }
}

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

    $dir = ourthology_media_dir() . '/' . $personId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not save the file — please try again.');
    }

    // Phase 36: a HEIC/HEIF photo (iPhone/Samsung/etc.) that reached the
    // server still in that format -- the browser's own client-side
    // conversion either isn't available or didn't run -- is converted
    // to JPEG here instead of being rejected outright.
    if (ourthology_looks_like_heic($file['tmp_name'], $detectedMime)) {
        $destination = ourthology_convert_heic_to_jpeg($file['tmp_name'], $dir);
        if ($destination === null) {
            throw new RuntimeException('That looks like an iPhone/Samsung HEIC photo, and it couldn\'t be converted automatically — please convert it to JPEG first and try again.');
        }
        $filename = basename($destination);
        $detectedMime = 'image/jpeg';
    } else {
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

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Could not save the file — please try again.');
        }
    }
    chmod($destination, 0640);

    // The HEIC conversion path above re-encodes the file, so its byte
    // count on disk no longer matches $file['size'] (the original
    // upload) -- read the real, final size off disk either way rather
    // than special-casing just the converted branch.
    $byteSize = @filesize($destination);
    $byteSize = $byteSize !== false ? $byteSize : $file['size'];

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
        'byte_size' => $byteSize,
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

    $dir = ourthology_media_dir() . '/avatars/' . $personId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not save the photo — please try again.');
    }

    // Phase 36: same HEIC/HEIF -> JPEG server-side safety net as
    // store_uploaded_media() above.
    if (ourthology_looks_like_heic($file['tmp_name'], $detectedMime)) {
        $destination = ourthology_convert_heic_to_jpeg($file['tmp_name'], $dir);
        if ($destination === null) {
            throw new RuntimeException('That looks like an iPhone/Samsung HEIC photo, and it couldn\'t be converted automatically — please convert it to JPEG first and try again.');
        }
        $filename = basename($destination);
        $detectedMime = 'image/jpeg';
    } else {
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

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Could not save the photo — please try again.');
        }
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
    // Phase 33: 'custom' is narrower than 'public' — same family group
    // isn't enough on its own, the viewer also has to be on the entry's
    // OWNING person's own custom_memory_audience list (edited on that
    // person's edit_person.php "Account Settings" tab).
    if ($media['visibility'] === 'custom' && (int) $media['owner_family_group_id'] === $viewerFamilyGroupId) {
        require_once __DIR__ . '/custom_audience.php';
        if (person_in_custom_audience($pdo, (int) $media['owner_person_id'], $viewerPersonId)) {
            return true;
        }
    }
    // A memory tagged-and-approved for the viewer shows on their own
    // timeline (Phase 17) even when it's marked private — the approval
    // itself is what grants them access, same as it would be their own
    // entry, so its media must be reachable for them too, not just the
    // entry's text.
    require_once __DIR__ . '/memory_tags.php';
    if (person_has_approved_tag($pdo, (int) $media['entry_id'], $viewerPersonId)) {
        return true;
    }
    // Phase 32: a tag still awaiting the viewer's own approval also grants
    // access — pending.php shows them the memory's text and media so they
    // can actually judge what they're being asked to approve, rather than
    // asking them to decide blind. This is narrower than the approved case
    // above: it only ever matches the specific person the tag is pending
    // on, never anyone else in the family group.
    return person_has_pending_tag_awaiting_approval($pdo, (int) $media['entry_id'], $viewerPersonId);
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

/** True if $personId has a still-pending tag on $timelineEntryId — the narrower gate (see can_view_media() above) that lets a reviewer preview a memory's media before they've decided whether to approve the tag. */
function person_has_pending_tag_awaiting_approval(PDO $pdo, int $timelineEntryId, int $personId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM memory_tags WHERE timeline_entry_id = :eid AND person_id = :pid AND status = 'pending'"
    );
    $stmt->execute(['eid' => $timelineEntryId, 'pid' => $personId]);
    return (bool) $stmt->fetchColumn();
}

// --- Postcards (Phase 48) ----------------------------------------------
//
// A postcard's front image is validated and stored the same
// real-content-sniffing, HEIC-safety-netted way as any other upload here,
// but images-only and under its own postcards/<senderPersonId>/
// subfolder -- kept apart from a person's own timeline media (and NOT
// counted in person_media_bytes_used()'s quota SUM, since it isn't
// really theirs to keep yet) until an actual, independent copy is made
// via store_postcard_copy_as_media() below -- which IS a normal media
// row from that point on, and so counts against quota completely
// normally, no extra bookkeeping needed anywhere else.

const POSTCARD_IMAGE_MAX_BYTES = 15 * 1024 * 1024; // 15MB -- one photo, generous enough for a phone's full-res shot
const POSTCARD_IMAGE_ALLOWED = [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
];

/**
 * Validates and stores a postcard's front-image upload. Mirrors
 * store_uploaded_media() above (same real-content sniffing, same
 * HEIC/HEIF server-side safety net) but images-only and written to its
 * own postcards/<senderPersonId>/ subfolder rather than a person's normal
 * media dir -- see the block comment just above.
 */
function store_postcard_image(array $file, int $senderPersonId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(match ($file['error'] ?? null) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That photo is too large for this server to accept.',
            UPLOAD_ERR_NO_FILE => 'Drop a photo onto the front of the postcard first.',
            default => 'Upload failed — please try again.',
        });
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload failed — please try again.');
    }
    if ($file['size'] > POSTCARD_IMAGE_MAX_BYTES) {
        throw new RuntimeException('That photo is larger than the 15MB limit.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $dir = ourthology_media_dir() . '/postcards/' . $senderPersonId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not save the photo — please try again.');
    }

    if (ourthology_looks_like_heic($file['tmp_name'], $detectedMime)) {
        $destination = ourthology_convert_heic_to_jpeg($file['tmp_name'], $dir);
        if ($destination === null) {
            throw new RuntimeException('That looks like an iPhone/Samsung HEIC photo, and it couldn\'t be converted automatically — please convert it to JPEG first and try again.');
        }
        $filename = basename($destination);
        $detectedMime = 'image/jpeg';
    } else {
        $extension = null;
        foreach (POSTCARD_IMAGE_ALLOWED as $ext => $mimes) {
            if (in_array($detectedMime, $mimes, true)) {
                $extension = $ext;
                break;
            }
        }
        if ($extension === null) {
            throw new RuntimeException('Postcard photos must be a JPEG, PNG, GIF, or WEBP image.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Could not save the photo — please try again.');
        }
    }
    chmod($destination, 0640);

    $byteSize = @filesize($destination);
    $byteSize = $byteSize !== false ? $byteSize : $file['size'];

    $dims = @getimagesize($destination);
    [$width, $height] = $dims ?: [null, null];

    return [
        'file_path' => 'postcards/' . $senderPersonId . '/' . $filename,
        'mime_type' => $detectedMime,
        'byte_size' => $byteSize,
        'width'     => $width,
        'height'    => $height,
    ];
}

/**
 * Copies an already-stored file (by its path relative to
 * ourthology_media_dir(), e.g. a postcard's own image_path) into a real
 * media-row-ready file under $ownerPersonId's normal timeline media
 * folder -- used when a postcard's photo needs to become an actual,
 * independent media row: the sender's own "record to my timeline" copy,
 * or a recipient's "save to my timeline" copy. A genuine on-disk copy,
 * not a shared reference, so each resulting media row (and, later,
 * delete_media_file() on any one of them) can never affect another.
 */
function store_postcard_copy_as_media(string $sourceRelativePath, string $mimeType, int $ownerPersonId): array
{
    $source = ourthology_media_dir() . '/' . $sourceRelativePath;
    if (!is_file($source)) {
        throw new RuntimeException('That postcard photo is no longer available.');
    }

    $dir = ourthology_media_dir() . '/' . $ownerPersonId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not save the photo — please try again.');
    }

    $extension = pathinfo($source, PATHINFO_EXTENSION) ?: 'jpg';
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $dir . '/' . $filename;

    if (!@copy($source, $destination)) {
        throw new RuntimeException('Could not save the photo — please try again.');
    }
    chmod($destination, 0640);

    $byteSize = @filesize($destination);
    $dims = @getimagesize($destination);
    [$width, $height] = $dims ?: [null, null];

    return [
        'file_path' => $ownerPersonId . '/' . $filename,
        'mime_type' => $mimeType,
        'byte_size' => $byteSize !== false ? $byteSize : 0,
        'width'     => $width,
        'height'    => $height,
    ];
}
