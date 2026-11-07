# Funktionsumfang F1–F7

Diese Datei hält die vom Projektinhaber benannten Funktionen und den aktuellen Umsetzungsstand fest. Eine aufgeführte Funktion gilt erst dann als umgesetzt, wenn die Bedienoberfläche, Datenbankrechte und Zugriffskontrolle dafür vorhanden sind.

| Nr. | Bereich | Funktion | Stand |
| --- | --- | --- | --- |
| F1 | Nutzer und Rollen | Login, Rollen Coach / Klient / Admin, Passwort-Reset | Teilweise: Coach/Admin-Login und sichere Kontoanlage sind vorhanden. Klient-Login und Passwort-Reset fehlen. |
| F2 | Klientenverwaltung | Klientenstammdaten, Status (aktiv/pausiert/abgeschlossen) | Teilweise: Coach/Admin können Stammdaten anlegen und bearbeiten. Statusauswahl und Klient-Login-Zuordnung fehlen. |
| F3 | Termine | Termine pro Klient anlegen, bearbeiten, Verlauf | Als nächster Kernschritt: bestehende Tabelle `termine` verwenden; Rechte nach Klientenzuordnung prüfen. |
| F4 | Stream | Gemeinsamer Nachrichtenverlauf Coach ↔ Klient, Dateianhänge | Geplant: nur ausdrücklich geteilte Nachrichten; Dateispeicherung außerhalb des öffentlichen Webordners. |
| F5 | Aufgaben | Übungen/Aktionen mit Fälligkeit und Status | Teilweise durch Ziele ergänzt; eigenständige Aufgaben mit Fälligkeit und Erledigungsstatus fehlen. |
| F6 | Erinnerungen | Automatische E-Mail-Erinnerungen zu Aufgaben | Geplant: benötigt SMTP-Konfiguration, zeitgesteuerten DSM-Aufruf und Versandprotokoll. |
| F7 | Private Notizen | Nur für Coach sichtbar, durchsuchbar | Als nächster Kernschritt: private Notizen gehören zu einem Coach und Klienten, optional zu einem Termin; niemals im Klientenportal anzeigen. |

## Zusätzlicher Umfang

- Termine, Sitzungsnotizen und Ziele sollen an einem Klienten hängen und später im Verlauf auffindbar sein.
- Bestehende Einträge sollen über eine Bearbeiten-Funktion änderbar sein.
- Ziele erhalten Beschreibung, Erfolgskriterium, Zieltermin und Status.
- Private Sitzungsnotizen bleiben ausschließlich für den zuständigen Coach sichtbar. Admin-Rechte gewähren keinen pauschalen Zugriff auf private Notizen.
- Die F1–F7-Liste ist der Funktionsumfang; nicht als umgesetzt markierte Punkte bleiben offen und werden schrittweise ergänzt.

## Datenbankänderung

Die SQL-Datei `database/migrations/001_sitzungsnotizen_ziele.sql` ergänzt die Tabellen für Sitzungsnotizen und Ziele. Sie verändert die bestehende Klienten- und Terminstruktur nicht. Vor dem Ausführen eine Datenbanksicherung erstellen und mit dem Anwendungsbenutzer die neuen Tabellenrechte prüfen.
