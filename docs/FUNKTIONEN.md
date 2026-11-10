# Funktionsumfang F1–F8

Diese Datei hält den vom Projektinhaber benannten Funktionsumfang und den aktuellen Umsetzungsstand fest. Eine Funktion gilt erst dann als umgesetzt, wenn Oberfläche, Datenbankrechte und Zugriffskontrolle vorhanden sind.

| Nr. | Bereich | Funktion | Stand |
| --- | --- | --- | --- |
| F1 | Nutzer und Rollen | Login, Rollen Coach / Klient / Admin / Verwalter, Passwort-Reset | Umgesetzt, sofern SMTP eingerichtet und eine gültige E-Mail am Konto hinterlegt ist: Coach, Admin, Verwalter und Coachee können einen einmaligen 60-Minuten-Rücksetzlink anfordern. SMTP-Konfiguration ist pro Coach/Admin/Verwalter getrennt; Coachee-Rücksetzlinks verwenden die Einstellungen des zugeordneten Coaches und die individuelle Antwortadresse. Coachee-E-Mail wird in der Zugangspflege erfasst. Manuelles Setzen eines Coachee-Passworts bleibt ebenfalls möglich. |
| F2 | Klientenverwaltung | Klientenstammdaten, Status (aktiv/pausiert/abgeschlossen) | Teilweise: Coaches sehen eigene Klienten; Admin kann Stammdaten ansehen, aber nicht zuordnen; Verwalter kann Stammdaten und Coach-Zuordnung verwalten. Coach-Wechsel werden protokolliert. |
| F3 | Termine | Termine pro Klient anlegen, bearbeiten, Verlauf | Umgesetzt: Anlage, Bearbeitung und Verlauf; Rechte werden anhand der Klientenzuordnung geprüft. Aus Dashboard und Klientenakte ist die jeweilige Klientenakte direkt erreichbar; Termine können dort mit vorausgewähltem Klienten neu angelegt werden. |
| F4 | Stream | Gemeinsamer Nachrichtenverlauf Coach ↔ Klient, Dateianhänge | Umgesetzt: Coach und Coachee schreiben und hängen PDF/JPG/PNG an. Neue Coachee-Nachrichten erscheinen als ungelesene Aktivität auf der Coach-Übersicht und werden beim Öffnen der Klientenakte als gelesen markiert. Neue Coach-Antworten werden im Coachee-Portal deutlich als ungelesen angezeigt und beim Portalaufruf als gelesen markiert (Migration 019). |
| F5 | Aufgaben | Übungen/Aktionen mit Fälligkeit und Status | Umgesetzt: Coach legt Aufgaben mit Fälligkeit an; Coachee ändert den Status. Statusänderungen durch Coachees erscheinen als ungelesene Aktivität auf der Coach-Übersicht. |
| F6 | Erinnerungen | Automatische E-Mail-Erinnerungen zu Aufgaben | Umgesetzt: Coachees schalten Erinnerungen selbst ein oder aus, wählen täglich/wöchentlich und Ruhezeiten. Standard ist ausgeschaltet. Ein stündlich geplanter DSM-Task versendet höchstens eine Mail je Coachee und gewähltem Rhythmus, ohne Aufgabentitel oder Coaching-Inhalte. Der Versand nutzt die SMTP-Einstellungen des zugeordneten Coaches. |
| F7 | Private Notizen | Nur für Coach sichtbar, durchsuchbar | Umgesetzt: private Notizen sind coachgebunden, optional mit Termin und einem für den Coach sichtbaren Wissensformat verknüpft, durchsuchbar und können Vorher-/Nachher-Werte von -10 bis +10 enthalten; Klient, Admin und Verwalter erhalten keinen Zugriff. |
| F8 | Dokumente und Vereinbarungen | Vorlagen griffbereit halten, Klienten zuweisen, ausfüllen/unterschreiben und unterschriebene Fassung beim Klienten speichern | Im Code umgesetzt: geschützter Rücklauf per Scan oder QR-Unterschrift; benötigt Migration 003, HTTPS und Synology-Funktionstest. |

## Dokumentenablauf (F8)

- Coaches verwalten eigene Vorlagen, zum Beispiel Coaching-Vertrag, Datenschutz- und Einwilligungsunterlagen sowie Formulare zum Ausfüllen.
- Coaches können unzugewiesene Vorlagen samt Datei endgültig löschen. Wurde eine Vorlage bereits einem Klienten zugewiesen, wird sie aus der aktiven Liste entfernt, während die vorhandenen Klientendokumente erhalten bleiben. Hochgeladene Rückläufe können einzeln gelöscht werden; der private Dateiinhalt wird entfernt und der Dokumentstatus angepasst.
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

## NLP-Formate-Wissenssammlung

