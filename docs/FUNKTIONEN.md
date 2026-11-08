# Funktionsumfang F1–F8

Diese Datei hält den vom Projektinhaber benannten Funktionsumfang und den aktuellen Umsetzungsstand fest. Eine Funktion gilt erst dann als umgesetzt, wenn Oberfläche, Datenbankrechte und Zugriffskontrolle vorhanden sind.

| Nr. | Bereich | Funktion | Stand |
| --- | --- | --- | --- |
| F1 | Nutzer und Rollen | Login, Rollen Coach / Klient / Admin, Passwort-Reset | Teilweise umgesetzt: eigener Coachee-Login, Coach kann Passwort neu setzen; Coachee kann es im Portal ändern. Admin-Reset für Coachees fehlt. |
| F2 | Klientenverwaltung | Klientenstammdaten, Status (aktiv/pausiert/abgeschlossen) | Teilweise: Stammdaten, Status und Coach-seitige Zuordnung eines Coachee-Zugangs sind vorhanden. |
| F3 | Termine | Termine pro Klient anlegen, bearbeiten, Verlauf | Umgesetzt: Anlage, Bearbeitung und Verlauf; Rechte werden anhand der Klientenzuordnung geprüft. |
| F4 | Stream | Gemeinsamer Nachrichtenverlauf Coach ↔ Klient, Dateianhänge | Geplant: nur ausdrücklich geteilte Nachrichten; Dateispeicherung außerhalb des öffentlichen Webordners. |
| F5 | Aufgaben | Übungen/Aktionen mit Fälligkeit und Status | Teilweise umgesetzt: Coach legt Aufgaben mit Fälligkeit an; Coachee aktualisiert den Status. Bearbeitung und Erinnerungen fehlen noch. |
| F6 | Erinnerungen | Automatische E-Mail-Erinnerungen zu Aufgaben | Geplant: benötigt SMTP-Konfiguration, zeitgesteuerten DSM-Aufruf und Versandprotokoll. |
| F7 | Private Notizen | Nur für Coach sichtbar, durchsuchbar | Umgesetzt: private Notizen sind coachgebunden, optional mit einem Termin verknüpft und durchsuchbar; Klient und Admin erhalten keinen Zugriff. |
| F8 | Dokumente und Vereinbarungen | Vorlagen griffbereit halten, Klienten zuweisen, ausfüllen/unterschreiben und unterschriebene Fassung beim Klienten speichern | Im Code umgesetzt: geschützter Rücklauf per Scan oder QR-Unterschrift; benötigt Migration 003, HTTPS und Synology-Funktionstest. |

## Dokumentenablauf (F8)

- Coaches verwalten eigene Vorlagen, zum Beispiel Coaching-Vertrag, Datenschutz- und Einwilligungsunterlagen sowie Formulare zum Ausfüllen.
- Eine Vorlage kann einem bestimmten Klienten zugewiesen werden. Der Vorgang hält Vorlage/Version, Klient, Status und Zeitpunkte fest.
- Die unterschriebene oder ausgefüllte Endfassung wird privat gespeichert und mit dem Klienten sowie der verwendeten Vorlagenversion verknüpft.
- Dateien liegen außerhalb des öffentlichen Webordners. Jede Ansicht, jeder Download und jede Änderung prüft die Rechte serverseitig.
- Zuweisungen und unterschriebene Rückläufe sind im Coachee-Portal für den jeweiligen Klienten abrufbar. Vorlagen und private Coach-Notizen bleiben nur dem Coach zugänglich.
- Phase 1: Coach verwaltet Vorlagen, weist eine bestimmte Version einem Klienten zu und lädt die unterschriebene PDF/Scan-Datei nach Rückgabe zum Klienten hoch.
- QR-Signaturen sind verfügbar: Coach erzeugt für eine einzelne PDF-Zuweisung einen 24 Stunden gültigen Einmal-Link und gibt einen sechsstelligen Prüfcode getrennt an den Klienten weiter.
- Der Link kann widerrufen werden, akzeptiert nur eine fertige Signatur und wird nach fünf falschen Prüfcodes gesperrt. Der Zufallstoken steht im URL-Fragment, wird nicht an den Serverzugriffslog übertragen und in der Datenbank nur als Hash gespeichert.
- Nach dem Prüfcode sieht der Klient die PDF, zeichnet seine Unterschrift am Handy und bestätigt den Vorgang. Die Anwendung fügt eine Unterschriftsseite in eine neue PDF ein; die unterschriebene Version wird privat beim Klienten abgelegt. Serverzeitpunkt sowie Hashes der Original- und signierten PDF werden protokolliert.
- Die QR-Signatur ist coachseitig über HTTPS verfügbar. Klientendaten und PDFs gehen nicht an den QR-Code-Dienst; die PDF-Bibliothek wird im Browser von einem versionierten CDN geladen. Prüfe, ob dieses Signaturverfahren für das jeweilige Dokument passend ist.
- Beide Wege (Scan-Upload und QR-Signatur) speichern Originalvorlage, Version, Rücklauf, Zeitpunkte und Status; Dateien bleiben außerhalb des öffentlichen Webordners und sind nur nach serverseitiger Rechteprüfung erreichbar.

## Zusätzlicher Umfang

- Termine, Sitzungsnotizen und Ziele hängen an einem Klienten und sind im Verlauf auffindbar.
- Bestehende Einträge sollen über eine Bearbeiten-Funktion änderbar sein.
- Ziele erhalten Beschreibung, Erfolgskriterium, Zieltermin und Status.
- Private Sitzungsnotizen bleiben ausschließlich für den zuständigen Coach sichtbar. Admin-Rechte gewähren keinen pauschalen Zugriff auf private Notizen.
- Nicht als umgesetzt markierte Anforderungen bleiben offen und werden schrittweise ergänzt.

## Datenbankänderung

Migrationen 001 bis 003 ergänzen Notizen/Ziele, Dokumente und QR-Signaturen; Migration 004 ergänzt Status, Migration 005 Portalzugänge, Aufgaben und Darstellungseinstellungen. Vor jeder Migration eine Datenbanksicherung erstellen und die Tabellenrechte des Anwendungsbenutzers prüfen.
