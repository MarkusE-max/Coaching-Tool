<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/totp.php';

function mfaSetupH(string|int|null $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function mfaSetupCsrf(): string {
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) !== 64) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

$pending = $_SESSION['mfa_pending_user'] ?? null;
$activeUser = $_SESSION['user'] ?? null;
$isFirstSetup = is_array($pending) && isset($pending['id'], $pending['role']) && ($pending['role'] !== 'client' || isset($pending['client_id']));
$isAuthenticatedSetup = is_array($activeUser) && isset($activeUser['id'], $activeUser['role']) && ($activeUser['role'] !== 'client' || isset($activeUser['client_id']));
if (!$isFirstSetup && !$isAuthenticatedSetup) { header('Location: ./'); exit; }
if ($isAuthenticatedSetup && empty($_SESSION['mfa_setup_authorized'])) { header('Location: security-settings.php'); exit; }
$identity = $isFirstSetup ? $pending : $activeUser;
$db = coachingDatabase();
$secret = $_SESSION['mfa_setup_secret'] ?? '';
if (!is_string($secret) || $secret === '') {
    $secret = coachingMfaNewSecret();
    $_SESSION['mfa_setup_secret'] = $secret;
}
$error = '';
$recoveryCodes = null;
unset($_SESSION['mfa_recovery_codes_once']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $code = trim(is_string($_POST['code'] ?? null) ? $_POST['code'] : '');
    if (!is_string($token) || !hash_equals(mfaSetupCsrf(), $token)) $error = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
    elseif (!coachingMfaVerifyTotp($secret, $code)) $error = 'Der Code ist ungültig. Prüfe die Uhrzeit deines Geräts und versuche es erneut.';
    else {
        try {
            $recovery = coachingMfaCreateRecoveryCodes();
            $secretEncrypted = coachingMfaEncrypt($secret);
            $recoveryJson = json_encode($recovery['hashes'], JSON_THROW_ON_ERROR);
            if (($identity['role'] ?? '') === 'client') {
                $saved = $db->prepare('UPDATE coaching_portal_zugaenge SET mfa_aktiv=1,mfa_geheimnis_verschluesselt=:secret,mfa_ersatzcodes_hashes=:recovery WHERE id=:id AND klient_id=:client AND aktiv=1');
                $saved->execute(['secret'=>$secretEncrypted,'recovery'=>$recoveryJson,'id'=>(int)$identity['id'],'client'=>(int)$identity['client_id']]);
            } else {
                $saved = $db->prepare('UPDATE benutzer SET mfa_aktiv=1,mfa_geheimnis_verschluesselt=:secret,mfa_ersatzcodes_hashes=:recovery WHERE id=:id AND rolle=:role AND aktiv=1');
                $saved->execute(['secret'=>$secretEncrypted,'recovery'=>$recoveryJson,'id'=>(int)$identity['id'],'role'=>(string)$identity['role']]);
            }
            if ($saved->rowCount() !== 1) throw new RuntimeException('Das Benutzerkonto konnte nicht aktualisiert werden.');
            unset($_SESSION['mfa_setup_secret'], $_SESSION['mfa_setup_authorized']);
            if ($isFirstSetup) {
                if (($identity['role'] ?? '') === 'client') {
                    $login = $db->prepare('UPDATE coaching_portal_zugaenge SET letzter_login=NOW() WHERE id=:id AND klient_id=:client');
                    $login->execute(['id'=>(int)$identity['id'],'client'=>(int)$identity['client_id']]);
                } else {
                    $login = $db->prepare('UPDATE benutzer SET letzter_login=NOW() WHERE id=:id');
                    $login->execute(['id'=>(int)$identity['id']]);
                }
                session_regenerate_id(true);
                $_SESSION['user'] = ['id'=>(int)$identity['id'],'name'=>(string)$identity['name'],'role'=>(string)$identity['role']];
                if (($identity['role'] ?? '') === 'client') $_SESSION['user']['client_id'] = (int)$identity['client_id'];
                unset($_SESSION['mfa_pending_user']);
            }
            $_SESSION['mfa_verified'] = true;
            $recoveryCodes = $recovery['codes'];
        } catch (Throwable $e) {
            error_log('Coaching Tool MFA setup failed: '.$e->getMessage());
            $error = 'Die Zwei-Faktor-Anmeldung konnte nicht eingerichtet werden. Bitte prüfe die Datenbankmigration 022, Tabellenrechte und den privaten mail_encryption_key.';
        }
    }
}
$csrf = mfaSetupCsrf();
$uri = coachingMfaOtpAuthUri($secret, (string)($identity['name'] ?? 'Benutzer'));
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Zwei-Faktor-Anmeldung einrichten · Coaching Tool</title><link rel="stylesheet" href="assets/app.css"><style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f5f7f5;color:#1f302d}.panel{max-width:680px;margin:7vh auto;padding:28px;background:#fff;border:1px solid #e1e9e3;border-radius:16px}.secret{font-family:ui-monospace,monospace;overflow-wrap:anywhere;background:#f2f6f3;padding:12px;border-radius:8px}.codes{font-family:ui-monospace,monospace;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:14px;background:#f2f6f3;border-radius:8px}label{display:block;margin:18px 0 6px;font-weight:600}input{width:100%;max-width:300px;padding:12px;border:1px solid #cbd9d0;border-radius:8px;font:inherit}.button{display:inline-block;background:#245c50;color:#fff;border:0;border-radius:8px;padding:12px 16px;text-decoration:none;font:inherit;font-weight:650;margin-top:14px}.error{padding:12px;background:#fff1ef;border-radius:8px;color:#842f2f}#qr{margin:18px 0}</style></head><body><main class="panel">
<h1><?= $recoveryCodes ? 'Zwei-Faktor-Anmeldung aktiviert' : 'Zwei-Faktor-Anmeldung einrichten' ?></h1>
<?php if($error!==''):?><p class="error" role="alert"><?=mfaSetupH($error)?></p><?php endif;?>
<?php if(is_array($recoveryCodes)):?>
<p>Speichere diese Wiederherstellungscodes sicher. Jeder Code ist nur einmal nutzbar und wird nach dem Schließen nicht erneut angezeigt.</p><div class="codes"><?php foreach($recoveryCodes as $code):?><span><?=mfaSetupH($code)?></span><?php endforeach;?></div><a class="button" href="./">Zur Coaching-Anwendung</a>
<?php else:?>
<p>Verwende eine Authenticator-App wie Google Authenticator, Microsoft Authenticator, Authy oder 1Password und scanne den QR-Code. Danach ist die zweite Abfrage bei jedem Login erforderlich.</p>
<div id="qr" aria-label="QR-Code für die Authenticator-App"></div>
<p>Falls Scannen nicht möglich ist, gib diesen Schlüssel manuell ein:</p><div class="secret"><?=mfaSetupH($secret)?></div>
<form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?=mfaSetupH($csrf)?>"><label for="code">Sechsstelliger App-Code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,8}" maxlength="8" required><br><button class="button" type="submit">Einrichtung bestätigen</button></form>
<?php if($isFirstSetup):?><p><a href="mfa-cancel.php">Anmeldung abbrechen</a></p><?php else:?><p><a href="./">Zurück</a></p><?php endif;?>
<script src="assets/vendor/qrcode.min.js"></script><script>new QRCode(document.getElementById('qr'),{text:<?=json_encode($uri, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>,width:190,height:190});</script>
<?php endif;?></main></body></html>