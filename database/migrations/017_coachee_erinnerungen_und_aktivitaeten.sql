-- Coaching Tool: Coachee-Erinnerungseinstellungen und Coach-Aktivitäten
-- Vor Import eine Datenbanksicherung anlegen; einmalig nach Migration 016 importieren.
ALTER TABLE coaching_portal_zugaenge
 ADD COLUMN aufgaben_erinnerungen_aktiv TINYINT(1) NOT NULL DEFAULT 0 AFTER aktiv,
 ADD COLUMN aufgaben_erinnerung_rhythmus ENUM('taeglich','woechentlich') NOT NULL DEFAULT 'taeglich' AFTER aufgaben_erinnerungen_aktiv,
 ADD COLUMN aufgaben_ruhe_von TIME NOT NULL DEFAULT '21:00:00' AFTER aufgaben_erinnerung_rhythmus,
 ADD COLUMN aufgaben_ruhe_bis TIME NOT NULL DEFAULT '08:00:00' AFTER aufgaben_ruhe_von;

CREATE TABLE coaching_aufgaben_erinnerung_status (
 aufgabe_id BIGINT UNSIGNED NOT NULL,
 zuletzt_versendet_am DATETIME NULL,
 zuletzt_versucht_am DATE NULL,
 PRIMARY KEY (aufgabe_id),
 KEY idx_aufgaben_erinnerung_versand (zuletzt_versendet_am,zuletzt_versucht_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coaching_aktivitaeten (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 klient_id BIGINT UNSIGNED NOT NULL,
 auftrag_id BIGINT UNSIGNED NOT NULL,
 ereignistyp ENUM('coachee_nachricht','aufgabenstatus') NOT NULL,
 referenz_id BIGINT UNSIGNED NULL,
 objekt_titel VARCHAR(190) NOT NULL DEFAULT '',
 status_vorher ENUM('offen','in_bearbeitung','erledigt') NULL,
 status_nachher ENUM('offen','in_bearbeitung','erledigt') NULL,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 gelesen_am DATETIME NULL,
 PRIMARY KEY (id),
 KEY idx_aktivitaet_coach_unread (coach_benutzer_id,gelesen_am,erstellt_am),
 KEY idx_aktivitaet_client_order (klient_id,auftrag_id,coach_benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rechte: coaching_portal_zugaenge SELECT/UPDATE;
-- coaching_aufgaben_erinnerung_status SELECT/INSERT/UPDATE;
-- coaching_aktivitaeten SELECT/INSERT/UPDATE.
