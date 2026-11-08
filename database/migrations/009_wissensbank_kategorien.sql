-- Coaching Tool: zentral verwaltete Kategorien für die Wissensdatenbank
-- MariaDB 10 / utf8mb4. Vor dem Update der PHP-Dateien einmalig ausführen.

CREATE TABLE coaching_format_kategorien (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 name VARCHAR(100) NOT NULL,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_coaching_format_kategorie_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO coaching_format_kategorien (name) VALUES
 ('NLP-Formate'),
 ('Positive Psychologie'),
 ('EMDR'),
 ('Systemische Beratung'),
 ('Achtsamkeit'),
 ('Sonstiges');

-- Bestehende frei eingegebene Kategorien bleiben erhalten und werden übernommen.
INSERT IGNORE INTO coaching_format_kategorien (name)
 SELECT DISTINCT TRIM(kategorie)
 FROM coaching_formate
 WHERE TRIM(kategorie) <> '';

ALTER TABLE coaching_formate
 ADD COLUMN kategorie_id BIGINT UNSIGNED NULL AFTER kategorie,
 ADD KEY idx_coaching_format_kategorie (kategorie_id),
 ADD CONSTRAINT fk_coaching_formate_kategorie
  FOREIGN KEY (kategorie_id) REFERENCES coaching_format_kategorien(id)
  ON UPDATE CASCADE ON DELETE RESTRICT;

UPDATE coaching_formate f
 INNER JOIN coaching_format_kategorien k ON k.name = TRIM(f.kategorie)
 SET f.kategorie_id = k.id
 WHERE TRIM(f.kategorie) <> '';

-- Der Datenbankbenutzer der Anwendung benötigt SELECT, INSERT, UPDATE, DELETE
-- auf coaching_format_kategorien sowie SELECT/UPDATE auf coaching_formate.
