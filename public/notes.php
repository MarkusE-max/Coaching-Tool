<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/app/bootstrap.php';

function noteH(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || ($user['role'] ?? '') !== 'coach' || !isset($user['id'])) {
    http_response_code(403);
    exit('Private Sitzungsnotizen sind nur für Coaches zugänglich.');
}
$database = coachingDatabase();
$token = $_SESSION['csrf_token'] ?? '';
if (!is_string($token) || strlen($token) !== 64) { $token = bin2hex(random_bytes(32)); $_SESSION['csrf_token'] = $token; }
$coachId = (int) $user['id'];
$error = '';
$message = isset($_GET['saved']) ? 'Die Sitzungsnotiz wurde gespeichert.' : '';
$values = ['id' => 0, 'klient_id' => 0, 'termin_id' => '', 'titel' => '', 'inhalt' => ''];

$clientQuery = $database->prepare('SELECT id, vorname, nachname FROM klienten WHERE benutzer_id = :owner ORDER BY nachname, vorname');
$clientQuery->execute(['owner' => $coachId]);
$clients = $clientQuery->fetchAll(PDO::FETCH_ASSOC);

$appointmentQuery = $database->prepare("SELECT t.id, t.klient_id, t.beginn, k.vorname, k.nachname FROM termine t INNER JOIN klienten k ON k.id = t.klient_id WHERE k.benutzer_id = :owner ORDER BY t.beginn DESC LIMIT 500");
$appointmentQuery->execute(['owner' => $coachId]);
$appointments = $appointmentQuery->fetchAll(PDO::FETCH_ASSOC);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf_token'] ?? '';
        $rawId = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
        $id = $rawId === false ? -1 : (int) $rawId;
        $clientId = filter_var($_POST['klient_id'] ?? null, FILTER_VALIDATE_INT);
        $clientId = $clientId === false ? 0 : (int) $clientId;
        $appointmentId = filter_var($_POST['termin_id'] ?? '', FILTER_VALIDATE_INT);
        $appointmentId = $appointmentId === false || $appointmentId === 0 ? null : (int) $appointmentId;
        $title = trim(is_string($_POST['titel'] ?? null) ? $_POST['titel'] : '');
        $body = trim(is_string($_POST['inhalt'] ?? null) ? $_POST['inhalt'] : '');
        $values = ['id' => max(0, $id), 'klient_id' => $clientId, 'termin_id' => $appointmentId ?? '', 'titel' => $title, 'inhalt' => $body];
        if (!is_string($csrf) || !hash_equals($token, $csrf)) throw new InvalidArgumentException('Die Anfrage ist abgelaufen. Bitte lade die Seite neu.');
        if ($id < 0 || $clientId < 1 || $title === '' || strlen($title) > 190 || $body === '' || strlen($body) > 1000000) {
            throw new InvalidArgumentException('Bitte wähle einen Klienten und gib Titel und Notiztext ein.');
        }
        $checkClient = $database->prepare('SELECT id FROM klienten WHERE id = :id AND benutzer_id = :owner');
        $checkClient->execute(['id' => $clientId, 'owner' => $coachId]);
        if (!$checkClient->fetchColumn()) throw new InvalidArgumentException('Der Klient gehört nicht zu deinem Zugang.');
        if ($appointmentId !== null) {
            $checkAppointment = $database->prepare('SELECT t.id FROM termine t INNER JOIN klienten k ON k.id = t.klient_id WHERE t.id = :id AND t.klient_id = :client_id AND k.benutzer_id = :owner');
            $checkAppointment->execute(['id' => $appointmentId, 'client_id' => $clientId, 'owner' => $coachId]);
            if (!$checkAppointment->fetchColumn()) throw new InvalidArgumentException('Der Termin gehört nicht zu diesem Klienten.');
        }
        if ($id > 0) {
            $exists = $database->prepare('SELECT id FROM coaching_sitzungsnotizen WHERE id = :id AND coach_benutzer_id = :owner');
            $exists->execute(['id' => $id, 'owner' => $coachId]);
            if (!$exists->fetchColumn()) throw new InvalidArgumentException('Die Notiz wurde nicht gefunden.');
            $update = $database->prepare('UPDATE coaching_sitzungsnotizen SET klient_id = :client_id, termin_id = :appointment_id, titel = :title, inhalt = :body WHERE id = :id AND coach_benutzer_id = :owner');
            $update->execute(['client_id' => $clientId, 'appointment_id' => $appointmentId, 'title' => $title, 'body' => $body, 'id' => $id, 'owner' => $coachId]);
        } else {
            $insert = $database->prepare("INSERT INTO coaching_sitzungsnotizen (termin_id, klient_id, coach_benutzer_id, titel, inhalt, sichtbarkeit) VALUES (:appointment_id, :client_id, :owner, :title, :body, 'coach_private')");
            $insert->execute(['appointment_id' => $appointmentId, 'client_id' => $clientId, 'owner' => $coachId, 'title' => $title, 'body' => $body]);
        }
        header('Location: notes.php?saved=1');
        exit;
    }

    if (isset($_GET['termin_id'])) {
        $appointmentId = filter_var($_GET['termin_id'], FILTER_VALIDATE_INT);
        if ($appointmentId === false || (int) $appointmentId < 1) throw new InvalidArgumentException('Ungültige Termin-ID.');
        $query = $database->prepare('SELECT t.id, t.klient_id FROM termine t INNER JOIN klienten k ON k.id = t.klient_id WHERE t.id = :id AND k.benutzer_id = :owner');
        $query->execute(['id' => (int) $appointmentId, 'owner' => $coachId]);
        $appointment = $query->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) throw new InvalidArgumentException('Der Termin gehört nicht zu deinem Zugang.');
        $values['termin_id'] = (int) $appointment['id'];
        $values['klient_id'] = (int) $appointment['klient_id'];
    }
    if (isset($_GET['edit'])) {
        $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
        if ($editId === false || (int) $editId < 1) throw new InvalidArgumentException('Ungültige Notiz-ID.');
        $query = $database->prepare('SELECT n.id, n.klient_id, n.termin_id, n.titel, n.inhalt FROM coaching_sitzungsnotizen n INNER JOIN klienten k ON k.id = n.klient_id WHERE n.id = :id AND n.coach_benutzer_id = :note_owner AND k.benutzer_id = :client_owner');
        $query->execute(['id' => (int) $editId, 'note_owner' => $coachId, 'client_owner' => $coachId]);
        $record = $query->fetch(PDO::FETCH_ASSOC);
        if (!$record) throw new InvalidArgumentException('Die Notiz wurde nicht gefunden.');
        $values = $record;
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Coaching Tool private note operation failed: ' . $exception->getMessage());
    $error = 'Die Notiz konnte nicht geladen oder gespeichert werden. Prüfe, ob die SQL-Migration ausgeführt wurde und die Tabellenrechte gesetzt sind.';
}

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
if (strlen($search) > 190) $search = substr($search, 0, 190);
$list = $database->prepare('SELECT n.id, n.termin_id, n.klient_id, n.titel, n.inhalt, n.erstellt_am, k.vorname, k.nachname, t.beginn FROM coaching_sitzungsnotizen n INNER JOIN klienten k ON k.id = n.klient_id LEFT JOIN termine t ON t.id = n.termin_id WHERE n.coach_benutzer_id = :note_owner AND k.benutzer_id = :client_owner AND (:search = \'%%\' OR n.titel LIKE :title_search OR n.inhalt LIKE :body_search) ORDER BY n.erstellt_am DESC LIMIT 200');
$needle = '%' . $search . '%';
$list->execute(['note_owner' => $coachId, 'client_owner' => $coachId, 'search' => $search === '' ? '%%' : $search, 'title_search' => $needle, 'body_search' => $needle]);
$notes = $list->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Private Sitzungsnotizen · Coaching Tool</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#1f302d;background:#f5f7f5}*{box-sizing:border-box}body{margin:0}.page{max-width:1120px;margin:auto;padding:24px clamp(18px,5vw,64px) 70px}.bar{display:flex;justify-content:space-between;padding:14px 0 22px;border-bottom:1px solid #e2e9e4}.brand{color:inherit;text-decoration:none;font-weight:700}.brand span{font-weight:400;color:#60716b}a{color:#35695b}h1{font-size:clamp(30px,5vw,48px);letter-spacing:-.04em;margin:38px 0 8px}p{color:#65766f;line-height:1.55}.layout{display:grid;grid-template-columns:minmax(280px,380px) 1fr;gap:20px;margin-top:26px}.card{background:white;border:1px solid #e5ebe6;border-radius:14px;padding:20px;box-shadow:0 8px 30px #263b3210}.form{display:grid;gap:10px}.form label{font-size:13px;font-weight:650;margin-top:6px}.form input,.form select,.form textarea{width:100%;border:1px solid #dce5df;border-radius:9px;padding:12px;font:inherit;background:#fff}.form textarea{min-height:180px;resize:vertical}.button{border:0;border-radius:9px;padding:12px 16px;background:#245c50;color:white;font:inherit;font-weight:650;cursor:pointer;margin-top:12px}.error{padding:12px;background:#fff3f1;color:#8c3434;border-radius:9px}.notice{padding:12px;background:#edf5ef;color:#245c50;border-radius:9px}.note{padding:14px 0;border-bottom:1px solid #edf1ee}.note:last-child{border:0}.note-title{font-weight:650}.muted{color:#74817c;font-size:12px}.note-body{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.55;margin-top:8px}.search{display:flex;gap:8px;margin:15px 0}.search input{flex:1;min-width:0;border:1px solid #dce5df;border-radius:9px;padding:10px}.tag{font-size:11px;border-radius:999px;background:#eef3ef;padding:4px 8px}@media(max-width:760px){.layout{grid-template-columns:1fr}}
</style></head>
<body><main class="page"><header class="bar"><a class="brand" href="./">Coaching<span>Tool</span></a><nav><a href="appointments.php">Termine</a> · <a href="goals.php">Ziele</a></nav></header>
<h1>Private Sitzungsnotizen</h1><p>Diese Notizen sind nur für dich als zuständigen Coach sichtbar und werden nicht im Klientenbereich geteilt.</p>
<?php if ($message !== ''): ?><p class="notice" role="status"><?= noteH($message) ?></p><?php endif; ?><?php if ($error !== ''): ?><p class="error" role="alert"><?= noteH($error) ?></p><?php endif; ?>
<div class="layout"><section class="card"><h2><?= (int) $values['id'] > 0 ? 'Notiz bearbeiten' : 'Notiz erfassen' ?></h2>
<form class="form" method="post"><input type="hidden" name="csrf_token" value="<?= noteH($token) ?>"><input type="hidden" name="id" value="<?= noteH((int) $values['id']) ?>">
<label for="klient_id">Klient</label><select id="klient_id" name="klient_id" required><option value="">Bitte auswählen</option><?php foreach ($clients as $client): ?><option value="<?= noteH($client['id']) ?>" <?= (int) $values['klient_id'] === (int) $client['id'] ? 'selected' : '' ?>><?= noteH($client['vorname'] . ' ' . $client['nachname']) ?></option><?php endforeach; ?></select>
<label for="termin_id">Termin (optional)</label><select id="termin_id" name="termin_id"><option value="">Keinem Termin zuordnen</option><?php foreach ($appointments as $appointment): ?><option value="<?= noteH($appointment['id']) ?>" <?= (int) ($values['termin_id'] ?? 0) === (int) $appointment['id'] ? 'selected' : '' ?>><?= noteH(date('d.m.Y H:i', strtotime($appointment['beginn'])) . ' · ' . $appointment['vorname'] . ' ' . $appointment['nachname']) ?></option><?php endforeach; ?></select>
<label for="titel">Titel</label><input id="titel" name="titel" maxlength="190" required value="<?= noteH($values['titel']) ?>">
<label for="inhalt">Notiz</label><textarea id="inhalt" name="inhalt" required><?= noteH($values['inhalt']) ?></textarea>
<button class="button" type="submit">Notiz speichern</button><?php if ((int) $values['id'] > 0): ?> <a href="notes.php">Abbrechen</a><?php endif; ?></form></section>
<section class="card"><h2>Notizen (<?= count($notes) ?>)</h2><form class="search" method="get"><input name="q" value="<?= noteH($search) ?>" placeholder="Notizen durchsuchen"><button type="submit">Suchen</button></form>
<?php if ($notes === []): ?><p>Noch keine privaten Notizen vorhanden.</p><?php else: ?><?php foreach ($notes as $note): ?><article class="note"><div class="note-title"><?= noteH($note['titel']) ?> <span class="tag"><?= noteH($note['vorname'] . ' ' . $note['nachname']) ?></span></div><div class="muted"><?php if ($note['beginn']): ?><?= noteH(date('d.m.Y H:i', strtotime($note['beginn']))) ?> · <?php endif; ?><?= noteH(date('d.m.Y H:i', strtotime($note['erstellt_am']))) ?></div><div class="note-body"><?= noteH($note['inhalt']) ?></div><a href="notes.php?edit=<?= noteH($note['id']) ?>">Bearbeiten</a></article><?php endforeach; ?><?php endif; ?>
</section></div><p><a href="./">Zur Übersicht</a></p></main></body></html>
