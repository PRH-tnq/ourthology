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
  -- Phase 84: "I know they're deceased but don't know the year yet" --
  -- set on an unclaimed profile whenever `died` itself can't be filled in
  -- yet. Only ever meaningful while died IS NULL; every write path that
  -- sets a real `died` date clears this back to 0 in the same statement,
  -- so the two are never both "on" at once. Treated everywhere `died IS
  -- NOT NULL` already means "deceased, don't offer an invite link /
  -- birthday reminder / postcard recipient slot" -- see person_is_deceased()
  -- in includes/graph.php.
  deceased_year_unknown TINYINT(1)  NOT NULL DEFAULT 0,
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

-- Phase 67: a saved greeting card gets its own real timeline_entries copy
-- exactly like a saved postcard/letter (see save_card_to_timeline() in
-- includes/cards.php) -- widened to admit that third origin value.
ALTER TABLE timeline_entries
  MODIFY COLUMN origin ENUM('postcard','letter','card') NULL DEFAULT NULL;

-- ---------------------------------------------------------------------
-- Phase 55: "let me edit the To and From names on the letter or postcard
-- too, I might want to contract my name or the recipient's" -- letters
-- get the same editable-address-names postcards got in Phase 54. Unlike
-- postcards.to_line (which starts genuinely blank), the letter compose
-- panel's "Dear ___," / "Best regards, ___" names are always auto-filled
-- with a computed default before the sender ever touches them, so these
-- columns end up populated (with either that default or a shortened
-- name) on essentially every letter, not just customized ones -- see
-- create_letter()/save_letter_copy_to_timeline() in includes/letters.php
-- and letter.php's send action.
-- ---------------------------------------------------------------------
ALTER TABLE letters
  ADD COLUMN to_line VARCHAR(80) NULL AFTER body_html,
  ADD COLUMN from_line VARCHAR(80) NULL AFTER to_line;

