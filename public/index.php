<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/schema.php';

$databaseConnected = false;
$schemaReady = false;
try {
    $database = coachingDatabase();
    $databaseConnected = true;
    $schemaReady = coachingSchemaStatus($database)['ready'];
    if (!$schemaReady) {
        http_response_code(503);
    }
} catch (Throwable $error) {
    http_response_code(503);
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7f5">
    <title>Coaching Tool</title>
    <link rel="stylesheet" href="assets/app.css">
    <script src="assets/app.js" defer></script>
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <a class="brand" href="./" aria-label="Coaching Tool Startseite">
                <span class="brand-mark" aria-hidden="true">C</span>
                <span>Coaching<span class="brand-light">Tool</span></span>
            </a>
            <span class="environment">Entwicklungsstand</span>
        </header>

        <section class="welcome">
            <p class="eyebrow">COACHING VERWALTEN</p>
            <h1>Ein klarer Rahmen für gute Entwicklung.</h1>
            <p class="intro">
                Die Basis für Klienten, Coaching-Aufträge und Sitzungen.
                Coach-Verwaltung und Coachee-Portal wachsen Schritt für Schritt.
            </p>

            <section class="status-card" aria-labelledby="db-title">
                <span class="status-dot <?= $schemaReady ? 'is-connected' : 'is-disconnected' ?>" id="status-dot"></span>
                <div>
                    <strong id="db-title">MariaDB</strong>
                    <small id="db-status" role="status" aria-live="polite">
                        <?php if (!$databaseConnected): ?>
                            Keine Verbindung
                        <?php elseif ($schemaReady): ?>
                            Verbunden · Coaching-Tabellen erkannt
                        <?php else: ?>
                            Verbunden · Tabellenstruktur unvollständig
                        <?php endif; ?>
                    </small>
                </div>
                <button class="refresh-button" id="check-database" type="button">Verbindung prüfen</button>
            </section>
        </section>

        <footer>Privater Entwicklungsstand · Noch keine Klientendaten hinterlegt</footer>
    </main>
</body>
</html>
