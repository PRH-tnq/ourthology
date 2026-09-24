<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/graph.php';
require_once __DIR__ . '/media.php';
// ourthology_my_identities() lives in auth.php — every caller of this
// file is a page that has already required auth.php for require_login()
// / current_user_with_person(), so it's assumed loaded rather than
// re-required here (same convention includes/peripheral.php follows for
// graph.php's functions).

/**
 * Phase 59: full, permanent erasure of a single person row and every
 * piece of data that hangs off them — used both for the departing
 * account's own master/home person and, when a peripheral tree (Phase
 * 58) doesn't qualify for a full group wipe (see
 * ourthology_erase_family_group() below), for just their "YOU" node
 * there. Unlike edit_person.php's existing delete_person action (which
 * only ever runs on a still-unclaimed placeholder with zero memories),
 * this also has to clean up everything a real, active account can
 * accumulate — postcards, letters, letter_images, calendar_events, and
 * the peripheral_tree_links row itself, none of which delete_person ever
 * needed to touch.
 *
 * Caller is expected to run this inside its own transaction (see
 * ourthology_delete_own_account() below) — this function does not
 * begin/commit one itself, so it can be composed with the account-row
 * disable and, where relevant, ourthology_erase_family_group(), as one
 * atomic operation.
 *
 * Deletion order below exists entirely to satisfy schema.sql's foreign
 * keys, almost none of which carry ON DELETE CASCADE on the persons
 * side (deliberately — see fk_rel_parent, fk_part_a, fk_postcard_sender,
 * etc.) — every row that references this person has to be removed or
 * repointed before the persons row itself can go.
 */
/**
 * Phase 91: every file an erase_* function below wants gone is routed
 * through here. With no $deferred list it's deleted straight away (the
 * original Phase 59 behaviour); pass an array by reference and the path
 * is collected instead, so the caller can delete the files only AFTER
 * its transaction has actually committed -- a rolled-back delete then
 * leaves both the rows and their files exactly as they were, rather than
 * rows pointing at files that were already removed.
 */
function ourthology_erase_file(string $relativePath, ?array &$deferred): void
{
    if ($deferred === null) {
        delete_media_file($relativePath);
    } else {
        $deferred[] = $relativePath;
    }
}

