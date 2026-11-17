-- Individuelle Sicht- und Bearbeitungsrechte je Coachee-Zugang.
-- Nach Migration 020 importieren.
ALTER TABLE coaching_portal_zugaenge
 ADD COLUMN zeige_termine TINYINT(1) NOT NULL DEFAULT 1,
 ADD COLUMN zeige_ziele TINYINT(1) NOT NULL DEFAULT 1,
 ADD COLUMN zeige_aufgaben TINYINT(1) NOT NULL DEFAULT 1,
 ADD COLUMN aufgaben_bearbeiten TINYINT(1) NOT NULL DEFAULT 1,
 ADD COLUMN zeige_nachrichten TINYINT(1) NOT NULL DEFAULT 1,
 ADD COLUMN nachrichten_senden TINYINT(1) NOT NULL DEFAULT 1,
 ADD COLUMN zeige_dokumente TINYINT(1) NOT NULL DEFAULT 1,
 ADD COLUMN zeige_archiv TINYINT(1) NOT NULL DEFAULT 1;

-- Existing portal behavior is preserved. Coaches can adjust the individual switches per client.
