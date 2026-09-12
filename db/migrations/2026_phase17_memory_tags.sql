-- ourthology.com — Phase 17 migration: memory_tags
--
-- Run this ONCE against the live database via phpMyAdmin (Krystal
-- cPanel > phpMyAdmin > select the ourthology database > SQL tab >
-- paste this whole file > Go). It only ADDS a new table — nothing
-- existing is touched, so there is nothing to back up beforehand
-- specifically for this change (though a routine backup is always
-- fine). Safe to run exactly once; running it a second time will fail
-- with "table already exists" rather than doing anything harmful.
--
-- This is the first schema change since the initial deploy that needs
-- a manual migration step (everything before this fit inside the
-- original db/schema.sql). db/schema.sql has also been updated to
-- include this table, so a brand-new install never needs this file —
-- it's only for bringing an already-running database up to date.

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
  CONSTRAINT fk_tag_entry FOREIGN KEY (timeline_entry_id)
    REFERENCES timeline_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_tag_person FOREIGN KEY (person_id) REFERENCES persons(id),
  CONSTRAINT fk_tag_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_tag_approving FOREIGN KEY (approving_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_tags_status ON memory_tags(status);
CREATE INDEX idx_tags_person ON memory_tags(person_id, status);
