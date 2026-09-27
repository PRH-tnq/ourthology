-- Phase 100: the country each pinned home / memory place is in (ISO 3166
-- two-letter code, e.g. GB, US, HK), taken from the address lookup when the
-- pin is placed. Feeds the maps' "countries lived in / visited" counter;
-- older pins without one are worked out from their position instead.
-- Run in phpMyAdmin BEFORE uploading the Phase 100 PHP.
ALTER TABLE homes
  ADD COLUMN country_code CHAR(2) NULL AFTER country;
ALTER TABLE timeline_entries
  ADD COLUMN location_country CHAR(2) NULL AFTER location_lng;
