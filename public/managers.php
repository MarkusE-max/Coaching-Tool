<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/app/bootstrap.php';

function managerH(string|int|null $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Nur Administratoren können Verwalterkonten einrichten.');
}
$db = coachingDatabase();
$token = $_SESSION['csrf_token'] ?? '';
if (!is_string($token) || strlen($token) !== 64) { $token = bin2hex(random_bytes(32)); $_SESSION['csrf_token'] = $token; }
$error = '';
$notice = isset($_GET['created']) ? 'Das Verwalterkonto wurde angelegt.' : '';
$values = ['vorname'=>'','nachname'=>'','username'=>'','email'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $first = trim(is_string($_POST['vorname'] ?? null) ? $_POST['vorname'] : '');
    $last = trim(is_string($_POST['nachname'] ?? null) ? $_POST['nachname'] : '');
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $values = ['vorname'=>$first,'nachname'=>$last,'username'=>$username,'email'=>$email];
    if (!is_string($postedToken) || !hash_equals($token, $postedToken)) $error = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
    elseif ($first === '' || strlen($first) > 100 || $last === '' || strlen($last) > 100 || $username === '' || strlen($username) > 50 || $email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || strlen($password) > 4096 || preg_match('/[^A-Za-z0-9]/', $password) !== 1) $error = 'Bitte prüfe die Angaben. Das Passwort braucht mindestens 8 Zeichen und ein Sonderzeichen.';
    else {
        try {
            $insert = $db->prepare("INSERT INTO benutzer (benutzername,email,passwort_hash,vorname,nachname,rolle,aktiv) VALUES (:username,:email,:hash,:first,:last,'verwalter',1)");
            $insert->execute(['username'=>$username,'email'=>$email,'hash'=>password_hash($password,PASSWORD_DEFAULT),'first'=>$first,'last'=>$last]);
            header('Location: managers.php?created=1');
            exit;
        } catch (Throwable $e) {
            error_log('Manager account creation failed: '.$e->getMessage());
            $error = 'Das Konto konnte nicht gespeichert werden. Prüfe, ob Migration 007 ausgeführt wurde und Benutzername/E-Mail frei sind.';
        }
    }
}
try {
    $managers = $db->query("SELECT id,vorname,nachname,benutzername,email,aktiv FROM benutzer WHERE rolle='verwalter' ORDER BY aktiv DESC,nachname,vorname")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Manager list failed: '.$e->getMessage());
    $managers = [];
    $error = 'Verwalterkonten konnten nicht geladen werden. Bitte zuerst Migration 007 importieren.';
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verwalterkonten · Coaching Tool</title><link rel="stylesheet" href="assets/layout.css"><script src="assets/layout.js" defer></script><style>.page{max-width:1000px;margin:auto;padding:34px clamp(24px,4vw,58px) 62px}.grid{display:grid;grid-template-columns:minmax(280px,390px) 1fr;gap:18px;margin-top:24px}.card{background:#fff;border:1px solid #e4ebe6;border-radius:14px;padding:20px}.form{display:grid;gap:10px}.form input{width:100%;padding:11px;border:1px solid #dce5df;border-radius:8px;font:inherit}.button{border:0;border-radius:8px;padding:11px 15px;background:#245c50;color:#fff;font:inherit}.notice,.error{padding:12px;border-radius:8px}.notice{background:#edf5ef}.error{background:#fff3f1}.row{padding:12px 0;border-bottom:1px solid #edf1ee}.muted{color:#718078;font-size:13px}@media(max-width:800px){.grid{grid-template-columns:1fr}}</style></head><body class="has-app-sidebar"><?php require_once dirname(__DIR__).'/app/navigation.php';renderCoachingSidebar($user,'managers',$token);?><main class="page"><h1>Verwalterkonten</h1><p>Verwalter können Klienten Coaches zuordnen. Sie erhalten keinen Zugriff auf private Sitzungsnotizen. Administratoren können Verwalterkonten einrichten, aber keine Coach-Wechsel vornehmen.</p><?php if($notice):?><p class="notice" role="status"><?=managerH($notice)?></p><?php endif;?><?php if($error):?><p class="error" role="alert"><?=managerH($error)?></p><?php endif;?><div class="grid"><section class="card"><h2>Verwalter hinzufügen</h2><form class="form" method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?=managerH($token)?>"><label>Vorname<input name="vorname" maxlength="100" required value="<?=managerH($values['vorname'])?>"></label><label>Nachname<input name="nachname" maxlength="100" required value="<?=managerH($values['nachname'])?>"></label><label>Benutzername<input name="username" maxlength="50" required value="<?=managerH($values['username'])?>"></label><label>E-Mail<input name="email" type="email" maxlength="190" required value="<?=managerH($values['email'])?>"></label><label>Passwort (mindestens 8 Zeichen und Sonderzeichen)<input name="password" type="password" minlength="8" required autocomplete="new-password"></label><button class="button" type="submit">Verwalterkonto anlegen</button></form></section><section class="card"><h2>Bestehende Konten</h2><?php if(!$managers):?><p class="muted">Noch kein Verwalterkonto angelegt.</p><?php else:foreach($managers as $m):?><div class="row"><strong><?=managerH($m['vorname'].' '.$m['nachname'])?></strong><div class="muted"><?=managerH($m['benutzername'])?> · <?=managerH($m['email'])?> · <?=((int)$m['aktiv']===1?'Aktiv':'Inaktiv')?></div></div><?php endforeach;endif;?></section></div></main></body></html>
