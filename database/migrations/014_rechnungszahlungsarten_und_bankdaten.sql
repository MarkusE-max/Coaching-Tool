-- Coaching Tool: EC-Zahlung und zentrierte Bankdaten im Rechnungsfuß
-- Vor Import eine vollständige MariaDB-Sicherung anlegen; einmalig nach Migration 013 importieren.
ALTER TABLE coaching_rechnungseinstellungen
 ADD COLUMN bank_name VARCHAR(190) NULL AFTER bank_inhaber,
 ADD COLUMN ec_zahlung_aktiv TINYINT(1) NOT NULL DEFAULT 0 AFTER barzahlung_aktiv;
ALTER TABLE coaching_rechnungen
 MODIFY zahlungsart ENUM('ueberweisung','bar','ec') NOT NULL DEFAULT 'ueberweisung',
 ADD COLUMN bank_name VARCHAR(190) NULL AFTER bank_inhaber,
 ADD COLUMN zahlungsziel_tage SMALLINT UNSIGNED NULL AFTER faellig_am;
UPDATE coaching_rechnungen
 SET zahlungsziel_tage=GREATEST(0,DATEDIFF(faellig_am,rechnungsdatum))
 WHERE zahlungsart='ueberweisung' AND faellig_am IS NOT NULL
   AND rechnungsdatum IS NOT NULL AND zahlungsziel_tage IS NULL;
