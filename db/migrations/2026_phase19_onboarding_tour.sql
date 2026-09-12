-- ourthology.com — Phase 19 migration: onboarding tour flag
--
-- Run this ONCE against the live database via phpMyAdmin (Krystal
-- cPanel > phpMyAdmin > select the ourthology database > SQL tab >
-- paste this whole file > Go). It only ADDS a new column with a NULL
-- default — nothing existing is touched or backfilled, so every
-- current account is simply treated as "hasn't seen the tour yet" the
-- next time they load their timeline, which is the correct behavior.
-- Safe to run exactly once; running it a second time will fail with
-- "duplicate column name" rather than doing anything harmful.
--
-- db/schema.sql has also been updated to include this column, so a
-- brand-new install never needs this file — it's only for bringing an
-- already-running database up to date.

ALTER TABLE users
  ADD COLUMN tour_completed_at DATETIME NULL AFTER last_login_at;
