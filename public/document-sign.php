<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_document_sign_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/document_storage.php';

function signingH(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function signingJson(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function signingSendPdf(string $path, string $filename, bool $inline): never
{
    $fileSize = filesize($path);
    if (!is_int($fileSize) || $fileSize < 1) {
        http_response_code(404);
        exit('Dokument nicht gefunden.');
    }

    $start = 0;
    $end = $fileSize - 1;
    $status = 200;
    $rangeHeader = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
    if ($rangeHeader !== '') {
        if (preg_match('~^bytes=([0-9]*)-([0-9]*)$~D', $rangeHeader, $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }

        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            if ($suffixLength < 1) {
                http_response_code(416);
                header('Content-Range: bytes */' . $fileSize);
                exit;
            }
            $start = max(0, $fileSize - $suffixLength);
        } else {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $fileSize - 1 : min((int) $matches[2], $fileSize - 1);
        }

        if ($start >= $fileSize || $start > $end) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        $status = 206;
    }

    $length = $end - $start + 1;
    if ($inline) {
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self';");
    } else {
        header('X-Frame-Options: DENY');
    }
    header('Accept-Ranges: bytes');
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) $length);
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)) ?: 'Dokument.pdf';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeFilename . '"');
    if ($status === 206) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        $handle = fopen($path, 'rb');
        if ($handle === false || fseek($handle, $start) !== 0) {
            if (is_resource($handle)) fclose($handle);
            http_response_code(500);
            exit;
        }
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
    }
    exit;
}

if (!$isHttps) {
    http_response_code(400);
    ?><!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HTTPS erforderlich</title><body style="font-family:system-ui;max-width:620px;margin:10vh auto;padding:24px"><h1>Verschlüsselte Verbindung erforderlich</h1><p>Das Unterschreiben per QR-Code funktioniert nur über HTTPS. Öffne das Coaching Tool über seine sichere HTTPS-Adresse und erstelle dort einen neuen QR-Code.</p></body></html><?php
    exit;
}

$database = coachingDatabase();

