-- Coaching Tool: Verwalterrolle und Verknüpfung privater Sitzungsnotizen mit Formaten
-- Sicherung erstellen und nach dem deploy einmalig über phpMyAdmin importieren.
-- Ermöglicht die Rolle verwalter. Bestehende Rollenwerte bleiben erhalten.
ALTER TABLE benutzer MODIFY COLUMN rolle VARCHAR(20) NOT NULL;

-- Eine Notiz kann optional auf ein für den Coach sichtbares Wissensformat verweisen.
ALTER TABLE coaching_sitzungsnotizen
  ADD COLUMN IF NOT EXISTS format_id BIGINT UNSIGNED NULL AFTER termin_id,
  ADD KEY IF NOT EXISTS idx_sitzungsnotizen_format (format_id);

-- Audit-Protokoll für Änderungen der Klientenzuordnung. Keine Notiz- oder Sitzungsinhalte.
CREATE TABLE IF NOT EXISTS coaching_klientenzuweisungen (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  klient_id BIGINT UNSIGNED NOT NULL,
  vorheriger_coach_id BIGINT UNSIGNED NOT NULL,
  neuer_coach_id BIGINT UNSIGNED NOT NULL,
  geaendert_von_benutzer_id BIGINT UNSIGNED NOT NULL,
  geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_klientenzuweisung_klient (klient_id, geaendert_am),
  KEY idx_klientenzuweisung_verwalter (geaendert_von_benutzer_id, geaendert_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datenbankrechte für den App-Benutzer:
-- coaching_sitzungsnotizen: bestehende SELECT/INSERT/UPDATE Rechte reichen.
-- coaching_formate und coaching_format_freigaben: SELECT (bereits für Wissenssammlung erforderlich).
-- coaching_klientenzuweisungen: INSERT/SELECT.
-- benutzer: bestehende SELECT/UPDATE Rechte müssen ALTER TABLE nicht enthalten.
