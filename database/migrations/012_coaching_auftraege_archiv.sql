-- Coaching Tool: getrennte Coaching-Aufträge mit optionaler Coachee-Archivfreigabe
-- Nach vollständiger Sicherung einmalig nach Migration 011 importieren.
-- Vorhandene Inhalte werden pro Klient einem Auftrag "Bisherige Betreuung" zugeordnet.
-- Private Sitzungsnotizen bleiben ausschließlich im Coachbereich.

CREATE TABLE coaching_auftraege (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 klient_id BIGINT UNSIGNED NOT NULL,
 coach_benutzer_id BIGINT UNSIGNED NOT NULL,
 titel VARCHAR(190) NOT NULL,
 beschreibung TEXT NULL,
 status ENUM('aktiv','abgeschlossen') NOT NULL DEFAULT 'aktiv',
 archiv_freigegeben TINYINT(1) NOT NULL DEFAULT 0,
 erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 abgeschlossen_am DATETIME NULL,
 PRIMARY KEY (id),
 KEY idx_auftrag_klient_status (klient_id,status),
 KEY idx_auftrag_coach (coach_benutzer_id,klient_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO coaching_auftraege (klient_id,coach_benutzer_id,titel,status,archiv_freigegeben,abgeschlossen_am)
 SELECT k.id,k.benutzer_id,'Bisherige Betreuung',
        CASE WHEN s.status='abgeschlossen' THEN 'abgeschlossen' ELSE 'aktiv' END,
        0,
        CASE WHEN s.status='abgeschlossen' THEN COALESCE(s.geaendert_am,CURRENT_TIMESTAMP) ELSE NULL END
 FROM klienten k
 LEFT JOIN coaching_klientenstatus s ON s.klient_id=k.id
 WHERE k.benutzer_id IS NOT NULL AND k.benutzer_id>0;

ALTER TABLE termine
 ADD COLUMN IF NOT EXISTS auftrag_id BIGINT UNSIGNED NULL,
 ADD KEY IF NOT EXISTS idx_termin_auftrag (auftrag_id);

ALTER TABLE coaching_sitzungsnotizen
 ADD COLUMN IF NOT EXISTS auftrag_id BIGINT UNSIGNED NULL,
 ADD KEY IF NOT EXISTS idx_notiz_auftrag (auftrag_id);

ALTER TABLE coaching_ziele
 ADD COLUMN IF NOT EXISTS auftrag_id BIGINT UNSIGNED NULL,
 ADD KEY IF NOT EXISTS idx_ziel_auftrag (auftrag_id);

ALTER TABLE coaching_aufgaben
 ADD COLUMN IF NOT EXISTS auftrag_id BIGINT UNSIGNED NULL,
 ADD KEY IF NOT EXISTS idx_aufgabe_auftrag (auftrag_id);

ALTER TABLE coaching_nachrichten
 ADD COLUMN IF NOT EXISTS auftrag_id BIGINT UNSIGNED NULL,
 ADD KEY IF NOT EXISTS idx_nachricht_auftrag (auftrag_id);

ALTER TABLE coaching_klientendokumente
 ADD COLUMN IF NOT EXISTS auftrag_id BIGINT UNSIGNED NULL,
 ADD KEY IF NOT EXISTS idx_klientendokument_auftrag (auftrag_id);

UPDATE termine t
 INNER JOIN coaching_auftraege a ON a.klient_id=t.klient_id AND a.titel='Bisherige Betreuung'
 SET t.auftrag_id=a.id
 WHERE t.auftrag_id IS NULL;

UPDATE coaching_sitzungsnotizen n
 INNER JOIN coaching_auftraege a ON a.klient_id=n.klient_id AND a.titel='Bisherige Betreuung'
 SET n.auftrag_id=a.id
 WHERE n.auftrag_id IS NULL;

UPDATE coaching_ziele z
 INNER JOIN coaching_auftraege a ON a.klient_id=z.klient_id AND a.titel='Bisherige Betreuung'
 SET z.auftrag_id=a.id
 WHERE z.auftrag_id IS NULL;

UPDATE coaching_aufgaben t
 INNER JOIN coaching_auftraege a ON a.klient_id=t.klient_id AND a.titel='Bisherige Betreuung'
 SET t.auftrag_id=a.id
 WHERE t.auftrag_id IS NULL;

UPDATE coaching_nachrichten n
 INNER JOIN coaching_auftraege a ON a.klient_id=n.klient_id AND a.titel='Bisherige Betreuung'
 SET n.auftrag_id=a.id
 WHERE n.auftrag_id IS NULL;

UPDATE coaching_klientendokumente d
 INNER JOIN coaching_auftraege a ON a.klient_id=d.klient_id AND a.titel='Bisherige Betreuung'
 SET d.auftrag_id=a.id
 WHERE d.auftrag_id IS NULL;

-- Bestehende App-Berechtigungen reichen, wenn der Datenbankbenutzer bereits
-- SELECT/INSERT/UPDATE für die genannten Tabellen sowie SELECT/INSERT für termine hat.
-- Für coaching_auftraege: SELECT, INSERT, UPDATE.
