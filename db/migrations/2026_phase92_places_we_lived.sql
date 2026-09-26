-- Phase 92: "Places we lived" -- homes a person (and anyone else tagged
-- as living there) lived in, with move-in/move-out dates per resident,
-- pinned on a map, plus any number of dated "updates" (an extension, a
-- new kitchen, a repaint...) each with their own photos.
-- Adds four new tables only; nothing existing is changed. Safe to run on
-- the live database. Run BEFORE uploading the Phase 92 PHP files.

CREATE TABLE homes (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  family_group_id       INT UNSIGNED NOT NULL,
  created_by_person_id  INT UNSIGNED NULL,
  created_by_user_id    INT UNSIGNED NOT NULL,
  name                  VARCHAR(120) NULL,
  address               TEXT NULL,
  postcode              VARCHAR(20) NULL,
  country               VARCHAR(80) NULL,
  lat                   DECIMAL(9,6) NULL,
  lng                   DECIMAL(9,6) NULL,
  visibility            ENUM('private','public','custom') NOT NULL DEFAULT 'public',
  notes                 TEXT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_home_created_person FOREIGN KEY (created_by_person_id) REFERENCES persons(id) ON DELETE SET NULL,
  CONSTRAINT fk_home_created_user FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE home_residents (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  home_id               INT UNSIGNED NOT NULL,
  person_id             INT UNSIGNED NOT NULL,
  moved_in              DATE NULL,
  moved_in_precision    ENUM('day','month','year') NULL,
  moved_out             DATE NULL,
  moved_out_precision   ENUM('day','month','year') NULL,
  created_by_user_id    INT UNSIGNED NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_home_resident (home_id, person_id),
  CONSTRAINT fk_resident_home FOREIGN KEY (home_id) REFERENCES homes(id) ON DELETE CASCADE,
  CONSTRAINT fk_resident_person FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
  CONSTRAINT fk_resident_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE home_updates (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  home_id               INT UNSIGNED NOT NULL,
  sort_order            INT UNSIGNED NOT NULL DEFAULT 0,
  title                 VARCHAR(160) NOT NULL,
  update_date           DATE NULL,
  update_date_precision ENUM('day','month','year') NULL,
  notes                 TEXT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_update_home FOREIGN KEY (home_id) REFERENCES homes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE home_media (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  home_id               INT UNSIGNED NOT NULL,
  update_id             INT UNSIGNED NULL,
  file_path             VARCHAR(255) NOT NULL,
  mime_type             VARCHAR(100) NOT NULL,
  byte_size             INT UNSIGNED NOT NULL,
  width                 INT UNSIGNED NULL,
  height                INT UNSIGNED NULL,
  sort_order            INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by_user_id   INT UNSIGNED NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_homemedia_home FOREIGN KEY (home_id) REFERENCES homes(id) ON DELETE CASCADE,
  CONSTRAINT fk_homemedia_update FOREIGN KEY (update_id) REFERENCES home_updates(id) ON DELETE CASCADE,
  CONSTRAINT fk_homemedia_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_home_group ON homes(family_group_id);
CREATE INDEX idx_resident_person ON home_residents(person_id);
CREATE INDEX idx_update_home_sort ON home_updates(home_id, sort_order);
CREATE INDEX idx_homemedia_home ON home_media(home_id, update_id, sort_order);
