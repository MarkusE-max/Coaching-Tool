# Rechnungsprüfung

Stand der Prüfung: 10.11.2026. Dies ist eine statische Prüfung des GitHub-Codes. Es wurde keine Rechnung auf dem NAS mit echtem oder Testklient erstellt; die Ausgabe, Druckansicht und Datenbankrechte müssen dort noch geprüft werden.

## Im Code vorhanden

- Eine Sitzung kann höchstens eine Rechnung erhalten; die Datenbank hat einen eindeutigen Schlüssel für die Sitzung.
- Rechnungen können nur für als durchgeführt markierte Sitzungen erstellt werden.
- Coach-Einstellungen, Empfänger-/Absenderdaten, Stundensatz, Steuerbezeichnung und -satz sowie Beträge werden in der Rechnung gespeichert. Nach dem Ausstellen kann der Entwurf nicht mehr geändert werden.
- Bar und EC können als bezahlt ausgestellt werden und zeigen „Betrag dankend erhalten“. Diese Zahlungsarten daher nur auswählen, wenn der Betrag tatsächlich entgegengenommen wurde.
- Bei Überweisung wird das individuelle Zahlungsziel des Klienten verwendet, andernfalls das Zahlungsziel des Coaches. Offene und bezahlte Rechnungen werden im Auftragsüberblick getrennt summiert.
- Die Auftragsübersicht fasst bestehende Sitzungsrechnungen zusammen und erstellt keine zusätzliche Forderung.
- Rechnungslogo und Dokumentdatei werden über eine zugriffsgeschützte Coach-Sitzung ausgeliefert. Der Logo-Endpunkt wurde ergänzt, damit ältere Rechnungen ohne gespeicherten Logo-Snapshot auf das aktuelle Coach-Logo zurückgreifen können. Bei vorhandenem Snapshot bleibt dieser maßgeblich.

## Vor dem produktiven Einsatz noch offen

1. **NAS-Testfall:** Mit einem synthetischen Klienten je eine Überweisungs-, Bar- und EC-Rechnung erstellen. Summen, Steuer, Zahlungsziel, Status, Logo, Druck/„Als PDF speichern“ und Auftragssumme prüfen. Für Bar/EC sicherstellen, dass der Betrag bereits bezahlt wurde.
2. **PDF-Aufbewahrung:** Das System rendert die Rechnung beim Öffnen/Drucken aus den gespeicherten Rechnungsdaten. Es legt noch keine unveränderliche PDF-Kopie beim Ausstellen ab. Ein späterer Programm- oder Layoutwechsel kann die Druckansicht verändern.
3. **Strukturierte E-Rechnung:** Das System erzeugt eine druckbare PDF, aber keine strukturierte E-Rechnung wie XRechnung oder ZUGFeRD. Das BMF beschreibt die E-Rechnungspflicht für inländische B2B-Umsätze ab 2025 und Übergangsregeln bis Ende 2027 für bestimmte Aussteller. Ob das für einen konkreten Coach und Rechnungsempfänger gilt, hängt vom Einzelfall ab. Siehe [BMF-FAQ zur E-Rechnung](https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html) und [§ 14 UStG](https://www.gesetze-im-internet.de/ustg_1980/__14.html).
4. **Coach-Wechsel:** Rechnungen sind dem Coach zugeordnet, der sie erstellt hat. Aktuelle Ansichten prüfen zusätzlich die heutige Klientenzuordnung. Nach einem Coach-Wechsel kann der frühere Coach seine ausgestellte Rechnung deshalb möglicherweise nicht mehr öffnen; das ist vor einer späteren Änderung der Rollen- und Archivregeln ausdrücklich zu entscheiden.

Die Anwendung ersetzt keine steuerliche Prüfung. Steuersätze, Steuerhinweise und zulässige Rechnungsform müssen pro Coach und Anwendungsfall geprüft werden.
