-- ourthology.com — database schema v1
-- MySQL/MariaDB, InnoDB, utf8mb4
-- Design notes live in the project doc "architecture.md" — read that first,
-- this file is the SQL implementation of the decisions recorded there.

-- ---------------------------------------------------------------------
-- persons: every real individual in the shared family graph, whether or
-- not they've registered yet. A person created by someone else (not yet
-- claimed) has claimed_by_user_id = NULL.
-- ---------------------------------------------------------------------
CREATE TABLE persons (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name          VARCHAR(60)  NOT NULL,
  middle_name         VARCHAR(60)  NULL,
  surname             VARCHAR(60)  NULL,
  born                DATE         NULL,
  died                DATE         NULL,
  avatar_path         VARCHAR(255) NULL,
  claimed_by_user_id  INT UNSIGNED NULL,
  created_by_user_id  INT UNSIGNED NULL,
  -- All persons reachable from one another via confirmed relationships
  -- share a family_group_id (maintained by app logic as a union-find
  -- structure, merged whenever a confirmed edge joins two groups).
  -- This turns "is X visible to me" into a single indexed equality check
  -- instead of a graph walk on every page load.
  family_group_id     INT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_claimed_user (claimed_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- users: login accounts. Every user maps to exactly one person (the
-- record they claimed or created for themselves at signup).
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(255) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  person_id       INT UNSIGNED NOT NULL,
  status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
  -- Death-trigger fields are deferred (see architecture.md "Open items") —
  -- columns reserved here so the later feature doesn't need a schema
  -- migration mid-flight, but no logic reads/writes them yet.
  deceased_at             DATETIME NULL,
  death_release_choice    ENUM('release_to_family','destroy') NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at   DATETIME NULL,
  -- Set once someone dismisses or finishes the first-login onboarding
  -- tour (Phase 19) — NULL means "hasn't seen it yet," checked on
  -- timeline.php to decide whether to show it.
  tour_completed_at DATETIME NULL,
  UNIQUE KEY uniq_person (person_id),
  CONSTRAINT fk_users_person FOREIGN KEY (person_id) REFERENCES persons(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE persons
  ADD CONSTRAINT fk_persons_claimed_by FOREIGN KEY (claimed_by_user_id) REFERENCES users(id),
  ADD CONSTRAINT fk_persons_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id);

-- ---------------------------------------------------------------------
-- relationships: directed parent -> child edges (direction matters;
-- siblings/grandparents/etc. are derived by traversal, not stored).
-- status='pending_approval' when person_id on the *other* end is already
-- claimed by a different user than whoever created the edge — that user
-- must confirm before the edge counts for graph membership/visibility.
-- ---------------------------------------------------------------------
CREATE TABLE relationships (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id           INT UNSIGNED NOT NULL,
  child_id            INT UNSIGNED NOT NULL,
  relation_kind       ENUM('genetic','step','adoptive') NOT NULL DEFAULT 'genetic',
  status              ENUM('confirmed','pending_approval') NOT NULL DEFAULT 'confirmed',
  created_by_user_id  INT UNSIGNED NOT NULL,
  approving_user_id   INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at         DATETIME NULL,
  UNIQUE KEY uniq_parent_child (parent_id, child_id),
  CONSTRAINT fk_rel_parent FOREIGN KEY (parent_id) REFERENCES persons(id),
  CONSTRAINT fk_rel_child FOREIGN KEY (child_id) REFERENCES persons(id),
  CONSTRAINT fk_rel_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_rel_approving FOREIGN KEY (approving_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- partnerships: spouse/partner edges (symmetric, so order of a/b is
-- arbitrary — app code always stores the lower person id first to keep
-- lookups simple).
-- ---------------------------------------------------------------------
CREATE TABLE partnerships (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_a_id         INT UNSIGNED NOT NULL,
  person_b_id         INT UNSIGNED NOT NULL,
  kind                ENUM('married','partner') NOT NULL DEFAULT 'married',
  status              ENUM('confirmed','pending_approval') NOT NULL DEFAULT 'confirmed',
  created_by_user_id  INT UNSIGNED NOT NULL,
  approving_user_id   INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at         DATETIME NULL,
  CONSTRAINT fk_part_a FOREIGN KEY (person_a_id) REFERENCES persons(id),
  CONSTRAINT fk_part_b FOREIGN KEY (person_b_id) REFERENCES persons(id),
  CONSTRAINT fk_part_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_part_approving FOREIGN KEY (approving_user_id) REFERENCES users(id),
  CONSTRAINT chk_part_distinct CHECK (person_a_id <> person_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- claim_tokens: manual-invite mechanism. Whoever adds an unclaimed
-- person gets a single-use, expiring link to share themselves (by text,
-- email, whatever) — no system-sent email in v1.
-- ---------------------------------------------------------------------
CREATE TABLE claim_tokens (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id           INT UNSIGNED NOT NULL,
  token               CHAR(43) NOT NULL UNIQUE, -- URL-safe base64 of 32 random bytes
  created_by_user_id  INT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at          DATETIME NOT NULL,
  used_at             DATETIME NULL,
  CONSTRAINT fk_claim_person FOREIGN KEY (person_id) REFERENCES persons(id),
  CONSTRAINT fk_claim_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- timeline_entries: memories/diary entries, each owned by one person.
-- visibility='public' means visible to the owner's whole family_group_id
-- (per the "whole connected graph" decision); 'private' means visible
-- only to the owning user until the (not-yet-built) death trigger fires;
-- 'custom' (Phase 33) means visible only to whoever is on that SAME
-- owning person's custom_memory_audience list below, wherever it's
-- checked (fetch_entries_for_person() in includes/entries.php,
-- can_view_media() in includes/media.php) — edited on edit_person.php's
-- own "Account Settings" tab, not per memory.
-- ---------------------------------------------------------------------
CREATE TABLE timeline_entries (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id           INT UNSIGNED NOT NULL,
  entry_type          ENUM('note','photo','video','diary') NOT NULL DEFAULT 'note',
  title               VARCHAR(255) NULL,
  body                TEXT NULL,
  occurred_on         DATE NULL,
  visibility          ENUM('private','public','custom') NOT NULL DEFAULT 'private',
  created_by_user_id  INT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_entry_person FOREIGN KEY (person_id) REFERENCES persons(id),
  CONSTRAINT fk_entry_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- media: files on disk (never DB blobs); one timeline entry can carry
-- several (e.g. a multi-photo memory).
-- ---------------------------------------------------------------------
CREATE TABLE media (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  timeline_entry_id   INT UNSIGNED NOT NULL,
  file_path           VARCHAR(500) NOT NULL,
  mime_type           VARCHAR(100) NOT NULL,
  byte_size           INT UNSIGNED NOT NULL,
  width               INT UNSIGNED NULL,
  height              INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_media_entry FOREIGN KEY (timeline_entry_id)
    REFERENCES timeline_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- memory_tags: someone tagged on another person's timeline entry ("this
-- memory is about you too"). The memory stays ONE shared timeline_entries
-- row — tagging never copies it — approving a tag just makes that same
-- row also show up on the tagged person's own timeline and marks their
-- tag approved.
--
-- Tagging a person already claimed by another user creates a
-- status='pending' row that person must approve on pending.php before
-- the memory appears for them — mirroring relationships/partnerships
-- exactly, including "decline = DELETE the row outright, no trace kept"
-- (so this enum only ever needs 'pending'/'approved', never 'declined').
-- Tagging an unclaimed person auto-approves immediately: nobody is
-- logged in as them to ask, matching the "unclaimed = open to the whole
-- family" rule already used elsewhere (person_is_editable_by()).
--
-- note is the tagged person's own short text about the memory, entered
-- once they've approved (or immediately, for an auto-approved unclaimed
-- tag) — shown alongside the memory on every profile it's referenced
-- from. It is theirs alone to write/edit; the memory's creator can't
-- touch it, and it never becomes editable by anyone else.
-- ---------------------------------------------------------------------
CREATE TABLE memory_tags (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  timeline_entry_id   INT UNSIGNED NOT NULL,
  person_id           INT UNSIGNED NOT NULL,
  status              ENUM('pending','approved') NOT NULL DEFAULT 'pending',
  note                TEXT NULL,
  created_by_user_id  INT UNSIGNED NOT NULL,
  approving_user_id   INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at         DATETIME NULL,
  UNIQUE KEY uniq_entry_person (timeline_entry_id, person_id),
  -- Cascades on the entry side (deleting a memory deletes its tags with
  -- it, same precedent as timeline_entries -> media above); deliberately
  -- NOT cascaded on the person side — edit_person.php's delete_person
  -- action deletes a departing person's own memory_tags rows itself,
  -- inside the same transaction as its other explicit cleanup deletes.
  CONSTRAINT fk_tag_entry FOREIGN KEY (timeline_entry_id)
    REFERENCES timeline_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_tag_person FOREIGN KEY (person_id) REFERENCES persons(id),
  CONSTRAINT fk_tag_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_tag_approving FOREIGN KEY (approving_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- custom_memory_audience (Phase 33): the audience list behind a memory's
-- 'custom' visibility, above — one row per (person_id, member_person_id)
-- pair. person_id is whoever the list belongs to (edited on their own
-- edit_person.php "Account Settings" tab); member_person_id is one
-- family member allowed to see person_id's memories whenever those are
-- marked Custom. Never contains person_id itself as its own member (the
-- owner can always see their own memories regardless of this list) —
-- enforced both by chk_audience_distinct here and by
-- set_custom_audience() in includes/custom_audience.php.
-- ---------------------------------------------------------------------
CREATE TABLE custom_memory_audience (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id           INT UNSIGNED NOT NULL,
  member_person_id    INT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_audience_pair (person_id, member_person_id),
  CONSTRAINT fk_audience_person FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
  CONSTRAINT fk_audience_member FOREIGN KEY (member_person_id) REFERENCES persons(id) ON DELETE CASCADE,
  CONSTRAINT chk_audience_distinct CHECK (person_id <> member_person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_persons_family_group ON persons(family_group_id);
CREATE INDEX idx_audience_member ON custom_memory_audience(member_person_id);
CREATE INDEX idx_entries_person_visibility ON timeline_entries(person_id, visibility);
CREATE INDEX idx_rel_status ON relationships(status);
CREATE INDEX idx_part_status ON partnerships(status);
CREATE INDEX idx_tags_status ON memory_tags(status);
CREATE INDEX idx_tags_person ON memory_tags(person_id, status);

-- ---------------------------------------------------------------------
-- Phase 48: postcards. A sender addresses a photo + message to one,
-- several, or "everyone" (resolved to every claimed person in their
-- family group AT SEND TIME -- a snapshot, not live membership, so a
-- later-joining member never retroactively receives an old postcard).
-- Its image is deliberately NOT a media row and lives outside
-- timeline_entries entirely -- see includes/media.php's
-- store_postcard_image()/store_postcard_copy_as_media() -- until either
-- the sender (record_to_timeline at send time) or a recipient (save vs.
-- discard, after reading) chooses to keep an actual, independent copy of
-- it as a real timeline_entries + media row they own.
-- ---------------------------------------------------------------------
CREATE TABLE postcards (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_person_id    INT UNSIGNED NOT NULL,
  created_by_user_id  INT UNSIGNED NOT NULL,
  family_group_id     INT UNSIGNED NOT NULL,
  image_path          VARCHAR(255) NOT NULL,
  image_mime_type     VARCHAR(100) NOT NULL,
  image_byte_size     INT UNSIGNED NOT NULL,
  image_width         INT UNSIGNED NULL,
  image_height        INT UNSIGNED NULL,
  message             TEXT NOT NULL,
  audience            ENUM('everyone','selected') NOT NULL DEFAULT 'selected',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_postcard_sender FOREIGN KEY (sender_person_id) REFERENCES persons(id),
  CONSTRAINT fk_postcard_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- postcard_recipients: one row per person a postcard was addressed to --
-- this app's Pending-queue row for it (fetch_pending_postcards_for_person()
-- in includes/postcards.php), mirroring memory_tags' own
-- pending/resolved shape but with an extra step: 'pending' (never
-- opened) -> 'read' (opened, not yet decided) -> 'saved' (kept, with
-- timeline_entry_id pointing at the resulting copy) or 'discarded'
-- (closed without keeping it -- nothing deleted, just marked closed).
-- ---------------------------------------------------------------------
CREATE TABLE postcard_recipients (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  postcard_id           INT UNSIGNED NOT NULL,
  recipient_person_id   INT UNSIGNED NOT NULL,
  status                ENUM('pending','read','saved','discarded') NOT NULL DEFAULT 'pending',
  timeline_entry_id     INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at               DATETIME NULL,
  resolved_at           DATETIME NULL,
  UNIQUE KEY uniq_postcard_recipient (postcard_id, recipient_person_id),
  CONSTRAINT fk_pr_postcard FOREIGN KEY (postcard_id) REFERENCES postcards(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_recipient FOREIGN KEY (recipient_person_id) REFERENCES persons(id),
  CONSTRAINT fk_pr_entry FOREIGN KEY (timeline_entry_id) REFERENCES timeline_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_postcard_sender ON postcards(sender_person_id);
CREATE INDEX idx_pr_recipient_status ON postcard_recipients(recipient_person_id, status);

-- Phase 48: postcard email opt-in, its own preference alongside
-- notify_pending_tags (Phase 40; note that ALTER, like this one, is
-- applied directly against the live database rather than folded back
-- into the users table above -- see phase48_migration.sql).
-- Phase 53: this same column now also gates letter emails (see
-- includes/notifications.php's ourthology_notify_letter_received()) --
-- deliberately reused rather than adding a second checkbox, per Phil's
-- own request that "the Account Settings tick box... cover postcards AND
-- letters" (edit_person.php's label was updated to say so).
ALTER TABLE users
  ADD COLUMN notify_postcards TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_pending_tags;

-- ---------------------------------------------------------------------
-- Phase 53: letters. A longer-form, single-recipient alternative to a
-- postcard, offered from the same compose pop-up. Its body is rich text
-- (basic formatting + any number of inline photos) rather than one photo
-- and a short plain-text note, so it gets its own body_html column and
-- its own letter_images table (see includes/media.php's
-- store_letter_image() and includes/letters.php's
-- ourthology_sanitize_letter_body_html()) instead of reusing postcards'
-- single image_path.
--
-- The greeting ("Dear <first name>") and closing ("Best regards,
-- <sender>") are never stored -- both are generated at render time from
-- sender_person_id/recipient_person_id, the same way a postcard's
-- sender/recipient names are joined in rather than frozen as text.
--
-- One row per letter (not one row per recipient, unlike postcards): a
-- letter only ever has exactly one recipient, so there's no equivalent
-- of postcard_recipients to split out.
-- ---------------------------------------------------------------------
CREATE TABLE letters (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_person_id      INT UNSIGNED NOT NULL,
  created_by_user_id    INT UNSIGNED NOT NULL,
  family_group_id       INT UNSIGNED NOT NULL,
  recipient_person_id   INT UNSIGNED NOT NULL,
  body_html             MEDIUMTEXT NOT NULL,
  status                ENUM('pending','read','saved','discarded') NOT NULL DEFAULT 'pending',
  timeline_entry_id     INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at               DATETIME NULL,
  resolved_at           DATETIME NULL,
  CONSTRAINT fk_letter_sender FOREIGN KEY (sender_person_id) REFERENCES persons(id),
  CONSTRAINT fk_letter_recipient FOREIGN KEY (recipient_person_id) REFERENCES persons(id),
  CONSTRAINT fk_letter_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_letter_entry FOREIGN KEY (timeline_entry_id) REFERENCES timeline_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- letter_images: one row per inline photo. Uploaded (letter_image_
-- upload.php) as the sender types, before the letter itself exists --
-- letter_id starts NULL and is claimed by create_letter() once the
-- sender actually sends, for only the images the final sanitized body
-- really references and that this same uploader really uploaded (see
-- ourthology_sanitize_letter_body_html()'s own doc comment). ON DELETE
-- CASCADE here only ever matters if a letters row itself were ever
-- deleted outright, which this app never does (same "nothing deleted,
-- just marked closed" rule as postcards) -- kept anyway as the correct
-- constraint for the relationship, not because anything currently
-- triggers it.
-- ---------------------------------------------------------------------
CREATE TABLE letter_images (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  letter_id             INT UNSIGNED NULL,
  uploader_person_id    INT UNSIGNED NOT NULL,
  family_group_id       INT UNSIGNED NOT NULL,
  file_path             VARCHAR(255) NOT NULL,
  mime_type             VARCHAR(100) NOT NULL,
  byte_size             INT UNSIGNED NOT NULL,
  width                 INT UNSIGNED NULL,
  height                INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_letter_image_letter FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  CONSTRAINT fk_letter_image_uploader FOREIGN KEY (uploader_person_id) REFERENCES persons(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_letter_sender ON letters(sender_person_id);
CREATE INDEX idx_letter_recipient_status ON letters(recipient_person_id, status);
CREATE INDEX idx_letter_image_uploader ON letter_images(uploader_person_id, letter_id);

-- ---------------------------------------------------------------------
-- Phase 54: "place the postmark at a jaunty angle that changes each time
-- a new postcard is made" -- rolled once per postcard (random_int(-18,
-- 22)) in create_postcard() and stored here so the SAME angle renders on
-- every future read, rather than re-randomizing (and thus visibly
-- "jumping") on each page load. The not-yet-sent compose preview on
-- timeline.php still rolls a fresh angle per page view, since there is
-- nothing to persist until the postcard is actually created.
--
-- to_line/from_line: "make it possible for me to edit the To and From...
-- I might want to contract my name or the recipient's" -- free text
-- (capped to 80 chars, trimmed) captured at send time and stored
-- alongside the postcard. NULL (not empty string) when left blank, so
-- the read view's existing fallback to the computed sender/recipient
-- display name still applies exactly as it did before Phase 54.
-- ---------------------------------------------------------------------
ALTER TABLE postcards
  ADD COLUMN postmark_angle SMALLINT NOT NULL DEFAULT 0 AFTER audience,
  ADD COLUMN to_line VARCHAR(80) NULL AFTER postmark_angle,
  ADD COLUMN from_line VARCHAR(80) NULL AFTER to_line;

-- ---------------------------------------------------------------------
-- Phase 54: "if the recipient saves the postcard/letter to their
-- timeline, show a little mini postcard/envelope symbol on their
-- timeline" -- set only at the moment a postcard or letter is actually
-- saved (save_postcard_to_timeline() / save_letter_copy_to_timeline() in
-- includes/postcards.php / includes/letters.php), never on an ordinary
-- memory, and left NULL for every timeline_entries row that didn't come
-- from either. Read back by fetch_entries_for_person() in
-- includes/entries.php and rendered as a small corner badge on the
-- timeline card (renderRail() in timeline.php).
-- ---------------------------------------------------------------------
ALTER TABLE timeline_entries
  ADD COLUMN origin ENUM('postcard','letter') NULL DEFAULT NULL AFTER entry_type;
