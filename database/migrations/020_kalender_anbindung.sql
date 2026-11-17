-- Individuelle Kalenderanbindung pro Coach; Zugangsdaten nur verschlüsselt speichern.
CREATE TABLE coaching_kalender_einstellungen (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 benutzer_id BIGINT UNSIGNED NOT NULL,
 provider ENUM('google','icloud') NOT NULL DEFAULT 'google',
 aktiv TINYINT(1) NOT NULL DEFAULT 0,
 google_email VARCHAR(190) NULL,
 refresh_token_verschluesselt TEXT NULL,
 access_token_verschluesselt TEXT NULL,
 token_ablauf_am DATETIME NULL,
 apple_id VARCHAR(190) NULL,
 icloud_passwort_verschluesselt TEXT NULL,
 kalender_id VARCHAR(1000) NULL,
 kalender_name VARCHAR(255) NULL,
 klientenname_anzeigen TINYINT(1) NOT NULL DEFAULT 0,
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_kalender_benutzer (benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coaching_kalender_termine (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 benutzer_id BIGINT UNSIGNED NOT NULL,
 termin_id BIGINT UNSIGNED NOT NULL,
 provider ENUM('google','icloud') NOT NULL,
 remote_id VARCHAR(1500) NOT NULL,
 kalender_id VARCHAR(1000) NOT NULL,
 abgeglichen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_kalender_termin (benutzer_id,termin_id),
 KEY idx_kalender_termine_coach (benutzer_id,abgeglichen_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datenbankkonto benötigt SELECT, INSERT, UPDATE, DELETE auf beiden Tabellen.
