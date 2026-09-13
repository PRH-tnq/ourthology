-- ourthology.com — Phase 22 migration: profile photos
--
-- Run this ONCE against the live database via phpMyAdmin (Krystal
-- cPanel > phpMyAdmin > select the ourthology database > SQL tab >
-- paste this whole file > Go). It only ADDS a new nullable column —
-- nothing existing is touched, so every current person simply has no
-- photo on record yet, which is the correct starting state. Safe to run
-- exactly once; running it a second time will fail with "duplicate
-- column name" rather than doing anything harmful.
--
-- db/schema.sql has also been updated to include this column, so a
-- brand-new install never needs this file — it's only for bringing an
-- already-running database up to date.

ALTER TABLE persons
  ADD COLUMN avatar_path VARCHAR(255) NULL AFTER died;
