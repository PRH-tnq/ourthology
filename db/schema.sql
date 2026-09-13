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
-- only to the owning user until the (not-yet-built) death trigger fires.
-- ---------------------------------------------------------------------
CREATE TABLE timeline_entries (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id           INT UNSIGNED NOT NULL,
  entry_type          ENUM('note','photo','video','diary') NOT NULL DEFAULT 'note',
  title               VARCHAR(255) NULL,
  body                TEXT NULL,
  occurred_on         DATE NULL,
  visibility          ENUM('private','public') NOT NULL DEFAULT 'private',
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

CREATE INDEX idx_persons_family_group ON persons(family_group_id);
CREATE INDEX idx_entries_person_visibility ON timeline_entries(person_id, visibility);
CREATE INDEX idx_rel_status ON relationships(status);
CREATE INDEX idx_part_status ON partnerships(status);
CREATE INDEX idx_tags_status ON memory_tags(status);
CREATE INDEX idx_tags_person ON memory_tags(person_id, status);
