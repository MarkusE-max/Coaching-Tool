<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/app/bootstrap.php';

function adminAccountH(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$currentUser = $_SESSION['user'] ?? null;
if (!is_array($currentUser) || ($currentUser['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Kein Zugriff. Nur Administratoren können Admin-Konten anlegen.');
}

$database = coachingDatabase();
$token = $_SESSION['csrf_token'] ?? '';
if (!is_string($token) || strlen($token) !== 64) {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
}

$error = '';
$notice = isset($_GET['created']) && $_GET['created'] === '1' ? 'Das Admin-Konto wurde angelegt.' : '';
$values = ['vorname' => '', 'nachname' => '', 'username' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $firstName = trim(is_string($_POST['vorname'] ?? null) ? $_POST['vorname'] : '');
    $lastName = trim(is_string($_POST['nachname'] ?? null) ? $_POST['nachname'] : '');
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $values = ['vorname' => $firstName, 'nachname' => $lastName, 'username' => $username, 'email' => $email];
    $passwordValid = strlen($password) >= 8 && strlen($password) <= 4096
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;

    if (!is_string($postedToken) || !hash_equals($token, $postedToken)) {
        $error = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
    } elseif ($firstName === '' || strlen($firstName) > 100
        || $lastName === '' || strlen($lastName) > 100
        || $username === '' || strlen($username) > 50
        || $email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !$passwordValid) {
        $error = 'Bitte prüfe deine Angaben. Das Passwort braucht mindestens 8 Zeichen und ein Sonderzeichen.';
    } else {
        try {
            $insert = $database->prepare(
                "INSERT INTO benutzer (benutzername, email, passwort_hash, vorname, nachname, rolle, aktiv)
                 VALUES (:username, :email, :password_hash, :first_name, :last_name, 'admin', 1)"
            );
            $insert->execute([
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
            header('Location: admin-accounts.php?created=1');
            exit;
        } catch (Throwable $exception) {
            error_log('Coaching Tool admin account creation failed: ' . $exception->getMessage());
            $error = 'Das Admin-Konto konnte nicht angelegt werden. Prüfe, ob Benutzername oder E-Mail bereits vergeben sind und der Datenbankbenutzer INSERT-Rechte auf benutzer hat.';
        }
    }
}

$admins = $database->query(
    "SELECT id, vorname, nachname, benutzername, email, aktiv FROM benutzer WHERE rolle = 'admin' ORDER BY aktiv DESC, nachname, vorname"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin-Konten · Coaching Tool</title>
<link rel="stylesheet" href="assets/layout.css"><script src="assets/layout.js" defer></script>
<style>
.page{max-width:1050px;margin:auto;padding:34px clamp(24px,4vw,58px) 62px}.grid{display:grid;grid-template-columns:minmax(280px,390px) 1fr;gap:18px;margin-top:24px}.card{background:#fff;border:1px solid #e4ebe6;border-radius:14px;padding:20px}.form{display:grid;gap:10px}.form input{width:100%;padding:11px;border:1px solid #dce5df;border-radius:8px;font:inherit}.button{display:inline-block;border:0;border-radius:8px;padding:11px 15px;background:#245c50;color:#fff;font:inherit;cursor:pointer}.notice,.error{padding:12px;border-radius:8px}.notice{background:#edf5ef}.error{background:#fff3f1;color:#8c3434}.row{padding:12px 0;border-bottom:1px solid #edf1ee;display:flex;justify-content:space-between;align-items:center;gap:12px}.muted{color:#718078;font-size:13px}.status{border-radius:999px;background:#edf5ef;padding:4px 8px;font-size:12px}.status.off{background:#f3eeee;color:#765c5c}@media(max-width:800px){.grid{grid-template-columns:1fr}.row{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body class="has-app-sidebar">
<?php require_once dirname(__DIR__) . '/app/navigation.php'; renderCoachingSidebar($currentUser, 'admins', $token); ?>
<main class="page">
<h1>Admin-Konten</h1>
<p class="muted">Nur Administratoren können weitere Admin-Zugänge anlegen. Das Passwort wird geschützt gespeichert.</p>
<?php if ($notice !== ''): ?><p class="notice" role="status"><?= adminAccountH($notice) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= adminAccountH($error) ?></p><?php endif; ?>
<div class="grid">
<section class="card">
<h2>Admin-Konto anlegen</h2>
<form class="form" method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= adminAccountH($token) ?>">
<label>Vorname<input name="vorname" maxlength="100" required value="<?= adminAccountH($values['vorname']) ?>"></label>
<label>Nachname<input name="nachname" maxlength="100" required value="<?= adminAccountH($values['nachname']) ?>"></label>
<label>Benutzername<input name="username" maxlength="50" autocomplete="username" required value="<?= adminAccountH($values['username']) ?>"></label>
<label>E-Mail<input name="email" type="email" maxlength="190" autocomplete="email" required value="<?= adminAccountH($values['email']) ?>"></label>
<label>Passwort (mindestens 8 Zeichen und ein Sonderzeichen)<input name="password" type="password" minlength="8" pattern="(?=.*[^A-Za-z0-9]).{8,}" title="Mindestens 8 Zeichen und mindestens ein Sonderzeichen." autocomplete="new-password" required></label>
<button class="button" type="submit">Admin-Konto anlegen</button>
</form>
</section>
<section class="card">
<h2>Vorhandene Admin-Konten (<?= count($admins) ?>)</h2>
<?php if ($admins === []): ?><p class="muted">Noch keine Admin-Konten vorhanden.</p><?php else: ?>
<?php foreach ($admins as $admin): ?>
<div class="row"><div><strong><?= adminAccountH($admin['vorname'] . ' ' . $admin['nachname']) ?></strong><div class="muted"><?= adminAccountH($admin['benutzername']) ?> · <?= adminAccountH($admin['email']) ?></div></div><span class="status <?= (int) $admin['aktiv'] === 1 ? '' : 'off' ?>"><?= (int) $admin['aktiv'] === 1 ? 'Aktiv' : 'Inaktiv' ?></span></div>
<?php endforeach; endif; ?>
</section>
</div>
</main>
</body>
</html>
