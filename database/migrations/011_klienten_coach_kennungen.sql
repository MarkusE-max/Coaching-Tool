-- Coaching Tool: dauerhaft eindeutige Klienten- und Coach-Kennungen
-- MariaDB 10. Vollständige Sicherung anlegen, danach einmalig nach Migration 010 importieren.
-- Bereits eingetragene Kundennummern bleiben erhalten; leere Nummern werden aus der
-- unveränderlichen internen Klienten-ID abgeleitet. Coach-Kennungen werden ebenso
-- aus der bestehenden Benutzer-ID abgeleitet.

UPDATE klienten
 SET kundennummer = CONCAT('KL-', LPAD(id, 6, '0'))
 WHERE kundennummer IS NULL OR TRIM(kundennummer) = '';

ALTER TABLE klienten
 ADD KEY idx_klienten_kundennummer (kundennummer);

ALTER TABLE benutzer
 ADD COLUMN coach_kennung VARCHAR(40) NULL;

UPDATE benutzer
 SET coach_kennung = CONCAT('CO-', LPAD(id, 5, '0'))
 WHERE rolle = 'coach' AND (coach_kennung IS NULL OR TRIM(coach_kennung) = '');

ALTER TABLE benutzer
 ADD UNIQUE KEY uq_benutzer_coach_kennung (coach_kennung);
