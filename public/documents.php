<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/document_storage.php';

function documentH(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || ($user['role'] ?? '') !== 'coach' || !isset($user['id'])) {
    http_response_code(403);
    exit('Die Dokumentenverwaltung ist nur für Coaches zugänglich.');
}

$database = coachingDatabase();
$coachId = (int) $user['id'];
$token = $_SESSION['csrf_token'] ?? '';
if (!is_string($token) || strlen($token) !== 64) {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
}
$error = '';
$notice = isset($_GET['saved']) ? 'Die Änderung wurde gespeichert.' : '';
$qrFlash = $_SESSION['document_sign_flash'] ?? null;
unset($_SESSION['document_sign_flash']);
if (!is_array($qrFlash)) $qrFlash = null;

$clientsQuery = $database->prepare('SELECT id, vorname, nachname FROM klienten WHERE benutzer_id = :coach ORDER BY nachname, vorname');
$clientsQuery->execute(['coach' => $coachId]);
$clients = $clientsQuery->fetchAll(PDO::FETCH_ASSOC);
$selectedClient = filter_var($_GET['klient_id'] ?? 0, FILTER_VALIDATE_INT);
$selectedClient = $selectedClient === false ? 0 : (int) $selectedClient;
if ($selectedClient > 0 && !in_array($selectedClient, array_map(static fn(array $row): int => (int) $row['id'], $clients), true)) {
    $selectedClient = 0;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($postedToken) || !hash_equals($token, $postedToken)) {
            throw new InvalidArgumentException('Die Anfrage ist abgelaufen. Bitte lade die Seite neu.');
        }
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        if ($action === 'upload_template') {
            $title = trim(is_string($_POST['titel'] ?? null) ? $_POST['titel'] : '');
            $category = trim(is_string($_POST['kategorie'] ?? null) ? $_POST['kategorie'] : '');
            if ($title === '' || strlen($title) > 190 || $category === '' || strlen($category) > 60) {
                throw new InvalidArgumentException('Bitte Titel und Kategorie eintragen.');
            }
            $file = coachingStoreDocumentUpload($_FILES['datei'] ?? [], ['application/pdf']);
            try {
                $versionQuery = $database->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM coaching_dokumentvorlagen WHERE coach_benutzer_id = :coach AND titel = :title');
                $versionQuery->execute(['coach' => $coachId, 'title' => $title]);
                $version = (int) $versionQuery->fetchColumn();
                $insert = $database->prepare('INSERT INTO coaching_dokumentvorlagen (coach_benutzer_id, titel, kategorie, version, originalname, speicher_schluessel, mime_typ, groesse_bytes) VALUES (:coach, :title, :category, :version, :name, :key, :mime, :size)');
                $insert->execute(['coach' => $coachId, 'title' => $title, 'category' => $category, 'version' => $version, 'name' => $file['name'], 'key' => $file['key'], 'mime' => $file['mime'], 'size' => $file['size']]);
            } catch (Throwable $exception) {
                @unlink($file['path']);
                throw $exception;
            }
            header('Location: documents.php?saved=1');
            exit;
        }

        if ($action === 'assign_template') {
            $templateId = filter_var($_POST['vorlage_id'] ?? null, FILTER_VALIDATE_INT);
            $clientId = filter_var($_POST['klient_id'] ?? null, FILTER_VALIDATE_INT);
            if ($templateId === false || $clientId === false || (int) $templateId < 1 || (int) $clientId < 1) {
                throw new InvalidArgumentException('Bitte Vorlage und Klient auswählen.');
            }
            $clientCheck = $database->prepare('SELECT id FROM klienten WHERE id = :client AND benutzer_id = :coach');
            $clientCheck->execute(['client' => (int) $clientId, 'coach' => $coachId]);
            if (!$clientCheck->fetchColumn()) throw new InvalidArgumentException('Der Klient gehört nicht zu deinem Zugang.');

            $templateQuery = $database->prepare('SELECT id, titel, version, originalname, speicher_schluessel FROM coaching_dokumentvorlagen WHERE id = :id AND coach_benutzer_id = :coach AND aktiv = 1');
            $templateQuery->execute(['id' => (int) $templateId, 'coach' => $coachId]);
            $template = $templateQuery->fetch(PDO::FETCH_ASSOC);
            if (!$template) throw new InvalidArgumentException('Die Vorlage wurde nicht gefunden oder ist nicht mehr aktiv.');

            $insert = $database->prepare("INSERT INTO coaching_klientendokumente (klient_id, coach_benutzer_id, vorlage_id, vorlage_version, vorlage_speicher_schluessel, vorlage_originalname, titel, status) VALUES (:client, :coach, :template, :version, :key, :name, :title, 'zugewiesen')");
            $insert->execute(['client' => (int) $clientId, 'coach' => $coachId, 'template' => $template['id'], 'version' => $template['version'], 'key' => $template['speicher_schluessel'], 'name' => $template['originalname'], 'title' => $template['titel']]);
            header('Location: documents.php?klient_id=' . (int) $clientId . '&saved=1');
            exit;
        }

        if ($action === 'mark_issued') {
            $documentId = filter_var($_POST['dokument_id'] ?? null, FILTER_VALIDATE_INT);
            if ($documentId === false || (int) $documentId < 1) throw new InvalidArgumentException('Ungültige Dokumentenzuweisung.');
            $update = $database->prepare("UPDATE coaching_klientendokumente SET status = 'ausgegeben', ausgegeben_am = COALESCE(ausgegeben_am, CURRENT_TIMESTAMP) WHERE id = :id AND coach_benutzer_id = :coach AND status IN ('zugewiesen', 'ausgegeben')");
            $update->execute(['id' => (int) $documentId, 'coach' => $coachId]);
            if ($update->rowCount() < 1) throw new InvalidArgumentException('Die Zuweisung wurde nicht gefunden oder ist bereits zurückgekommen.');
            header('Location: documents.php?saved=1');
            exit;
        }

        if ($action === 'upload_return') {
            $documentId = filter_var($_POST['dokument_id'] ?? null, FILTER_VALIDATE_INT);
            if ($documentId === false || (int) $documentId < 1) throw new InvalidArgumentException('Ungültige Dokumentenzuweisung.');
            $check = $database->prepare("SELECT id FROM coaching_klientendokumente WHERE id = :id AND coach_benutzer_id = :coach AND status <> 'archiviert'");
            $check->execute(['id' => (int) $documentId, 'coach' => $coachId]);
            if (!$check->fetchColumn()) throw new InvalidArgumentException('Die Dokumentenzuweisung wurde nicht gefunden.');
            $file = coachingStoreDocumentUpload($_FILES['datei'] ?? [], ['application/pdf', 'image/jpeg', 'image/png']);
            try {
                $database->beginTransaction();
                $versionQuery = $database->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM coaching_dokumentdateien WHERE klientendokument_id = :id AND coach_benutzer_id = :coach');
                $versionQuery->execute(['id' => (int) $documentId, 'coach' => $coachId]);
                $version = (int) $versionQuery->fetchColumn();
                $insert = $database->prepare('INSERT INTO coaching_dokumentdateien (klientendokument_id, coach_benutzer_id, speicher_schluessel, originalname, mime_typ, groesse_bytes, version) VALUES (:document, :coach, :key, :name, :mime, :size, :version)');
                $insert->execute(['document' => (int) $documentId, 'coach' => $coachId, 'key' => $file['key'], 'name' => $file['name'], 'mime' => $file['mime'], 'size' => $file['size'], 'version' => $version]);
                $update = $database->prepare("UPDATE coaching_klientendokumente SET status = 'zurueckgekommen' WHERE id = :id AND coach_benutzer_id = :coach AND status <> 'archiviert'");
                $update->execute(['id' => (int) $documentId, 'coach' => $coachId]);
                if ($update->rowCount() < 1) throw new RuntimeException('Die Dokumentenzuweisung konnte nicht aktualisiert werden.');
                $database->commit();
            } catch (Throwable $exception) {
                if ($database->inTransaction()) $database->rollBack();
                @unlink($file['path']);
                throw $exception;
            }
            header('Location: documents.php?saved=1');
            exit;
        }


        if ($action === 'create_sign_link') {
            if (!$isHttps) throw new InvalidArgumentException('Für QR-Unterschriften musst du das Coaching Tool über HTTPS öffnen.');
            $documentId = filter_var($_POST['dokument_id'] ?? null, FILTER_VALIDATE_INT);
            if ($documentId === false || (int) $documentId < 1) throw new InvalidArgumentException('Ungültige Dokumentenzuweisung.');
            $documentQuery = $database->prepare("SELECT d.id, d.status, d.titel, v.mime_typ FROM coaching_klientendokumente d INNER JOIN klienten k ON k.id = d.klient_id INNER JOIN coaching_dokumentvorlagen v ON v.id = d.vorlage_id AND v.coach_benutzer_id = d.coach_benutzer_id WHERE d.id = :id AND d.coach_benutzer_id = :coach AND k.benutzer_id = :client_owner AND d.status IN ('zugewiesen', 'ausgegeben')");
            $documentQuery->execute(['id' => (int) $documentId, 'coach' => $coachId, 'client_owner' => $coachId]);
            $signDocument = $documentQuery->fetch(PDO::FETCH_ASSOC);
            if (!$signDocument || $signDocument['mime_typ'] !== 'application/pdf') {
                throw new InvalidArgumentException('Nur aktive Klientendokumente mit PDF-Vorlage können per QR unterschrieben werden.');
            }
            $existingFiles = $database->prepare('SELECT id FROM coaching_dokumentdateien WHERE klientendokument_id = :id AND coach_benutzer_id = :coach LIMIT 1');
            $existingFiles->execute(['id' => (int) $documentId, 'coach' => $coachId]);
            if ($existingFiles->fetchColumn()) throw new InvalidArgumentException('Für dieses Dokument liegt bereits ein Rücklauf vor.');

            $serverHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $parsedHost = $serverHost !== '' ? parse_url('https://' . $serverHost) : false;
            $host = is_array($parsedHost) ? (string) ($parsedHost['host'] ?? '') : '';
            $port = is_array($parsedHost) ? (int) ($parsedHost['port'] ?? 0) : 0;
            $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/^[a-z0-9.-]+$/iD', $host) === 1;
            if (!$validHost || $port > 65535 || isset($parsedHost['user']) || isset($parsedHost['pass']) || isset($parsedHost['path']) || isset($parsedHost['query']) || isset($parsedHost['fragment'])) throw new InvalidArgumentException('Die sichere Serveradresse konnte nicht ermittelt werden. Öffne die Seite über ihre HTTPS-Adresse.');
            $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
            if ($port > 0) $authority .= ':' . $port;
            $basePath = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/documents.php')));
            $basePath = $basePath === '.' || $basePath === '/' ? '' : rtrim($basePath, '/');
            $token = bin2hex(random_bytes(32));
            $code = sprintf('%06d', random_int(0, 999999));
            $codeHash = password_hash($code, PASSWORD_DEFAULT);
            if (!is_string($codeHash)) throw new RuntimeException('Der Prüfcode konnte nicht erstellt werden.');

            $database->beginTransaction();
            try {
                $revoke = $database->prepare('UPDATE coaching_dokument_signaturanfragen SET widerrufen_am = CURRENT_TIMESTAMP WHERE klientendokument_id = :document AND coach_benutzer_id = :coach AND signiert_am IS NULL AND widerrufen_am IS NULL');
                $revoke->execute(['document' => (int) $documentId, 'coach' => $coachId]);
                $insert = $database->prepare('INSERT INTO coaching_dokument_signaturanfragen (klientendokument_id, coach_benutzer_id, token_hash, code_hash, ablauf_am) VALUES (:document, :coach, :token_hash, :code_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))');
                $insert->execute(['document' => (int) $documentId, 'coach' => $coachId, 'token_hash' => hash('sha256', $token), 'code_hash' => $codeHash]);
                $signRequestId = (int) $database->lastInsertId();
                $expiryQuery = $database->prepare('SELECT ablauf_am FROM coaching_dokument_signaturanfragen WHERE id = :id AND coach_benutzer_id = :coach');
                $expiryQuery->execute(['id' => $signRequestId, 'coach' => $coachId]);
                $expiresAt = (string) $expiryQuery->fetchColumn();
                $database->commit();
            } catch (Throwable $exception) {
                if ($database->inTransaction()) $database->rollBack();
                throw $exception;
            }
            $signUrl = 'https://' . $authority . $basePath . '/document-sign.php#token=' . $token;
            $_SESSION['document_sign_flash'] = ['request_id' => $signRequestId, 'token' => $token, 'code' => $code, 'url' => $signUrl, 'expires' => $expiresAt, 'title' => (string) $signDocument['titel']];
            header('Location: documents.php?klient_id=' . (int) ($selectedClient > 0 ? $selectedClient : 0) . '&sign_ready=1');
            exit;
        }

        if ($action === 'revoke_sign_link') {
            $requestId = filter_var($_POST['signaturanfrage_id'] ?? null, FILTER_VALIDATE_INT);
            if ($requestId === false || (int) $requestId < 1) throw new InvalidArgumentException('Ungültige Signaturanfrage.');
            $revoke = $database->prepare('UPDATE coaching_dokument_signaturanfragen SET widerrufen_am = CURRENT_TIMESTAMP WHERE id = :id AND coach_benutzer_id = :coach AND signiert_am IS NULL AND widerrufen_am IS NULL');
            $revoke->execute(['id' => (int) $requestId, 'coach' => $coachId]);
            if ($revoke->rowCount() < 1) throw new InvalidArgumentException('Die Signaturanfrage wurde nicht gefunden oder ist bereits abgeschlossen.');
            header('Location: documents.php?saved=1');
            exit;
        }

        throw new InvalidArgumentException('Die angeforderte Aktion ist nicht bekannt.');
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Coaching Tool document operation failed: ' . $exception->getMessage());
    $error = 'Das Dokument konnte nicht gespeichert werden. Prüfe SQL-Migration, Tabellenrechte und Schreibrechte des privaten Speicherordners.';
}

