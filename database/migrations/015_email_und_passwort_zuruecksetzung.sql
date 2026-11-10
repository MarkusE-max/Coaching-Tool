-- Coaching Tool: SMTP-Versand und sichere Passwort-Zurücksetzung
-- Vor Import vollständige MariaDB-Sicherung anlegen; einmalig nach Migration 014 importieren.
ALTER TABLE coaching_portal_zugaenge
 ADD COLUMN email VARCHAR(190) NULL AFTER benutzername;

CREATE TABLE coaching_email_einstellungen (
 id TINYINT UNSIGNED NOT NULL DEFAULT 1,
 aktiv TINYINT(1) NOT NULL DEFAULT 0,
 smtp_host VARCHAR(253) NOT NULL DEFAULT '',
 smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
 smtp_sicherheit ENUM('keine','starttls','ssl') NOT NULL DEFAULT 'starttls',
 smtp_auth TINYINT(1) NOT NULL DEFAULT 1,
 smtp_benutzername VARCHAR(190) NULL,
 smtp_passwort_verschluesselt TEXT NULL,
 absender_name VARCHAR(190) NOT NULL DEFAULT 'Coaching Tool',
 absender_email VARCHAR(190) NOT NULL DEFAULT '',
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coaching_passwort_resets (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 konto_typ ENUM('benutzer','coachee') NOT NULL,
 konto_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ablauf_am DATETIME NOT NULL,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 verwendet_am DATETIME NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_reset_token_hash (token_hash),
 KEY idx_reset_account_time (konto_typ,konto_id,erstellt_am),
 KEY idx_reset_expiry (ablauf_am,verwendet_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
