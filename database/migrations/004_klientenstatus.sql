-- Coaching Tool: overall client status for the central client overview
-- MariaDB 10 / utf8mb4. Additive migration; existing tables stay unchanged.
-- IDs refer to klienten.id and benutzer.id. No foreign keys because legacy ID widths vary.
CREATE TABLE IF NOT EXISTS coaching_klientenstatus (
 klient_id BIGINT UNSIGNED NOT NULL,
 status ENUM('aktiv','pausiert','abgeschlossen') NOT NULL DEFAULT 'aktiv',
 geaendert_von_benutzer_id BIGINT UNSIGNED NOT NULL,
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (klient_id),
 KEY idx_klientenstatus_aenderer (geaendert_von_benutzer_id),
 KEY idx_klientenstatus_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
