-- Coaching Tool: kurzlebige QR-Signatur-Anfragen
-- MariaDB 10 / utf8mb4; additive migration; existing tables remain unchanged.
-- Vor dem Import ein vollständiges Datenbank-Backup erstellen.
-- Foreign keys omitted because existing client/user ID widths are not standardized.

CREATE TABLE IF NOT EXISTS coaching_dokument_signaturanfragen (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    klientendokument_id BIGINT UNSIGNED NOT NULL,
    coach_benutzer_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    ablauf_am DATETIME NOT NULL,
    code_versuche TINYINT UNSIGNED NOT NULL DEFAULT 0,
    signierende_person VARCHAR(190) NULL,
    original_sha256 CHAR(64) NULL,
    signiert_sha256 CHAR(64) NULL,
    signierte_datei_id BIGINT UNSIGNED NULL,
    signiert_am DATETIME NULL,
    widerrufen_am DATETIME NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dokument_signatur_token (token_hash),
    KEY idx_dokument_signatur_dokument (klientendokument_id, coach_benutzer_id, signiert_am, widerrufen_am),
    KEY idx_dokument_signatur_ablauf (ablauf_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anwendungsrechte:
-- coaching_dokument_signaturanfragen: SELECT, INSERT, UPDATE
