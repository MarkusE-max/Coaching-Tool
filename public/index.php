<?php
declare(strict_types=1);

header('Cache-Control: no-store, private');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/schema.php';

function h(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

$database = null;
$databaseReady = false;
$databaseMessage = '';
$noUsers = false;
try {
    $database = coachingDatabase();
    $databaseReady = coachingSchemaStatus($database)['ready'];
    if (!$databaseReady) {
        $databaseMessage = 'Die erwartete Tabellenstruktur ist unvollständig.';
        http_response_code(503);
    } else {
        $noUsers = (int) $database->query('SELECT COUNT(*) FROM benutzer')->fetchColumn() === 0;
    }
} catch (Throwable $error) {
    $databaseMessage = 'Die Datenbank ist momentan nicht erreichbar.';
    http_response_code(503);
}

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseReady && $database instanceof PDO) {
    $postedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!is_string($postedToken) || !hash_equals(csrfToken(), $postedToken)) {
        $errorMessage = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
    } elseif ($action === 'logout') {
        unset($_SESSION['user']);
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        header('Location: ./');
        exit;
    } elseif ($action === 'login') {
        $postedUsername = $_POST['username'] ?? '';
        $postedPassword = $_POST['password'] ?? '';
        $username = is_string($postedUsername) ? trim($postedUsername) : '';
        $password = is_string($postedPassword) ? $postedPassword : '';
        $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

        try {
            $rateLimit = $database->prepare(
                'SELECT COUNT(*) FROM login_versuche
                 WHERE ip_adresse = :ip AND erfolgreich = 0
                   AND zeitpunkt >= (NOW() - INTERVAL 15 MINUTE)'
            );
            $rateLimit->execute(['ip' => $ipAddress]);
            $tooManyAttempts = (int) $rateLimit->fetchColumn() >= 5;

            if ($tooManyAttempts) {
                $errorMessage = 'Zu viele Versuche. Bitte warte 15 Minuten und versuche es erneut.';
            } elseif ($username === '' || strlen($username) > 50 || $password === '' || strlen($password) > 4096) {
                $errorMessage = 'Benutzername oder Passwort ist nicht korrekt.';
            } else {
                $query = $database->prepare(
                    'SELECT id, benutzername, passwort_hash, vorname, nachname, rolle, aktiv
                     FROM benutzer WHERE benutzername = :username LIMIT 1'
                );
                $query->execute(['username' => $username]);
                $user = $query->fetch();

                $validPassword = is_array($user)
                    && is_string($user['passwort_hash'] ?? null)
                    && password_verify($password, $user['passwort_hash']);

                $logAttempt = $database->prepare(
                    'INSERT INTO login_versuche (benutzername, ip_adresse, erfolgreich)
                     VALUES (:username, :ip, :success)'
                );
                $logAttempt->execute([
                    'username' => $username === '' ? null : $username,
                    'ip' => $ipAddress,
                    'success' => $validPassword && (int) $user['aktiv'] === 1 ? 1 : 0,
                ]);

                if (!$validPassword || (int) $user['aktiv'] !== 1) {
                    $errorMessage = 'Benutzername oder Passwort ist nicht korrekt.';
                } else {
                    $updateLogin = $database->prepare('UPDATE benutzer SET letzter_login = NOW() WHERE id = :id');
                    $updateLogin->execute(['id' => (int) $user['id']]);

                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'id' => (int) $user['id'],
                        'name' => trim($user['vorname'] . ' ' . $user['nachname']),
                        'role' => $user['rolle'],
                    ];
                    unset($_SESSION['csrf_token']);
                    header('Location: ./');
                    exit;
                }
            }
        } catch (Throwable $error) {
            $errorMessage = 'Die Anmeldung konnte gerade nicht verarbeitet werden.';
            http_response_code(503);
        }
    }
}

$currentUser = $_SESSION['user'] ?? null;
if (!is_array($currentUser) || !isset($currentUser['id'], $currentUser['role'], $currentUser['name'])) {
    $currentUser = null;
}

$clients = [];
$appointments = [];
$dashboardError = '';
if ($currentUser !== null && $databaseReady && $database instanceof PDO) {
    try {
        if ($currentUser['role'] === 'admin') {
            $clientQuery = $database->query(
                'SELECT id, vorname, nachname, status, startdatum
                 FROM klienten ORDER BY nachname, vorname LIMIT 100'
            );
            $appointmentQuery = $database->query(
                "SELECT t.beginn, t.dauer_minuten, t.format, k.vorname, k.nachname
                 FROM termine t
                 INNER JOIN klienten k ON k.id = t.klient_id
                 WHERE t.status = 'geplant' AND t.beginn >= NOW()
                 ORDER BY t.beginn LIMIT 10"
            );
        } else {
            $clientQuery = $database->prepare(
                'SELECT id, vorname, nachname, status, startdatum
                 FROM klienten WHERE benutzer_id = :user_id
                 ORDER BY nachname, vorname LIMIT 100'
            );
            $clientQuery->execute(['user_id' => (int) $currentUser['id']]);
            $appointmentQuery = $database->prepare(
                "SELECT t.beginn, t.dauer_minuten, t.format, k.vorname, k.nachname
                 FROM termine t
                 INNER JOIN klienten k ON k.id = t.klient_id
                 WHERE k.benutzer_id = :user_id
                   AND t.status = 'geplant' AND t.beginn >= NOW()
                 ORDER BY t.beginn LIMIT 10"
            );
            $appointmentQuery->execute(['user_id' => (int) $currentUser['id']]);
        }
        $clients = $clientQuery->fetchAll();
        $appointments = $appointmentQuery->fetchAll();
    } catch (Throwable $error) {
        $dashboardError = 'Die Übersicht konnte nicht geladen werden.';
    }
}

