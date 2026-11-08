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
    exit('Nur Administratoren können Verwalterkonten verwalten.');
}
$db = coachingDatabase();
$token = $_SESSION['csrf_token'] ?? '';
if (!is_string($token) || strlen($token) !== 64) { $token = bin2hex(random_bytes(32)); $_SESSION['csrf_token'] = $token; }
$error = '';
$notice = isset($_GET['saved']) ? 'Die Änderungen am Verwalterkonto wurden gespeichert.' : (isset($_GET['created']) ? 'Das Verwalterkonto wurde angelegt.' : '');
$values = ['id'=>0,'vorname'=>'','nachname'=>'','username'=>'','email'=>'','aktiv'=>1];
$editing = false;

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId === false || (int)$editId < 1) $error = 'Ungültige Verwalter-ID.';
    else {
        $q = $db->prepare("SELECT id,vorname,nachname,benutzername,email,aktiv FROM benutzer WHERE id=:id AND rolle='verwalter' LIMIT 1");
        $q->execute(['id'=>(int)$editId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) { $values = ['id'=>(int)$row['id'],'vorname'=>$row['vorname'],'nachname'=>$row['nachname'],'username'=>$row['benutzername'],'email'=>$row['email'],'aktiv'=>(int)$row['aktiv']]; $editing=true; }
        else $error = 'Dieses Verwalterkonto wurde nicht gefunden.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
    $id = $id === false ? -1 : (int)$id;
    $first = trim(is_string($_POST['vorname'] ?? null) ? $_POST['vorname'] : '');
    $last = trim(is_string($_POST['nachname'] ?? null) ? $_POST['nachname'] : '');
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $active = isset($_POST['aktiv']) && $_POST['aktiv'] === '1' ? 1 : 0;
    $values = ['id'=>max(0,$id),'vorname'=>$first,'nachname'=>$last,'username'=>$username,'email'=>$email,'aktiv'=>$id>0?$active:1];
    $editing = $id > 0;
    $passwordValid = strlen($password) >= 8 && strlen($password) <= 4096 && preg_match('/[^A-Za-z0-9]/', $password) === 1;
    if (!is_string($postedToken) || !hash_equals($token, $postedToken)) $error = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
    elseif ($id < 0 || $first === '' || strlen($first)>100 || $last === '' || strlen($last)>100 || $username === '' || strlen($username)>50 || $email === '' || strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL) || (!$editing && !$passwordValid) || ($editing && $password!=='' && !$passwordValid)) $error = 'Bitte prüfe die Angaben. Ein neues Passwort braucht mindestens 8 Zeichen und ein Sonderzeichen.';
    else {
        try {
            if ($editing) {
                $exists=$db->prepare("SELECT id FROM benutzer WHERE id=:id AND rolle='verwalter'");
                $exists->execute(['id'=>$id]);
                if (!$exists->fetchColumn()) throw new InvalidArgumentException('Dieses Verwalterkonto wurde nicht gefunden.');
                $set=['vorname=:first','nachname=:last','benutzername=:username','email=:email','aktiv=:active'];
                $params=['first'=>$first,'last'=>$last,'username'=>$username,'email'=>$email,'active'=>$active,'id'=>$id];
                if ($password!=='') { $set[]='passwort_hash=:hash'; $params['hash']=password_hash($password,PASSWORD_DEFAULT); }
                $update=$db->prepare('UPDATE benutzer SET '.implode(', ',$set).' WHERE id=:id');
                $update->execute($params);
                header('Location: managers.php?saved=1'); exit;
            }
            $insert=$db->prepare("INSERT INTO benutzer (benutzername,email,passwort_hash,vorname,nachname,rolle,aktiv) VALUES (:username,:email,:hash,:first,:last,'verwalter',1)");
            $insert->execute(['username'=>$username,'email'=>$email,'hash'=>password_hash($password,PASSWORD_DEFAULT),'first'=>$first,'last'=>$last]);
            header('Location: managers.php?created=1'); exit;
        } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
        catch (Throwable $e) { error_log('Manager account save failed: '.$e->getMessage()); $error='Das Konto konnte nicht gespeichert werden. Prüfe Migration 007 sowie doppelte Benutzernamen/E-Mail-Adressen.'; }
    }
}
try {
    $managers=$db->query("SELECT id,vorname,nachname,benutzername,email,aktiv FROM benutzer WHERE rolle='verwalter' ORDER BY aktiv DESC,nachname,vorname")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('Manager list failed: '.$e->getMessage()); $managers=[]; $error='Verwalterkonten konnten nicht geladen werden. Bitte zuerst Migration 007 importieren.'; }
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verwalterkonten · Coaching Tool</title><link rel="stylesheet" href="assets/layout.css"><script src="assets/layout.js" defer></script><style>.page{max-width:1050px;margin:auto;padding:34px clamp(24px,4vw,58px) 62px}.grid{display:grid;grid-template-columns:minmax(280px,390px) 1fr;gap:18px;margin-top:24px}.card{background:#fff;border:1px solid #e4ebe6;border-radius:14px;padding:20px}.form{display:grid;gap:10px}.form input{width:100%;padding:11px;border:1px solid #dce5df;border-radius:8px;font:inherit}.button{display:inline-block;border:0;border-radius:8px;padding:11px 15px;background:#245c50;color:#fff;font:inherit;text-decoration:none}.secondary{background:#edf5ef;color:#245c50}.notice,.error{padding:12px;border-radius:8px}.notice{background:#edf5ef}.error{background:#fff3f1}.row{padding:12px 0;border-bottom:1px solid #edf1ee;display:flex;justify-content:space-between;align-items:center;gap:12px}.muted{color:#718078;font-size:13px}.status{border-radius:999px;background:#edf5ef;padding:4px 8px;font-size:12px}.status.off{background:#f3eeee;color:#765c5c}@media(max-width:800px){.grid{grid-template-columns:1fr}.row{align-items:flex-start;flex-direction:column}}</style></head><body class="has-app-sidebar"><?php require_once dirname(__DIR__).'/app/navigation.php';renderCoachingSidebar($user,'managers',$token);?><main class="page"><h1>Verwalterkonten</h1><p>Verwalter können Klienten Coaches zuordnen. Sie erhalten keinen Zugriff auf private Sitzungsnotizen. Administratoren können Konten verwalten, aber keine Coach-Wechsel vornehmen.</p><?php if($notice):?><p class="notice" role="status"><?=managerH($notice)?></p><?php endif;?><?php if($error):?><p class="error" role="alert"><?=managerH($error)?></p><?php endif;?><div class="grid"><section class="card"><h2><?=$editing?'Verwalter bearbeiten':'Verwalter hinzufügen'?></h2><form class="form" method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?=managerH($token)?>"><input type="hidden" name="id" value="<?=managerH($values['id'])?>"><label>Vorname<input name="vorname" maxlength="100" required value="<?=managerH($values['vorname'])?>"></label><label>Nachname<input name="nachname" maxlength="100" required value="<?=managerH($values['nachname'])?>"></label><label>Benutzername<input name="username" maxlength="50" required value="<?=managerH($values['username'])?>"></label><label>E-Mail<input name="email" type="email" maxlength="190" required value="<?=managerH($values['email'])?>"></label><label>Neues Passwort<?=$editing?' (optional)':''?><input name="password" type="password" minlength="8" <?=$editing?'':'required'?> autocomplete="new-password"></label><?php if($editing):?><label><input type="checkbox" name="aktiv" value="1" <?=$values['aktiv']===1?'checked':''?>> Konto aktiv</label><?php endif;?><button class="button" type="submit"><?=$editing?'Änderungen speichern':'Verwalterkonto anlegen'?></button><?php if($editing):?> <a class="button secondary" href="managers.php">Abbrechen</a><?php endif;?></form></section><section class="card"><h2>Bestehende Konten</h2><?php if(!$managers):?><p class="muted">Noch kein Verwalterkonto angelegt.</p><?php else:foreach($managers as $m):?><div class="row"><div><strong><?=managerH($m['vorname'].' '.$m['nachname'])?></strong><div class="muted"><?=managerH($m['benutzername'])?> · <?=managerH($m['email'])?></div></div><div><span class="status <?=((int)$m['aktiv']===1?'':'off')?>"><?=((int)$m['aktiv']===1?'Aktiv':'Inaktiv')?></span> · <a href="managers.php?edit=<?=(int)$m['id']?>">Bearbeiten</a></div></div><?php endforeach;endif;?></section></div></main></body></html>
