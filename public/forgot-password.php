<?php
declare(strict_types=1);
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';
ini_set('session.use_strict_mode','1');session_name('coaching_tool_session');
session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);session_start();
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
require_once dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/mail.php';
function forgotH(string|int|null $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$db=coachingDatabase();$token=$_SESSION['csrf_token']??'';if(!is_string($token)||strlen($token)!==64){$token=bin2hex(random_bytes(32));$_SESSION['csrf_token']=$token;}
$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($token,(string)($_POST['csrf_token']??''))){http_response_code(400);exit('Ungültige Anfrage.');}
 $username=trim((string)($_POST['username']??''));$email=trim((string)($_POST['email']??''));
 if(strlen($username)<=80&&filter_var($email,FILTER_VALIDATE_EMAIL)){
  try{
   $accountType='';$accountId=0;$registeredEmail='';$mailOwnerId=0;
   $q=$db->prepare('SELECT id,email FROM benutzer WHERE benutzername=:username AND email=:email AND aktiv=1 LIMIT 1');
   $q->execute(['username'=>$username,'email'=>$email]);$account=$q->fetch(PDO::FETCH_ASSOC);
   if($account){$accountType='benutzer';$accountId=(int)$account['id'];$mailOwnerId=$accountId;$registeredEmail=(string)$account['email'];}
   else{
    $q=$db->prepare("SELECT p.id,p.email,k.benutzer_id AS mail_owner_id FROM coaching_portal_zugaenge p INNER JOIN klienten k ON k.id=p.klient_id INNER JOIN benutzer b ON b.id=k.benutzer_id AND b.rolle='coach' AND b.aktiv=1 WHERE p.benutzername=:username AND p.email=:email AND p.aktiv=1 LIMIT 1");
    $q->execute(['username'=>$username,'email'=>$email]);$account=$q->fetch(PDO::FETCH_ASSOC);
    if($account){$accountType='coachee';$accountId=(int)$account['id'];$mailOwnerId=(int)$account['mail_owner_id'];$registeredEmail=(string)$account['email'];}
   }
   $baseUrl=coachingMailAppBaseUrl();$mailSettings=$mailOwnerId>0?coachingMailSettings($db,$mailOwnerId):[];
   if($accountId>0&&$mailOwnerId>0&&$baseUrl!==null&&(int)$mailSettings['aktiv']===1){
    $limit=$db->prepare('SELECT COUNT(*) FROM coaching_passwort_resets WHERE konto_typ=:type AND konto_id=:id AND erstellt_am>=NOW()-INTERVAL 1 HOUR');
    $limit->execute(['type'=>$accountType,'id'=>$accountId]);
    if((int)$limit->fetchColumn()<3){
     $db->exec('DELETE FROM coaching_passwort_resets WHERE ablauf_am<NOW()-INTERVAL 1 DAY OR verwendet_am<NOW()-INTERVAL 7 DAY');
     $rawToken=bin2hex(random_bytes(32));$tokenHash=hash('sha256',$rawToken);
     $insert=$db->prepare('INSERT INTO coaching_passwort_resets (konto_typ,konto_id,token_hash,ablauf_am) VALUES (:type,:id,:hash,DATE_ADD(NOW(),INTERVAL 60 MINUTE))');
     $insert->execute(['type'=>$accountType,'id'=>$accountId,'hash'=>$tokenHash]);$resetId=(int)$db->lastInsertId();
     $url=$baseUrl.'/password-reset.php?token='.$rawToken;
     $body="Guten Tag,\n\nüber diesen einmaligen Link kannst du ein neues Passwort für dein Coaching Tool Konto festlegen:\n".$url."\n\nDer Link ist 60 Minuten gültig. Falls du diese E-Mail nicht angefordert hast, kannst du sie ignorieren.\n";
     try{coachingMailSend($db,$mailOwnerId,$registeredEmail,'Passwort für das Coaching Tool zurücksetzen',$body);}
     catch(Throwable $sendError){$remove=$db->prepare('DELETE FROM coaching_passwort_resets WHERE id=:id');$remove->execute(['id'=>$resetId]);error_log('Password reset delivery failed: '.$sendError->getMessage());}
    }
   }
  }catch(Throwable $e){error_log('Password reset request failed: '.$e->getMessage());}
 }
 $notice='Wenn Benutzername und E-Mail-Adresse zu einem aktiven Konto passen und der E-Mail-Versand eingerichtet ist, erhältst du eine Nachricht mit dem nächsten Schritt.';
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Passwort zurücksetzen</title><link rel="stylesheet" href="assets/layout.css"><style>.page{max-width:620px;margin:6vh auto;padding:28px}.card{background:#fff;border:1px solid #e4ebe6;border-radius:14px;padding:24px}.form{display:grid;gap:12px}.form label{display:grid;gap:5px;font-weight:600}.form input{padding:11px;border:1px solid #dce5df;border-radius:8px;font:inherit}.button{background:#245c50;color:white;border:0;border-radius:8px;padding:11px 15px;font:inherit;cursor:pointer}.notice{padding:12px;border-radius:8px;background:#edf5ef}.hint{color:#718078;font-size:13px}</style></head><body><main class="page"><section class="card"><p class="hint">Coaching Tool</p><h1>Passwort zurücksetzen</h1><p>Gib den Benutzernamen und die E-Mail-Adresse ein, die beim Konto hinterlegt sind.</p><?php if($notice):?><p class="notice"><?=forgotH($notice)?></p><?php endif;?><?php if($error):?><p role="alert"><?=forgotH($error)?></p><?php endif;?><form method="post" class="form"><input type="hidden" name="csrf_token" value="<?=forgotH($token)?>"><label>Benutzername<input name="username" maxlength="80" autocomplete="username" required></label><label>E-Mail-Adresse<input type="email" name="email" maxlength="190" autocomplete="email" required></label><button class="button" type="submit">Rücksetzlink anfordern</button></form><p class="hint"><a href="./">Zur Anmeldung</a></p></section></main></body></html>
