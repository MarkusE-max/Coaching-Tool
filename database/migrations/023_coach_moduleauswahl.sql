-- Coach-spezifische Modulauswahl. Vorher Datenbank und private Dateien sichern.
CREATE TABLE IF NOT EXISTS coaching_coach_moduleeinstellungen (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 termine_aktiv TINYINT(1) NOT NULL DEFAULT 1,
 aufgaben_aktiv TINYINT(1) NOT NULL DEFAULT 1,
 chat_aktiv TINYINT(1) NOT NULL DEFAULT 1,
 ziele_aktiv TINYINT(1) NOT NULL DEFAULT 1,
 dokumente_aktiv TINYINT(1) NOT NULL DEFAULT 1,
 auftraege_aktiv TINYINT(1) NOT NULL DEFAULT 1,
 rechnungen_aktiv TINYINT(1) NOT NULL DEFAULT 1,
 geaendert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id), UNIQUE KEY uq_coach_moduleinstellungen (coach_benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
