-- Phase 93: an optional "where it happened" on any memory.
-- location_label is what the person typed or picked (an address, a place
-- name, "Nan's house"); lat/lng are the map pin, NULL when no pin was set.
-- Run in phpMyAdmin BEFORE uploading the Phase 93 PHP.
ALTER TABLE timeline_entries
  ADD COLUMN location_label VARCHAR(255) NULL AFTER occurred_on,
  ADD COLUMN location_lat DECIMAL(9,6) NULL AFTER location_label,
  ADD COLUMN location_lng DECIMAL(9,6) NULL AFTER location_lat;
