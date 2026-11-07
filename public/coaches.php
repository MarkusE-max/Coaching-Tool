<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/app/bootstrap.php';

function coachH(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$currentUser = $_SESSION['user'] ?? null;
if (!is_array($currentUser) || ($currentUser['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Kein Zugriff. Diese Seite ist nur für Administratoren.');
}
$database = coachingDatabase();
$token = $_SESSION['csrf_token'] ?? '';
if (!is_string($token) || strlen($token) !== 64) {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
}
$error = '';
$message = isset($_GET['created']) && $_GET['created'] === '1'
    ? 'Das aktive Coach-Konto wurde angelegt.'
    : (isset($_GET['saved']) && $_GET['saved'] === '1' ? 'Die Änderungen wurden gespeichert.' : '');
$values = ['id' => 0, 'vorname' => '', 'nachname' => '', 'username' => '', 'email' => '', 'aktiv' => 1];
$editing = false;

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId === false || (int) $editId < 1) {
        $error = 'Ungültige Coach-ID.';
    } else {
        $query = $database->prepare("SELECT id, vorname, nachname, benutzername, email, aktiv FROM benutzer WHERE id = :id AND rolle = 'coach' LIMIT 1");
        $query->execute(['id' => (int) $editId]);
        $record = $query->fetch(PDO::FETCH_ASSOC);
        if ($record) {
            $values = ['id' => (int) $record['id'], 'vorname' => $record['vorname'], 'nachname' => $record['nachname'], 'username' => $record['benutzername'], 'email' => $record['email'], 'aktiv' => (int) $record['aktiv']];
            $editing = true;
        } else {
            $error = 'Dieses Coach-Konto wurde nicht gefunden.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $rawId = $_POST['id'] ?? '0';
    $id = filter_var($rawId, FILTER_VALIDATE_INT);
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $firstName = trim(is_string($_POST['vorname'] ?? null) ? $_POST['vorname'] : '');
    $lastName = trim(is_string($_POST['nachname'] ?? null) ? $_POST['nachname'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $values = ['id' => $id === false ? 0 : (int) $id, 'vorname' => $firstName, 'nachname' => $lastName, 'username' => $username, 'email' => $email, 'aktiv' => 1];
    $editing = $id !== false && (int) $id > 0;
    $passwordValid = strlen($password) >= 8 && strlen($password) <= 4096
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;

    if (!is_string($postedToken) || !hash_equals($token, $postedToken)) {
        $error = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
    } elseif ($id === false || (int) $id < 0
        || $username === '' || strlen($username) > 50
        || $email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $firstName === '' || strlen($firstName) > 400
        || $lastName === '' || strlen($lastName) > 400
        || (!$editing && !$passwordValid)
        || ($editing && $password !== '' && !$passwordValid)) {
        $error = 'Bitte prüfe deine Angaben. Ein neues Passwort braucht mindestens 8 Zeichen und ein Sonderzeichen.';
    } else {
        try {
            if ($editing) {
                $exists = $database->prepare("SELECT id FROM benutzer WHERE id = :id AND rolle = 'coach' LIMIT 1");
                $exists->execute(['id' => (int) $id]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('Dieses Coach-Konto wurde nicht gefunden.');
                }
                $set = ['benutzername = :username', 'email = :email', 'vorname = :first_name', 'nachname = :last_name'];
                $params = ['username' => $username, 'email' => $email, 'first_name' => $firstName, 'last_name' => $lastName, 'id' => (int) $id];
                if ($password !== '') {
                    $set[] = 'passwort_hash = :password_hash';
                    $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $update = $database->prepare('UPDATE benutzer SET ' . implode(', ', $set) . ' WHERE id = :id');
                $update->execute($params);
                header('Location: coaches.php?saved=1');
                exit;
            }

            $insert = $database->prepare(
                "INSERT INTO benutzer (benutzername, email, passwort_hash, vorname, nachname, rolle, aktiv)
                 VALUES (:username, :email, :password_hash, :first_name, :last_name, 'coach', 1)"
            );
            $insert->execute([
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
            header('Location: coaches.php?created=1');
            exit;
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Coaching Tool coach save failed: ' . $exception->getMessage());
            $error = 'Das Konto konnte nicht gespeichert werden. Prüfe, ob Benutzername oder E-Mail bereits verwendet werden und ob der Datenbankbenutzer INSERT-/UPDATE-Rechte auf benutzer hat.';
        }
    }
}

$list = $database->query("SELECT id, vorname, nachname, benutzername, email, aktiv FROM benutzer WHERE rolle = 'coach' ORDER BY aktiv DESC, nachname, vorname");
$coaches = $list->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Coach-Konten verwalten · Coaching Tool</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#1f302d;background:#f5f7f5}*{box-sizing:border-box}body{margin:0}.page{max-width:900px;margin:auto;padding:24px clamp(18px,5vw,64px) 70px}.bar{display:flex;justify-content:space-between;padding:14px 0 22px;border-bottom:1px solid #e2e9e4}.brand{color:inherit;text-decoration:none;font-weight:700}.brand span{font-weight:400;color:#60716b}a{color:#35695b}h1{font-size:clamp(30px,5vw,48px);letter-spacing:-.04em;margin:38px 0 8px}p{color:#65766f;line-height:1.55}.layout{display:grid;grid-template-columns:minmax(280px,390px) 1fr;gap:20px;margin-top:26px}.card{background:white;border:1px solid #e5ebe6;border-radius:14px;padding:20px;box-shadow:0 8px 30px #263b3210}.card h2{font-size:18px;margin:0 0 18px}.form{display:grid;gap:10px}.form label{font-size:13px;font-weight:650;margin-top:6px}.form input{width:100%;border:1px solid #dce5df;border-radius:9px;padding:12px;font:inherit}.form input:focus{outline:3px solid #a4c9b4}.button{border:0;border-radius:9px;padding:12px 16px;background:#245c50;color:white;font:inherit;font-weight:650;cursor:pointer;margin-top:12px}.notice,.error{padding:12px 14px;border-radius:9px}.notice{background:#edf5ef;color:#245c50}.error{background:#fff3f1;color:#8c3434}ul{list-style:none;padding:0;margin:0}li{padding:12px 0;border-bottom:1px solid #edf1ee}li:last-child{border-bottom:0}.muted{display:block;color:#74817c;font-size:12px;margin-top:4px}.coach-row{display:flex;justify-content:space-between;align-items:center;gap:12px}.back{display:inline-block;margin-top:24px}@media(max-width:700px){.layout{grid-template-columns:1fr}}
</style></head>
<body><main class="page">
<header class="bar"><a class="brand" href="./">Coaching<span>Tool</span></a><a href="clients.php">Zur Klientenverwaltung</a></header>
<h1>Coach-Konten verwalten</h1><p>Lege hier ein aktives Coach-Konto an. Es kann sich danach mit den Zugangsdaten selbst anmelden; das Passwort wird geschützt gespeichert.</p>
<?php if ($message !== ''): ?><p class="notice" role="status"><?= coachH($message) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= coachH($error) ?></p><?php endif; ?>
<div class="layout"><section class="card"><h2><?= $editing ? 'Coach bearbeiten' : 'Coach hinzufügen' ?></h2>
<form method="post" class="form" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= coachH($token) ?>"><input type="hidden" name="id" value="<?= coachH((int) $values['id']) ?>">
<label for="vorname">Vorname</label><input id="vorname" name="vorname" maxlength="100" required value="<?= coachH($values['vorname']) ?>">
<label for="nachname">Nachname</label><input id="nachname" name="nachname" maxlength="100" required value="<?= coachH($values['nachname']) ?>">
<label for="username">Benutzername</label><input id="username" name="username" maxlength="50" autocomplete="username" required value="<?= coachH($values['username']) ?>">
<label for="email">E-Mail</label><input id="email" name="email" type="email" maxlength="190" required value="<?= coachH($values['email']) ?>">
<label for="password"><?= $editing ? 'Neues Passwort (optional)' : 'Startpasswort (mindestens 8 Zeichen und ein Sonderzeichen)' ?></label><input id="password" name="password" type="password" minlength="8" pattern="(?=.*[^A-Za-z0-9]).{8,}" title="Mindestens 8 Zeichen und mindestens ein Sonderzeichen." autocomplete="new-password" <?= $editing ? '' : 'required' ?>><?php if ($editing): ?><span class="muted">Leer lassen, wenn das Passwort gleich bleiben soll.</span><?php endif; ?>
<button class="button" type="submit"><?= $editing ? 'Änderungen speichern' : 'Aktives Coach-Konto anlegen' ?></button><?php if ($editing): ?><a class="back" href="coaches.php">Abbrechen</a><?php endif; ?>
</form></section>
<section class="card"><h2>Coach-Konten (<?= count($coaches) ?>)</h2><?php if ($coaches === []): ?><p>Noch keine Coach-Konten vorhanden.</p><?php else: ?><ul><?php foreach ($coaches as $coach): ?><li class="coach-row"><div><strong><?= coachH($coach['vorname'] . ' ' . $coach['nachname']) ?></strong><span class="muted"><?= coachH($coach['benutzername']) ?> · <?= coachH($coach['email']) ?> · <?= (int) $coach['aktiv'] === 1 ? 'Aktiv' : 'Inaktiv' ?></span></div><a href="coaches.php?edit=<?= coachH($coach['id']) ?>">Bearbeiten</a></li><?php endforeach; ?></ul><?php endif; ?></section></div>
<a class="back" href="clients.php">Zurück zu Klienten und Coach-Zuordnung</a>
</main></body></html>