$templatesQuery = $database->prepare('SELECT id, titel, kategorie, version, originalname, groesse_bytes, erstellt_am FROM coaching_dokumentvorlagen WHERE coach_benutzer_id = :coach AND aktiv = 1 ORDER BY titel, version DESC');
$templatesQuery->execute(['coach' => $coachId]);
$templates = $templatesQuery->fetchAll(PDO::FETCH_ASSOC);

$assignedQuery = $database->prepare('SELECT d.id, d.klient_id, d.titel, d.vorlage_version, d.status, d.zugewiesen_am, d.ausgegeben_am, k.vorname, k.nachname FROM coaching_klientendokumente d INNER JOIN klienten k ON k.id = d.klient_id WHERE d.coach_benutzer_id = :coach AND k.benutzer_id = :client_owner' . ($selectedClient > 0 ? ' AND d.klient_id = :selected_client' : '') . ' ORDER BY d.zugewiesen_am DESC LIMIT 300');
$assignedParams = ['coach' => $coachId, 'client_owner' => $coachId];
if ($selectedClient > 0) $assignedParams['selected_client'] = $selectedClient;
$assignedQuery->execute($assignedParams);
$assignments = $assignedQuery->fetchAll(PDO::FETCH_ASSOC);

$fileQuery = $database->prepare('SELECT id, klientendokument_id, originalname, mime_typ, groesse_bytes, version, hochgeladen_am FROM coaching_dokumentdateien WHERE coach_benutzer_id = :coach ORDER BY klientendokument_id, version DESC');
$fileQuery->execute(['coach' => $coachId]);
$filesByDocument = [];
foreach ($fileQuery->fetchAll(PDO::FETCH_ASSOC) as $file) $filesByDocument[(int) $file['klientendokument_id']][] = $file;

