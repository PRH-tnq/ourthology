-- Phase 68: "extend [send a card] to all pages and for all events that
-- appear on the calendar" -- run this once via phpMyAdmin against the live
-- database before deploying this phase's code -- db/schema.sql has already
-- been updated to match, so a FRESH install never needs this file, only
-- the already-live one.
--
-- A card can now be sent for a non-birthday key date (an anniversary, a
-- memorial, ...) too, not only a person's own birthday. A key date isn't
-- bound to one person, so event_id records which calendar_events row (if
-- any) a card was triggered from -- nullable, since a birthday-triggered
-- card still has none. ON DELETE SET NULL (not CASCADE): removing a key
-- date later shouldn't take an already-sent card down with it, the same
-- "the send already happened, it's real" reasoning deliver_on's own
-- delivery gate is built on. See includes/calendar.php's
-- fetch_calendar_event_for_group()/ourthology_calendar_reminder_rows()
-- and card.php's widened 'send' action for how this column is used.
ALTER TABLE greeting_cards
  ADD COLUMN event_id INT UNSIGNED NULL AFTER recipient_person_id,
  ADD CONSTRAINT fk_card_event FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE SET NULL;
