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

function setupH(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isLocalSetupRequest(): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = array_map('intval', explode('.', $ip));
        return $parts[0] === 10
            || $parts[0] === 127
            || ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31)
            || ($parts[0] === 192 && $parts[1] === 168)
            || ($parts[0] === 169 && $parts[1] === 254);
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if ($packed === str_repeat("\0", 15) . "\x01") {
            return true;
        }
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        return ($first & 0xfe) === 0xfc || ($first === 0xfe && ($second & 0xc0) === 0x80);
    }

    return false;
}

function setupCsrfToken(): string
{
    if (!isset($_SESSION['setup_csrf'])) {
        $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['setup_csrf'];
}

$message = '';
$setupReady = false;
$setupClosed = false;
$database = null;
$setupKey = '';

if (!$isHttps) {
    http_response_code(403);
    $message = 'Die Ersteinrichtung ist nur über eine HTTPS-Adresse erlaubt.';
} elseif (!isLocalSetupRequest()) {
    http_response_code(404);
    $message = 'Diese Einrichtungsseite ist nur im lokalen Netzwerk verfügbar.';
} else {
    try {
        $database = coachingDatabase();
        if (!coachingSchemaStatus($database)['ready']) {
            throw new RuntimeException('Expected tables are missing.');
        }
        $userCount = (int) $database->query('SELECT COUNT(*) FROM benutzer')->fetchColumn();
        $setupClosed = $userCount > 0;

        $config = coachingConfig();
        $candidate = $config['initial_admin_setup_key'] ?? '';
        if (is_string($candidate) && strlen($candidate) >= 32 && strlen($candidate) <= 256 && !str_contains($candidate, 'REPLACE_')) {
            $setupKey = $candidate;
        }

        $setupReady = !$setupClosed && $setupKey !== '';
        if ($setupClosed) {
            http_response_code(410);
            $message = 'Der erste Administrator wurde bereits angelegt. Die Ersteinrichtung ist gesperrt.';
        } elseif ($setupKey === '') {
            http_response_code(503);
            $message = 'Der private Einrichtungsschlüssel fehlt oder ist noch der Platzhalter.';
        }
    } catch (Throwable $error) {
        http_response_code(503);
        $message = 'Datenbank oder erwartete Tabellen sind nicht verfügbar.';
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    $setupReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setupReady && $database instanceof PDO) {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    $postedSetupKey = $_POST['setup_key'] ?? '';
    $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    $rateLimit = $database->prepare(
        'SELECT COUNT(*) FROM login_versuche
         WHERE ip_adresse = :ip AND erfolgreich = 0
           AND zeitpunkt >= (NOW() - INTERVAL 15 MINUTE)'
    );
    $rateLimit->execute(['ip' => $ipAddress]);
    $tooManyAttempts = (int) $rateLimit->fetchColumn() >= 5;

    if ($tooManyAttempts) {
        $message = 'Zu viele Versuche. Bitte warte 15 Minuten und versuche es erneut.';
    } elseif (!is_string($postedCsrf) || !hash_equals(setupCsrfToken(), $postedCsrf)) {
        $message = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
    } elseif (!is_string($postedSetupKey) || !hash_equals($setupKey, $postedSetupKey)) {
        try {
            $log = $database->prepare(
                'INSERT INTO login_versuche (benutzername, ip_adresse, erfolgreich)
                 VALUES (:name, :ip, 0)'
            );
            $log->execute(['name' => 'first-admin-setup', 'ip' => $ipAddress]);
        } catch (Throwable $error) {
            // Keep setup-key failures generic to the browser.
        }
        $message = 'Der Einrichtungsschlüssel ist nicht korrekt.';
    } else {
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $firstName = $_POST['vorname'] ?? '';
        $lastName = $_POST['nachname'] ?? '';
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        $username = is_string($username) ? trim($username) : '';
        $email = is_string($email) ? trim($email) : '';
        $firstName = is_string($firstName) ? trim($firstName) : '';
        $lastName = is_string($lastName) ? trim($lastName) : '';
        $password = is_string($password) ? $password : '';
        $passwordConfirm = is_string($passwordConfirm) ? $passwordConfirm : '';

        if ($username === '' || strlen($username) > 50
            || $email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $firstName === '' || strlen($firstName) > 100
            || $lastName === '' || strlen($lastName) > 100
            || strlen($password) < 8 || strlen($password) > 4096
            || preg_match('/[^A-Za-z0-9]/', $password) !== 1
            || !hash_equals($password, $passwordConfirm)) {
            $message = 'Bitte prüfe die Angaben. Das Passwort muss mindestens 8 Zeichen lang sein, ein Sonderzeichen enthalten und beide Eingaben müssen übereinstimmen.';
        } else {
            try {
                $lock = (int) $database->query("SELECT GET_LOCK('coaching_tool_first_admin', 0)")->fetchColumn();
                if ($lock !== 1) {
                    throw new RuntimeException('Setup is already in progress.');
                }

                try {
                    $userCount = (int) $database->query('SELECT COUNT(*) FROM benutzer')->fetchColumn();
                    if ($userCount > 0) {
                        $setupClosed = true;
                        $setupReady = false;
                        $message = 'Ein Benutzer wurde inzwischen angelegt. Die Ersteinrichtung ist gesperrt.';
                    } else {
                        $insert = $database->prepare(
                            "INSERT INTO benutzer
                             (benutzername, email, passwort_hash, vorname, nachname, rolle, aktiv)
                             VALUES (:username, :email, :password_hash, :first_name, :last_name, 'admin', 1)"
                        );
                        $insert->execute([
                            'username' => $username,
                            'email' => $email,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                        ]);
                        session_regenerate_id(true);
                        unset($_SESSION['setup_csrf']);
                        header('Location: ./');
                        exit;
                    }
                } finally {
                    $database->query("SELECT RELEASE_LOCK('coaching_tool_first_admin')");
                }
            } catch (Throwable $error) {
                $message = 'Das Konto konnte nicht angelegt werden. Prüfe, ob der Datenbankbenutzer INSERT-Rechte auf benutzer hat und ob Benutzername/E-Mail bereits existieren.';
                http_response_code(503);
            }
        }
    }
}

$token = setupCsrfToken();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Ersten Administrator anlegen · Coaching Tool</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <a class="brand" href="./" aria-label="Coaching Tool Startseite">
                <span class="brand-mark" aria-hidden="true">C</span>
                <span>Coaching<span class="brand-light">Tool</span></span>
            </a>
            <span class="environment">Ersteinrichtung</span>
        </header>

        <section class="login-panel">
            <p class="eyebrow">NUR EINMAL MÖGLICH</p>
            <h1>Ersten Administrator anlegen</h1>
            <p class="intro">Diese Einrichtung funktioniert nur über HTTPS, aus dem lokalen Netzwerk und solange noch kein Benutzerkonto existiert.</p>

            <?php if ($message !== ''): ?>
                <p class="form-error" role="alert"><?= setupH($message) ?></p>
            <?php endif; ?>

            <?php if ($setupReady): ?>
                <form method="post" class="login-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= setupH($token) ?>">
                    <label for="setup_key">Privater Einrichtungsschlüssel</label>
                    <input id="setup_key" name="setup_key" type="password" autocomplete="off" required>
                    <label for="username">Benutzername</label>
                    <input id="username" name="username" type="text" maxlength="50" autocomplete="off" required>
                    <label for="email">E-Mail</label>
                    <input id="email" name="email" type="email" maxlength="190" autocomplete="email" required>
                    <label for="vorname">Vorname</label>
                    <input id="vorname" name="vorname" type="text" maxlength="100" autocomplete="given-name" required>
                    <label for="nachname">Nachname</label>
                    <input id="nachname" name="nachname" type="text" maxlength="100" autocomplete="family-name" required>
                    <label for="password">Passwort (mindestens 8 Zeichen und ein Sonderzeichen)</label>
                    <input id="password" name="password" type="password" minlength="8" pattern="(?=.*[^A-Za-z0-9]).{8,}" title="Mindestens 8 Zeichen und mindestens ein Sonderzeichen." autocomplete="new-password" required>
                    <label for="password_confirm">Passwort wiederholen</label>
                    <input id="password_confirm" name="password_confirm" type="password" minlength="8" pattern="(?=.*[^A-Za-z0-9]).{8,}" title="Mindestens 8 Zeichen und mindestens ein Sonderzeichen." autocomplete="new-password" required>
                    <button class="primary-button" type="submit">Administratorkonto anlegen</button>
                </form>
                <p class="login-note">Nach erfolgreicher Einrichtung ist diese Seite automatisch gesperrt. Entferne danach den Einrichtungsschlüssel aus config.local.php.</p>
            <?php endif; ?>
        </section>

        <footer>Einrichtung nur im lokalen Netzwerk · Keine Zugangsdaten weitergeben</footer>
    </main>
</body>
</html>
