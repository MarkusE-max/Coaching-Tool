-- Coaching Tool: shared coach/coachee message stream and NLP format library
-- MariaDB 10 / utf8mb4. Additive only; existing application tables remain unchanged.
-- File payloads are stored in the existing private storage/documents folder.

CREATE TABLE IF NOT EXISTS coaching_nachrichten (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 klient_id BIGINT UNSIGNED NOT NULL,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 autor_rolle ENUM('coach','client') NOT NULL,
 inhalt TEXT NOT NULL,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_nachricht_verlauf (klient_id,coach_benutzer_id,id),
 KEY idx_nachricht_erstellt (erstellt_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_nachrichten_dateien (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 nachricht_id BIGINT UNSIGNED NOT NULL,
 klient_id BIGINT UNSIGNED NOT NULL,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 speicher_schluessel CHAR(52) NOT NULL,
 originalname VARCHAR(255) NOT NULL,
 mime_typ VARCHAR(100) NOT NULL,
 groesse_bytes BIGINT UNSIGNED NOT NULL,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_nachricht_datei_speicher (speicher_schluessel),
 KEY idx_nachricht_datei_owner (nachricht_id,klient_id,coach_benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_formate (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 titel VARCHAR(190) NOT NULL,
 kategorie VARCHAR(100) NOT NULL DEFAULT '',
 kurzbeschreibung VARCHAR(1000) NOT NULL DEFAULT '',
 ziel TEXT NULL,
 ablauf MEDIUMTEXT NOT NULL,
 dauer_minuten SMALLINT UNSIGNED NULL,
 materialien TEXT NULL,
 hinweise TEXT NULL,
 quellen TEXT NULL,
 tags VARCHAR(500) NOT NULL DEFAULT '',
 freigabe ENUM('privat','ausgewaehlte_coaches','alle_coaches') NOT NULL DEFAULT 'privat',
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_format_owner (coach_benutzer_id,geaendert_am),
 KEY idx_format_freigabe (freigabe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_format_freigaben (
 format_id BIGINT UNSIGNED NOT NULL,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 freigegeben_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (format_id,coach_benutzer_id),
 KEY idx_format_freigabe_coach (coach_benutzer_id,format_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rights needed: SELECT/INSERT for messages and message files; SELECT/INSERT/UPDATE for coaching_formate; SELECT/INSERT/DELETE for coaching_format_freigaben.
