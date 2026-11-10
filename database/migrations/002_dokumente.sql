-- Coaching Tool: Vorlagen, Klientendokumente und zurückgegebene Signaturdateien
-- MariaDB 10 / utf8mb4
-- Vor dem Import eine vollständige Datenbanksicherung erstellen.
-- Additive Migration: bestehende Klienten- und Termintabellen bleiben unverändert.
-- IDs referenzieren bestehende benutzer.id und klienten.id; auf Fremdschlüssel
-- wird wegen der unbekannten ID-Spaltenbreite des vorhandenen Schemas verzichtet.

CREATE TABLE IF NOT EXISTS coaching_dokumentvorlagen (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    coach_benutzer_id BIGINT UNSIGNED NOT NULL,
    titel VARCHAR(190) NOT NULL,
    kategorie VARCHAR(60) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    originalname VARCHAR(255) NOT NULL,
    speicher_schluessel CHAR(52) NOT NULL,
    mime_typ VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    groesse_bytes BIGINT UNSIGNED NOT NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dokumentvorlage_speicher (speicher_schluessel),
    UNIQUE KEY uq_dokumentvorlage_version (coach_benutzer_id, titel, version),
    KEY idx_dokumentvorlage_coach (coach_benutzer_id, aktiv, kategorie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_klientendokumente (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    klient_id BIGINT UNSIGNED NOT NULL,
    coach_benutzer_id BIGINT UNSIGNED NOT NULL,
    vorlage_id BIGINT UNSIGNED NOT NULL,
    vorlage_version INT UNSIGNED NOT NULL,
    vorlage_speicher_schluessel CHAR(52) NOT NULL,
    vorlage_originalname VARCHAR(255) NOT NULL,
    titel VARCHAR(190) NOT NULL,
    status ENUM('zugewiesen', 'ausgegeben', 'zurueckgekommen', 'archiviert') NOT NULL DEFAULT 'zugewiesen',
    zugewiesen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ausgegeben_am DATETIME NULL,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_klientendokument_klient_status (klient_id, status),
    KEY idx_klientendokument_coach (coach_benutzer_id, klient_id),
    KEY idx_klientendokument_vorlage (vorlage_id, vorlage_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coaching_dokumentdateien (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    klientendokument_id BIGINT UNSIGNED NOT NULL,
    coach_benutzer_id BIGINT UNSIGNED NOT NULL,
    speicher_schluessel CHAR(52) NOT NULL,
    originalname VARCHAR(255) NOT NULL,
    mime_typ VARCHAR(100) NOT NULL,
    groesse_bytes BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    hochgeladen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dokumentdatei_speicher (speicher_schluessel),
    UNIQUE KEY uq_dokumentdatei_version (klientendokument_id, version),
    KEY idx_dokumentdatei_coach (coach_benutzer_id, klientendokument_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anwendungsrechte:
-- coaching_dokumentvorlagen: SELECT, INSERT, UPDATE
-- coaching_klientendokumente: SELECT, INSERT, UPDATE
-- coaching_dokumentdateien: SELECT, INSERT, DELETE (DELETE only for removal of uploaded returns)
