-- Phase 58: peripheral trees + dual identity. Run this once via
-- phpMyAdmin against the live database before deploying this phase's
-- code -- db/schema.sql has already been updated to match, so a FRESH
-- install never needs this file, only the already-live one.
--
-- 1) Relax the 1-account : 1-person constraint. persons.claimed_by_user_id
-- was UNIQUE (one account could only ever claim one person row anywhere
-- in the whole graph); this lets one account claim a SECOND, separate
-- person row -- their own "YOU" node on a peripheral tree -- while
-- users.person_id (that account's original/"home" identity) stays UNIQUE
-- and unchanged.
ALTER TABLE persons
  ADD INDEX idx_persons_claimed_by (claimed_by_user_id),
  DROP INDEX uniq_claimed_user;

-- 2) peripheral_tree_links: connects an in-law's node on a master tree
-- to their own "YOU" node on the peripheral tree created for them.
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

-- 3) timeline_entries.copied_from_entry_id: tracks the on-demand
-- "copy your memories to your other tree" facility offered on tree.php
-- right after a switch.
ALTER TABLE timeline_entries
  ADD COLUMN copied_from_entry_id INT UNSIGNED NULL AFTER origin,
  ADD CONSTRAINT fk_entry_copied_from FOREIGN KEY (copied_from_entry_id) REFERENCES timeline_entries(id);

CREATE INDEX idx_entries_copied_from ON timeline_entries(copied_from_entry_id);