$signatureQuery = $database->prepare('SELECT id, klientendokument_id, ablauf_am, code_versuche FROM coaching_dokument_signaturanfragen WHERE coach_benutzer_id = :coach AND signiert_am IS NULL AND widerrufen_am IS NULL AND ablauf_am > CURRENT_TIMESTAMP ORDER BY erstellt_am DESC');
$signatureQuery->execute(['coach' => $coachId]);
$activeSignatures = [];
foreach ($signatureQuery->fetchAll(PDO::FETCH_ASSOC) as $signatureRequest) {
    $docId = (int) $signatureRequest['klientendokument_id'];
    if (!isset($activeSignatures[$docId])) $activeSignatures[$docId] = $signatureRequest;
}

$formatBytes = static function (int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : number_format(max(1, $bytes / 1024), 0, ',', '.') . ' KB';
};
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dokumente · Coaching Tool</title>
<link rel="stylesheet" href="assets/layout.css"><script src="assets/layout.js" defer></script>
<style>
.document-page{max-width:1180px;margin:auto;padding:30px clamp(18px,4vw,56px) 70px}.document-page h1{font-size:clamp(30px,4vw,44px);letter-spacing:-.04em;margin:20px 0 8px}.document-intro{color:#65766f;line-height:1.6;max-width:760px}.document-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:24px}.document-card{background:#fff;border:1px solid #e5ebe6;border-radius:14px;padding:20px;box-shadow:0 8px 30px #263b3210}.document-card h2{margin:0 0 14px;font-size:18px}.document-form{display:grid;gap:9px}.document-form label{font-size:13px;font-weight:650;margin-top:4px}.document-form input,.document-form select{width:100%;border:1px solid #dce5df;border-radius:9px;padding:11px;font:inherit;background:#fff}.document-button{border:0;border-radius:9px;padding:11px 14px;background:#245c50;color:#fff;font:inherit;font-weight:650;cursor:pointer;width:max-content}.document-button.secondary{background:#edf4ef;color:#245c50}.document-alert{padding:12px 14px;border-radius:9px}.document-alert.error{background:#fff3f1;color:#8c3434}.document-alert.ok{background:#edf5ef;color:#245c50}.document-list{margin-top:22px}.document-row{padding:16px 0;border-bottom:1px solid #edf1ee;display:grid;gap:10px}.document-row:last-child{border:0}.document-row-top{display:flex;gap:12px;justify-content:space-between;align-items:flex-start}.document-meta{font-size:12px;color:#74817c;line-height:1.6}.document-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.document-actions a{color:#35695b}.document-actions form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.document-actions input[type=file]{max-width:240px}.document-chip{font-size:11px;border-radius:999px;background:#eef3ef;padding:4px 8px;white-space:nowrap}.document-empty{color:#74817c}.sign-share{margin:18px 0;padding:18px;border:1px solid #d5e4d9;background:#f2f7f3;border-radius:12px}.sign-share-grid{display:grid;grid-template-columns:180px 1fr;gap:18px;align-items:center}.sign-qr{width:180px;height:180px;background:#fff;padding:8px;border-radius:8px}.sign-code{font-size:24px;letter-spacing:.14em;font-weight:750;color:#245c50}.sign-url{display:block;max-width:100%;overflow-wrap:anywhere;font-size:12px}.sign-warning{font-size:12px;color:#65766f;line-height:1.5}@media(max-width:600px){.sign-share-grid{grid-template-columns:1fr}.sign-qr{margin:auto}}@media(max-width:800px){.document-grid{grid-template-columns:1fr}.document-row-top{display:block}.document-chip{display:inline-block;margin-top:8px}}
</style>
</head>
<body class="has-app-sidebar">
<?php require_once dirname(__DIR__) . '/app/navigation.php'; renderCoachingSidebar($user, 'documents', $token); ?>
<main class="document-page">
<h1>Dokumente</h1>
<?php if ($qrFlash !== null): ?>
<section class="document-card sign-share">
<h2>QR-Signatur für <?= documentH($qrFlash['title'] ?? '') ?></h2>
<div class="sign-share-grid"><div id="signature-qr" class="sign-qr" aria-label="QR-Code für die Unterschrift"></div><div>
<p>Der Link läuft am <?= documentH(date('d.m.Y H:i', strtotime((string) ($qrFlash['expires'] ?? 'now')))) ?> ab und kann nur einmal zum Unterschreiben verwendet werden.</p>
<p>Prüfcode: <strong class="sign-code"><?= documentH($qrFlash['code'] ?? '') ?></strong></p>
<p class="sign-warning">Zeige den QR-Code dem Klienten und teile den Prüfcode getrennt mit, zum Beispiel telefonisch. Der Code wird nach Verlassen dieser Seite nicht erneut angezeigt. Bei Bedarf kannst du einen neuen QR-Link erstellen oder einen aktiven Link in der Dokumentliste widerrufen.</p>
<a class="sign-url" href="<?= documentH($qrFlash['url'] ?? '') ?>"><?= documentH($qrFlash['url'] ?? '') ?></a>
</div></div>
<form class="document-actions" method="post"><input type="hidden" name="csrf_token" value="<?= documentH($token) ?>"><input type="hidden" name="action" value="revoke_sign_link"><input type="hidden" name="signaturanfrage_id" value="<?= documentH($qrFlash['request_id'] ?? '') ?>"><button class="document-button secondary" type="submit">QR-Link widerrufen</button></form>
</section>
<script src="assets/vendor/qrcode.min.js" defer></script><script>document.addEventListener('DOMContentLoaded',function(){var target=document.getElementById('signature-qr');if(target&&window.QRCode){new QRCode(target,{text:<?php echo json_encode((string) ($qrFlash['url'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,width:164,height:164,correctLevel:QRCode.CorrectLevel.M})}})</script>
<?php endif; ?>

<p class="document-intro">Lege eigene PDF-Vorlagen an, ordne eine konkrete Vorlagenversion einem Klienten zu und speichere die unterschriebene Rückgabe geschützt beim Klienten. Dateien sind nur für dich als zuständigen Coach erreichbar.</p>
<p class="document-intro">Alternativ kannst du eine PDF per QR-Code auf dem Handy unterschreiben lassen. Dazu braucht der Klient den QR-Code und einen Prüfcode, den du getrennt weitergibst. QR-Unterschriften benötigen HTTPS.</p>
<?php if ($notice !== ''): ?><p class="document-alert ok" role="status"><?= documentH($notice) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="document-alert error" role="alert"><?= documentH($error) ?></p><?php endif; ?>
<div class="document-grid">
<section class="document-card">
<h2>Neue PDF-Vorlage</h2>
<form class="document-form" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= documentH($token) ?>"><input type="hidden" name="action" value="upload_template">
<label for="template-title">Titel</label><input id="template-title" name="titel" maxlength="190" required placeholder="z. B. Coaching-Vertrag">
<label for="template-category">Kategorie</label><input id="template-category" name="kategorie" maxlength="60" required placeholder="Vertrag, Datenschutz, Arbeitsblatt">
<label for="template-file">PDF-Datei (max. 15 MB)</label><input id="template-file" type="file" name="datei" accept="application/pdf,.pdf" required>
<button class="document-button" type="submit">Vorlage speichern</button>
</form>
</section>
<section class="document-card">
<h2>Vorlage einem Klienten zuweisen</h2>
<?php if ($templates === [] || $clients === []): ?><p class="document-empty"><?= $templates === [] ? 'Lege zuerst eine PDF-Vorlage an.' : 'Lege zuerst einen Klienten an.' ?></p><?php else: ?>
<form class="document-form" method="post">
<input type="hidden" name="csrf_token" value="<?= documentH($token) ?>"><input type="hidden" name="action" value="assign_template">
<label for="assign-template">Vorlage</label><select id="assign-template" name="vorlage_id" required><option value="">Bitte auswählen</option><?php foreach ($templates as $template): ?><option value="<?= documentH($template['id']) ?>"><?= documentH($template['titel'] . ' · Version ' . $template['version']) ?></option><?php endforeach; ?></select>
<label for="assign-client">Klient</label><select id="assign-client" name="klient_id" required><option value="">Bitte auswählen</option><?php foreach ($clients as $client): ?><option value="<?= documentH($client['id']) ?>" <?= $selectedClient === (int) $client['id'] ? 'selected' : '' ?>><?= documentH($client['vorname'] . ' ' . $client['nachname']) ?></option><?php endforeach; ?></select>
<button class="document-button" type="submit">Dokument zuweisen</button>
</form><?php endif; ?>
</section>
</div>
<section class="document-card document-list">
<div class="document-row-top"><h2>Vorlagen (<?= count($templates) ?>)</h2></div>
<?php if ($templates === []): ?><p class="document-empty">Noch keine Vorlagen vorhanden.</p><?php else: ?>
<?php foreach ($templates as $template): ?><article class="document-row"><div class="document-row-top"><strong><?= documentH($template['titel']) ?> <span class="document-chip">Version <?= documentH($template['version']) ?></span></strong><a href="document-download.php?type=template&id=<?= documentH($template['id']) ?>">PDF herunterladen</a></div><div class="document-meta"><?= documentH($template['kategorie']) ?> · <?= documentH($template['originalname']) ?> · <?= documentH($formatBytes((int) $template['groesse_bytes'])) ?> · <?= documentH(date('d.m.Y', strtotime($template['erstellt_am']))) ?></div></article><?php endforeach; ?>
<?php endif; ?>
</section>
<section class="document-card document-list">
<div class="document-row-top"><h2>Klientendokumente (<?= count($assignments) ?>)</h2><form method="get"><label class="document-meta" for="client-filter">Klient </label><select id="client-filter" name="klient_id" onchange="this.form.submit()"><option value="">Alle</option><?php foreach ($clients as $client): ?><option value="<?= documentH($client['id']) ?>" <?= $selectedClient === (int) $client['id'] ? 'selected' : '' ?>><?= documentH($client['vorname'] . ' ' . $client['nachname']) ?></option><?php endforeach; ?></select></form></div>
<?php if ($assignments === []): ?><p class="document-empty">Noch keine Dokumente zugewiesen.</p><?php else: ?>
<?php foreach ($assignments as $assignment): ?><article class="document-row">
<div class="document-row-top"><div><strong><?= documentH($assignment['titel']) ?></strong><div class="document-meta"><?= documentH($assignment['vorname'] . ' ' . $assignment['nachname']) ?> · Vorlage Version <?= documentH($assignment['vorlage_version']) ?> · zugewiesen <?= documentH(date('d.m.Y', strtotime($assignment['zugewiesen_am']))) ?></div></div><span class="document-chip"><?= documentH(match ($assignment['status']) {'zugewiesen' => 'Zugewiesen', 'ausgegeben' => 'Ausgegeben', 'zurueckgekommen' => 'Zurückgekommen', 'archiviert' => 'Archiviert', default => $assignment['status']}) ?></span></div>
<div class="document-actions"><a href="document-download.php?type=assigned-template&id=<?= documentH($assignment['id']) ?>">Zugewiesene Vorlage herunterladen</a>
<?php $documentId = (int) $assignment['id']; $activeSign = $activeSignatures[$documentId] ?? null; $hasReturnFile = !empty($filesByDocument[$documentId]); ?>
<?php if (in_array($assignment['status'], ['zugewiesen', 'ausgegeben'], true) && !$hasReturnFile): ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= documentH($token) ?>"><input type="hidden" name="action" value="create_sign_link"><input type="hidden" name="dokument_id" value="<?= documentH($assignment['id']) ?>"><button class="document-button" type="submit">QR-Code zum Unterschreiben</button></form>
<?php if ($activeSign !== null): ?><span class="document-meta">Aktiver QR-Link bis <?= documentH(date('d.m.Y H:i', strtotime($activeSign['ablauf_am']))) ?> · <?= (int) $activeSign['code_versuche'] ?>/5 Prüfversuche</span><form method="post"><input type="hidden" name="csrf_token" value="<?= documentH($token) ?>"><input type="hidden" name="action" value="revoke_sign_link"><input type="hidden" name="signaturanfrage_id" value="<?= documentH($activeSign['id']) ?>"><button class="document-button secondary" type="submit">QR-Link widerrufen</button></form><?php endif; ?>
<?php endif; ?>

<?php if (in_array($assignment['status'], ['zugewiesen', 'ausgegeben'], true)): ?>
<?php if ($assignment['status'] === 'zugewiesen'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= documentH($token) ?>"><input type="hidden" name="action" value="mark_issued"><input type="hidden" name="dokument_id" value="<?= documentH($assignment['id']) ?>"><button class="document-button secondary" type="submit">Als ausgegeben markieren</button></form><?php endif; ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= documentH($token) ?>"><input type="hidden" name="action" value="upload_return"><input type="hidden" name="dokument_id" value="<?= documentH($assignment['id']) ?>"><label class="document-meta" for="return-<?= documentH($assignment['id']) ?>">Rücklauf (PDF/JPG/PNG)</label><input id="return-<?= documentH($assignment['id']) ?>" type="file" name="datei" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" required><button class="document-button" type="submit">Rücklauf speichern</button></form>
<?php endif; ?>
</div>
<?php foreach ($filesByDocument[(int) $assignment['id']] ?? [] as $signedFile): ?><div class="document-meta">Rücklauf Version <?= documentH($signedFile['version']) ?> · <?= documentH($signedFile['originalname']) ?> · <?= documentH($formatBytes((int) $signedFile['groesse_bytes'])) ?> · <?= documentH(date('d.m.Y H:i', strtotime($signedFile['hochgeladen_am']))) ?> · <a href="document-download.php?type=return&id=<?= documentH($signedFile['id']) ?>">geschützt herunterladen</a></div><?php endforeach; ?>
</article><?php endforeach; ?>
<?php endif; ?>
</section>
<p class="document-meta">Speicherort: privater Ordner außerhalb von <code>public/</code>. Die Dateien sind nicht über direkte öffentliche Links erreichbar.</p>
</main></body></html>
