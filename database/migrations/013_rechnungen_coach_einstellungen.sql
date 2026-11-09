-- Coaching Tool: Einzelrechnungen je Sitzung, Coach-Abrechnungseinstellungen und Auftragsübersichten
-- Vollständige MariaDB-Sicherung anlegen; einmalig nach Migration 012 importieren.
-- Steuersätze und Textvorlagen sind coachbezogen. Rechnungen speichern unveränderliche Snapshots.

CREATE TABLE coaching_rechnungseinstellungen (
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 absender_name VARCHAR(190) NOT NULL DEFAULT '',
 absender_strasse VARCHAR(190) NULL,
 absender_plz VARCHAR(20) NULL,
 absender_ort VARCHAR(100) NULL,
 absender_land VARCHAR(100) NULL,
 absender_email VARCHAR(190) NULL,
 absender_telefon VARCHAR(40) NULL,
 steuernummer VARCHAR(100) NULL,
 umsatzsteuer_id VARCHAR(100) NULL,
 bank_inhaber VARCHAR(190) NULL,
 bank_iban VARCHAR(64) NULL,
 bank_bic VARCHAR(32) NULL,
 barzahlung_aktiv TINYINT(1) NOT NULL DEFAULT 0,
 standard_steuersatz_id BIGINT UNSIGNED NULL,
 standard_vorlage_id BIGINT UNSIGNED NULL,
 logo_speicher_schluessel CHAR(52) NULL,
 logo_mime VARCHAR(40) NULL,
 zahlungsziel_tage SMALLINT UNSIGNED NOT NULL DEFAULT 14,
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (coach_benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coaching_steuersaetze (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 bezeichnung VARCHAR(100) NOT NULL,
 prozentsatz DECIMAL(5,2) NOT NULL DEFAULT 0.00,
 aktiv TINYINT(1) NOT NULL DEFAULT 1,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_steuersatz_coach_aktiv (coach_benutzer_id,aktiv),
 CONSTRAINT chk_steuersatz_wert CHECK (prozentsatz >= 0 AND prozentsatz <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coaching_rechnungsvorlagen (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 titel VARCHAR(190) NOT NULL,
 kopftext TEXT NULL,
 fusszeile TEXT NULL,
 auftragstext TEXT NULL,
 aktiv TINYINT(1) NOT NULL DEFAULT 1,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_rechnungsvorlage_coach_aktiv (coach_benutzer_id,aktiv)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coaching_rechnungen (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 klient_id BIGINT UNSIGNED NOT NULL,
 auftrag_id BIGINT UNSIGNED NOT NULL,
 termin_id BIGINT UNSIGNED NOT NULL,
 rechnungsnummer VARCHAR(50) NULL,
 status ENUM('entwurf','gestellt','bezahlt','storniert') NOT NULL DEFAULT 'entwurf',
 rechnungsdatum DATE NULL,
 faellig_am DATE NULL,
 bezahlt_am DATETIME NULL,
 waehrung CHAR(3) NOT NULL DEFAULT 'EUR',
 sitzung_beginn DATETIME NOT NULL,
 dauer_minuten SMALLINT UNSIGNED NOT NULL,
 leistungsbeschreibung VARCHAR(255) NOT NULL,
 kundennummer VARCHAR(40) NULL,
 coach_kennung VARCHAR(40) NULL,
 empfaenger_name VARCHAR(190) NOT NULL,
 empfaenger_adresse TEXT NULL,
 empfaenger_email VARCHAR(190) NULL,
 absender_name VARCHAR(190) NOT NULL,
 absender_adresse TEXT NULL,
 absender_email VARCHAR(190) NULL,
 absender_telefon VARCHAR(40) NULL,
 steuernummer VARCHAR(100) NULL,
 umsatzsteuer_id VARCHAR(100) NULL,
 bank_inhaber VARCHAR(190) NULL,
 bank_iban VARCHAR(64) NULL,
 bank_bic VARCHAR(32) NULL,
 zahlungsart ENUM('ueberweisung','bar') NOT NULL DEFAULT 'ueberweisung',
 logo_speicher_schluessel CHAR(52) NULL,
 logo_mime VARCHAR(40) NULL,
 vorlage_titel VARCHAR(190) NULL,
 kopftext TEXT NULL,
 fusszeile TEXT NULL,
 auftragstext TEXT NULL,
 stundensatz DECIMAL(10,2) NOT NULL DEFAULT 0.00,
 netto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 steuersatz_bezeichnung VARCHAR(100) NOT NULL,
 steuersatz DECIMAL(5,2) NOT NULL DEFAULT 0.00,
 steuerbetrag DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 brutto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_rechnung_termin (termin_id),
 UNIQUE KEY uq_rechnungsnummer (rechnungsnummer),
 KEY idx_rechnung_coach_status (coach_benutzer_id,status,rechnungsdatum),
 KEY idx_rechnung_auftrag (auftrag_id,klient_id),
 KEY idx_rechnung_klient (klient_id,coach_benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kein automatischer Steuersatz wird angenommen. Jeder Coach pflegt seine
-- anwendbaren Sätze und wählt den Standardsatz selbst in invoice-settings.php.
-- Bestehende abgeschlossene Sitzungen erhalten erst beim bewussten Erstellen
-- einer Rechnung einen Rechnungssnapshot.
