-- Coaching Tool: coach-specific client overview card order
-- Run once after migrations 001-017, after taking a database backup.
CREATE TABLE coaching_klientenuebersicht_layout (
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 layout_json TEXT NOT NULL,
 geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (coach_benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rights: SELECT/INSERT/UPDATE on coaching_klientenuebersicht_layout.
