-- Coaching Tool: Vorher-/Nachher-Skala für private Sitzungsnotizen
-- MariaDB 10. Sicherung der Datenbank erstellen und Migration einmalig importieren.
-- Wertebereich: -10 bis +10. Bestehende Einträge bleiben ohne Bewertung (NULL).
ALTER TABLE coaching_sitzungsnotizen
  ADD COLUMN IF NOT EXISTS skala_vorher TINYINT NULL AFTER inhalt,
  ADD COLUMN IF NOT EXISTS skala_nachher TINYINT NULL AFTER skala_vorher;
