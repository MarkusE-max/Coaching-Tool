<?php
declare(strict_types=1);
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';
ini_set('session.use_strict_mode','1');session_name('coaching_tool_session');
session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);session_start();
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
require_once dirname(__DIR__).'/app/bootstrap.php';
function resetH(string|int|null $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$db=coachingDatabase();$rawToken=(string)($_POST['token']??$_GET['token']??'');
$tokenValid=preg_match('/^[a-f0-9]{64}$/i',$rawToken)===1;
$tokenHash=$tokenValid?hash('sha256',$rawToken):str_repeat('0',64);
$csrf=$_SESSION['csrf_token']??'';if(!is_string($csrf)||strlen($csrf)!==64){$csrf=bin2hex(random_bytes(32));$_SESSION['csrf_token']=$csrf;}
$error='';$done=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($csrf,(string)($_POST['csrf_token']??''))){http_response_code(400);exit('Ungültige Anfrage.');}
 $password=(string)($_POST['password']??'');$confirm=(string)($_POST['password_confirm']??'');
 if(strlen($password)<8||strlen($password)>4096||!preg_match('/[^A-Za-z0-9]/',$password))$error='Das Passwort muss mindestens 8 Zeichen und mindestens ein Sonderzeichen enthalten.';
 elseif(!hash_equals($password,$confirm))$error='Die beiden Passwörter stimmen nicht überein.';
 elseif(!$tokenValid)$error='Dieser Rücksetzlink ist ungültig oder abgelaufen.';
 else{
  try{
   $db->beginTransaction();
   $q=$db->prepare('SELECT id,konto_typ,konto_id FROM coaching_passwort_resets WHERE token_hash=:hash AND verwendet_am IS NULL AND ablauf_am>NOW() FOR UPDATE');
   $q->execute(['hash'=>$tokenHash]);$resetRecord=$q->fetch(PDO::FETCH_ASSOC);
   if(!$resetRecord)throw new InvalidArgumentException('Dieser Rücksetzlink ist ungültig oder abgelaufen.');
   if($resetRecord['konto_typ']==='benutzer')$update=$db->prepare('UPDATE benutzer SET passwort_hash=:hash WHERE id=:id AND aktiv=1');
   else $update=$db->prepare('UPDATE coaching_portal_zugaenge SET passwort_hash=:hash WHERE id=:id AND aktiv=1');
   $update->execute(['hash'=>password_hash($password,PASSWORD_DEFAULT),'id'=>(int)$resetRecord['konto_id']]);
   if($update->rowCount()!==1)throw new InvalidArgumentException('Das Konto ist nicht mehr aktiv.');
   $used=$db->prepare('UPDATE coaching_passwort_resets SET verwendet_am=NOW() WHERE konto_typ=:type AND konto_id=:id AND verwendet_am IS NULL');
   $used->execute(['type'=>$resetRecord['konto_typ'],'id'=>(int)$resetRecord['konto_id']]);
   $db->commit();$done=true;
  }catch(InvalidArgumentException $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
  catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Password reset completion failed: '.$e->getMessage());$error='Das Passwort konnte nicht geändert werden. Bitte fordere einen neuen Rücksetzlink an.';}
 }
}
if(!$done&&!$error&&$tokenValid){$q=$db->prepare('SELECT id FROM coaching_passwort_resets WHERE token_hash=:hash AND verwendet_am IS NULL AND ablauf_am>NOW()');$q->execute(['hash'=>$tokenHash]);if(!$q->fetchColumn())$error='Dieser Rücksetzlink ist ungültig oder abgelaufen.';}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Neues Passwort</title><link rel="stylesheet" href="assets/layout.css"><style>.page{max-width:620px;margin:6vh auto;padding:28px}.card{background:#fff;border:1px solid #e4ebe6;border-radius:14px;padding:24px}.form{display:grid;gap:12px}.form label{display:grid;gap:5px;font-weight:600}.form input{padding:11px;border:1px solid #dce5df;border-radius:8px;font:inherit}.button{background:#245c50;color:white;border:0;border-radius:8px;padding:11px 15px;font:inherit;cursor:pointer}.notice{padding:12px;border-radius:8px;background:#edf5ef}.error{padding:12px;border-radius:8px;background:#fff3f1}.hint{color:#718078;font-size:13px}</style></head><body><main class="page"><section class="card"><p class="hint">Coaching Tool</p><h1>Neues Passwort festlegen</h1><?php if($done):?><p class="notice">Dein Passwort wurde geändert. Du kannst dich jetzt mit dem neuen Passwort anmelden.</p><p><a href="./">Zur Anmeldung</a></p><?php else:?><?php if($error):?><p class="error" role="alert"><?=resetH($error)?></p><?php endif;?><?php if($tokenValid&&!str_contains($error,'ungültig')&&!str_contains($error,'abgelaufen')):?><form method="post" class="form"><input type="hidden" name="csrf_token" value="<?=resetH($csrf)?>"><input type="hidden" name="token" value="<?=resetH($rawToken)?>"><label>Neues Passwort<input type="password" name="password" minlength="8" maxlength="4096" autocomplete="new-password" required></label><label>Neues Passwort wiederholen<input type="password" name="password_confirm" minlength="8" maxlength="4096" autocomplete="new-password" required></label><p class="hint">Mindestens 8 Zeichen und mindestens ein Sonderzeichen.</p><button class="button" type="submit">Passwort speichern</button></form><?php endif;?><p class="hint"><a href="forgot-password.php">Neuen Rücksetzlink anfordern</a></p><?php endif;?></section></main><script>if(location.search)history.replaceState(null,'',location.pathname);</script></body></html>
