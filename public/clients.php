<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');

require_once dirname(__DIR__) . '/app/bootstrap.php';

function h(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || !isset($user['id'], $user['role'], $user['name'])) {
    header('Location: ./');
    exit;
}
if (!in_array($user['role'], ['coach', 'admin', 'verwalter'], true)) {
    http_response_code(403);
    exit('Kein Zugriff auf die Klientenverwaltung.');
}
try {
    $database = coachingDatabase();
} catch (Throwable $exception) {
    error_log('Coaching Tool clients database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    ?><!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Klientenverwaltung nicht erreichbar</title><body style="font-family:system-ui;max-width:680px;margin:10vh auto;padding:24px;color:#1f302d"><h1>Klientenverwaltung nicht erreichbar</h1><p>Die Datenbankverbindung konnte nicht geöffnet werden. Bitte prüfe die Verbindung und versuche es erneut.</p><p><a href="./">Zur Übersicht</a></p></body></html><?php
    exit;
}
$schemaWarning = '';
try {
    $clientColumns = $database->query('SHOW COLUMNS FROM klienten')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $exception) {
    error_log('Coaching Tool client schema inspection failed: ' . $exception->getMessage());
    $clientColumns = [];
    $schemaWarning = 'Die Kliententabelle konnte nicht geprüft werden. Bitte kontrolliere Tabellenstruktur und Leserechte.';
}
$emailColumn = null;
foreach (['email', 'e_mail', 'mail', 'email_adresse'] as $candidate) {
    if (in_array($candidate, $clientColumns, true)) { $emailColumn = $candidate; break; }
}
$phoneColumn = null;
foreach (['telefon', 'telefonnummer', 'telefon_nr', 'phone', 'mobil'] as $candidate) {
    if (in_array($candidate, $clientColumns, true)) { $phoneColumn = $candidate; break; }
}
$emailSelect = $emailColumn !== null ? 'k.' . $emailColumn . ' AS email' : 'NULL AS email';
$phoneSelect = $phoneColumn !== null ? 'k.' . $phoneColumn . ' AS telefon' : 'NULL AS telefon';
$token = $_SESSION['csrf_token'] ?? '';
if (!is_string($token) || strlen($token) !== 64) {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
}
$isAdmin = $user['role'] === 'admin';
$isManager = $user['role'] === 'verwalter';
$canSeeAllClients = $isAdmin || $isManager;
$message = '';
$error = $schemaWarning;
$values = ['id' => 0, 'vorname' => '', 'nachname' => '', 'email' => '', 'telefon' => '', 'benutzer_id' => $isManager ? 0 : (int) $user['id']];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($postedToken) || !hash_equals($token, $postedToken)) {
            throw new InvalidArgumentException('Die Anfrage ist abgelaufen. Bitte lade die Seite neu.');
        }
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
        $id = $id === false ? 0 : (int) $id;
        $first = trim(is_string($_POST['vorname'] ?? null) ? $_POST['vorname'] : '');
        $last = trim(is_string($_POST['nachname'] ?? null) ? $_POST['nachname'] : '');
        $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $phone = trim(is_string($_POST['telefon'] ?? null) ? $_POST['telefon'] : '');
        if ($isAdmin && $id === 0) throw new InvalidArgumentException('Administratoren können keine Klienten anlegen oder Coaches zuordnen. Bitte nutze dafür ein Coach- oder Verwalterkonto.');
        $ownerInput = $isManager ? filter_var($_POST['benutzer_id'] ?? null, FILTER_VALIDATE_INT) : null;
        if ($first === '' || $last === '' || strlen($first) > 400 || strlen($last) > 400) {
            throw new InvalidArgumentException('Bitte Vor- und Nachnamen eintragen (jeweils höchstens 100 Zeichen).');
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
            throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse eintragen.');
        }
        if (strlen($phone) > 40) {
            throw new InvalidArgumentException('Die Telefonnummer darf höchstens 40 Zeichen lang sein.');
        }
        if ($isManager && ($ownerInput === false || (int) $ownerInput < 1)) {
            throw new InvalidArgumentException('Bitte einen zuständigen Coach auswählen.');
        }
        if ($isManager) {
            $checkOwner = $database->prepare("SELECT id FROM benutzer WHERE id = :id AND aktiv = 1 AND rolle = 'coach' LIMIT 1");
            $checkOwner->execute(['id' => (int) $ownerInput]);
            if (!$checkOwner->fetchColumn()) throw new InvalidArgumentException('Der ausgewählte Coach ist nicht aktiv.');
        }
        if ($id > 0) {
            $check = $database->prepare('SELECT id, benutzer_id FROM klienten WHERE id = :id' . ($user['role'] === 'coach' ? ' AND benutzer_id = :owner' : ''));
            $params = ['id' => $id];
            if ($user['role'] === 'coach') $params['owner'] = (int) $user['id'];
            $check->execute($params);
            $existingClient = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existingClient) throw new InvalidArgumentException('Dieser Klient wurde nicht gefunden oder gehört nicht zu deinem Zugang.');
            $oldOwner = (int) $existingClient['benutzer_id'];
            $owner = $isManager ? (int) $ownerInput : $oldOwner;
            $set = ['vorname = :first', 'nachname = :last'];
            $updateParams = ['first' => $first, 'last' => $last, 'id' => $id];
            if ($isManager) { $set[] = 'benutzer_id = :owner'; $updateParams['owner'] = $owner; }
            if ($emailColumn !== null) { $set[] = $emailColumn . ' = :email'; $updateParams['email'] = $email ?: null; }
            if ($phoneColumn !== null) { $set[] = $phoneColumn . ' = :phone'; $updateParams['phone'] = $phone ?: null; }
            if ($isManager) $database->beginTransaction();
            $update = $database->prepare('UPDATE klienten SET ' . implode(', ', $set) . ' WHERE id = :id');
            $update->execute($updateParams);
            if ($isManager && $oldOwner !== $owner) {
                $audit = $database->prepare('INSERT INTO coaching_klientenzuweisungen (klient_id, vorheriger_coach_id, neuer_coach_id, geaendert_von_benutzer_id) VALUES (:client, :old_coach, :new_coach, :manager)');
                $audit->execute(['client' => $id, 'old_coach' => $oldOwner, 'new_coach' => $owner, 'manager' => (int) $user['id']]);
            }
            if ($isManager) $database->commit();
            $message = $isManager && $oldOwner !== $owner ? 'Klientendaten und Coach-Zuordnung wurden gespeichert.' : 'Die Klientendaten wurden gespeichert.';
        } else {
            if (!$isManager && $user['role'] !== 'coach') throw new InvalidArgumentException('Nur Coaches und Verwalter können Klienten anlegen.');
            $owner = $isManager ? (int) $ownerInput : (int) $user['id'];
            $insertColumns = ['benutzer_id', 'vorname', 'nachname'];
            $insertValues = [':owner', ':first', ':last'];
            $insertParams = ['owner' => $owner, 'first' => $first, 'last' => $last];
            if ($emailColumn !== null) { $insertColumns[] = $emailColumn; $insertValues[] = ':email'; $insertParams['email'] = $email ?: null; }
            if ($phoneColumn !== null) { $insertColumns[] = $phoneColumn; $insertValues[] = ':phone'; $insertParams['phone'] = $phone ?: null; }
            $insert = $database->prepare('INSERT INTO klienten (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')');
            $insert->execute($insertParams);
            $message = 'Der Klient wurde angelegt.';
        }
        $values = ['id' => 0, 'vorname' => '', 'nachname' => '', 'email' => '', 'telefon' => '', 'benutzer_id' => (int) $user['id']];
    } elseif (isset($_GET['edit'])) {
        $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
        if ($id === false || (int) $id < 1) throw new InvalidArgumentException('Ungültige Klienten-ID.');
        $query = $database->prepare('SELECT id, vorname, nachname, ' . str_replace('k.', '', $emailSelect) . ', ' . str_replace('k.', '', $phoneSelect) . ', benutzer_id FROM klienten WHERE id = :id' . ($user['role'] === 'coach' ? ' AND benutzer_id = :owner' : ''));
        $params = ['id' => (int) $id];
        if ($user['role'] === 'coach') $params['owner'] = (int) $user['id'];
        $query->execute($params);
        $record = $query->fetch(PDO::FETCH_ASSOC);
        if (!$record) throw new InvalidArgumentException('Dieser Klient wurde nicht gefunden oder gehört nicht zu deinem Zugang.');
        $values = $record;
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    if ($database->inTransaction()) $database->rollBack();
    $error = 'Die Änderung konnte nicht gespeichert werden. Bitte prüfe die Datenbankrechte und die Verbindung.';
}

