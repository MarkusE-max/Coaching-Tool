# Klientendaten exportieren und aufbewahren

## Datenexport

In der Klientenakte gibt es für den zuständigen Coach den Link **Datenexport**. Er lädt eine ZIP-Datei mit den Klientenstammdaten, direkt zugeordneten Coaching-Daten, Rechnungsdaten, privaten Coach-Sitzungsnotizen und zugehörigen Dateien herunter. Der Export enthält keine Zugangspasswort-Hashes, Reset-Tokens, Login-IP-Protokolle oder coachweiten Vorlagen/Einstellungen. Admins und andere Coaches können den Export nicht abrufen.

Die Anwendung verwendet die PHP-Erweiterung `zip`. Aktiviere sie im zugewiesenen PHP-8.2-Profil. Der Export ist für den persönlichen Download gedacht: Lege die ZIP-Datei nur in einem geschützten, verschlüsselten Ablageort ab und entferne lokale Kopien, sobald sie nicht mehr benötigt werden.

## Aufbewahrung und Löschung

Die Anwendung löscht keine Klientendaten automatisch. Die zulässige Aufbewahrungsdauer hängt unter anderem von Vertrags-, Abrechnungs- und Nachweispflichten ab. Lege sie mit deiner steuerlichen beziehungsweise rechtlichen Beratung fest, bevor du Löschfristen technisch automatisierst.

Empfohlener Ablauf für eine Löschanfrage:

1. Prüfe die Identität der anfragenden Person und ob offene Aufträge, Streitfälle oder Aufbewahrungspflichten bestehen.
2. Sperre bei Bedarf den Coachee-Zugang, ohne Rechnungen oder Coaching-Verläufe voreilig zu löschen.
3. Erstelle auf Wunsch zuerst den Datenexport für den zuständigen Coach und übergib Daten nur nach deiner Prüfung an die berechtigte Person.
4. Schließe den Auftrag und nutze das vorhandene Archiv, solange Aufbewahrung erforderlich ist. Eine Archivfreigabe für Coachees bleibt eine ausdrückliche Coach-Entscheidung.
5. Wenn die geprüfte Frist abgelaufen ist, lösche die zugehörigen Klientendaten und privaten Dateien vollständig. Prüfe dabei Stammdaten, Termine, Notizen, Ziele, Aufgaben, Nachrichten samt Anhängen, Dokumente, Portalzugang, Aktivitätsprotokolle und gegebenenfalls nicht mehr aufzubewahrende Rechnungsdaten.
6. Beachte, dass Sicherungskopien gelöschte Daten noch bis zum Ablauf ihrer Aufbewahrungszeit enthalten. Dokumentiere die Löschanfrage und den Abschluss außerhalb der Coaching-Inhalte.

In der Anwendung gibt es derzeit keinen automatischen Löschlauf für vollständige Klientenakten. Das verhindert eine ungewollte Löschung von Rechnungen und Nachweisen, bevor die jeweils geltende Aufbewahrungsfrist geprüft wurde. Eine spätere Löschverwaltung sollte erst mit festgelegten Fristen, einer Vorschau der betroffenen Daten und einem protokollierten Bestätigungsschritt ergänzt werden.
