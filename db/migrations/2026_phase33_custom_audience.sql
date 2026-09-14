-- ourthology.com — Phase 33 migration: Custom memory visibility
--
-- Run this ONCE against the live database via phpMyAdmin (Krystal
-- cPanel > phpMyAdmin > select the ourthology database > SQL tab >
-- paste this whole file > Go).
--
-- Adds a third publication scope, 'custom', alongside the existing
-- 'private'/'public' on timeline_entries.visibility — MODIFY COLUMN on
-- an ENUM is safe here since every existing row's value ('private' or
-- 'public') stays a valid member of the widened enum; nothing existing
-- is touched. Also creates custom_memory_audience, the table behind
-- edit_person.php's new "Account Settings" tab: one row per
-- (person_id, member_person_id) pair, where person_id is whoever the
-- list belongs to (their OWN memories, whenever marked Custom, are only
-- ever visible to people on this list) and member_person_id is one
-- family member allowed to see them. A brand-new install never needs
-- this file — db/schema.sql already includes both changes.
--
-- Safe to run exactly once; running it a second time will fail (enum
-- already includes 'custom' / table already exists) rather than doing
-- anything harmful.

ALTER TABLE timeline_entries
  MODIFY COLUMN visibility ENUM('private','public','custom') NOT NULL DEFAULT 'private';

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

CREATE INDEX idx_audience_member ON custom_memory_audience(member_person_id);
