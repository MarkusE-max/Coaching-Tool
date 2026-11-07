-- Coaching Tool: Sitzungsnotizen und Ziele
-- MariaDB 10 / utf8mb4
-- Sicherung der Datenbank erstellen, bevor diese Migration ausgeführt wird.
-- Diese Migration ist additiv und legt nur neue Tabellen an.
-- Referenz-IDs bilden die Werte aus klienten.id, termine.id und benutzer.id ab.
-- Auf Fremdschlüssel wurde verzichtet, damit sie mit der vorhandenen ID-Spaltenbreite kompatibel bleibt.

CREATE TABLE IF NOT EXISTS coaching_sitzungsnotizen (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    termin_id BIGINT UNSIGNED NULL,
    klient_id BIGINT UNSIGNED NOT NULL,
    coach_benutzer_id BIGINT UNSIGNED NOT NULL,
    titel VARCHAR(190) NOT NULL,
    inhalt MEDIUMTEXT NOT NULL,
    sichtbarkeit ENUM('coach_private', 'geteilt') NOT NULL DEFAULT 'coach_private',
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sitzungsnotizen_coach_klient (coach_benutzer_id, klient_id),
    KEY idx_sitzungsnotizen_termin (termin_id),
    KEY idx_sitzungsnotizen_erstellt (erstellt_am),
    FULLTEXT KEY ft_sitzungsnotizen_inhalt (titel, inhalt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_ziele (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    klient_id BIGINT UNSIGNED NOT NULL,
    coach_benutzer_id BIGINT UNSIGNED NOT NULL,
    titel VARCHAR(190) NOT NULL,
    beschreibung TEXT NULL,
    erfolgskriterium TEXT NULL,
    zieltermin DATE NULL,
    status ENUM('aktiv', 'pausiert', 'erreicht', 'abgebrochen') NOT NULL DEFAULT 'aktiv',
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ziele_coach_klient_status (coach_benutzer_id, klient_id, status),
    KEY idx_ziele_zieltermin (zieltermin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Die Anwendung benötigt zusätzlich:
-- coaching_sitzungsnotizen: SELECT, INSERT, UPDATE
-- coaching_ziele: SELECT, INSERT, UPDATE
-- termine: SELECT, INSERT, UPDATE
