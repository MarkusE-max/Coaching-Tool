-- Coaching Tool: Klientenadressen und Rechnungsgrundlagen
-- MariaDB 10. Erst nach einer vollständigen Datenbanksicherung ausführen.
-- Einmalig nach Migration 009 importieren.

ALTER TABLE klienten
 ADD COLUMN adresse_strasse VARCHAR(190) NULL,
 ADD COLUMN adresse_plz VARCHAR(20) NULL,
 ADD COLUMN adresse_ort VARCHAR(100) NULL,
 ADD COLUMN adresse_land VARCHAR(100) NULL,
 ADD COLUMN rechnungsadresse_abweichend TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN rechnung_empfaenger VARCHAR(190) NULL,
 ADD COLUMN rechnung_strasse VARCHAR(190) NULL,
 ADD COLUMN rechnung_plz VARCHAR(20) NULL,
 ADD COLUMN rechnung_ort VARCHAR(100) NULL,
 ADD COLUMN rechnung_land VARCHAR(100) NULL,
 ADD COLUMN rechnung_email VARCHAR(190) NULL,
 ADD COLUMN kundennummer VARCHAR(40) NULL,
 ADD COLUMN stundensatz DECIMAL(10,2) NULL,
 ADD COLUMN preisvereinbarung TEXT NULL,
 ADD COLUMN zahlungsziel_tage SMALLINT UNSIGNED NULL;

-- Die Anwendung aktualisiert die neuen Spalten in klienten. Der bestehende
-- Tabellenzugriff mit SELECT/INSERT/UPDATE reicht dafür aus.