try {
    $ownerQuery = $isManager
        ? $database->query("SELECT id, vorname, nachname FROM benutzer WHERE aktiv = 1 AND rolle = 'coach' ORDER BY nachname, vorname")
        : null;
    $owners = $ownerQuery ? $ownerQuery->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($isManager && $owners === []) $error = 'Es gibt kein aktives Coach-Konto. Lege zuerst eines über Coach-Konten verwalten an.';
    if ($canSeeAllClients) {
        $listQuery = $database->query('SELECT k.id, k.vorname, k.nachname, ' . $emailSelect . ', ' . $phoneSelect . ', k.benutzer_id, b.vorname AS coach_vorname, b.nachname AS coach_nachname FROM klienten k LEFT JOIN benutzer b ON b.id = k.benutzer_id ORDER BY k.nachname, k.vorname LIMIT 200');
    } else {
        $listQuery = $database->prepare('SELECT id, vorname, nachname, ' . str_replace('k.', '', $emailSelect) . ', ' . str_replace('k.', '', $phoneSelect) . ' FROM klienten WHERE benutzer_id = :owner ORDER BY nachname, vorname LIMIT 200');
        $listQuery->execute(['owner' => (int) $user['id']]);
    }
    $clients = $listQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('Coaching Tool clients page failed: ' . $exception->getMessage());
    $owners = [];
    $clients = [];
    $error = 'Klienten und Coaches konnten nicht geladen werden. Prüfe die Spalten und Leserechte der Tabellen.';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Klienten verwalten · Coaching Tool</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#1f302d;background:#f5f7f5}*{box-sizing:border-box}body{margin:0}.page{max-width:1080px;margin:auto;padding:24px clamp(18px,5vw,64px) 70px}.bar{display:flex;justify-content:space-between;align-items:center;padding:14px 0 22px;border-bottom:1px solid #e2e9e4}.brand{color:inherit;text-decoration:none;font-weight:700}.brand span{font-weight:400;color:#60716b}.back{color:#35695b}h1{font-size:clamp(30px,5vw,48px);letter-spacing:-.04em;margin:38px 0 8px}p{color:#65766f;line-height:1.55}.layout{display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:20px;margin-top:26px}.card{background:white;border:1px solid #e5ebe6;border-radius:14px;padding:20px;box-shadow:0 8px 30px #263b3210}.card h2{font-size:18px;margin:0 0 18px}.form{display:grid;gap:10px}.form label{font-size:13px;font-weight:650;margin-top:6px}.form input,.form select{width:100%;border:1px solid #dce5df;border-radius:9px;padding:12px;font:inherit;background:white}.form input:focus,.form select:focus{outline:3px solid #a4c9b4}.button{display:inline-block;border:0;border-radius:9px;padding:12px 16px;background:#245c50;color:white;text-decoration:none;font:inherit;font-weight:650;cursor:pointer;margin-top:12px}.cancel{margin-left:8px;color:#35695b}.notice{padding:12px 14px;background:#edf5ef;color:#245c50;border-radius:9px}.error{padding:12px 14px;background:#fff3f1;color:#8c3434;border-radius:9px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;border-bottom:1px solid #edf1ee;padding:12px 8px;vertical-align:top}th{font-size:12px;color:#65766f}td a{color:#35695b}.muted{font-size:12px;color:#74817c}footer{margin-top:26px;font-size:12px;color:#74817c}@media(max-width:760px){.layout{grid-template-columns:1fr}th,td{padding:10px 6px}.desktop{display:none}}
</style><link rel="stylesheet" href="assets/layout.css"><script src="assets/layout.js" defer></script>
</head>
<body class="has-app-sidebar">
<?php require_once dirname(__DIR__) . '/app/navigation.php'; renderCoachingSidebar($user, 'clients', $token); ?>
<main class="page">

<h1>Klienten verwalten</h1><p>Lege Klienten an und halte Kontaktangaben aktuell. Coaches sehen nur die ihnen zugeordneten Personen.</p>
<?php if ($isAdmin): ?><p><a href="coaches.php">Coach-Konten verwalten →</a> · <a href="managers.php">Verwalterkonten →</a></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice" role="status"><?= h($message) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
<div class="layout"><section class="card"><h2><?= (int) $values['id'] > 0 ? 'Klient bearbeiten' : 'Neuen Klienten anlegen' ?></h2>
<form method="post" class="form">
<input type="hidden" name="csrf_token" value="<?= h($token) ?>"><input type="hidden" name="id" value="<?= h((int) $values['id']) ?>">
<label for="vorname">Vorname</label><input id="vorname" name="vorname" maxlength="100" required value="<?= h($values['vorname']) ?>">
<label for="nachname">Nachname</label><input id="nachname" name="nachname" maxlength="100" required value="<?= h($values['nachname']) ?>">
<?php if ($emailColumn !== null): ?><label for="email">E-Mail (optional)</label><input id="email" name="email" type="email" maxlength="190" value="<?= h($values['email'] ?? '') ?>"><?php endif; ?>
<?php if ($phoneColumn !== null): ?><label for="telefon">Telefon (optional)</label><input id="telefon" name="telefon" type="tel" maxlength="40" value="<?= h($values['telefon'] ?? '') ?>"><?php endif; ?>
<?php if ($isManager): ?><label for="benutzer_id">Zuständiger Coach</label><select id="benutzer_id" name="benutzer_id" required><option value="">Bitte auswählen</option><?php foreach ($owners as $owner): ?><option value="<?= h($owner['id']) ?>" <?= (int) $values['benutzer_id'] === (int) $owner['id'] ? 'selected' : '' ?>><?= h($owner['vorname'] . ' ' . $owner['nachname']) ?></option><?php endforeach; ?></select><?php elseif ($isAdmin && (int) $values['id'] > 0): ?><p class="muted">Coach-Wechsel sind nur mit einem Verwalterkonto möglich.</p><?php endif; ?>
<button class="button" type="submit">Speichern</button><?php if ((int) $values['id'] > 0): ?><a class="cancel" href="clients.php">Abbrechen</a><?php endif; ?>
</form></section>
<section class="card"><h2>Klienten (<?= count($clients) ?>)</h2><?php if (!$clients): ?><p>Noch keine Klienten eingetragen.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Name</th><th>Kontakt</th><?php if ($canSeeAllClients): ?><th>Coach</th><?php endif; ?><th></th></tr></thead><tbody><?php foreach ($clients as $client): ?><tr><td><?= h($client['vorname'] . ' ' . $client['nachname']) ?></td><td><?= h($client['email'] ?? '') ?><?php if (!empty($client['telefon'])): ?><br><span class="muted"><?= h($client['telefon']) ?></span><?php endif; ?></td><?php if ($canSeeAllClients): ?><td><?= h(trim(($client['coach_vorname'] ?? '') . ' ' . ($client['coach_nachname'] ?? ''))) ?></td><?php endif; ?><td><?php if (!$isManager): ?><a href="client-overview.php?id=<?= h($client['id']) ?>">Übersicht</a> · <?php endif; ?><a href="clients.php?edit=<?= h($client['id']) ?>">Bearbeiten</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section></div>
<footer>Privater Bereich · Klientendaten sind nur für berechtigte Zugänge sichtbar</footer></main></body></html>
