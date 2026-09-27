-- Phase 105: a memory can be dated by its year alone. 'year' means only the
-- year is known -- occurred_on then holds a stand-in day in June or July of
-- that year (picked at random) so it sits mid-year on the timeline, and the
-- memory card says the exact date isn't known. Everything saved before this
-- has a full date ('day').
-- Run in phpMyAdmin BEFORE uploading the Phase 105 PHP.
ALTER TABLE timeline_entries
  ADD COLUMN date_precision ENUM('day','year') NOT NULL DEFAULT 'day' AFTER occurred_on;
