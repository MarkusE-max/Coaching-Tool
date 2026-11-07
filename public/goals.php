<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/app/bootstrap.php';

function goalH(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || !isset($user['id'], $user['role']) || !in_array($user['role'], ['admin', 'coach'], true)) {
    header('Location: ./');
    exit;
}
$database = coachingDatabase();
$token = $_SESSION['csrf_token'] ?? '';
if (!is_string($token) || strlen($token) !== 64) { $token = bin2hex(random_bytes(32)); $_SESSION['csrf_token'] = $token; }
$isAdmin = $user['role'] === 'admin';
$statuses = ['aktiv' => 'Aktiv', 'pausiert' => 'Pausiert', 'erreicht' => 'Erreicht', 'abgebrochen' => 'Abgebrochen'];
$error = '';
$message = isset($_GET['saved']) ? 'Das Ziel wurde gespeichert.' : '';
$values = ['id' => 0, 'klient_id' => 0, 'titel' => '', 'beschreibung' => '', 'erfolgskriterium' => '', 'zieltermin' => '', 'status' => 'aktiv'];

try {
    if ($isAdmin) {
        $clientQuery = $database->query('SELECT id, vorname, nachname, benutzer_id FROM klienten ORDER BY nachname, vorname LIMIT 500');
    } else {
        $clientQuery = $database->prepare('SELECT id, vorname, nachname, benutzer_id FROM klienten WHERE benutzer_id = :owner ORDER BY nachname, vorname LIMIT 500');
        $clientQuery->execute(['owner' => (int) $user['id']]);
    }
    $clients = $clientQuery->fetchAll(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = $_POST['csrf_token'] ?? '';
        $rawId = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
        $id = $rawId === false ? -1 : (int) $rawId;
        $rawClient = filter_var($_POST['klient_id'] ?? null, FILTER_VALIDATE_INT);
        $clientId = $rawClient === false ? 0 : (int) $rawClient;
        $title = trim(is_string($_POST['titel'] ?? null) ? $_POST['titel'] : '');
        $description = trim(is_string($_POST['beschreibung'] ?? null) ? $_POST['beschreibung'] : '');
        $criterion = trim(is_string($_POST['erfolgskriterium'] ?? null) ? $_POST['erfolgskriterium'] : '');
        $dueDate = trim(is_string($_POST['zieltermin'] ?? null) ? $_POST['zieltermin'] : '');
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        $values = ['id' => max(0, $id), 'klient_id' => $clientId, 'titel' => $title, 'beschreibung' => $description, 'erfolgskriterium' => $criterion, 'zieltermin' => $dueDate, 'status' => $status];
        if (!is_string($postedToken) || !hash_equals($token, $postedToken)) throw new InvalidArgumentException('Die Anfrage ist abgelaufen. Bitte lade die Seite neu.');
        if ($id < 0 || $clientId < 1 || $title === '' || strlen($title) > 190 || strlen($description) > 20000 || strlen($criterion) > 20000 || !array_key_exists($status, $statuses)) {
            throw new InvalidArgumentException('Bitte prüfe Klient, Titel und Zielstatus.');
        }
        if ($dueDate !== '') {
            $date = DateTime::createFromFormat('Y-m-d', $dueDate);
            $dateErrors = DateTime::getLastErrors();
            if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $dueDate) {
                throw new InvalidArgumentException('Bitte ein gültiges Zieldatum auswählen.');
            }
        }
        $clientCheck = $database->prepare('SELECT id, benutzer_id FROM klienten WHERE id = :id' . ($isAdmin ? '' : ' AND benutzer_id = :owner'));
        $clientParams = ['id' => $clientId];
        if (!$isAdmin) $clientParams['owner'] = (int) $user['id'];
        $clientCheck->execute($clientParams);
        $client = $clientCheck->fetch(PDO::FETCH_ASSOC);
        if (!$client) throw new InvalidArgumentException('Der ausgewählte Klient gehört nicht zu deinem Zugang.');
        $goalCoachId = (int) $client['benutzer_id'];

        if ($id > 0) {
            $exists = $database->prepare('SELECT id FROM coaching_ziele WHERE id = :id' . ($isAdmin ? '' : ' AND coach_benutzer_id = :owner'));
            $existsParams = ['id' => $id];
            if (!$isAdmin) $existsParams['owner'] = (int) $user['id'];
            $exists->execute($existsParams);
            if (!$exists->fetchColumn()) throw new InvalidArgumentException('Das Ziel wurde nicht gefunden oder gehört nicht zu deinem Zugang.');
            $update = $database->prepare('UPDATE coaching_ziele SET klient_id = :client_id, coach_benutzer_id = :coach_id, titel = :title, beschreibung = :description, erfolgskriterium = :criterion, zieltermin = :due_date, status = :status WHERE id = :id');
            $update->execute(['client_id' => $clientId, 'coach_id' => $goalCoachId, 'title' => $title, 'description' => $description ?: null, 'criterion' => $criterion ?: null, 'due_date' => $dueDate ?: null, 'status' => $status, 'id' => $id]);
        } else {
            $insert = $database->prepare('INSERT INTO coaching_ziele (klient_id, coach_benutzer_id, titel, beschreibung, erfolgskriterium, zieltermin, status) VALUES (:client_id, :coach_id, :title, :description, :criterion, :due_date, :status)');
            $insert->execute(['client_id' => $clientId, 'coach_id' => $goalCoachId, 'title' => $title, 'description' => $description ?: null, 'criterion' => $criterion ?: null, 'due_date' => $dueDate ?: null, 'status' => $status]);
        }
        header('Location: goals.php?saved=1');
        exit;
    }

    if (isset($_GET['edit'])) {
        $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
        if ($editId === false || (int) $editId < 1) throw new InvalidArgumentException('Ungültige Ziel-ID.');
        $query = $database->prepare('SELECT g.id, g.klient_id, g.titel, g.beschreibung, g.erfolgskriterium, g.zieltermin, g.status FROM coaching_ziele g INNER JOIN klienten k ON k.id = g.klient_id WHERE g.id = :id' . ($isAdmin ? '' : ' AND g.coach_benutzer_id = :owner AND k.benutzer_id = :client_owner'));
        $params = ['id' => (int) $editId];
        if (!$isAdmin) { $params['owner'] = (int) $user['id']; $params['client_owner'] = (int) $user['id']; }
        $query->execute($params);
        $record = $query->fetch(PDO::FETCH_ASSOC);
        if (!$record) throw new InvalidArgumentException('Das Ziel wurde nicht gefunden oder gehört nicht zu deinem Zugang.');
        $values = $record;
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Coaching Tool goal operation failed: ' . $exception->getMessage());
    $error = 'Ziele konnten nicht geladen oder gespeichert werden. Prüfe, ob die SQL-Migration ausgeführt wurde und die Tabellenrechte gesetzt sind.';
}

