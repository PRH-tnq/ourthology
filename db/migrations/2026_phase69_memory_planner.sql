-- Phase 69: Memory Planner. Run this once via phpMyAdmin against the live
-- database before deploying this phase's code -- db/schema.sql has already
-- been updated to match, so a FRESH install never needs this file, only
-- the already-live one.
--
-- Widen timeline_entries.origin first (a saved trip needs 'trip' as a
-- valid value the moment its save handler can run), then create the three
-- new tables. See db/schema.sql's own Phase 69 comment block for the full
-- design rationale (why every trip photo is an ordinary media row rather
-- than a table of its own), includes/trips.php for how these columns are
-- used, and trip_plan.php for the save/fetch endpoint.
ALTER TABLE timeline_entries
  MODIFY COLUMN origin ENUM('postcard','letter','card','trip') NULL DEFAULT NULL;

CREATE TABLE trip_plans (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id           INT UNSIGNED NOT NULL,
  timeline_entry_id   INT UNSIGNED NOT NULL,
  title               VARCHAR(255) NOT NULL,
  start_date          DATE NOT NULL,
  finish_date         DATE NOT NULL,
  created_by_user_id  INT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tripplan_entry (timeline_entry_id),
  CONSTRAINT fk_tripplan_person FOREIGN KEY (person_id) REFERENCES persons(id),
  CONSTRAINT fk_tripplan_entry FOREIGN KEY (timeline_entry_id)
    REFERENCES timeline_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_tripplan_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trip_events (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_plan_id        INT UNSIGNED NOT NULL,
  sort_order          INT UNSIGNED NOT NULL DEFAULT 0,
  title               VARCHAR(255) NOT NULL,
  event_date          DATE NULL,
  plan_notes          TEXT NULL,
  memory_notes        TEXT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tripevent_plan FOREIGN KEY (trip_plan_id)
    REFERENCES trip_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trip_event_media (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_event_id       INT UNSIGNED NOT NULL,
  media_id            INT UNSIGNED NOT NULL,
  role                ENUM('plan','memory') NOT NULL,
  sort_order          INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_tripmedia_event FOREIGN KEY (trip_event_id)
    REFERENCES trip_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_tripmedia_media FOREIGN KEY (media_id)
    REFERENCES media(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_tripmedia_media (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_tripplan_person ON trip_plans(person_id);
CREATE INDEX idx_tripevent_plan_sort ON trip_events(trip_plan_id, sort_order);
CREATE INDEX idx_tripmedia_event_role ON trip_event_media(trip_event_id, role, sort_order);