function ourthology_erase_person(PDO $pdo, int $personId, ?array &$deferredFiles = null): void
{
    $person = person_row($pdo, $personId);
    if ($person === null) {
        return; // already gone -- nothing to do
    }

    // --- files on disk, collected/removed before their rows disappear ---

    if (!empty($person['avatar_path'])) {
        ourthology_erase_file($person['avatar_path'], $deferredFiles);
    }

    $mediaStmt = $pdo->prepare(
        'SELECT m.file_path FROM media m
         JOIN timeline_entries t ON t.id = m.timeline_entry_id
         WHERE t.person_id = :pid'
    );
    $mediaStmt->execute(['pid' => $personId]);
    foreach ($mediaStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        ourthology_erase_file($path, $deferredFiles);
    }

    $postcardImgStmt = $pdo->prepare('SELECT image_path FROM postcards WHERE sender_person_id = :pid');
    $postcardImgStmt->execute(['pid' => $personId]);
    foreach ($postcardImgStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        ourthology_erase_file($path, $deferredFiles);
    }

    // Phase 91: greeting cards (Phase 67) postdate this function and were
    // never cleaned up here -- a card this person sent OR received kept a
    // foreign key to their persons row (fk_card_sender/fk_card_recipient,
    // no cascade), so deleting any account that had ever sent or been
    // sent a card failed outright and rolled back. Every card either side
    // of them is removed, like a letter (cards are single-recipient too).
    $cardImgStmt = $pdo->prepare('SELECT image_path FROM greeting_cards WHERE sender_person_id = :pid OR recipient_person_id = :pid2');
    $cardImgStmt->execute(['pid' => $personId, 'pid2' => $personId]);
    foreach ($cardImgStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        ourthology_erase_file($path, $deferredFiles);
    }

    $letterIdsStmt = $pdo->prepare('SELECT id FROM letters WHERE sender_person_id = :pid OR recipient_person_id = :pid2');
    $letterIdsStmt->execute(['pid' => $personId, 'pid2' => $personId]);
    $ownLetterIds = array_map('intval', $letterIdsStmt->fetchAll(PDO::FETCH_COLUMN));

    if ($ownLetterIds) {
        $in = implode(',', array_fill(0, count($ownLetterIds), '?'));
        $imgStmt = $pdo->prepare("SELECT file_path FROM letter_images WHERE letter_id IN ($in)");
        $imgStmt->execute($ownLetterIds);
        foreach ($imgStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            ourthology_erase_file($path, $deferredFiles);
        }
    }

    // letter_images this person uploaded that AREN'T already covered
    // above — an abandoned draft (letter_id still NULL), or, in
    // principle, an image uploaded into a letter between two other
    // people. Their files are removed here; the rows are deleted with
    // the rest of this person's rows, below.
    $uploadStmt = $pdo->prepare('SELECT file_path, letter_id FROM letter_images WHERE uploader_person_id = :pid');
    $uploadStmt->execute(['pid' => $personId]);
    foreach ($uploadStmt->fetchAll() as $img) {
        $lid = $img['letter_id'] !== null ? (int) $img['letter_id'] : null;
        if ($lid !== null && in_array($lid, $ownLetterIds, true)) {
            continue; // file already removed above; row cascades when the letter row goes
        }
        ourthology_erase_file($img['file_path'], $deferredFiles);
    }

    // --- rows referencing this person, deleted/severed before the
    //     persons row itself can go ---

    // postcard_recipients: this person as a recipient of ANYONE's
    // postcard — also breaks any fk_pr_entry reference into this
    // person's own saved timeline_entries, ahead of deleting those below.
    $pdo->prepare('DELETE FROM postcard_recipients WHERE recipient_person_id = :pid')->execute(['pid' => $personId]);

    // postcards this person sent (cascades their postcard_recipients rows)
    $pdo->prepare('DELETE FROM postcards WHERE sender_person_id = :pid')->execute(['pid' => $personId]);

    // letters this person sent or received (cascades their letter_images rows)
    if ($ownLetterIds) {
        $in = implode(',', array_fill(0, count($ownLetterIds), '?'));
        $pdo->prepare("DELETE FROM letters WHERE id IN ($in)")->execute($ownLetterIds);
    }

    // greeting cards this person sent or received (see the file cleanup
    // above) -- also clears fk_card_entry references into this person's
    // own saved timeline_entries ahead of deleting those below.
    $pdo->prepare('DELETE FROM greeting_cards WHERE sender_person_id = :pid OR recipient_person_id = :pid2')
        ->execute(['pid' => $personId, 'pid2' => $personId]);

    // any remaining letter_images uploaded by this person (abandoned
    // draft, or into a letter that wasn't theirs to send/receive)
    $pdo->prepare('DELETE FROM letter_images WHERE uploader_person_id = :pid')->execute(['pid' => $personId]);

    $pdo->prepare('DELETE FROM calendar_events WHERE created_by_person_id = :pid')->execute(['pid' => $personId]);
    $pdo->prepare('DELETE FROM peripheral_tree_links WHERE master_person_id = :pid OR peripheral_person_id = :pid2')
        ->execute(['pid' => $personId, 'pid2' => $personId]);

    // Sever any OTHER entry's copy-facility pointer into one of this
    // person's own timeline_entries (fk_entry_copied_from — self-
    // referential, no cascade) before those rows are deleted below.
    $ownEntryIdsStmt = $pdo->prepare('SELECT id FROM timeline_entries WHERE person_id = :pid');
    $ownEntryIdsStmt->execute(['pid' => $personId]);
    $ownEntryIds = array_map('intval', $ownEntryIdsStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($ownEntryIds) {
        $in = implode(',', array_fill(0, count($ownEntryIds), '?'));
        $pdo->prepare("UPDATE timeline_entries SET copied_from_entry_id = NULL WHERE copied_from_entry_id IN ($in)")
            ->execute($ownEntryIds);
    }

    // timeline_entries -> media / memory_tags (entry side) cascade automatically
    $pdo->prepare('DELETE FROM timeline_entries WHERE person_id = :pid')->execute(['pid' => $personId]);
    // memory_tags THIS person holds on someone ELSE's entry -- not
    // reached by the cascade just above, which only clears tags on this
    // person's OWN entries.
    $pdo->prepare('DELETE FROM memory_tags WHERE person_id = :pid')->execute(['pid' => $personId]);

    $pdo->prepare('DELETE FROM claim_tokens WHERE person_id = :pid')->execute(['pid' => $personId]);
    $pdo->prepare('DELETE FROM relationships WHERE parent_id = :pid OR child_id = :pid2')
        ->execute(['pid' => $personId, 'pid2' => $personId]);
    $pdo->prepare('DELETE FROM partnerships WHERE person_a_id = :pid OR person_b_id = :pid2')
        ->execute(['pid' => $personId, 'pid2' => $personId]);
    // custom_memory_audience: ON DELETE CASCADE on both person_id and
    // member_person_id -- nothing to do explicitly.

    $pdo->prepare('DELETE FROM persons WHERE id = :id')->execute(['id' => $personId]);
}

/**
 * Phase 59: full wipe of an entire family_group_id — every person in it
 * plus everything that hangs off them, group-wide. Only ever called once
 * the caller (ourthology_delete_own_account() below, via
 * ourthology_account_deletion_preview()'s qualification check) has
 * confirmed nobody OTHER than the departing account has claimed a person
 * anywhere in this group. Mirrors ourthology_erase_person() above but
 * works in bulk, using the fact that postcards/letters/letter_images/
 * calendar_events each carry their own family_group_id column directly
 * rather than needing a join through persons for every row.
 */
function ourthology_erase_family_group(PDO $pdo, int $familyGroupId, ?array &$deferredFiles = null): void
{
    $personsStmt = $pdo->prepare('SELECT id, avatar_path FROM persons WHERE family_group_id = :gid');
    $personsStmt->execute(['gid' => $familyGroupId]);
    $persons = $personsStmt->fetchAll();
    if (!$persons) {
        return; // already gone, or never existed -- nothing to do
    }
    $personIds = array_map(static fn(array $p): int => (int) $p['id'], $persons);
    $in = implode(',', array_fill(0, count($personIds), '?'));

    // --- files on disk ---

    foreach ($persons as $p) {
        if (!empty($p['avatar_path'])) {
            ourthology_erase_file($p['avatar_path'], $deferredFiles);
        }
    }
    $mediaStmt = $pdo->prepare(
        "SELECT m.file_path FROM media m
         JOIN timeline_entries t ON t.id = m.timeline_entry_id
         WHERE t.person_id IN ($in)"
    );
    $mediaStmt->execute($personIds);
    foreach ($mediaStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        ourthology_erase_file($path, $deferredFiles);
    }
    $pcImgStmt = $pdo->prepare('SELECT image_path FROM postcards WHERE family_group_id = :gid');
    $pcImgStmt->execute(['gid' => $familyGroupId]);
    foreach ($pcImgStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        ourthology_erase_file($path, $deferredFiles);
    }
    $letterImgStmt = $pdo->prepare('SELECT file_path FROM letter_images WHERE family_group_id = :gid');
    $letterImgStmt->execute(['gid' => $familyGroupId]);
    foreach ($letterImgStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        ourthology_erase_file($path, $deferredFiles);
    }

    // Phase 91: greeting cards -- see ourthology_erase_person() above.
    // Scoped by family_group_id like postcards/letters, plus any card
    // addressed to or from someone in this group from elsewhere.
    $cardImgStmt = $pdo->prepare(
        "SELECT image_path FROM greeting_cards
         WHERE family_group_id = ? OR sender_person_id IN ($in) OR recipient_person_id IN ($in)"
    );
    $cardImgStmt->execute(array_merge([$familyGroupId], $personIds, $personIds));
    foreach ($cardImgStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        ourthology_erase_file($path, $deferredFiles);
    }

    // --- rows, in FK-safe order ---

    $pdo->prepare("DELETE FROM greeting_cards WHERE family_group_id = ? OR sender_person_id IN ($in) OR recipient_person_id IN ($in)")
        ->execute(array_merge([$familyGroupId], $personIds, $personIds));

    // postcards cascade their postcard_recipients rows automatically
    $pdo->prepare('DELETE FROM postcards WHERE family_group_id = :gid')->execute(['gid' => $familyGroupId]);
    // letters cascade their letter_images rows automatically
    $pdo->prepare('DELETE FROM letters WHERE family_group_id = :gid')->execute(['gid' => $familyGroupId]);
    // any letter_images left (abandoned drafts, letter_id still NULL)
    $pdo->prepare('DELETE FROM letter_images WHERE family_group_id = :gid')->execute(['gid' => $familyGroupId]);
    $pdo->prepare('DELETE FROM calendar_events WHERE family_group_id = :gid')->execute(['gid' => $familyGroupId]);
    $pdo->prepare("DELETE FROM peripheral_tree_links WHERE master_person_id IN ($in) OR peripheral_person_id IN ($in)")
        ->execute(array_merge($personIds, $personIds));

    $entryIdsStmt = $pdo->prepare("SELECT id FROM timeline_entries WHERE person_id IN ($in)");
    $entryIdsStmt->execute($personIds);
    $entryIds = array_map('intval', $entryIdsStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($entryIds) {
        $inE = implode(',', array_fill(0, count($entryIds), '?'));
        $pdo->prepare("UPDATE timeline_entries SET copied_from_entry_id = NULL WHERE copied_from_entry_id IN ($inE)")
            ->execute($entryIds);
    }

    $pdo->prepare("DELETE FROM timeline_entries WHERE person_id IN ($in)")->execute($personIds);
    $pdo->prepare("DELETE FROM memory_tags WHERE person_id IN ($in)")->execute($personIds);
    $pdo->prepare("DELETE FROM claim_tokens WHERE person_id IN ($in)")->execute($personIds);
    $pdo->prepare("DELETE FROM relationships WHERE parent_id IN ($in) OR child_id IN ($in)")
        ->execute(array_merge($personIds, $personIds));
    $pdo->prepare("DELETE FROM partnerships WHERE person_a_id IN ($in) OR person_b_id IN ($in)")
        ->execute(array_merge($personIds, $personIds));
    // custom_memory_audience: ON DELETE CASCADE on both sides -- nothing
    // to do explicitly.

    $pdo->prepare('DELETE FROM persons WHERE family_group_id = :gid')->execute(['gid' => $familyGroupId]);
}

/**
 * Phase 59: everything delete_account.php's confirmation page shows the
 * user before they can proceed, plus the same qualification check
 * ourthology_delete_own_account() itself trusts for whether a peripheral
 * tree gets a full group wipe or just has its one "YOU" node erased --
 * built entirely read-only (no writes), so it's safe to call as often as
 * the confirmation flow re-renders.
 */
function ourthology_account_deletion_preview(PDO $pdo, int $userId): array
{
    $identities = ourthology_my_identities($pdo, $userId);
    $home = null;
    $peripheral = null;
    foreach ($identities as $identity) {
        if ($identity['is_home']) {
            $home = $identity;
        } else {
            $peripheral = $identity;
        }
    }

    $countFor = static function (int $personId) use ($pdo): array {
        $q = static function (string $sql, array $params) use ($pdo): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };
        return [
            'memories'      => $q('SELECT COUNT(*) FROM timeline_entries WHERE person_id = :pid', ['pid' => $personId]),
            'relationships' => $q('SELECT COUNT(*) FROM relationships WHERE parent_id = :pid OR child_id = :pid2', ['pid' => $personId, 'pid2' => $personId]),
            'partnerships'  => $q('SELECT COUNT(*) FROM partnerships WHERE person_a_id = :pid OR person_b_id = :pid2', ['pid' => $personId, 'pid2' => $personId]),
            'postcards'     => $q('SELECT COUNT(*) FROM postcards WHERE sender_person_id = :pid', ['pid' => $personId]),
            'letters'       => $q('SELECT COUNT(*) FROM letters WHERE sender_person_id = :pid OR recipient_person_id = :pid2', ['pid' => $personId, 'pid2' => $personId]),
            'cards'         => $q('SELECT COUNT(*) FROM greeting_cards WHERE sender_person_id = :pid OR recipient_person_id = :pid2', ['pid' => $personId, 'pid2' => $personId]),
        ];
    };

    $result = [
        'home'                                 => $home,
        'home_counts'                          => $home !== null ? $countFor((int) $home['person_id']) : null,
        'peripheral'                           => $peripheral,
        'peripheral_counts'                    => null,
        'peripheral_qualifies_for_group_wipe'  => false,
        'peripheral_group_person_count'        => 0,
    ];

    if ($peripheral !== null) {
        $groupId = (int) $peripheral['family_group_id'];
        // "no unclaimed profiles" per Phil's own wording, narrowed (after
        // review) to "no profile claimed by anyone OTHER than this
        // account" -- the literal reading would risk wiping out a
        // different, real account's data if a separately-signed-up
        // relative had claimed a spot on this same peripheral tree.
        $otherClaimStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM persons WHERE family_group_id = :gid AND claimed_by_user_id IS NOT NULL AND claimed_by_user_id <> :uid'
        );
        $otherClaimStmt->execute(['gid' => $groupId, 'uid' => $userId]);
        $qualifies = ((int) $otherClaimStmt->fetchColumn()) === 0;
        $result['peripheral_qualifies_for_group_wipe'] = $qualifies;

        if ($qualifies) {
            $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM persons WHERE family_group_id = :gid');
            $cntStmt->execute(['gid' => $groupId]);
            $result['peripheral_group_person_count'] = (int) $cntStmt->fetchColumn();
        } else {
            $result['peripheral_counts'] = $countFor((int) $peripheral['person_id']);
        }
    }

    return $result;
}

