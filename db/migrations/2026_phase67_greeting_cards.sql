-- Phase 67: greeting cards. Run this once via phpMyAdmin against the live
-- database before deploying this phase's code -- db/schema.sql has already
-- been updated to match, so a FRESH install never needs this file, only
-- the already-live one.
--
-- Widen timeline_entries.origin first (a saved card needs 'card' as a
-- valid value the moment save_card_to_timeline() can run), then create
-- greeting_cards itself, modeled on letters (one row per card, addressed
-- to exactly one recipient) rather than postcards' multi-recipient
-- junction table -- a card is always for one specific person's one
-- specific occasion. See includes/cards.php and card.php for how these
-- columns are used; ourthology_birthday_banner_rows() in includes/graph.php
-- is what triggers a card from the birthday reminder banner.
ALTER TABLE timeline_entries
  MODIFY COLUMN origin ENUM('postcard','letter','card') NULL DEFAULT NULL;

CREATE TABLE greeting_cards (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_person_id      INT UNSIGNED NOT NULL,
  created_by_user_id    INT UNSIGNED NOT NULL,
  family_group_id       INT UNSIGNED NOT NULL,
  recipient_person_id   INT UNSIGNED NOT NULL,
  occasion              VARCHAR(60) NOT NULL DEFAULT 'Birthday',
  image_path            VARCHAR(255) NOT NULL,
  image_mime_type       VARCHAR(100) NOT NULL,
  image_byte_size       INT UNSIGNED NOT NULL,
  image_width           INT UNSIGNED NULL,
  image_height          INT UNSIGNED NULL,
  cover_message         VARCHAR(120) NOT NULL,
  to_line               VARCHAR(80) NULL,
  greeting_line         VARCHAR(80) NULL,
  message               TEXT NOT NULL,
  from_line             VARCHAR(80) NULL,
  postmark_angle        SMALLINT NOT NULL DEFAULT 0,
  deliver_on            DATE NOT NULL,
  status                ENUM('pending','read','saved','discarded') NOT NULL DEFAULT 'pending',
  timeline_entry_id     INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at               DATETIME NULL,
  resolved_at           DATETIME NULL,
  CONSTRAINT fk_card_sender FOREIGN KEY (sender_person_id) REFERENCES persons(id),
  CONSTRAINT fk_card_recipient FOREIGN KEY (recipient_person_id) REFERENCES persons(id),
  CONSTRAINT fk_card_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_card_entry FOREIGN KEY (timeline_entry_id) REFERENCES timeline_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_card_sender ON greeting_cards(sender_person_id);
CREATE INDEX idx_card_recipient_status_deliver ON greeting_cards(recipient_person_id, status, deliver_on);
