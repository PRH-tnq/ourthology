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

/** Delete a media row's underlying file from disk (call before/alongside deleting the DB row). */
function delete_media_file(string $relativePath): void
{
    $full = ourthology_media_dir() . '/' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
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

/** True if $viewerPersonId / $viewerFamilyGroupId may view this media row. */
function can_view_media(array $media, int $viewerPersonId, int $viewerFamilyGroupId): bool
{
    if ((int) $media['owner_person_id'] === $viewerPersonId) {
        return true;
    }
    return $media['visibility'] === 'public' && (int) $media['owner_family_group_id'] === $viewerFamilyGroupId;
}
