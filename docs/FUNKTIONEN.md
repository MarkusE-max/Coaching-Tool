# Funktionsumfang F1–F8

Diese Datei hält den vom Projektinhaber benannten Funktionsumfang und den aktuellen Umsetzungsstand fest. Eine Funktion gilt erst dann als umgesetzt, wenn Oberfläche, Datenbankrechte und Zugriffskontrolle vorhanden sind.

| Nr. | Bereich | Funktion | Stand |
| --- | --- | --- | --- |
| F1 | Nutzer und Rollen | Login, Rollen Coach / Klient / Admin, Passwort-Reset | Teilweise: Coach/Admin-Login und sichere Kontoanlage sind vorhanden. Klient-Login und Passwort-Reset fehlen. |
| F2 | Klientenverwaltung | Klientenstammdaten, Status (aktiv/pausiert/abgeschlossen) | Teilweise: Coach/Admin können Stammdaten anlegen und bearbeiten. Statusauswahl und Klient-Login-Zuordnung fehlen. |
| F3 | Termine | Termine pro Klient anlegen, bearbeiten, Verlauf | Umgesetzt: Anlage, Bearbeitung und Verlauf; Rechte werden anhand der Klientenzuordnung geprüft. |
| F4 | Stream | Gemeinsamer Nachrichtenverlauf Coach ↔ Klient, Dateianhänge | Geplant: nur ausdrücklich geteilte Nachrichten; Dateispeicherung außerhalb des öffentlichen Webordners. |
| F5 | Aufgaben | Übungen/Aktionen mit Fälligkeit und Status | Offen: Ziele sind vorhanden; eigenständige Aufgaben mit Fälligkeit und Erledigungsstatus fehlen. |
| F6 | Erinnerungen | Automatische E-Mail-Erinnerungen zu Aufgaben | Geplant: benötigt SMTP-Konfiguration, zeitgesteuerten DSM-Aufruf und Versandprotokoll. |
| F7 | Private Notizen | Nur für Coach sichtbar, durchsuchbar | Umgesetzt: private Notizen sind coachgebunden, optional mit einem Termin verknüpft und durchsuchbar; Klient und Admin erhalten keinen Zugriff. |
| F8 | Dokumente und Vereinbarungen | Vorlagen griffbereit halten, Klienten zuweisen, ausfüllen/unterschreiben und unterschriebene Fassung beim Klienten speichern | Geplant: Bibliothek, Versionen und sichere Ablage vorgesehen. Unterschriftweg (digital im Portal oder unterschriebene Datei hochladen) ist noch festzulegen. |

## Dokumentenablauf (F8)

- Coaches verwalten eigene Vorlagen, zum Beispiel Coaching-Vertrag, Datenschutz- und Einwilligungsunterlagen sowie Formulare zum Ausfüllen.
- Eine Vorlage kann einem bestimmten Klienten zugewiesen werden. Der Vorgang hält Vorlage/Version, Klient, Status und Zeitpunkte fest.
- Die unterschriebene oder ausgefüllte Endfassung wird privat gespeichert und mit dem Klienten sowie der verwendeten Vorlagenversion verknüpft.
- Dateien liegen außerhalb des öffentlichen Webordners. Jede Ansicht, jeder Download und jede Änderung prüft die Rechte serverseitig.
- Der Coachee darf nur die für ihn freigegebenen Dokumente sehen. Coach-interne Dokumente bleiben privat.
- Offen ist, ob der erste Ablauf mit Upload einer unterschriebenen PDF/Scan-Datei startet oder ob der Coachee direkt im Portal ausfüllt und digital unterschreibt. Der Portalweg hängt außerdem vom Klient-Login aus F1 ab.

## Zusätzlicher Umfang

- Termine, Sitzungsnotizen und Ziele hängen an einem Klienten und sind im Verlauf auffindbar.
- Bestehende Einträge sollen über eine Bearbeiten-Funktion änderbar sein.
- Ziele erhalten Beschreibung, Erfolgskriterium, Zieltermin und Status.
- Private Sitzungsnotizen bleiben ausschließlich für den zuständigen Coach sichtbar. Admin-Rechte gewähren keinen pauschalen Zugriff auf private Notizen.
- Nicht als umgesetzt markierte Anforderungen bleiben offen und werden schrittweise ergänzt.

## Datenbankänderung

Die SQL-Datei `database/migrations/001_sitzungsnotizen_ziele.sql` ergänzt die Tabellen für Sitzungsnotizen und Ziele. F8 benötigt später eine zusätzliche Migration für Vorlagen, zugewiesene Dokumente, Versionen und Speicherreferenzen; diese wird nach Festlegung des Unterschriftwegs erstellt. Vor jeder Migration eine Datenbanksicherung erstellen und die Tabellenrechte des Anwendungsbenutzers prüfen.