/**
 * Phase 59: "I may want to delete my profile and leave the site" -- the
 * whole account-departure operation, run as one transaction. The caller
 * (delete_account.php's POST handler) is responsible for every
 * confirmation gate up to this point (re-typed email, password
 * re-entry, the "I understand this cannot be undone" checkbox, CSRF) --
 * by the time this runs, the decision is final.
 *
 * What happens, in order:
 *   1. The account row itself is disabled and scrubbed (never hard-
 *      deleted -- see schema.sql's users.status column and
 *      fk_users_person: dozens of OTHER people's relationships,
 *      partnerships, memories, postcards, letters etc. reference
 *      users(id) with no cascade, and hard-deleting the row would
 *      either violate those constraints or require touching everyone
 *      else's shared family history just to remove one departing
 *      account). person_id is nulled first, satisfying fk_users_person
 *      ahead of any person-row deletion below -- login.php's existing
 *      status==='active' check then blocks this account from ever
 *      logging back in, and current_user_with_person()'s INNER JOIN on
 *      persons means any lingering session gets force-logged-out on its
 *      very next page load, with no further code needed for either.
 *   2. Their master/home person row is fully erased -- every
 *      relationship, partnership, memory, postcard, letter of theirs
 *      gone (ourthology_erase_person()).
 *   3. If they'd created a peripheral tree (Phase 58), it's erased too
 *      -- as a WHOLE separate family tree if nobody else has claimed a
 *      spot on it (ourthology_erase_family_group()), or, if someone
 *      else has, just their own "YOU" node there, leaving that other
 *      account's tree completely untouched.
 *
 * Throws on failure; caller wraps the call in its own try/catch around
 * a transaction boundary, the same pattern edit_person.php's
 * delete_person action already uses.
 */
function ourthology_delete_own_account(PDO $pdo, int $userId, ?array &$deferredFiles = null): void
{
    $preview = ourthology_account_deletion_preview($pdo, $userId);
    if ($preview['home'] === null) {
        throw new RuntimeException("Could not find this account's own identity.");
    }

    $pdo->prepare(
        "UPDATE users SET person_id = NULL, status = 'disabled',
                email = CONCAT('deleted-user-', id, '@ourthology.invalid'),
                password_hash = :hash
         WHERE id = :uid"
    )->execute([
        'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        'uid'  => $userId,
    ]);

    if ($preview['peripheral'] !== null) {
        if ($preview['peripheral_qualifies_for_group_wipe']) {
            ourthology_erase_family_group($pdo, (int) $preview['peripheral']['family_group_id'], $deferredFiles);
        } else {
            ourthology_erase_person($pdo, (int) $preview['peripheral']['person_id'], $deferredFiles);
        }
    }

    ourthology_erase_person($pdo, (int) $preview['home']['person_id'], $deferredFiles);
}
