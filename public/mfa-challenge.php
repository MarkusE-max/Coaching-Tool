<?php
declare(strict_types=1);
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';
ini_set('session.use_strict_mode','1');session_name('coaching_tool_session');session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);session_start();header('Cache-Control: no-store, private');
require_once dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/totp.php';
function mfaChallengeH(string|int|null $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$pending=$_SESSION['mfa_pending_user']??null;
if(!is_array($pending)||!isset($pending['id'],$pending['role'])||($pending['role']==='client'&&!isset($pending['client_id']))){header('Location: ./');exit;}
if(!isset($_SESSION['csrf_token'])||!is_string($_SESSION['csrf_token'])||strlen($_SESSION['csrf_token'])!==64)$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$token=$_SESSION['csrf_token'];$error='';$db=coachingDatabase();$ip=substr((string)($_SERVER['REMOTE_ADDR']??'unknown'),0,45);
if($_SERVER['REQUEST_METHOD']==='POST'){
 $posted=$_POST['csrf_token']??'';$code=trim(is_string($_POST['code']??null)?$_POST['code']:'');
 if(!is_string($posted)||!hash_equals($token,$posted))$error='Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
 else{
  try{
   $limit=$db->prepare('SELECT COUNT(*) FROM login_versuche WHERE ip_adresse=:ip AND erfolgreich=0 AND zeitpunkt >= (NOW() - INTERVAL 15 MINUTE)');$limit->execute(['ip'=>$ip]);
   if((int)$limit->fetchColumn()>=5)$error='Zu viele Versuche. Bitte warte 15 Minuten und melde dich erneut an.';
   else{
    if (($pending['role'] ?? '') === 'client') {
     $q=$db->prepare('SELECT p.id,p.klient_id,p.benutzername,k.vorname,k.nachname,p.aktiv,p.mfa_aktiv,p.mfa_geheimnis_verschluesselt,p.mfa_ersatzcodes_hashes FROM coaching_portal_zugaenge p INNER JOIN klienten k ON k.id=p.klient_id WHERE p.id=:id AND p.klient_id=:client LIMIT 1');
     $q->execute(['id'=>(int)$pending['id'],'client'=>(int)$pending['client_id']]);$account=$q->fetch(PDO::FETCH_ASSOC);$accountRole='client';
    } else {
     $q=$db->prepare('SELECT id,benutzername,vorname,nachname,rolle,aktiv,mfa_aktiv,mfa_geheimnis_verschluesselt,mfa_ersatzcodes_hashes FROM benutzer WHERE id=:id AND rolle=:role LIMIT 1');
     $q->execute(['id'=>(int)$pending['id'],'role'=>(string)$pending['role']]);$account=$q->fetch(PDO::FETCH_ASSOC);$accountRole=(string)$pending['role'];
    }
    if(!$account||(int)$account['aktiv']!==1||(int)$account['mfa_aktiv']!==1)throw new RuntimeException('The account is not ready for second-factor verification.');
    $secret=coachingMfaDecrypt((string)$account['mfa_geheimnis_verschluesselt']);
    $valid=coachingMfaVerifyTotp($secret,$code);
    $hashes=json_decode((string)($account['mfa_ersatzcodes_hashes']??'[]'),true);if(!is_array($hashes))$hashes=[];
    $remaining=$valid?null:coachingMfaConsumeRecoveryCode($code,$hashes);
    if(!$valid&&$remaining===null){
     $log=$db->prepare('INSERT INTO login_versuche (benutzername,ip_adresse,erfolgreich) VALUES (:username,:ip,0)');$log->execute(['username'=>$account['benutzername'],'ip'=>$ip]);
     $error='Der Code ist nicht gültig. Prüfe die Uhrzeit deines Geräts oder nutze einen Wiederherstellungscode.';
    }else{
     if($remaining!==null){$table=$accountRole==='client'?'coaching_portal_zugaenge':'benutzer';$keyColumn=$accountRole==='client'?'id':'id';$oldCodes=(string)($account['mfa_ersatzcodes_hashes']??'[]');$save=$db->prepare("UPDATE $table SET mfa_ersatzcodes_hashes=:codes WHERE $keyColumn=:id AND mfa_ersatzcodes_hashes=:old");$save->execute(['codes'=>json_encode($remaining,JSON_THROW_ON_ERROR),'id'=>(int)$account['id'],'old'=>$oldCodes]);if($save->rowCount()!==1){$error='Dieser Wiederherstellungscode wurde bereits verwendet. Bitte probiere einen anderen.';throw new InvalidArgumentException('Recovery code was already consumed.');}}
     $log=$db->prepare('INSERT INTO login_versuche (benutzername,ip_adresse,erfolgreich) VALUES (:username,:ip,1)');$log->execute(['username'=>$account['benutzername'],'ip'=>$ip]);
     if($accountRole==='client'){$last=$db->prepare('UPDATE coaching_portal_zugaenge SET letzter_login=NOW() WHERE id=:id AND klient_id=:client');$last->execute(['id'=>(int)$account['id'],'client'=>(int)$account['klient_id']]);}else{$last=$db->prepare('UPDATE benutzer SET letzter_login=NOW() WHERE id=:id');$last->execute(['id'=>(int)$account['id']]);}
     session_regenerate_id(true);$_SESSION['user']=['id'=>(int)$account['id'],'name'=>trim($account['vorname'].' '.$account['nachname']),'role'=>$accountRole];if($accountRole==='client')$_SESSION['user']['client_id']=(int)$account['klient_id'];$_SESSION['mfa_verified']=true;unset($_SESSION['mfa_pending_user'],$_SESSION['csrf_token']);header('Location: ./');exit;
    }
   }
  }catch(Throwable $e){error_log('Coaching Tool MFA verification failed: '.$e->getMessage());$error='Die Zwei-Faktor-Anmeldung konnte nicht geprüft werden. Bitte starte die Anmeldung erneut.';}
 }
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Zwei-Faktor-Anmeldung · Coaching Tool</title><link rel="stylesheet" href="assets/app.css"><style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f5f7f5;color:#1f302d}.panel{max-width:500px;margin:10vh auto;padding:28px;background:#fff;border:1px solid #e1e9e3;border-radius:16px}label{display:block;margin:20px 0 6px;font-weight:600}input{width:100%;padding:12px;border:1px solid #cbd9d0;border-radius:8px;font:inherit}.button{display:inline-block;background:#245c50;color:#fff;border:0;border-radius:8px;padding:12px 16px;font:inherit;font-weight:650;margin-top:14px}.error{padding:12px;background:#fff1ef;border-radius:8px;color:#842f2f}.hint{color:#68786f;font-size:14px}</style></head><body><main class="panel"><h1>Zwei-Faktor-Anmeldung</h1><p>Gib den sechsstelligen Code aus deiner Authenticator-App ein. Du kannst stattdessen auch einen noch nicht verwendeten Wiederherstellungscode nutzen.</p><?php if($error!==''):?><p class="error" role="alert"><?=mfaChallengeH($error)?></p><?php endif;?><form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?=mfaChallengeH($token)?>"><label for="code">App-Code oder Wiederherstellungscode</label><input id="code" name="code" autocomplete="one-time-code" required autofocus><button class="button" type="submit">Anmeldung abschließen</button></form><p class="hint"><a href="mfa-cancel.php">Anmeldung abbrechen</a></p></main></body></html>