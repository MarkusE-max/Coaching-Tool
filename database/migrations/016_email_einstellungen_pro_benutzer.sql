-- Coaching Tool: individuelle SMTP- und Antwortadressen je Benutzerkonto
-- Vor Import eine Datenbanksicherung anlegen; einmalig nach Migration 015 importieren.
-- Die bisher zentrale Konfiguration wird dem ersten aktiven Admin-Konto zugeordnet.
ALTER TABLE coaching_email_einstellungen
 MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 ADD COLUMN benutzer_id BIGINT UNSIGNED NULL AFTER id,
 ADD COLUMN antwort_email VARCHAR(190) NULL AFTER absender_email;

UPDATE coaching_email_einstellungen
 SET benutzer_id=(SELECT id FROM benutzer WHERE rolle='admin' AND aktiv=1 ORDER BY id LIMIT 1)
 WHERE id=1 AND benutzer_id IS NULL;

ALTER TABLE coaching_email_einstellungen
 ADD UNIQUE KEY uq_email_settings_benutzer (benutzer_id);