-- ---------------------------------------------------------------------
-- Phase 67: greeting_cards -- "send a card", triggered from the birthday
-- reminder banner (see ourthology_birthday_banner_rows() in
-- includes/graph.php) rather than composed freely like a postcard/letter.
-- Modeled on letters (one row per card, addressed to exactly one
-- recipient) rather than postcards' multi-recipient junction table --
-- a card is always for one specific person's one specific occasion.
--
-- deliver_on gates when the RECIPIENT can see it (see
-- fetch_pending_cards_for_person() in includes/cards.php, which filters
-- WHERE deliver_on <= CURDATE()) -- the card sits in the sender's own
-- "sent" history immediately on send, but the recipient's own pending
-- queue only picks it up once today reaches deliver_on. Computed
-- server-side at send time, from the recipient's own persons.born, as
-- "the day before their next birthday" (see card.php's send action and
-- ourthology_next_annual_occurrence() in includes/graph.php) -- never
-- trusted from the client, so there's no way to post an arbitrary early
-- delivery date.
--
-- cover_message is the front-of-card overlay text ("Happy Birthday!");
-- to_line/greeting_line/message/from_line are the inside spread's four
-- editable spaces ("To....../Greeting....../message....../lots of love,
-- ___"). occasion is a short free-text label (default 'Birthday') kept
-- mostly for the sender's own sent-history line -- the fields themselves
-- are fully editable, so nothing here actually enforces "birthday" vs
-- any other occasion once the card's been opened up to write in.
-- ---------------------------------------------------------------------
CREATE TABLE greeting_cards (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_person_id      INT UNSIGNED NOT NULL,
  created_by_user_id    INT UNSIGNED NOT NULL,
  family_group_id       INT UNSIGNED NOT NULL,
  recipient_person_id   INT UNSIGNED NOT NULL,
  occasion              VARCHAR(60) NOT NULL DEFAULT 'Birthday',
  image_path            VARCHAR(255) NOT NULL,
  image_mime_type       VARCHAR(100) NOT NULL,
  image_byte_size       INT UNSIGNED NOT NULL,
  image_width           INT UNSIGNED NULL,
  image_height          INT UNSIGNED NULL,
  cover_message         VARCHAR(120) NOT NULL,
  to_line               VARCHAR(80) NULL,
  greeting_line         VARCHAR(80) NULL,
  message               TEXT NOT NULL,
  from_line             VARCHAR(80) NULL,
  postmark_angle        SMALLINT NOT NULL DEFAULT 0,
  deliver_on            DATE NOT NULL,
  status                ENUM('pending','read','saved','discarded') NOT NULL DEFAULT 'pending',
  timeline_entry_id     INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at               DATETIME NULL,
  resolved_at           DATETIME NULL,
  CONSTRAINT fk_card_sender FOREIGN KEY (sender_person_id) REFERENCES persons(id),
  CONSTRAINT fk_card_recipient FOREIGN KEY (recipient_person_id) REFERENCES persons(id),
  CONSTRAINT fk_card_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_card_entry FOREIGN KEY (timeline_entry_id) REFERENCES timeline_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_card_sender ON greeting_cards(sender_person_id);
CREATE INDEX idx_card_recipient_status_deliver ON greeting_cards(recipient_person_id, status, deliver_on);

-- ---------------------------------------------------------------------
-- Phase 56: the family calendar -- "add in a family calendar feature and
-- populate it with birthdays from the family... allow other key dates to
-- be added by anyone in the family." Birthdays are computed on the fly
-- from persons.born (see includes/calendar.php) and never stored here;
-- this table holds only the "key dates" a family member adds by hand
-- (anniversaries, memorials, and so on).
--
-- Every key date recurs annually by design -- there's no one-off vs.
-- recurring flag, since a family calendar's whole point is things that
-- come back every year -- so event_month/event_day are the date that
-- matters and are always required. event_year is optional and exists
-- only so a date with a natural "since" (a wedding, a move) can show a
-- "N years" count the way a birthday shows "turns N"; leave it NULL for
-- a date with no such count (e.g. "First day of summer holidays").
--
-- created_by_person_id/created_by_user_id follow the same "store the
-- real family member, not just the account" choice postcards.
-- sender_person_id already made -- no FK-backed family_groups table
-- exists (family_group_id is the same plain app-maintained column used
-- throughout persons/postcards/letters), and any family member -- not
-- only the original adder -- can remove a key date (delete_calendar_
-- event() in includes/calendar.php), matching how permissively this app
-- already treats shared family content elsewhere (e.g. any family
-- member can edit an unclaimed person's profile).
-- ---------------------------------------------------------------------
CREATE TABLE calendar_events (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  family_group_id       INT UNSIGNED NOT NULL,
  title                 VARCHAR(120) NOT NULL,
  event_month           TINYINT UNSIGNED NOT NULL,
  event_day             TINYINT UNSIGNED NOT NULL,
  event_year            SMALLINT UNSIGNED NULL,
  created_by_person_id  INT UNSIGNED NOT NULL,
  created_by_user_id    INT UNSIGNED NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_calendar_event_person FOREIGN KEY (created_by_person_id) REFERENCES persons(id),
  CONSTRAINT fk_calendar_event_user FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_calendar_event_group ON calendar_events(family_group_id);

-- ---------------------------------------------------------------------
-- Phase 58: peripheral trees + dual identity. Someone connected to a
-- family ONLY by marriage/partnership (an in-law, at any depth -- see
-- includes/peripheral.php) can't add their OWN antecedents onto that
-- master tree, since that would start mixing two separate bloodlines
-- onto one tree. Instead they're given a brand-new, completely separate
-- family tree of their own -- a fresh family_group_id, classed exactly
-- like any other master tree -- and, when the same account can claim
-- both sides, can switch between the two.
--
-- 1) Relax the 1-account : 1-person constraint that used to be enforced
-- here. persons.claimed_by_user_id was UNIQUE (one account could only
-- ever claim one person row anywhere in the whole graph); Phase 58 lets
-- one account claim a SECOND, separate person row -- their own "YOU"
-- node on a peripheral tree -- while users.person_id (that account's
-- original/"home" identity) stays UNIQUE and unchanged. A plain,
-- non-unique index replaces it so the existing FK (fk_persons_claimed_by)
-- still has the index InnoDB requires on a foreign key column.
-- ---------------------------------------------------------------------
ALTER TABLE persons
  ADD INDEX idx_persons_claimed_by (claimed_by_user_id),
  DROP INDEX uniq_claimed_user;

-- ---------------------------------------------------------------------
-- peripheral_tree_links: connects an in-law's node on a master tree to
-- their own "YOU" node on the peripheral tree created for them. One row
-- per peripheral tree. master_person_id is the in-law as they still
-- appear on the ORIGINAL tree (untouched by any of this -- still their
-- node there even after a peripheral tree exists for them);
-- peripheral_person_id is the new "YOU" node on the new tree, claimed by
-- the same user once they've claimed the master side (and left unclaimed,
-- like any other invited person, until then -- see
-- ourthology_create_peripheral_tree() in includes/peripheral.php).
--
-- UNIQUE on both sides: exactly one peripheral tree per in-law, and a
-- peripheral tree's "YOU" node belongs to exactly one master link. Access
-- to the "switch to your other family" icon (tree.php) is decided purely
-- from persons.claimed_by_user_id on whichever side is being viewed --
-- this table has no separate creator/owner flag of its own, since the
-- creator IS whoever ends up claiming master_person_id, whether or not
-- they're literally who clicked through peripheral_tree.php
-- (created_by_user_id below is an audit trail only, not an access check).
-- ---------------------------------------------------------------------
CREATE TABLE peripheral_tree_links (
  id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  master_family_group_id     INT UNSIGNED NOT NULL,
  master_person_id           INT UNSIGNED NOT NULL,
  peripheral_family_group_id INT UNSIGNED NOT NULL,
  peripheral_person_id       INT UNSIGNED NOT NULL,
  created_by_user_id         INT UNSIGNED NOT NULL,
  created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ptl_master_person (master_person_id),
  UNIQUE KEY uniq_ptl_peripheral_person (peripheral_person_id),
  CONSTRAINT fk_ptl_master_person FOREIGN KEY (master_person_id) REFERENCES persons(id),
  CONSTRAINT fk_ptl_peripheral_person FOREIGN KEY (peripheral_person_id) REFERENCES persons(id),
  CONSTRAINT fk_ptl_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_ptl_master_group ON peripheral_tree_links(master_family_group_id);
CREATE INDEX idx_ptl_peripheral_group ON peripheral_tree_links(peripheral_family_group_id);

-- ---------------------------------------------------------------------
-- timeline_entries.copied_from_entry_id: the on-demand copy facility
-- offered on tree.php right after a switch ("optionally...make copies of
-- timeline entries and postcards to the other account"). A saved
-- postcard/letter already becomes an ordinary timeline_entries row
-- (origin='postcard'/'letter', Phase 54), so copying timeline_entries
-- covers both without a separate postcards/letters copy path.
--
-- Self-referential and nullable: NULL for every ordinary entry, set only
-- on a row created BY the copy facility, pointing at the entry it was
-- copied from -- lets ourthology_copy_facility_state()/
-- ourthology_copy_pending_entries() (includes/peripheral.php) work out
-- what's already been copied (in either direction) so the banner only
-- offers what's still pending, and clicking it twice never duplicates.
-- Deliberately one-time and text-only (title/body/date/visibility/
-- origin) -- never re-copies a copy (only entries with this column NULL
-- are eligible to be copied at all), and never touches photos/videos.
-- ---------------------------------------------------------------------
ALTER TABLE timeline_entries
  ADD COLUMN copied_from_entry_id INT UNSIGNED NULL AFTER origin,
  ADD CONSTRAINT fk_entry_copied_from FOREIGN KEY (copied_from_entry_id) REFERENCES timeline_entries(id);

CREATE INDEX idx_entries_copied_from ON timeline_entries(copied_from_entry_id);

-- ---------------------------------------------------------------------
-- Phase 59: account deletion ("I may want to delete my profile and
-- leave the site"). Deleting your own account disables and scrubs the
-- users row (see ourthology_delete_own_account() in
-- includes/account_deletion.php) rather than hard-deleting it -- dozens
-- of OTHER people's relationships, partnerships, memories, postcards
-- etc. reference users(id) with no ON DELETE CASCADE, and hard-deleting
-- the row would either violate those constraints or require touching
-- everyone else's shared family history just to remove one departing
-- account. The person row(s) it pointed at ARE fully erased, though --
-- which has to happen after users.person_id no longer points at them,
-- to satisfy fk_users_person. That ordering requires person_id to be
-- nullable.
-- ---------------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN person_id INT UNSIGNED NULL;

-- ---------------------------------------------------------------------
-- Phase 68: "extend [send a card] to all pages and for all events that
-- appear on the calendar" -- a card can now be sent for a non-birthday
-- key date (an anniversary, etc.) too, not only a person's own birthday.
-- A key date isn't bound to one person, so event_id records which
-- calendar_events row (if any) a card was triggered from -- nullable,
-- since a birthday-triggered card still has none. ON DELETE SET NULL
-- (not CASCADE): removing a key date later shouldn't take an
-- already-sent card down with it, the same "the send already happened,
-- it's real" reasoning deliver_on's own delivery gate is built on.
--
-- This ALTER has to come after calendar_events' own CREATE TABLE above
-- (Phase 56) in file order, since it references that table -- unlike
-- greeting_cards' initial CREATE TABLE further up, which predates it in
-- this file even though calendar_events shipped first.
-- ---------------------------------------------------------------------
ALTER TABLE greeting_cards
  ADD COLUMN event_id INT UNSIGNED NULL AFTER recipient_person_id,
  ADD CONSTRAINT fk_card_event FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- Phase 69: Memory Planner. Plans a multi-day trip/event up front (a
-- title, a start/finish date, and any number of "events" within it, each
-- with its own booking receipts/tickets and scribbled notes to plan with)
-- and then records memories against those same events as the trip
-- actually happens -- one pop-up, reopened later for the same trip, that
-- gradually turns from a plan into a memory of it.
--
-- Reuses the ordinary timeline_entries/media/memory_tags machinery
-- rather than inventing a parallel one: every trip gets exactly one
-- companion timeline_entries row (origin='trip', entry_type='note',
-- occurred_on = start_date), the same way a saved postcard/letter/card
-- does (Phase 54/67) -- so it shows up in the rail, gets tagged and
-- approved via the existing memory_tags flow ("tag-and-approve, like
-- normal memories" per Phil), and is deleted by timeline.php's existing
-- delete_entry action with no code changes there at all.
--
-- Every plan/memory image across every event in the trip is stored as an
-- ORDINARY media row too, pointing at that one shared timeline_entry_id
-- -- never a separate table of its own bytes/mime/size -- so media.php's
-- existing access check (can_view_media()) and thumbnail cache
-- (ensure_media_thumbnail()) already serve and gate every trip photo for
-- free, and the planner's own image-zoom lightbox is just another
-- consumer of the same /media.php?id= URLs the rest of the app already
-- uses. trip_event_media below only records WHICH event and WHICH side
-- (plan or memory) each of those media rows belongs to.
-- ---------------------------------------------------------------------
ALTER TABLE timeline_entries
  MODIFY COLUMN origin ENUM('postcard','letter','card','trip') NULL DEFAULT NULL;

CREATE TABLE trip_plans (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id           INT UNSIGNED NOT NULL,
  timeline_entry_id   INT UNSIGNED NOT NULL,
  title               VARCHAR(255) NOT NULL,
  start_date          DATE NOT NULL,
  finish_date         DATE NOT NULL,
  created_by_user_id  INT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tripplan_entry (timeline_entry_id),
  CONSTRAINT fk_tripplan_person FOREIGN KEY (person_id) REFERENCES persons(id),
  CONSTRAINT fk_tripplan_entry FOREIGN KEY (timeline_entry_id)
    REFERENCES timeline_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_tripplan_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per event within a trip ("flight out", "the campsite", "the
-- wedding itself", ...) -- Phil asked for "flexible space for at least
-- 10 events but more if needed", so this is a plain child table rather
-- than a fixed number of columns, with sort_order carrying the order
-- they're arranged/reordered in on the pop-up. plan_notes is the
-- left-hand "Plans" column's scribbled text for this event; memory_notes
-- is the right-hand "Memories" column's — kept as two separate columns
-- (rather than reusing timeline_entries.body, which this table doesn't
-- have one of) since the two are independent and a trip has many events
-- sharing the one timeline_entries row.
CREATE TABLE trip_events (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_plan_id        INT UNSIGNED NOT NULL,
  sort_order          INT UNSIGNED NOT NULL DEFAULT 0,
  title               VARCHAR(255) NOT NULL,
  event_date          DATE NULL,
  plan_notes          TEXT NULL,
  memory_notes        TEXT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tripevent_plan FOREIGN KEY (trip_plan_id)
    REFERENCES trip_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Links an ordinary media row to the one trip_event/side (plan or
-- memory) it was uploaded for. media_id is UNIQUE: each media row is
-- either a plan attachment or a memory attachment of exactly one event,
-- never shared between two slots. The 10-images-per-event-per-side cap
-- (matching MEDIA_MAX_FILES_PER_ENTRY's existing per-entry convention)
-- is enforced in trip_plan.php, not here.
CREATE TABLE trip_event_media (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_event_id       INT UNSIGNED NOT NULL,
  media_id            INT UNSIGNED NOT NULL,
  role                ENUM('plan','memory') NOT NULL,
  sort_order          INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_tripmedia_event FOREIGN KEY (trip_event_id)
    REFERENCES trip_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_tripmedia_media FOREIGN KEY (media_id)
    REFERENCES media(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_tripmedia_media (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_tripplan_person ON trip_plans(person_id);
CREATE INDEX idx_tripevent_plan_sort ON trip_events(trip_plan_id, sort_order);
CREATE INDEX idx_tripmedia_event_role ON trip_event_media(trip_event_id, role, sort_order);

-- ---------------------------------------------------------------------
-- Phase 72: "allow the 'Best regards' prefilled text to be edited" -- the
-- letter composer's closing sentence used to be a hard-coded "Best
-- regards," (see pending.php's read view / ourthology_letter_plain_text()
-- in includes/letters.php) with only the signer's NAME after it editable
-- via from_line. closing_line separates that out: the sign-off phrase
-- itself, edited the same single-line contenteditable way to_line/
-- from_line already are, independent of from_line (which keeps meaning
-- just the name) so a shortened name and a customized phrase can each be
-- edited without disturbing the other. NULL on every letter sent before
-- this phase -- every render site falls back to the original literal
-- "Best regards," in that case, so old letters look exactly as they
-- always did. See create_letter()/save_letter_copy_to_timeline() in
-- includes/letters.php and letter.php's send action.
-- ---------------------------------------------------------------------
ALTER TABLE letters
  ADD COLUMN closing_line VARCHAR(80) NULL AFTER from_line;
