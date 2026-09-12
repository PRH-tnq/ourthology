<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Uploaded media lives ABOVE the web root — never inside ourthology.com —
 * so the only way to ever read a file's bytes is through media.php, which
 * re-checks visibility/ownership on every single request. A predictable or
 * leaked filename is harmless on its own: it isn't web-reachable at all.
 */
function ourthology_media_dir(): string
{
    return dirname(__DIR__, 2) . '/ourthology-media';
}

const MEDIA_MAX_BYTES = 25 * 1024 * 1024; // 25MB

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
        throw new RuntimeException('That file type is not supported — please use a JPEG, PNG, GIF, WEBP, MP4, MOV, or WEBM file.');
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
