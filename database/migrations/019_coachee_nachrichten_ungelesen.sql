-- Coaching Tool: Coachee-Anzeige ungelesener Nachrichten vom Coach
-- Einmalig nach Migration 018 importieren, nachdem eine Datenbanksicherung erstellt wurde.
ALTER TABLE coaching_nachrichten
 ADD COLUMN coachee_gelesen_am DATETIME NULL AFTER erstellt_am,
 ADD KEY idx_nachricht_coachee_unread (klient_id,coach_benutzer_id,auftrag_id,autor_rolle,coachee_gelesen_am);

-- Bestehende Nachrichten gelten als bereits gesehen; nur neue Coach-Nachrichten
-- nach der Migration werden beim Coachee als ungelesen markiert.
UPDATE coaching_nachrichten
 SET coachee_gelesen_am=erstellt_am
 WHERE autor_rolle='coach' AND coachee_gelesen_am IS NULL;

-- Rechte: coaching_nachrichten SELECT/INSERT/UPDATE.
