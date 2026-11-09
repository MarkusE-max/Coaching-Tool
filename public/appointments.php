<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/app/bootstrap.php';

function appointmentH(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function appointmentEnumOptions(string $type, array $fallback): array
{
    if (preg_match('/^enum\((.*)\)$/i', trim($type), $typeMatch) !== 1) {
        return $fallback;
    }
    preg_match_all("/'([^']*)'/", $typeMatch[1], $matches);
    return $matches[1] !== [] ? array_map('stripcslashes', $matches[1]) : $fallback;
}

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || !isset($user['id'], $user['role']) || !in_array($user['role'], ['admin', 'coach'], true)) {
    header('Location: ./');
    exit;
}
try {
    $database = coachingDatabase();
    $termRows = $database->query('SHOW COLUMNS FROM termine')->fetchAll(PDO::FETCH_ASSOC);
    $termMeta = [];
    foreach ($termRows as $row) $termMeta[$row['Field']] = $row['Type'];
} catch (Throwable $exception) {
    error_log('Coaching Tool appointments initialization failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('Die Terminverwaltung ist nicht verfügbar. Prüfe Datenbankverbindung und Tabelle termine.');
}

$required = ['id', 'klient_id', 'beginn', 'dauer_minuten', 'format', 'status'];
$missing = array_values(array_diff($required, array_keys($termMeta)));
$topicColumn = null;
foreach (['thema', 'titel', 'beschreibung'] as $candidate) {
    if (isset($termMeta[$candidate])) { $topicColumn = $candidate; break; }
}
$topicSelect = $topicColumn !== null ? 't.' . $topicColumn . ' AS thema' : 'NULL AS thema';
$formatOptions = appointmentEnumOptions($termMeta['format'] ?? '', ['vor_ort', 'telefon', 'video']);
$statusOptions = appointmentEnumOptions($termMeta['status'] ?? '', ['geplant', 'stattgefunden', 'abgesagt']);
$formatLabels = ['vor_ort' => 'Vor Ort', 'telefon' => 'Telefon', 'video' => 'Video', 'online' => 'Online'];
$statusLabels = ['geplant' => 'Geplant', 'abgeschlossen' => 'Abgeschlossen', 'stattgefunden' => 'Stattgefunden', 'abgesagt' => 'Abgesagt'];
$token = $_SESSION['csrf_token'] ?? '';
if (!is_string($token) || strlen($token) !== 64) {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
}
$isAdmin = $user['role'] === 'admin';
$error = $missing === [] ? '' : 'Die Tabelle termine hat nicht alle erwarteten Spalten.';
$message = isset($_GET['saved']) ? 'Der Termin wurde gespeichert.' : '';
$values = ['id' => 0, 'klient_id' => 0, 'beginn' => '', 'dauer_minuten' => 60, 'format' => $formatOptions[0] ?? 'vor_ort', 'status' => $statusOptions[0] ?? 'geplant', 'thema' => ''];
$clients = [];
$appointments = [];

try {
    if ($isAdmin) {
        $clientQuery = $database->query('SELECT id, vorname, nachname, benutzer_id FROM klienten ORDER BY nachname, vorname LIMIT 500');
    } else {
        $clientQuery = $database->prepare('SELECT id, vorname, nachname, benutzer_id FROM klienten WHERE benutzer_id = :owner ORDER BY nachname, vorname LIMIT 500');
        $clientQuery->execute(['owner' => (int) $user['id']]);
    }
    $clients = $clientQuery->fetchAll(PDO::FETCH_ASSOC);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['klient_id'])) {
        $prefillClient = filter_var($_GET['klient_id'], FILTER_VALIDATE_INT);
        if ($prefillClient !== false && (int) $prefillClient > 0) {
            $prefill = $database->prepare('SELECT id FROM klienten WHERE id = :id' . ($isAdmin ? '' : ' AND benutzer_id = :owner'));
            $prefillParams = ['id' => (int) $prefillClient];
            if (!$isAdmin) $prefillParams['owner'] = (int) $user['id'];
            $prefill->execute($prefillParams);
            if ($prefill->fetchColumn()) $values['klient_id'] = (int) $prefillClient;
            else $error = 'Der ausgewählte Klient gehört nicht zu deinem Zugang.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $missing === []) {
        $postedToken = $_POST['csrf_token'] ?? '';
        $rawId = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
        $id = $rawId === false ? -1 : (int) $rawId;
        $rawClientId = filter_var($_POST['klient_id'] ?? null, FILTER_VALIDATE_INT);
        $clientId = $rawClientId === false ? 0 : (int) $rawClientId;
        $startInput = is_string($_POST['beginn'] ?? null) ? trim($_POST['beginn']) : '';
        $duration = filter_var($_POST['dauer_minuten'] ?? null, FILTER_VALIDATE_INT);
        $format = is_string($_POST['format'] ?? null) ? $_POST['format'] : '';
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        $topic = trim(is_string($_POST['thema'] ?? null) ? $_POST['thema'] : '');
        $values = ['id' => max(0, $id), 'klient_id' => $clientId, 'beginn' => $startInput, 'dauer_minuten' => $duration === false ? 60 : (int) $duration, 'format' => $format, 'status' => $status, 'thema' => $topic];
        $date = DateTime::createFromFormat('Y-m-d\TH:i', $startInput);
        $dateErrors = DateTime::getLastErrors();
        $dateValid = $date instanceof DateTime
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
            && $date->format('Y-m-d\TH:i') === $startInput;

        if (!is_string($postedToken) || !hash_equals($token, $postedToken)) {
            throw new InvalidArgumentException('Die Anfrage ist abgelaufen. Bitte lade die Seite neu.');
        }
        if ($id < 0 || $clientId < 1 || !$dateValid
            || $duration === false || (int) $duration < 5 || (int) $duration > 600
            || !in_array($format, $formatOptions, true) || !in_array($status, $statusOptions, true)
            || strlen($topic) > 190) {
            throw new InvalidArgumentException('Bitte prüfe Klient, Datum, Dauer, Format und Status.');
        }

        $clientCheck = $database->prepare('SELECT id FROM klienten WHERE id = :id' . ($isAdmin ? '' : ' AND benutzer_id = :owner'));
        $clientParams = ['id' => $clientId];
        if (!$isAdmin) $clientParams['owner'] = (int) $user['id'];
        $clientCheck->execute($clientParams);
        if (!$clientCheck->fetchColumn()) {
            throw new InvalidArgumentException('Der ausgewählte Klient gehört nicht zu deinem Zugang.');
        }
        $engagementId = coachingActiveEngagementId($database, $clientId);

        if ($id > 0) {
            $existing = $database->prepare('SELECT t.id FROM termine t INNER JOIN klienten k ON k.id = t.klient_id WHERE t.id = :id' . ($isAdmin ? '' : ' AND k.benutzer_id = :owner'));
            $existingParams = ['id' => $id];
            if (!$isAdmin) $existingParams['owner'] = (int) $user['id'];
            $existing->execute($existingParams);
            if (!$existing->fetchColumn()) throw new InvalidArgumentException('Der Termin wurde nicht gefunden oder gehört nicht zu deinem Zugang.');
        }

        $fields = ['klient_id = :client_id', 'auftrag_id = :engagement_id', 'beginn = :start', 'dauer_minuten = :duration', 'format = :format', 'status = :status'];
        $params = ['client_id' => $clientId, 'engagement_id' => $engagementId, 'start' => $date->format('Y-m-d H:i:s'), 'duration' => (int) $duration, 'format' => $format, 'status' => $status];
        if ($topicColumn !== null) { $fields[] = $topicColumn . ' = :topic'; $params['topic'] = $topic; }

        if ($id > 0) {
            $params['id'] = $id;
            $update = $database->prepare('UPDATE termine SET ' . implode(', ', $fields) . ' WHERE id = :id');
            $update->execute($params);
        } else {
            $insertColumns = ['klient_id', 'auftrag_id', 'beginn', 'dauer_minuten', 'format', 'status'];
            $insertValues = [':client_id', ':engagement_id', ':start', ':duration', ':format', ':status'];
            if ($topicColumn !== null) { $insertColumns[] = $topicColumn; $insertValues[] = ':topic'; }
            $insert = $database->prepare('INSERT INTO termine (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')');
            $insert->execute($params);
        }
        header('Location: appointments.php?saved=1');
        exit;
    }

    if (isset($_GET['edit']) && $missing === []) {
        $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
        if ($editId === false || (int) $editId < 1) throw new InvalidArgumentException('Ungültige Termin-ID.');
        $query = $database->prepare('SELECT t.id, t.klient_id, t.beginn, t.dauer_minuten, t.format, t.status, ' . $topicSelect . ' FROM termine t INNER JOIN klienten k ON k.id = t.klient_id INNER JOIN coaching_auftraege a ON a.id=t.auftrag_id AND a.status=\'active\' WHERE t.id = :id' . ($isAdmin ? '' : ' AND k.benutzer_id = :owner'));
        $params = ['id' => (int) $editId];
        if (!$isAdmin) $params['owner'] = (int) $user['id'];
        $query->execute($params);
        $record = $query->fetch(PDO::FETCH_ASSOC);
        if (!$record) throw new InvalidArgumentException('Der Termin wurde nicht gefunden oder gehört nicht zu deinem Zugang.');
        $values = $record;
        $values['beginn'] = date('Y-m-d\TH:i', strtotime((string) $record['beginn']));
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Coaching Tool appointment save/list failed: ' . $exception->getMessage());
    $error = 'Termine konnten nicht geladen oder gespeichert werden. Prüfe Datenbankrechte und Tabellenspalten.';
}

if ($missing === []) {
    try {
        $sql = 'SELECT t.id, t.klient_id, t.beginn, t.dauer_minuten, t.format, t.status, ' . $topicSelect . ', k.vorname, k.nachname, a.titel AS auftrag_titel FROM termine t INNER JOIN klienten k ON k.id = t.klient_id INNER JOIN coaching_auftraege a ON a.id=t.auftrag_id WHERE a.status=\'aktiv\'' . ($isAdmin ? '' : ' AND k.benutzer_id = :owner') . ' ORDER BY t.beginn DESC LIMIT 200';
        $query = $database->prepare($sql);
        $query->execute($isAdmin ? [] : ['owner' => (int) $user['id']]);
        $appointments = $query->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        error_log('Coaching Tool appointment list failed: ' . $exception->getMessage());
        $error = 'Der Terminverlauf konnte nicht geladen werden. Prüfe die Leserechte auf termine.';
    }
}
$clientsById = [];
foreach ($clients as $client) $clientsById[(int) $client['id']] = $client;
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terminverwaltung · Coaching Tool</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#1f302d;background:#f5f7f5}*{box-sizing:border-box}body{margin:0}.page{max-width:1120px;margin:auto;padding:24px clamp(18px,5vw,64px) 70px}.bar{display:flex;justify-content:space-between;padding:14px 0 22px;border-bottom:1px solid #e2e9e4}.brand{color:inherit;text-decoration:none;font-weight:700}.brand span{font-weight:400;color:#60716b}a{color:#35695b}h1{font-size:clamp(30px,5vw,48px);letter-spacing:-.04em;margin:38px 0 8px}p{color:#65766f;line-height:1.55}.layout{display:grid;grid-template-columns:minmax(280px,380px) 1fr;gap:20px;margin-top:26px}.card{background:white;border:1px solid #e5ebe6;border-radius:14px;padding:20px;box-shadow:0 8px 30px #263b3210}.card h2{font-size:18px;margin:0 0 18px}.form{display:grid;gap:10px}.form label{font-size:13px;font-weight:650;margin-top:6px}.form input,.form select{width:100%;border:1px solid #dce5df;border-radius:9px;padding:12px;font:inherit;background:white}.button{border:0;border-radius:9px;padding:12px 16px;background:#245c50;color:white;font:inherit;font-weight:650;cursor:pointer;margin-top:12px}.notice,.error{padding:12px 14px;border-radius:9px}.notice{background:#edf5ef;color:#245c50}.error{background:#fff3f1;color:#8c3434}.row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid #edf1ee}.row:last-child{border-bottom:0}.muted{color:#74817c;font-size:12px}.tag{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef3ef;color:#53675e;font-size:11px}@media(max-width:760px){.layout{grid-template-columns:1fr}}
</style><link rel="stylesheet" href="assets/layout.css"><script src="assets/layout.js" defer></script>
</head>
<body class="has-app-sidebar">
<?php require_once dirname(__DIR__) . '/app/navigation.php'; renderCoachingSidebar($user, 'appointments', $token); ?>
<main class="page">

<h1>Terminverwaltung</h1><p>Plane Termine, ändere sie bei Bedarf und behalte den bisherigen Verlauf pro Klient im Blick.</p>
<?php if ($message !== ''): ?><p class="notice" role="status"><?= appointmentH($message) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= appointmentH($error) ?></p><?php endif; ?>
<div class="layout"><section class="card"><h2><?= (int) $values['id'] > 0 ? 'Termin bearbeiten' : 'Termin anlegen' ?></h2>
<?php if ($missing !== []): ?><p>Die Tabelle termine enthält nicht alle für die Terminverwaltung erforderlichen Felder.</p><?php elseif ($clients === []): ?><p>Lege zuerst einen Klienten an, bevor du einen Termin planst.</p><?php else: ?>
<form method="post" class="form"><input type="hidden" name="csrf_token" value="<?= appointmentH($token) ?>"><input type="hidden" name="id" value="<?= appointmentH((int) $values['id']) ?>">
<label for="klient_id">Klient</label><select id="klient_id" name="klient_id" required><option value="">Bitte auswählen</option><?php foreach ($clients as $client): ?><option value="<?= appointmentH($client['id']) ?>" <?= (int) $values['klient_id'] === (int) $client['id'] ? 'selected' : '' ?>><?= appointmentH($client['vorname'] . ' ' . $client['nachname']) ?></option><?php endforeach; ?></select>
<label for="beginn">Datum und Uhrzeit</label><input id="beginn" name="beginn" type="datetime-local" required value="<?= appointmentH($values['beginn']) ?>">
<label for="dauer_minuten">Dauer (Minuten)</label><input id="dauer_minuten" name="dauer_minuten" type="number" min="5" max="600" step="5" required value="<?= appointmentH($values['dauer_minuten']) ?>">
<label for="format">Format</label><select id="format" name="format" required><?php foreach ($formatOptions as $option): ?><option value="<?= appointmentH($option) ?>" <?= $values['format'] === $option ? 'selected' : '' ?>><?= appointmentH($formatLabels[$option] ?? $option) ?></option><?php endforeach; ?></select>
<?php if ($topicColumn !== null): ?><label for="thema">Thema (optional)</label><input id="thema" name="thema" maxlength="190" value="<?= appointmentH($values['thema'] ?? '') ?>"><?php endif; ?>
<label for="status">Status</label><select id="status" name="status" required><?php foreach ($statusOptions as $option): ?><option value="<?= appointmentH($option) ?>" <?= $values['status'] === $option ? 'selected' : '' ?>><?= appointmentH($statusLabels[$option] ?? ucfirst($option)) ?></option><?php endforeach; ?></select>
<button class="button" type="submit">Termin speichern</button><?php if ((int) $values['id'] > 0): ?> <a href="appointments.php">Abbrechen</a><?php endif; ?>
</form><?php endif; ?></section>
<section class="card"><h2>Terminverlauf (<?= count($appointments) ?>)</h2><?php if ($appointments === []): ?><p>Noch keine Termine vorhanden.</p><?php else: ?><?php foreach ($appointments as $appointment): ?><div class="row"><div><strong><?= appointmentH(date('d.m.Y · H:i', strtotime((string) $appointment['beginn']))) ?></strong><div><?= appointmentH($appointment['vorname'] . ' ' . $appointment['nachname']) ?><?php if (!empty($appointment['thema'])): ?> · <?= appointmentH($appointment['thema']) ?><?php endif; ?></div><span class="muted"><?= appointmentH((string) $appointment['dauer_minuten']) ?> Min. · <?= appointmentH($formatLabels[$appointment['format']] ?? $appointment['format']) ?> · <?= appointmentH($statusLabels[$appointment['status']] ?? $appointment['status']) ?></span></div><div><a href="appointments.php?edit=<?= appointmentH($appointment['id']) ?>">Bearbeiten</a><?php if ($user['role'] === 'coach'): ?> · <a href="notes.php?termin_id=<?= appointmentH($appointment['id']) ?>">Notizen</a><?php endif; ?></div></div><?php endforeach; ?><?php endif; ?></section></div>
<p><a href="./">Zur Übersicht</a></p>
</main></body></html>
