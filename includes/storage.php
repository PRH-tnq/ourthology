<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/media.php';

/**
 * Storage quota (Phase 34).
 *
 * Every profile's own uploaded media (plus its profile photo) counts
 * against a limit. Phil's plan is to sell larger tiers later — 1GB, then
 * 3GB, then 10GB — but that billing layer isn't built yet, so today
 * every profile gets the same flat starting allowance. This constant is
 * the ONLY thing a future tiered-plan phase needs to change: every page
 * that shows a usage number or bar calls person_storage_summary() below
 * rather than reading this constant directly, so swapping a flat number
 * for a per-account lookup (e.g. a users.storage_quota_bytes column tied
 * to a subscription) later is a one-function change, not a grep-and-
 * replace across every page.
 */
const STORAGE_DEFAULT_QUOTA_BYTES = 1024 * 1024 * 1024; // 1 GiB

/**
 * Bytes used by this person's own timeline media (photos, videos,
 * documents attached to memories THEY own). byte_size is recorded on the
 * media row at upload time (see store_uploaded_media() in media.php), so
 * this is a plain indexed SUM — no filesystem walk needed on every page
 * load. Media on a memory this person is merely tagged in (Phase 17) is
 * deliberately excluded: tagging never copies a memory, so its bytes
 * belong to whoever actually owns and uploaded it, not everyone tagged
 * in it.
 */
function person_media_bytes_used(PDO $pdo, int $personId): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(m.byte_size), 0)
           FROM media m
           JOIN timeline_entries e ON e.id = m.timeline_entry_id
          WHERE e.person_id = :pid'
    );
    $stmt->execute(['pid' => $personId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Bytes used by this person's own profile photo, if they have one.
 * avatar_path has no stored byte_size (unlike a media row), so this one
 * file is measured directly with filesize() — a single stat() call per
 * page load, not worth caching a column for.
 */
function person_avatar_bytes_used(?array $person): int
{
    if ($person === null || empty($person['avatar_path'])) {
        return 0;
    }
    $full = ourthology_media_dir() . '/' . $person['avatar_path'];
    $size = @filesize($full);
    return $size !== false ? $size : 0;
}

/**
 * The quota this person's storage is measured against, in bytes. Flat
 * STORAGE_DEFAULT_QUOTA_BYTES for every profile today — see that
 * constant's own doc comment for what changes when tiered plans ship.
 */
function person_storage_quota_bytes(PDO $pdo, int $personId): int
{
    return STORAGE_DEFAULT_QUOTA_BYTES;
}

/**
 * The full usage picture for one person's profile — bytes used (media +
 * avatar), the quota it's measured against, and the percentage, ready
 * for a tracker bar. Pass the person's own row (from fetch_family_graph()
 * or person_row()) as $person so the avatar file doesn't need a second
 * database lookup; omit it and only the media total is counted.
 */
function person_storage_summary(PDO $pdo, int $personId, ?array $person = null): array
{
    $mediaBytes = person_media_bytes_used($pdo, $personId);
    $avatarBytes = person_avatar_bytes_used($person);
    $usedBytes = $mediaBytes + $avatarBytes;
    $quotaBytes = person_storage_quota_bytes($pdo, $personId);

    $percent = $quotaBytes > 0 ? ($usedBytes / $quotaBytes) * 100 : 0.0;

    return [
        'media_bytes'     => $mediaBytes,
        'avatar_bytes'    => $avatarBytes,
        'used_bytes'      => $usedBytes,
        'quota_bytes'     => $quotaBytes,
        'percent'         => $percent,
        'percent_display' => min(100.0, max(0.0, $percent)),
        'is_over'         => $usedBytes > $quotaBytes,
        'is_near_limit'   => $percent >= 90,
    ];
}

/**
 * Human-readable "412 MB" / "1.2 GB" — binary (1024-based) units, matching
 * how "1GB" is meant elsewhere in this app's own plan (a flat 1024^3-byte
 * allowance, not a marketing 1000^3 GB).
 */
function format_storage_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 MB';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($bytes, 1024));
    $i = max(0, min($i, count($units) - 1));
    $value = $bytes / (1024 ** $i);
    $decimals = ($i >= 2 && $value < 10) ? 1 : 0; // "1.2 GB" but "412 MB" / "48 GB"
    return number_format($value, $decimals) . ' ' . $units[$i];
}
