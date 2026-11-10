# Datensicherung und Wiederherstellung

Das CLI-Skript `scripts/backup-coaching-data.php` sichert MariaDB und die privaten Coaching-Dateien gemeinsam. Es ist nicht per Browser erreichbar und benötigt PHP 8.2 CLI mit PDO MySQL und zlib.

## Einmalige Einrichtung in DSM

1. Wähle in File Station einen privaten Sicherungsordner außerhalb von `/web/Coaching-Tool/public`, zum Beispiel einen Ordner auf einem verschlüsselten gemeinsamen Ordner. Das Skript legt standardmäßig `/web/Coaching-Tool/storage/backups` an. Für einen anderen Ort ergänze in der privaten `config.local.php`:
   ```php
   'backup_storage_path' => '/volume1/Privat/Coaching-Tool-Backups',
   'backup_retention_days' => 30,
   ```
2. Der DSM-Aufgabenplaner muss als Benutzer laufen, der `config.local.php` lesen, die privaten Dokumente lesen und in den Sicherungsordner schreiben darf. Gib dem Ordner keine öffentliche Freigabe.
3. Öffne **Systemsteuerung → Aufgabenplaner**, erstelle eine geplante benutzerdefinierte Aufgabe und trage als Befehl den PHP-8.2-CLI-Interpreter mit dem Skriptpfad ein, zum Beispiel:
   ```text
   /usr/local/bin/php82 /web/Coaching-Tool/scripts/backup-coaching-data.php
   ```
   Der PHP-Pfad kann je nach DSM-Paket abweichen. Lass die Aufgabe zunächst einmal manuell laufen und prüfe den Ausführungsbericht.
4. Plane die Aufgabe täglich. Das Skript bewahrt standardmäßig 30 Tage auf und löscht nur seine eigenen, korrekt benannten Backup-Ordner, die älter sind als die Aufbewahrungsfrist.
5. Das Skript speichert `database.sql.gz`, eine Kopie der privaten Dateien und ein Manifest in einem Zeitstempel-Ordner. Die MariaDB-Sicherung enthält Tabellenstruktur und Daten. Die NAS-Sicherung ist nicht selbst verschlüsselt: Lege den Ordner auf einem verschlüsselten Synology-Ordner ab und aktiviere Verschlüsselung, wenn du eine zweite Kopie mit Hyper Backup auf ein anderes Gerät oder einen externen Speicher überträgst. Sichere auch diesen verschlüsselten Zielort getrennt.

## Wiederherstellung zuerst in einer Testdatenbank prüfen

Eine Wiederherstellung überschreibt Tabellen gleichen Namens. Prüfe sie zunächst in einer neu angelegten leeren MariaDB-Datenbank:

1. Halte den Webzugriff auf die Testkopie an und erstelle in DSM/phpMyAdmin eine leere Testdatenbank mit Zeichensatz `utf8mb4`.
2. Kopiere den gewünschten Zeitstempel-Ordner aus der Sicherung an einen privaten Arbeitsort. Öffne `manifest.json` und vergewissere dich, dass das Sicherungsdatum stimmt.
3. Wähle die Testdatenbank in phpMyAdmin aus und importiere `database.sql.gz`. Der Dump enthält `DROP TABLE IF EXISTS`; importiere ihn daher niemals in eine Datenbank, deren Daten du behalten musst.
4. Kopiere die Dateien aus dem Sicherungsordner `documents/` über File Station zurück in den privaten Dokumentenspeicher der Testkopie. Erhalte die Unterordnerstruktur und stelle sicher, dass der PHP-Prozess wieder Leserechte und für neue Uploads Schreibrechte hat.
5. Zeige die Testkopie mit einer separaten privaten Konfiguration auf die Testdatenbank und den wiederhergestellten Dokumentenspeicher. Prüfe `/health.php`, Login, Dokumentansicht und eine neu erstellte Testdatei.
6. Erst wenn die Testwiederherstellung funktioniert, plane einen Produktions-Restore. Sichere vorher den aktuellen Stand erneut. Importiere die Datenbank nur nach bewusster Auswahl der richtigen Datenbank und stelle anschließend den zugehörigen Dokumentenordner wieder her.

Das Skript sichert keine `config.local.php`, keine PHP-Anwendungsdateien und keine NAS-Systemeinstellungen. Bewahre die private Konfiguration sicher und getrennt auf; sie enthält Zugangsdaten und Schlüssel. Aktualisierte Anwendungsdateien bleiben unter Versionskontrolle auf GitHub.
