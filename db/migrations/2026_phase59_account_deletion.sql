-- Phase 59: account deletion. Run this once via phpMyAdmin against the
-- live database before deploying this phase's code -- db/schema.sql has
-- already been updated to match, so a FRESH install never needs this
-- file, only the already-live one.
--
-- Deleting your own account disables and scrubs the users row (see
-- ourthology_delete_own_account() in includes/account_deletion.php --
-- it's never hard-deleted, since dozens of OTHER people's relationships,
-- partnerships, memories, postcards etc. reference users(id) with no
-- cascade) but fully erases the person row(s) it pointed at. That erase
-- has to happen AFTER the users row no longer points at it, to satisfy
-- fk_users_person -- which means person_id has to be nullable.
ALTER TABLE users
  MODIFY COLUMN person_id INT UNSIGNED NULL;
