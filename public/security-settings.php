<?php
declare(strict_types=1);
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';ini_set('session.use_strict_mode','1');session_name('coaching_tool_session');session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);session_start();header('Cache-Control: no-store, private');
require_once dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/totp.php';require_once dirname(__DIR__).'/app/navigation.php';
function securityH(string|int|null $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$user=$_SESSION['user']??null;if(!is_array($user)||!isset($user['id'],$user['role'])||$user['role']==='client'){http_response_code(403);exit('Diese Einstellung steht nur Coach-, Verwalter- und Administratorkonten zur Verfügung.');}
$db=coachingDatabase();$id=(int)$user['id'];$q=$db->prepare('SELECT mfa_aktiv,mfa_ersatzcodes_hashes FROM benutzer WHERE id=:id AND aktiv=1');$q->execute(['id'=>$id]);$account=$q->fetch(PDO::FETCH_ASSOC);if(!$account){http_response_code(403);exit('Das Benutzerkonto ist nicht aktiv.');}
$token=$_SESSION['csrf_token']??'';if(!is_string($token)||strlen($token)!==64){$token=bin2hex(random_bytes(32));$_SESSION['csrf_token']=$token;}
$error='';$notice=isset($_GET['updated'])?'Die Zwei-Faktor-Anmeldung wurde auf ein neues Gerät übertragen.':(isset($_GET['reset'])?'Die Zwei-Faktor-Anmeldung wurde zurückgesetzt. Beim nächsten Login muss sie neu eingerichtet werden.':'');
if($_SERVER['REQUEST_METHOD']==='POST'){
 $posted=$_POST['csrf_token']??'';$password=is_string($_POST['password']??null)?$_POST['password']:'';$code=trim(is_string($_POST['code']??null)?$_POST['code']:'');
 if(!is_string($posted)||!hash_equals($token,$posted))$error='Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
 else{
  try{
   $q=$db->prepare('SELECT passwort_hash,mfa_aktiv,mfa_geheimnis_verschluesselt,mfa_ersatzcodes_hashes FROM benutzer WHERE id=:id AND aktiv=1');$q->execute(['id'=>$id]);$fresh=$q->fetch(PDO::FETCH_ASSOC);
   if(!$fresh||!password_verify($password,(string)$fresh['passwort_hash']))$error='Das Passwort oder der aktuelle App-Code ist nicht korrekt.';
   elseif((int)$fresh['mfa_aktiv']!==1)$error='Die Zwei-Faktor-Anmeldung ist noch nicht eingerichtet.';
   else{
    $valid=coachingMfaVerifyTotp(coachingMfaDecrypt((string)$fresh['mfa_geheimnis_verschluesselt']),$code);
    $hashes=json_decode((string)($fresh['mfa_ersatzcodes_hashes']??'[]'),true);if(!is_array($hashes))$hashes=[];
    $remaining=$valid?null:coachingMfaConsumeRecoveryCode($code,$hashes);
    if(!$valid&&$remaining===null)$error='Das Passwort oder der aktuelle App-Code ist nicht korrekt.';
    else{
     if($remaining!==null){$old=(string)$fresh['mfa_ersatzcodes_hashes'];$save=$db->prepare('UPDATE benutzer SET mfa_ersatzcodes_hashes=:new WHERE id=:id AND mfa_ersatzcodes_hashes=:old');$save->execute(['new'=>json_encode($remaining,JSON_THROW_ON_ERROR),'old'=>$old,'id'=>$id]);if($save->rowCount()!==1)throw new RuntimeException('Recovery code already used.');}
     $_SESSION['mfa_setup_secret']=coachingMfaNewSecret();$_SESSION['mfa_setup_authorized']=1;header('Location: mfa-setup.php');exit;
    }
   }
  }catch(Throwable $e){error_log('Coaching Tool MFA rotation request failed: '.$e->getMessage());$error='Die Einrichtung konnte nicht gestartet werden. Prüfe den aktuellen App-Code und versuche es erneut.';}
 }
}
$codes=json_decode((string)($account['mfa_ersatzcodes_hashes']??'[]'),true);$remainingCount=is_array($codes)?count($codes):0;$csrf=$_SESSION['csrf_token'];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sicherheit · Coaching Tool</title><link rel="stylesheet" href="assets/layout.css"><script src="assets/layout.js" defer></script><style>.page{max-width:900px;margin:auto;padding:32px clamp(20px,4vw,56px) 64px}.card{background:#fff;border:1px solid #e3ebe5;border-radius:14px;padding:22px;margin:20px 0}.form{display:grid;gap:10px;max-width:520px}.form input{padding:11px;border:1px solid #dce5df;border-radius:8px;font:inherit}.button{border:0;border-radius:8px;padding:11px 15px;background:#245c50;color:#fff;font:inherit;cursor:pointer}.notice,.error{padding:12px;border-radius:8px}.notice{background:#edf5ef}.error{background:#fff3f1}</style></head><body class="has-app-sidebar"><?php renderCoachingSidebar($user,'security-settings',$csrf);?><main class="page"><h1>Sicherheit</h1><p>Die Zwei-Faktor-Anmeldung schützt dein Konto zusätzlich zum Passwort und ist für Coach-, Verwalter- und Administratorkonten erforderlich.</p><?php if($notice):?><p class="notice" role="status"><?=securityH($notice)?></p><?php endif;?><?php if($error):?><p class="error" role="alert"><?=securityH($error)?></p><?php endif;?><section class="card"><h2>Status: <?=((int)$account['mfa_aktiv']===1?'Aktiv':'Einrichtung erforderlich')?></h2><p>Verbleibende Wiederherstellungscodes: <?=$remainingCount?>. Beim Umzug auf ein neues Handy kannst du die Anmeldung hier sicher neu einrichten.</p><form class="form" method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?=securityH($csrf)?>"><label>Aktuelles Passwort<input type="password" name="password" autocomplete="current-password" required></label><label>Aktueller App-Code oder Wiederherstellungscode<input name="code" autocomplete="one-time-code" required></label><button class="button" type="submit">Authenticator wechseln</button></form></section></main></body></html>