- Coaches können eigene Formate mit Kategorie, Ziel/Einsatzbereich, Ablauf, Dauer, Materialien, Hinweisen und Quellen erfassen und durchsuchen.
- Jeder Eintrag ist privat, für ausgewählte aktive Coaches lesbar oder für alle aktiven Coaches freigegeben. Freigegebene Einträge sind für andere Coaches nur lesbar.
- Die Sammlung wird nicht mit vorgefertigten Praxisanleitungen befüllt; Inhalte werden durch die Coaches eingetragen und können Quellenangaben enthalten. Keine Klientenidentifikationsdaten in geteilte Einträge schreiben.

## Datenexport und Aufbewahrung\n\n- Der zuständige Coach kann aus der Klientenakte eine ZIP-Datei mit Stammdaten, direkt verbundenen Coaching-Daten, Rechnungsdaten, privaten Sitzungsnotizen und zugehörigen Dateien abrufen. Zugangspasswort-Hashes, Reset-Tokens, Login-IP-Protokolle sowie coachweite Vorlagen und Einstellungen werden ausgeschlossen. Dafür muss PHP 8.2 die Erweiterung `zip` geladen haben.\n- Vollständige Klientenakten werden nicht automatisch gelöscht. Vor einer Löschung ist die geltende Aufbewahrungspflicht für Rechnungen und Nachweise zu prüfen. Export, Archivierung, Sperrung des Coachee-Zugangs und dokumentierte Löschung sind in `docs/DATENEXPORT_UND_AUFBEWAHRUNG.md` beschrieben.\n\n## Zusätzlicher Umfang

- Termine, Sitzungsnotizen und Ziele hängen an einem Klienten und sind im Verlauf auffindbar. Coaches können aktive Dokumentvorlagen direkt aus der Klientenübersicht zuweisen; die Reihenfolge der Übersichtskarten wird pro Coach gespeichert (Migration 018).
- Bestehende Einträge sollen über eine Bearbeiten-Funktion änderbar sein.
- Ziele erhalten Beschreibung, Erfolgskriterium, Zieltermin und Status.
- Private Sitzungsnotizen bleiben ausschließlich für den zuständigen Coach sichtbar. Admin-Rechte gewähren keinen pauschalen Zugriff auf private Notizen.
- Nicht als umgesetzt markierte Anforderungen bleiben offen und werden schrittweise ergänzt.

## Datenbankänderung

Migrationen 001 bis 003 ergänzen Notizen/Ziele, Dokumente und QR-Signaturen; Migration 004 ergänzt Status, Migration 005 Portalzugänge, Aufgaben und Darstellungseinstellungen; Migration 017 ergänzt Erinnerungspräferenzen und Coach-Aktivitäten, Migration 018 das pro Coach gespeicherte Klientenübersichtslayout und Migration 019 den Gelesenstatus für Coach-Nachrichten im Coachee-Portal. Migration 007 ergänzt Verwalterrolle, Notiz-Format-Verknüpfung und Auditprotokoll für Coach-Wechsel; Migration 008 ergänzt Vorher-/Nachher-Werte für Sitzungsnotizen. Vor jeder Migration eine Datenbanksicherung erstellen und die Tabellenrechte des Anwendungsbenutzers prüfen.

## SMTP und Passwort-Zurücksetzung

- Administratoren können einen allgemeinen SMTP-Server in `/email-settings.php` eintragen, Verbindung und Zugangsdaten per Testmail prüfen und den Versand aktivieren.
- Unterstützt werden STARTTLS, SSL/TLS sowie unverschlüsselte Verbindung für ein geschütztes internes Mail-Relay. SMTP-Passwörter werden AES-256-GCM-verschlüsselt in der Datenbank abgelegt; der Schlüssel bleibt ausschließlich in `config.local.php`.
- `app_base_url` in der privaten Konfiguration muss die HTTPS-Adresse des Coaching Tools enthalten. Diese Adresse wird für Zurücksetzlinks verwendet, nicht der vom Browser übermittelte Hostnamen.
- Zurücksetzanforderungen verlangen Benutzername und hinterlegte E-Mail-Adresse. Antworten verraten nicht, ob ein Konto gefunden wurde. Tokens werden gehasht gespeichert, laufen nach 60 Minuten ab, sind einmal verwendbar und pro Konto begrenzt.
- Coach-/Verwalter-/Admin-E-Mails stammen aus `benutzer.email`; Coachees erhalten ihre Adresse über die Zugangspflege. Ohne aktive SMTP-Einstellung oder gültige E-Mail wird kein Link versendet.
- Die Anwendung verbindet sich per SMTP-Client aus PHP heraus. Für externen Versand müssen DNS, ausgehender Netzwerkzugriff und der SMTP-Port im Synology-Netz verfügbar sein.

- Migration 016 trennt SMTP-Zugangsdaten und Antwortadresse pro Benutzerkonto. Jeder Coach konfiguriert ausschließlich seinen eigenen Versand; ein Coachee erhält Reset-E-Mails über den aktuellen Coach.
- Migration 017 ergänzt abwählbare Aufgaben-Erinnerungen mit Rhythmus/Ruhezeit sowie eine ungelesene Aktivitätsliste für Coachee-Nachrichten und Aufgabenstatus-Änderungen.
