-- Coaching Tool: MFA, Verwalter-Coach-Zuordnungen und Raumplanung
-- Sicherung der Datenbank erstellen und diese Migration einmalig vor dem PHP-Update importieren.

ALTER TABLE benutzer
  ADD COLUMN IF NOT EXISTS mfa_aktiv TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS mfa_geheimnis_verschluesselt TEXT NULL,
  ADD COLUMN IF NOT EXISTS mfa_ersatzcodes_hashes TEXT NULL;

-- Ein Verwalter kann mehrere Coaches betreuen und ein Coach mehrere Verwalter haben.
-- Aus Datenschutzgründen startet die Zuordnung leer; der Administrator weist sie zu.
CREATE TABLE IF NOT EXISTS coaching_verwalter_coaches (
  verwalter_benutzer_id BIGINT UNSIGNED NOT NULL,
  coach_benutzer_id BIGINT UNSIGNED NOT NULL,
  angelegt_von_benutzer_id BIGINT UNSIGNED NOT NULL,
  angelegt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (verwalter_benutzer_id, coach_benutzer_id),
  KEY idx_verwalter_coach_coach (coach_benutzer_id),
  KEY idx_verwalter_coach_erstellt_von (angelegt_von_benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_raeume (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  standort VARCHAR(190) NULL,
  beschreibung VARCHAR(1000) NULL,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  erstellt_von_benutzer_id BIGINT UNSIGNED NOT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_raeume_aktiv_name (aktiv, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_raum_berechtigungen (
  raum_id BIGINT UNSIGNED NOT NULL,
  coach_benutzer_id BIGINT UNSIGNED NOT NULL,
  darf_buchen TINYINT(1) NOT NULL DEFAULT 1,
  geaendert_von_benutzer_id BIGINT UNSIGNED NOT NULL,
  geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (raum_id, coach_benutzer_id),
  KEY idx_raum_berechtigung_coach (coach_benutzer_id, darf_buchen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_raum_buchungen (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  raum_id BIGINT UNSIGNED NOT NULL,
  coach_benutzer_id BIGINT UNSIGNED NOT NULL,
  titel VARCHAR(190) NOT NULL,
  beginn DATETIME NOT NULL,
  ende DATETIME NOT NULL,
  notiz VARCHAR(1000) NULL,
  status ENUM('gebucht','storniert') NOT NULL DEFAULT 'gebucht',
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_raum_buchung_zeit (raum_id, status, beginn, ende),
  KEY idx_raum_buchung_coach (coach_benutzer_id, beginn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datenbankkonto benötigt SELECT/INSERT/UPDATE auf benutzer und den vier neuen Tabellen.
-- Für Raumverwaltung/Buchung: zusätzlich DELETE auf coaching_raum_berechtigungen.