try {
    if ($isAdmin) {
        $query = $database->query('SELECT g.id, g.klient_id, g.titel, g.status, g.zieltermin, g.geaendert_am, k.vorname, k.nachname FROM coaching_ziele g INNER JOIN klienten k ON k.id = g.klient_id ORDER BY g.geaendert_am DESC LIMIT 200');
    } else {
        $query = $database->prepare('SELECT g.id, g.klient_id, g.titel, g.status, g.zieltermin, g.geaendert_am, k.vorname, k.nachname FROM coaching_ziele g INNER JOIN klienten k ON k.id = g.klient_id WHERE k.benutzer_id = :owner ORDER BY g.geaendert_am DESC LIMIT 200');
        $query->execute(['owner' => (int) $user['id']]);
    }
    $goals = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('Coaching Tool goal list failed: ' . $exception->getMessage());
    $goals = [];
    $error = 'Die Zielliste konnte nicht geladen werden. Prüfe die SQL-Migration und Tabellenrechte.';
}
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ziele · Coaching Tool</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#1f302d;background:#f5f7f5}*{box-sizing:border-box}body{margin:0}.page{max-width:1120px;margin:auto;padding:24px clamp(18px,5vw,64px) 70px}.bar{display:flex;justify-content:space-between;padding:14px 0 22px;border-bottom:1px solid #e2e9e4}.brand{color:inherit;text-decoration:none;font-weight:700}.brand span{font-weight:400;color:#60716b}a{color:#35695b}h1{font-size:clamp(30px,5vw,48px);letter-spacing:-.04em;margin:38px 0 8px}p{color:#65766f;line-height:1.55}.layout{display:grid;grid-template-columns:minmax(280px,380px) 1fr;gap:20px;margin-top:26px}.card{background:#fff;border:1px solid #e5ebe6;border-radius:14px;padding:20px;box-shadow:0 8px 30px #263b3210}.form{display:grid;gap:10px}.form label{font-size:13px;font-weight:650;margin-top:6px}.form input,.form select,.form textarea{width:100%;border:1px solid #dce5df;border-radius:9px;padding:12px;font:inherit;background:#fff}.form textarea{min-height:100px;resize:vertical}.button{border:0;border-radius:9px;padding:12px 16px;background:#245c50;color:#fff;font:inherit;font-weight:650;cursor:pointer;margin-top:12px}.error{padding:12px;background:#fff3f1;color:#8c3434;border-radius:9px}.notice{padding:12px;background:#edf5ef;color:#245c50;border-radius:9px}.row{display:flex;justify-content:space-between;gap:14px;padding:14px 0;border-bottom:1px solid #edf1ee}.row:last-child{border:0}.muted{color:#74817c;font-size:12px}.tag{font-size:11px;border-radius:999px;background:#eef3ef;padding:4px 8px}@media(max-width:760px){.layout{grid-template-columns:1fr}}
</style></head>
<body><main class="page"><header class="bar"><a class="brand" href="./">Coaching<span>Tool</span></a><nav><a href="appointments.php">Termine</a> · <a href="notes.php">Private Notizen</a></nav></header>
<h1>Ziele</h1><p>Lege Entwicklungsziele mit Erfolgskriterium und Zieldatum an und halte den Fortschritt fest.</p>
<?php if ($message !== ''): ?><p class="notice" role="status"><?= goalH($message) ?></p><?php endif; ?><?php if ($error !== ''): ?><p class="error" role="alert"><?= goalH($error) ?></p><?php endif; ?>
<div class="layout"><section class="card"><h2><?= (int) $values['id'] > 0 ? 'Ziel bearbeiten' : 'Ziel anlegen' ?></h2><?php if ($clients === []): ?><p>Lege zuerst einen Klienten an.</p><?php else: ?>
<form class="form" method="post"><input type="hidden" name="csrf_token" value="<?= goalH($token) ?>"><input type="hidden" name="id" value="<?= goalH((int) $values['id']) ?>">
<label for="klient_id">Klient</label><select name="klient_id" id="klient_id" required><option value="">Bitte auswählen</option><?php foreach ($clients as $client): ?><option value="<?= goalH($client['id']) ?>" <?= (int) $values['klient_id'] === (int) $client['id'] ? 'selected' : '' ?>><?= goalH($client['vorname'] . ' ' . $client['nachname']) ?></option><?php endforeach; ?></select>
<label for="titel">Ziel</label><input id="titel" name="titel" maxlength="190" required value="<?= goalH($values['titel']) ?>">
<label for="beschreibung">Beschreibung (optional)</label><textarea id="beschreibung" name="beschreibung"><?= goalH($values['beschreibung'] ?? '') ?></textarea>
<label for="erfolgskriterium">Woran ist das Ziel erreicht? (optional)</label><textarea id="erfolgskriterium" name="erfolgskriterium"><?= goalH($values['erfolgskriterium'] ?? '') ?></textarea>
<label for="zieltermin">Zieldatum (optional)</label><input id="zieltermin" name="zieltermin" type="date" value="<?= goalH($values['zieltermin'] ?? '') ?>">
<label for="status">Status</label><select id="status" name="status"><?php foreach ($statuses as $value => $label): ?><option value="<?= goalH($value) ?>" <?= $values['status'] === $value ? 'selected' : '' ?>><?= goalH($label) ?></option><?php endforeach; ?></select>
<button class="button" type="submit">Ziel speichern</button><?php if ((int) $values['id'] > 0): ?> <a href="goals.php">Abbrechen</a><?php endif; ?>
</form><?php endif; ?></section>
<section class="card"><h2>Entwicklungsziele (<?= count($goals) ?>)</h2><?php if ($goals === []): ?><p>Noch keine Ziele erfasst.</p><?php else: ?><?php foreach ($goals as $goal): ?><div class="row"><div><strong><?= goalH($goal['titel']) ?></strong><div><?= goalH($goal['vorname'] . ' ' . $goal['nachname']) ?></div><span class="muted"><?= goalH($statuses[$goal['status']] ?? $goal['status']) ?><?php if ($goal['zieltermin']): ?> · Zieltermin <?= goalH(date('d.m.Y', strtotime($goal['zieltermin']))) ?><?php endif; ?></span></div><a href="goals.php?edit=<?= goalH($goal['id']) ?>">Bearbeiten</a></div><?php endforeach; ?><?php endif; ?></section></div>
<p><a href="./">Zur Übersicht</a></p></main></body></html>
