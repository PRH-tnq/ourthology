-- Phase 99: the order a memory's photos/documents are shown in, set by
-- dragging them within the upload box. Existing memories keep their
-- current order (upload order), since every row starts at 0 and ties fall
-- back to id. Run in phpMyAdmin BEFORE uploading the Phase 99 PHP.
ALTER TABLE media
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER height;
CREATE INDEX idx_media_entry_sort ON media (timeline_entry_id, sort_order, id);