/*
 * The QR URL keeps its random capability in the fragment (#token=...). Browsers
 * do not send fragments in HTTP requests or server access logs. The page posts
 * it once to this endpoint, then the session carries the verified request.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sign_action'] ?? '') === 'init') {
    $rawToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        $_SESSION['sign_error'] = 'Dieser Signaturlink ist ungültig oder abgelaufen.';
        header('Location: ' . (string) ($_SERVER['SCRIPT_NAME'] ?? 'document-sign.php'), true, 303);
        exit;
    }
    $tokenHash = hash('sha256', $rawToken);
    $lookup = $database->prepare("SELECT id FROM coaching_dokument_signaturanfragen WHERE token_hash = :hash AND ablauf_am > CURRENT_TIMESTAMP AND widerrufen_am IS NULL AND signiert_am IS NULL");
    $lookup->execute(['hash' => $tokenHash]);
    $requestId = $lookup->fetchColumn();
    if (!$requestId) {
        $_SESSION['sign_error'] = 'Dieser Signaturlink ist ungültig oder abgelaufen.';
        header('Location: ' . (string) ($_SERVER['SCRIPT_NAME'] ?? 'document-sign.php'), true, 303);
        exit;
    }
    session_regenerate_id(true);
    $_SESSION['sign_request_id'] = (int) $requestId;
    $_SESSION['sign_token_hash'] = $tokenHash;
    $_SESSION['sign_code_verified'] = false;
    $_SESSION['sign_csrf'] = bin2hex(random_bytes(32));
    header('Location: ' . (string) ($_SERVER['SCRIPT_NAME'] ?? 'document-sign.php'), true, 303);
    exit;
}

$request = null;
$requestId = filter_var($_SESSION['sign_request_id'] ?? null, FILTER_VALIDATE_INT);
$sessionTokenHash = $_SESSION['sign_token_hash'] ?? '';
if ($requestId !== false && (int) $requestId > 0 && is_string($sessionTokenHash) && preg_match('/^[a-f0-9]{64}$/', $sessionTokenHash)) {
    $requestQuery = $database->prepare("SELECT r.id, r.klientendokument_id, r.coach_benutzer_id, r.token_hash, r.code_hash, r.ablauf_am, r.code_versuche, r.signierende_person, r.original_sha256, r.signiert_sha256, r.signierte_datei_id, r.signiert_am, r.widerrufen_am, d.klient_id, d.titel, d.vorlage_speicher_schluessel, d.vorlage_originalname, v.mime_typ, k.vorname, k.nachname FROM coaching_dokument_signaturanfragen r INNER JOIN coaching_klientendokumente d ON d.id = r.klientendokument_id AND d.coach_benutzer_id = r.coach_benutzer_id INNER JOIN coaching_dokumentvorlagen v ON v.id = d.vorlage_id AND v.coach_benutzer_id = d.coach_benutzer_id INNER JOIN klienten k ON k.id = d.klient_id WHERE r.id = :id AND r.token_hash = :token_hash AND k.benutzer_id = r.coach_benutzer_id");
    $requestQuery->execute(['id' => (int) $requestId, 'token_hash' => $sessionTokenHash]);
    $request = $requestQuery->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($request !== null && isset($_GET['source']) && $_GET['source'] === '1') {
    if (empty($_SESSION['sign_code_verified']) || $request['signiert_am'] !== null || $request['widerrufen_am'] !== null || strtotime((string) $request['ablauf_am']) <= time()) {
        http_response_code(403);
        exit('Kein Zugriff.');
    }
    try {
        $sourcePath = coachingResolveDocumentFile((string) $request['vorlage_speicher_schluessel'], (string) $request['mime_typ']);
        signingSendPdf($sourcePath, 'Vorlage.pdf', true);
    } catch (Throwable $exception) {
        error_log('Coaching Tool signing source download failed: ' . $exception->getMessage());
        http_response_code(404);
        exit('Dokument nicht gefunden.');
    }
}

if ($request !== null && isset($_GET['download']) && $_GET['download'] === '1') {
    if ($request['signiert_am'] === null || empty($_SESSION['sign_code_verified']) || $request['signierte_datei_id'] === null) {
        http_response_code(403);
        exit('Kein Zugriff.');
    }
    $signedQuery = $database->prepare('SELECT speicher_schluessel, originalname, mime_typ FROM coaching_dokumentdateien WHERE id = :id AND klientendokument_id = :document AND coach_benutzer_id = :coach');
    $signedQuery->execute(['id' => (int) $request['signierte_datei_id'], 'document' => (int) $request['klientendokument_id'], 'coach' => (int) $request['coach_benutzer_id']]);
    $signedFile = $signedQuery->fetch(PDO::FETCH_ASSOC);
    if (!$signedFile || $signedFile['mime_typ'] !== 'application/pdf') {
        http_response_code(404);
        exit('Dokument nicht gefunden.');
    }
    try {
        signingSendPdf(coachingResolveDocumentFile((string) $signedFile['speicher_schluessel'], (string) $signedFile['mime_typ']), (string) $signedFile['originalname'], false);
    } catch (Throwable $exception) {
        error_log('Coaching Tool signed PDF download failed: ' . $exception->getMessage());
        http_response_code(404);
        exit('Dokument nicht gefunden.');
    }
}

$error = is_string($_SESSION['sign_error'] ?? null) ? $_SESSION['sign_error'] : '';
unset($_SESSION['sign_error']);
$csrf = $_SESSION['sign_csrf'] ?? '';
if (!is_string($csrf) || strlen($csrf) !== 64) {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['sign_csrf'] = $csrf;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sign_action']) && $_POST['sign_action'] !== 'init') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!is_string($postedCsrf) || !hash_equals($csrf, $postedCsrf) || $request === null) {
        signingJson(['ok' => false, 'message' => 'Die Anfrage ist ungültig. Bitte lade die Seite neu.'], 400);
    }
    if ($_POST['sign_action'] === 'verify') {
        if ($request['signiert_am'] !== null || $request['widerrufen_am'] !== null || strtotime((string) $request['ablauf_am']) <= time() || (int) $request['code_versuche'] >= 5) {
            $_SESSION['sign_error'] = 'Dieser Signaturlink ist abgelaufen oder gesperrt. Bitte wende dich an deinen Coach.';
        } else {
            $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
            if (preg_match('/^[0-9]{6}$/', $code) && password_verify($code, (string) $request['code_hash'])) {
                $_SESSION['sign_code_verified'] = true;
                header('Location: ' . (string) ($_SERVER['SCRIPT_NAME'] ?? 'document-sign.php'), true, 303);
                exit;
            }
            $attempt = $database->prepare('UPDATE coaching_dokument_signaturanfragen SET code_versuche = code_versuche + 1 WHERE id = :id AND token_hash = :hash AND code_versuche < 5 AND signiert_am IS NULL AND widerrufen_am IS NULL');
            $attempt->execute(['id' => (int) $request['id'], 'hash' => $sessionTokenHash]);
            $_SESSION['sign_error'] = 'Der Prüfcode stimmt nicht. Nach fünf Fehlversuchen wird der Link gesperrt.';
        }
        header('Location: ' . (string) ($_SERVER['SCRIPT_NAME'] ?? 'document-sign.php'), true, 303);
        exit;
    }

    if ($_POST['sign_action'] === 'complete') {
        if (empty($_SESSION['sign_code_verified']) || $request['signiert_am'] !== null || $request['widerrufen_am'] !== null || strtotime((string) $request['ablauf_am']) <= time()) {
            signingJson(['ok' => false, 'message' => 'Dieser Signaturlink ist abgelaufen oder wurde bereits verwendet.'], 410);
        }
        $signerName = trim(is_string($_POST['signer_name'] ?? null) ? $_POST['signer_name'] : '');
        if ($signerName === '' || strlen($signerName) > 190) {
            signingJson(['ok' => false, 'message' => 'Bitte gib deinen vollständigen Namen ein.'], 422);
        }
        $submittedHash = is_string($_POST['source_sha256'] ?? null) ? strtolower($_POST['source_sha256']) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $submittedHash)) {
            signingJson(['ok' => false, 'message' => 'Das Dokument konnte nicht eindeutig zugeordnet werden. Bitte lade die Seite neu.'], 422);
        }

        $upload = null;
        try {
            $sourcePath = coachingResolveDocumentFile((string) $request['vorlage_speicher_schluessel'], (string) $request['mime_typ']);
            $actualSourceHash = hash_file('sha256', $sourcePath);
            if (!is_string($actualSourceHash) || !hash_equals($actualSourceHash, $submittedHash)) {
                signingJson(['ok' => false, 'message' => 'Die angezeigte Vorlage hat sich geändert. Bitte wende dich an deinen Coach.'], 409);
            }
            $upload = coachingStoreDocumentUpload($_FILES['signed_pdf'] ?? [], ['application/pdf']);
            $signedHash = hash_file('sha256', $upload['path']);
            if (!is_string($signedHash)) throw new RuntimeException('Die signierte PDF konnte nicht geprüft werden.');

            $database->beginTransaction();
            $lock = $database->prepare('SELECT id, signiert_am, widerrufen_am, ablauf_am FROM coaching_dokument_signaturanfragen WHERE id = :id AND coach_benutzer_id = :coach AND token_hash = :hash FOR UPDATE');
            $lock->execute(['id' => (int) $request['id'], 'coach' => (int) $request['coach_benutzer_id'], 'hash' => $sessionTokenHash]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$locked || $locked['signiert_am'] !== null || $locked['widerrufen_am'] !== null || strtotime((string) $locked['ablauf_am']) <= time()) {
                throw new InvalidArgumentException('Dieser Signaturlink ist abgelaufen oder wurde bereits verwendet.');
            }

            $versionQuery = $database->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM coaching_dokumentdateien WHERE klientendokument_id = :document AND coach_benutzer_id = :coach');
            $versionQuery->execute(['document' => (int) $request['klientendokument_id'], 'coach' => (int) $request['coach_benutzer_id']]);
            $version = (int) $versionQuery->fetchColumn();
            $filename = substr((string) $request['titel'], 0, 225) . ' - unterschrieben.pdf';
            $insertFile = $database->prepare("INSERT INTO coaching_dokumentdateien (klientendokument_id, coach_benutzer_id, speicher_schluessel, originalname, mime_typ, groesse_bytes, version) VALUES (:document, :coach, :key, :name, 'application/pdf', :size, :version)");
            $insertFile->execute(['document' => (int) $request['klientendokument_id'], 'coach' => (int) $request['coach_benutzer_id'], 'key' => $upload['key'], 'name' => $filename, 'size' => $upload['size'], 'version' => $version]);
            $signedFileId = (int) $database->lastInsertId();

            $updateDocument = $database->prepare("UPDATE coaching_klientendokumente SET status = 'zurueckgekommen' WHERE id = :id AND coach_benutzer_id = :coach AND status <> 'archiviert'");
            $updateDocument->execute(['id' => (int) $request['klientendokument_id'], 'coach' => (int) $request['coach_benutzer_id']]);
            if ($updateDocument->rowCount() < 1) throw new InvalidArgumentException('Das zugewiesene Dokument ist nicht mehr aktiv.');

            $updateRequest = $database->prepare('UPDATE coaching_dokument_signaturanfragen SET signierende_person = :name, original_sha256 = :source_hash, signiert_sha256 = :signed_hash, signierte_datei_id = :file_id, signiert_am = CURRENT_TIMESTAMP WHERE id = :id AND coach_benutzer_id = :coach AND signiert_am IS NULL AND widerrufen_am IS NULL');
            $updateRequest->execute(['name' => $signerName, 'source_hash' => $actualSourceHash, 'signed_hash' => $signedHash, 'file_id' => $signedFileId, 'id' => (int) $request['id'], 'coach' => (int) $request['coach_benutzer_id']]);
            if ($updateRequest->rowCount() < 1) throw new InvalidArgumentException('Der Signaturvorgang wurde bereits abgeschlossen.');
            $database->commit();

            $_SESSION['sign_completed'] = true;
            signingJson(['ok' => true, 'message' => 'Unterschrift gespeichert. Die unterschriebene PDF ist jetzt bereit.', 'download_url' => 'document-sign.php?download=1']);
        } catch (Throwable $exception) {
            if ($database->inTransaction()) $database->rollBack();
            if (is_array($upload) && isset($upload['path']) && is_file($upload['path'])) @unlink($upload['path']);
            if ($exception instanceof InvalidArgumentException) {
                signingJson(['ok' => false, 'message' => $exception->getMessage()], 422);
            }
            error_log('Coaching Tool QR signing failed: ' . $exception->getMessage());
            signingJson(['ok' => false, 'message' => 'Die Unterschrift konnte nicht gespeichert werden. Bitte versuche es erneut oder wende dich an deinen Coach.'], 500);
        }
    }

    signingJson(['ok' => false, 'message' => 'Die Aktion ist nicht verfügbar.'], 400);
}

$isVerified = $request !== null && !empty($_SESSION['sign_code_verified']) && $request['widerrufen_am'] === null;
$isExpired = $request !== null && strtotime((string) $request['ablauf_am']) <= time() && $request['signiert_am'] === null;
$isLocked = $request !== null && (int) $request['code_versuche'] >= 5 && $request['signiert_am'] === null;
if ($request === null) {
    $error = $error !== '' ? $error : 'Bitte scanne den QR-Code erneut. Der Link ist ungültig oder abgelaufen.';
} elseif ($request['widerrufen_am'] !== null) {
    $error = 'Dieser Signaturlink wurde widerrufen. Bitte wende dich an deinen Coach.';
} elseif ($isExpired) {
    $error = 'Dieser Signaturlink ist abgelaufen. Bitte wende dich an deinen Coach.';
} elseif ($isLocked) {
    $error = 'Dieser Signaturlink wurde nach fünf falschen Prüfcodes gesperrt. Bitte wende dich an deinen Coach.';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Dokument unterschreiben · Coaching Tool</title>
<style>
:root{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#20312d;background:#f5f7f5}*{box-sizing:border-box}body{margin:0}.sign-page{max-width:760px;margin:0 auto;padding:22px 16px 56px}.sign-brand{font-weight:700;padding:8px 0 20px;border-bottom:1px solid #e1e8e2}.sign-card{margin:22px 0;background:#fff;border:1px solid #e3e9e4;border-radius:16px;padding:clamp(18px,4vw,28px);box-shadow:0 10px 32px #263b3210}.sign-page h1{font-size:clamp(26px,6vw,38px);line-height:1.15;letter-spacing:-.035em;margin:8px 0 10px}.sign-page h2{font-size:19px;margin:0 0 12px}.sign-intro,.sign-muted{color:#66766f;line-height:1.55}.sign-form{display:grid;gap:12px}.sign-form label{font-weight:650;font-size:14px}.sign-form input[type=text],.sign-form input[type=tel]{width:100%;border:1px solid #d7e1da;border-radius:10px;padding:13px;font:inherit}.sign-button{border:0;border-radius:10px;padding:13px 16px;background:#245c50;color:#fff;font:inherit;font-weight:700;cursor:pointer}.sign-button:disabled{opacity:.55;cursor:wait}.sign-error{background:#fff1ee;color:#8b302f;border-radius:10px;padding:12px}.sign-success{background:#edf5ef;color:#245c50;border-radius:10px;padding:14px}.sign-preview{width:100%;height:min(68vh,720px);border:1px solid #dce4de;border-radius:10px;background:#eef2ef}.sign-canvas{display:block;width:100%;height:190px;border:1px solid #cfdad2;border-radius:10px;background:white;touch-action:none}.sign-controls{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.sign-secondary{border:1px solid #cfdbd3;border-radius:9px;background:#fff;padding:9px 12px;color:#38564b;font:inherit;cursor:pointer}.sign-consent{display:flex;align-items:flex-start;gap:10px;font-weight:400!important;line-height:1.45}.sign-consent input{margin-top:3px}.sign-legal{font-size:12px;color:#6c7a74;line-height:1.55}.sign-hidden{display:none!important}.sign-wait{color:#596e65;padding:10px 0}
</style>
</head>
<body>
<main class="sign-page">
<div class="sign-brand">Coaching Tool</div>
<section class="sign-card">
<?php if ($request === null || $error !== '' && !$isVerified): ?>
<h1>Dokument unterschreiben</h1>
<?php if ($error !== ''): ?><p class="sign-error" role="alert"><?= signingH($error) ?></p><?php else: ?><p id="qr-status" class="sign-wait">Der sichere Signaturlink wird geöffnet …</p><noscript><p class="sign-error">Zum Öffnen dieses QR-Links muss JavaScript aktiviert sein.</p></noscript><?php endif; ?>
<?php if ($request !== null && !$isExpired && !$isLocked && $request['widerrufen_am'] === null): ?>
<form class="sign-form" method="post">
<input type="hidden" name="csrf_token" value="<?= signingH($csrf) ?>"><input type="hidden" name="sign_action" value="verify">
<label for="sign-code">Prüfcode vom Coach</label><input id="sign-code" name="code" type="tel" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
<button class="sign-button" type="submit">Dokument öffnen</button>
</form>
<p class="sign-legal">Der Prüfcode wird dir getrennt vom QR-Code mitgeteilt. Teile ihn nicht weiter.</p>
<?php endif; ?>
<?php elseif ($request['signiert_am'] !== null): ?>
<h1>Unterschrift gespeichert</h1><p class="sign-success">Deine unterschriebene PDF wurde sicher gespeichert.</p><p>Du kannst sie jetzt herunterladen:</p><a class="sign-button" href="document-sign.php?download=1">Unterschriebene PDF herunterladen</a>
<?php elseif (!$isVerified): ?>
<h1>Prüfung erforderlich</h1><p class="sign-intro">Gib den sechsstelligen Prüfcode ein, den dir dein Coach getrennt vom QR-Code mitgeteilt hat.</p>
<?php if ($error !== ''): ?><p class="sign-error" role="alert"><?= signingH($error) ?></p><?php endif; ?>
<form class="sign-form" method="post">
<input type="hidden" name="csrf_token" value="<?= signingH($csrf) ?>"><input type="hidden" name="sign_action" value="verify">
<label for="sign-code">Prüfcode</label><input id="sign-code" name="code" type="tel" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
<button class="sign-button" type="submit">Dokument öffnen</button>
</form><p class="sign-legal">Der Prüfcode wird getrennt vom QR-Code mitgeteilt und schützt den Link, falls er weitergeleitet wird.</p>
<?php else: ?>
<h1>Dokument unterschreiben</h1>
<p class="sign-intro"><strong><?= signingH($request['titel']) ?></strong><br>für <?= signingH(trim($request['vorname'] . ' ' . $request['nachname'])) ?></p>
<p class="sign-muted">Prüfe das Dokument. Unten wird eine Seite mit deiner Unterschrift an die PDF angefügt.</p>
<iframe class="sign-preview" src="document-sign.php?source=1" title="PDF-Dokument"></iframe><p class="sign-muted">Falls die Vorschau auf deinem Handy leer bleibt: <a href="document-sign.php?source=1" target="_blank" rel="noopener">PDF in einem neuen Tab öffnen</a>.</p>
<form id="sign-form" class="sign-form" method="post">
<input type="hidden" name="csrf_token" value="<?= signingH($csrf) ?>">
<label for="signer-name">Vollständiger Name</label><input id="signer-name" name="signer_name" type="text" maxlength="190" autocomplete="name" required value="<?= signingH(trim($request['vorname'] . ' ' . $request['nachname'])) ?>">
<label>Unterschrift mit Finger oder Maus</label><canvas id="signature-canvas" class="sign-canvas" aria-label="Unterschriftsfeld"></canvas>
<div class="sign-controls"><button class="sign-secondary" type="button" id="clear-signature">Unterschrift löschen</button></div>
<label class="sign-consent"><input type="checkbox" id="sign-consent" required><span>Ich bestätige, dass ich dieses Dokument gelesen habe und mit meiner Unterschrift absenden möchte.</span></label>
<p class="sign-legal">Die gezeichnete Unterschrift und dein Name werden in eine neue PDF eingefügt. Der Server speichert zusätzlich den Zeitpunkt und die Prüfsumme der Vorlage und der unterschriebenen PDF. Prüfe bitte, ob dieses Unterschriftsverfahren für das Dokument passend ist.</p>
<button id="submit-signature" class="sign-button" type="submit">Dokument unterschreiben und senden</button>
<p id="sign-result" role="status" aria-live="polite"></p>
</form>
<?php endif; ?>
</section>
</main>
<?php if ($request === null): ?>
<script>
(async()=>{
 const token=new URLSearchParams(location.hash.slice(1)).get('token');
 if(!token)return;
 history.replaceState(null,'',location.pathname);
 const form=new URLSearchParams({sign_action:'init',token});
 try{
  const response=await fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:form});
  if(response.redirected){location.replace(response.url);return}
  if(response.ok){location.reload();return}
  document.getElementById('qr-status').textContent='Der Link konnte nicht geöffnet werden. Bitte scanne den QR-Code erneut.';
 }catch(_){document.getElementById('qr-status').textContent='Verbindung fehlgeschlagen. Bitte versuche es erneut.'}
})();
</script>
<?php elseif ($isVerified && $request['signiert_am'] === null && $error === ''): ?>
<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js" referrerpolicy="no-referrer"></script>
<script>
(()=>{
 const form=document.getElementById('sign-form');
 const canvas=document.getElementById('signature-canvas');
 const context=canvas.getContext('2d');
 const result=document.getElementById('sign-result');
 const submit=document.getElementById('submit-signature');
 let drawing=false,drawn=false;
 const resize=()=>{
  const rect=canvas.getBoundingClientRect();
  const ratio=Math.max(window.devicePixelRatio||1,1);
  const image=drawn?canvas.toDataURL('image/png'):null;
  canvas.width=Math.max(1,Math.round(rect.width*ratio));canvas.height=Math.max(1,Math.round(rect.height*ratio));
  context.setTransform(ratio,0,0,ratio,0,0);context.lineWidth=2.4;context.lineCap='round';context.lineJoin='round';context.strokeStyle='#1e302b';
  if(image){const saved=new Image();saved.onload=()=>context.drawImage(saved,0,0,rect.width,rect.height);saved.src=image}
 };
 const point=(event)=>{const rect=canvas.getBoundingClientRect();return{x:event.clientX-rect.left,y:event.clientY-rect.top}};
 canvas.addEventListener('pointerdown',event=>{event.preventDefault();drawing=true;canvas.setPointerCapture(event.pointerId);const p=point(event);context.beginPath();context.moveTo(p.x,p.y);context.lineTo(p.x+.1,p.y+.1);context.stroke();drawn=true});
 canvas.addEventListener('pointermove',event=>{if(!drawing)return;event.preventDefault();const p=point(event);context.lineTo(p.x,p.y);context.stroke()});
 for(const name of ['pointerup','pointercancel','pointerleave'])canvas.addEventListener(name,()=>{drawing=false});
 document.getElementById('clear-signature').addEventListener('click',()=>{context.clearRect(0,0,canvas.width,canvas.height);drawn=false});
 resize();window.addEventListener('resize',resize);
 form.addEventListener('submit',async event=>{
  event.preventDefault();result.className='sign-wait';
  if(!drawn){result.textContent='Bitte zeichne zuerst deine Unterschrift.';return}
  if(!form.reportValidity())return;
  if(!window.PDFLib){result.className='sign-error';result.textContent='Die PDF-Verarbeitung konnte nicht geladen werden. Prüfe die Internetverbindung und lade die Seite neu.';return}
  submit.disabled=true;result.textContent='Die unterschriebene PDF wird erstellt und sicher gespeichert …';
  try{
   const response=await fetch('document-sign.php?source=1',{credentials:'same-origin',cache:'no-store'});
   if(!response.ok)throw new Error('Das Dokument konnte nicht geöffnet werden.');
   const original=new Uint8Array(await response.arrayBuffer());
   const hashBuffer=await crypto.subtle.digest('SHA-256',original);
   const sourceHash=Array.from(new Uint8Array(hashBuffer),byte=>byte.toString(16).padStart(2,'0')).join('');
   const {PDFDocument,StandardFonts,rgb}=PDFLib;
   const pdf=await PDFDocument.load(original);
   const page=pdf.addPage([595.28,841.89]);
   const font=await pdf.embedFont(StandardFonts.Helvetica);
   const title=<?= json_encode((string) $request['titel'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
   const signerName=document.getElementById('signer-name').value.trim();
   const dateText=<?= json_encode(date('d.m.Y H:i'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
   page.drawText('Unterschrift', {x:48,y:780,size:22,font,color:rgb(0.12,0.2,0.18)});
   page.drawText('Dokument: '+title.slice(0,75), {x:48,y:744,size:11,font,color:rgb(0.25,0.33,0.29),maxWidth:500});
   page.drawText('Unterzeichnende Person: '+signerName.slice(0,90), {x:48,y:720,size:11,font,color:rgb(0.25,0.33,0.29),maxWidth:500});
   const pngData=canvas.toDataURL('image/png').split(',')[1];
   const pngBytes=Uint8Array.from(atob(pngData),char=>char.charCodeAt(0));
   const signature=await pdf.embedPng(pngBytes);
   const dims=signature.scale(Math.min(1,480/signature.width,170/signature.height));
   page.drawImage(signature,{x:48,y:460,width:dims.width,height:dims.height});
   page.drawLine({start:{x:48,y:450},end:{x:340,y:450},thickness:.7,color:rgb(.55,.61,.57)});
   page.drawText('Unterschrift', {x:48,y:431,size:9,font,color:rgb(.35,.42,.38)});
   page.drawText('Erfasst über den persönlichen QR-Link mit zusätzlichem Prüfcode.', {x:48,y:390,size:9,font,color:rgb(.35,.42,.38),maxWidth:490});
   page.drawText('Zeitpunkt der Speicherung wird serverseitig protokolliert.', {x:48,y:374,size:9,font,color:rgb(.35,.42,.38),maxWidth:490});
   page.drawText('Auf dem Gerät erstellt am: '+dateText, {x:48,y:354,size:8,font,color:rgb(.45,.51,.47)});
   const signedBytes=await pdf.save();
   const data=new FormData();
   data.append('csrf_token',<?= json_encode($csrf) ?>);data.append('sign_action','complete');data.append('signer_name',signerName);data.append('source_sha256',sourceHash);
   data.append('signed_pdf',new Blob([signedBytes],{type:'application/pdf'}),'unterschrieben.pdf');
   const save=await fetch('document-sign.php',{method:'POST',credentials:'same-origin',body:data});
   const resultData=await save.json();
   if(!save.ok||!resultData.ok)throw new Error(resultData.message||'Die PDF konnte nicht gespeichert werden.');
   form.innerHTML='<p class="sign-success">'+resultData.message+'</p><a class="sign-button" href="'+resultData.download_url+'">Unterschriebene PDF herunterladen</a>';
  }catch(error){result.className='sign-error';result.textContent=error instanceof Error?error.message:'Unbekannter Fehler. Bitte wende dich an deinen Coach.';submit.disabled=false}
 });
})();
</script>
<?php endif; ?>
</body></html>