$token = csrfToken();
$formatLabels = ['vor_ort' => 'Vor Ort', 'telefon' => 'Telefon', 'video' => 'Video'];
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

        <?php if (!$databaseReady): ?>
            <section class="welcome">
                <p class="eyebrow">COACHING VERWALTEN</p>
                <h1>Datenbank nicht bereit</h1>
                <p class="intro"><?= h($databaseMessage) ?> Prüfe <a href="health.php">/health.php</a>.</p>
            </section>
        <?php elseif ($currentUser === null): ?>
            <section class="login-panel">
                <p class="eyebrow">COACH-BEREICH</p>
                <h1>Anmelden</h1>
                <p class="intro">Melde dich mit einem aktiven Konto aus der Benutzertabelle an.</p>
                <?php if ($errorMessage !== ''): ?>
                    <p class="form-error" role="alert"><?= h($errorMessage) ?></p>
                <?php endif; ?>
                <form method="post" class="login-form" autocomplete="on">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
                    <label for="username">Benutzername</label>
                    <input id="username" name="username" type="text" maxlength="50" autocomplete="username" required>
                    <label for="password">Passwort</label>
                    <div class="password-field">
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                        <button type="button" class="text-button" id="toggle-password" aria-pressed="false">Anzeigen</button>
                    </div>
                    <button class="primary-button" type="submit">Anmelden</button>
                </form>
                <p class="login-note">Nach fünf falschen Versuchen wird die Anmeldung für die IP-Adresse 15 Minuten pausiert.</p>
                <?php if ($noUsers): ?>
                    <p class="login-note"><a href="setup-admin.php">Ersten Administrator sicher einrichten</a></p>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="dashboard">
                <div class="dashboard-heading">
                    <div>
                        <p class="eyebrow">COACH-ÜBERSICHT</p>
                        <h1>Guten Tag, <?= h($currentUser['name']) ?>.</h1>
                        <p class="intro">Hier siehst du Klienten und anstehende Termine aus deinem Zugang.</p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
                        <button class="secondary-button" type="submit">Abmelden</button>
                    </form>
                </div>

                <?php if ($dashboardError !== ''): ?>
                    <p class="form-error" role="alert"><?= h($dashboardError) ?></p>
                <?php endif; ?>

                <p class="client-tools-link"><a href="clients.php">Klienten anlegen und bearbeiten →</a> · <a href="appointments.php">Terminverwaltung</a> · <a href="goals.php">Ziele</a><?php if ($currentUser['role'] === 'coach'): ?> · <a href="notes.php">Private Notizen</a><?php endif; ?><?php if ($currentUser['role'] === 'admin'): ?> · <a href="coaches.php">Coach-Konten verwalten →</a><?php endif; ?></p>
                <div class="dashboard-grid">
                    <section class="data-card">
                        <div class="card-heading"><h2>Meine Klienten</h2><span><?= count($clients) ?></span></div>
                        <?php if ($clients === []): ?>
                            <p class="empty-state">Für diesen Zugang sind noch keine Klienten hinterlegt.</p>
                        <?php else: ?>
                            <ul class="client-list">
                                <?php foreach ($clients as $client): ?>
                                    <li>
                                        <span class="person-mark" aria-hidden="true"><?= h(substr((string) $client['vorname'], 0, 1)) ?></span>
                                        <span class="client-name"><?= h($client['vorname'] . ' ' . $client['nachname']) ?></span>
                                        <span class="status-label"><?= h($client['status']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <section class="data-card">
                        <div class="card-heading"><h2>Anstehende Termine</h2><span><?= count($appointments) ?></span></div>
                        <?php if ($appointments === []): ?>
                            <p class="empty-state">Es sind keine kommenden Termine geplant.</p>
                        <?php else: ?>
                            <ul class="appointment-list">
                                <?php foreach ($appointments as $appointment): ?>
                                    <li>
                                        <time><?= h(date('d.m.Y · H:i', strtotime($appointment['beginn']))) ?></time>
                                        <strong><?= h($appointment['vorname'] . ' ' . $appointment['nachname']) ?></strong>
                                        <small><?= h((string) $appointment['dauer_minuten']) ?> Min. · <?= h($formatLabels[$appointment['format']] ?? $appointment['format']) ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>
                </div>
                <p class="privacy-note">Notizen und Coaching-Inhalte werden in dieser Übersicht nicht angezeigt.</p>
            </section>
        <?php endif; ?>

        <footer>Privater Entwicklungsstand · Zugang nur für berechtigte Personen</footer>
    </main>
</body>
</html>
