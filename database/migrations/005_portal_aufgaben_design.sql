-- Coaching Tool: Coachee portal, tasks and per-user design preferences
-- MariaDB 10 / utf8mb4. Additive tables only; legacy client/user tables unchanged.

CREATE TABLE IF NOT EXISTS coaching_portal_zugaenge (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 klient_id BIGINT UNSIGNED NOT NULL,
 benutzername VARCHAR(80) NOT NULL,
 passwort_hash VARCHAR(255) NOT NULL,
 aktiv TINYINT(1) NOT NULL DEFAULT 1,
 letzter_login DATETIME NULL,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_portal_klient (klient_id),
 UNIQUE KEY uq_portal_benutzername (benutzername),
 KEY idx_portal_aktiv (aktiv)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_aufgaben (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 klient_id BIGINT UNSIGNED NOT NULL,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 titel VARCHAR(190) NOT NULL,
 beschreibung TEXT NULL,
 faellig_am DATE NULL,
 status ENUM('offen','in_bearbeitung','erledigt') NOT NULL DEFAULT 'offen',
 klient_sichtbar TINYINT(1) NOT NULL DEFAULT 1,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_aufgabe_klient (klient_id,status,faellig_am),
 KEY idx_aufgabe_coach (coach_benutzer_id,klient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_design_einstellungen (
 benutzer_id BIGINT UNSIGNED NOT NULL,
 schriftart VARCHAR(20) NOT NULL DEFAULT 'system',
 schriftgroesse TINYINT UNSIGNED NOT NULL DEFAULT 16,
 farbe_hintergrund CHAR(7) NOT NULL DEFAULT '#f5f7f5',
 farbe_flaeche CHAR(7) NOT NULL DEFAULT '#ffffff',
 farbe_text CHAR(7) NOT NULL DEFAULT '#1f302d',
 farbe_akzent CHAR(7) NOT NULL DEFAULT '#245c50',
 farbe_menue CHAR(7) NOT NULL DEFAULT '#173a33',
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
