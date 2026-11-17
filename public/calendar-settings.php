<?php
declare(strict_types=1);
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';
ini_set('session.use_strict_mode','1');session_name('coaching_tool_session');
session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/mail.php';require_once dirname(__DIR__).'/app/calendar.php';
function calendarH(string|int|null $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$user=$_SESSION['user']??null;if(!is_array($user)||($user['role']??'')!=='coach'){http_response_code(403);exit('Die Kalenderanbindung steht Coaches zur Verfügung.');}
$db=coachingDatabase();$id=(int)$user['id'];$settings=coachingCalendarSettings($db,$id);$token=$_SESSION['csrf_token']??'';if(!is_string($token)||strlen($token)!==64){$token=bin2hex(random_bytes(32));$_SESSION['csrf_token']=$token;}
$error='';$notice=isset($_GET['saved'])?'Kalendereinstellungen gespeichert.':'';
$cfg=coachingCalendarConfig();$callback='';
$base=coachingMailAppBaseUrl();if($base)$callback=$base.'/calendar-settings.php';
if(isset($_GET['oauth'])&&$_GET['oauth']==='google'){
 try{
  if(!isset($_SESSION['calendar_oauth_state'])||!hash_equals((string)$_SESSION['calendar_oauth_state'],(string)($_GET['state']??'')))throw new RuntimeException('Die Google-Anmeldung ist abgelaufen. Bitte erneut starten.');
  unset($_SESSION['calendar_oauth_state']);
  if(isset($_GET['error']))throw new RuntimeException('Google hat die Kalenderfreigabe nicht erteilt.');
  $code=(string)($_GET['code']??'');if($code==='')throw new RuntimeException('Google hat keinen Anmeldecode zurückgegeben.');
  [$st,$body]=coachingCalendarHttp('https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['code'=>$code,'client_id'=>$cfg['client_id']??'','client_secret'=>$cfg['client_secret']??'','redirect_uri'=>$callback,'grant_type'=>'authorization_code']),'POST');
  $data=json_decode($body,true);if($st!==200||!is_array($data)||empty($data['access_token']))throw new RuntimeException('Google-Anmeldung fehlgeschlagen. Prüfe OAuth-Client und Rückrufadresse.');
  $refresh=(string)($data['refresh_token']??'');if($refresh==='')$refresh=coachingCalendarDecrypt($settings['refresh_token_verschluesselt']??null);
  if($refresh==='')throw new RuntimeException('Google hat kein dauerhaftes Zugriffstoken zurückgegeben. Verbindung erneut starten und Freigabe bestätigen.');
  [$us,$ub]=coachingCalendarHttp('https://www.googleapis.com/oauth2/v2/userinfo',['Authorization: Bearer '.$data['access_token']]);$who=json_decode($ub,true);
  $save=$db->prepare("INSERT INTO coaching_kalender_einstellungen (benutzer_id,provider,aktiv,google_email,refresh_token_verschluesselt,access_token_verschluesselt,token_ablauf_am) VALUES (:id,'google',0,:email,:refresh,:access,:expires) ON DUPLICATE KEY UPDATE provider='google',google_email=VALUES(google_email),refresh_token_verschluesselt=VALUES(refresh_token_verschluesselt),access_token_verschluesselt=VALUES(access_token_verschluesselt),token_ablauf_am=VALUES(token_ablauf_am)");
  $save->execute(['id'=>$id,'email'=>$who['email']??null,'refresh'=>coachingCalendarEncrypt($refresh),'access'=>coachingCalendarEncrypt((string)$data['access_token']),'expires'=>date('Y-m-d H:i:s',time()+(int)($data['expires_in']??3600))]);
  header('Location: calendar-settings.php?connected=1');exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
if(isset($_GET['connected']))$notice='Google-Konto verbunden. Wähle unten den Kalender aus und aktiviere die Synchronisierung.';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($token,(string)($_POST['csrf_token']??'')))throw new RuntimeException('Seite abgelaufen, bitte neu laden.');
  $action=(string)($_POST['action']??'');
  if($action==='google'){
   if(empty($cfg['client_id'])||empty($cfg['client_secret'])||!$callback)throw new RuntimeException('Google OAuth muss zuerst in config.local.php eingerichtet werden; app_base_url muss die HTTPS-Adresse dieser App enthalten.');
   $state=bin2hex(random_bytes(32));$_SESSION['calendar_oauth_state']=$state;
   $url='https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>$cfg['client_id'],'redirect_uri'=>$callback,'response_type'=>'code','scope'=>'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/userinfo.email','access_type'=>'offline','prompt'=>'consent','state'=>$state]);
   header('Location: '.$url);exit;
  }
  if($action==='icloud'){
   $apple=trim((string)($_POST['apple_id']??''));$pw=(string)($_POST['app_password']??'');if(!filter_var($apple,FILTER_VALIDATE_EMAIL)||$pw==='')throw new RuntimeException('Apple-ID und App-spezifisches Passwort eingeben.');
   $calendars=coachingIcloudCalendars($apple,$pw);
   $up=$db->prepare("INSERT INTO coaching_kalender_einstellungen (benutzer_id,provider,aktiv,apple_id,icloud_passwort_verschluesselt) VALUES (:id,'icloud',0,:apple,:pw) ON DUPLICATE KEY UPDATE provider='icloud',apple_id=VALUES(apple_id),icloud_passwort_verschluesselt=VALUES(icloud_passwort_verschluesselt),aktiv=0");
   $up->execute(['id'=>$id,'apple'=>$apple,'pw'=>coachingCalendarEncrypt($pw)]);$settings=coachingCalendarSettings($db,$id);$notice='iCloud-Konto geprüft. Wähle unten den Kalender und aktiviere die Synchronisierung.';
  }
  if($action==='save'){
   $provider=(string)($_POST['provider']??'');$calendar=(string)($_POST['kalender_id']??'');$name=(string)($_POST['kalender_name']??'');
   if(!in_array($provider,['google','icloud'],true)||$calendar===''||strlen($calendar)>1000)throw new RuntimeException('Bitte einen Kalender auswählen.');
   $up=$db->prepare('UPDATE coaching_kalender_einstellungen SET provider=:provider,kalender_id=:cal,kalender_name=:name,klientenname_anzeigen=:show,aktiv=:active WHERE benutzer_id=:id');
   $up->execute(['provider'=>$provider,'cal'=>$calendar,'name'=>$name,'show'=>isset($_POST['klientenname_anzeigen'])?1:0,'active'=>isset($_POST['aktiv'])?1:0,'id'=>$id]);
   header('Location: calendar-settings.php?saved=1');exit;
  }
  if($action==='disconnect'){$q=$db->prepare('UPDATE coaching_kalender_einstellungen SET aktiv=0,refresh_token_verschluesselt=NULL,access_token_verschluesselt=NULL,icloud_passwort_verschluesselt=NULL,kalender_id=NULL,kalender_name=NULL WHERE benutzer_id=:id');$q->execute(['id'=>$id]);header('Location: calendar-settings.php?saved=1');exit;}
 }catch(Throwable $e){error_log('Calendar settings failed: '.$e->getMessage());$error=$e instanceof InvalidArgumentException||str_starts_with($e->getMessage(),'Google')||str_starts_with($e->getMessage(),'Apple')||str_starts_with($e->getMessage(),'iCloud')||str_starts_with($e->getMessage(),'Bitte')||str_starts_with($e->getMessage(),'Die')||str_starts_with($e->getMessage(),'Seite')?$e->getMessage():'Kalendereinstellungen konnten nicht gespeichert werden. Prüfe Migration 020, Tabellenrechte und PHP-cURL.';}
}
$settings=coachingCalendarSettings($db,$id);$calendars=[];
try{if($settings['provider']==='google'&&!empty($settings['refresh_token_verschluesselt']))$calendars=coachingGoogleCalendars(coachingGoogleToken($settings+['benutzer_id'=>$id],$db));elseif($settings['provider']==='icloud'&&!empty($settings['icloud_passwort_verschluesselt']))$calendars=coachingIcloudCalendars((string)$settings['apple_id'],coachingCalendarDecrypt($settings['icloud_passwort_verschluesselt']));}catch(Throwable $e){if($error==='')$error=$e->getMessage();}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kalenderanbindung</title><link rel="stylesheet" href="assets/layout.css"><style>.page{max-width:920px;margin:auto;padding:32px clamp(20px,4vw,56px) 64px}.card{background:#fff;border:1px solid #e4ebe6;border-radius:14px;padding:22px;margin:18px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.form{display:grid;gap:12px}.form label{display:grid;gap:5px;font-weight:600}.form input,.form select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #dce5df;border-radius:8px;font:inherit}.check{display:flex;align-items:center;gap:8px}.check input{width:auto}.button{background:#245c50;color:white;border:0;border-radius:8px;padding:10px 14px;font:inherit;cursor:pointer}.notice,.error,.hint{padding:12px;border-radius:9px}.notice{background:#edf5ef}.error{background:#fff3f1}.hint{background:#f6f8f6;color:#586a60}.muted{color:#718078;font-size:14px}@media(max-width:700px){.grid{grid-template-columns:1fr}}</style></head><body class="has-app-sidebar"><?php require_once dirname(__DIR__).'/app/navigation.php';renderCoachingSidebar($user,'calendar-settings',$token);?><main class="page"><h1>Meine Kalender</h1><p>Verbinde deinen Google- oder iCloud-Kalender. Neue und geänderte Kliententermine werden in den ausgewählten beschreibbaren Kalender eingetragen.</p>
<?php if($notice):?><p class="notice"><?=calendarH($notice)?></p><?php endif;?><?php if($error):?><p class="error"><?=calendarH($error)?></p><?php endif;?>
<section class="card"><h2>Google Kalender</h2><p class="muted">Verbindung mit deinem persönlichen Google-Konto; du kannst auch freigegebene Kalender auswählen, auf die du Schreibzugriff hast.</p><p class="hint">OAuth-Rückrufadresse für die Google Cloud Console: <strong><?=calendarH($callback?:'Bitte zuerst app_base_url auf HTTPS setzen')?></strong></p><form method="post"><input type="hidden" name="csrf_token" value="<?=calendarH($token)?>"><button class="button" name="action" value="google">Mit Google verbinden</button></form><?php if(!empty($settings['google_email'])):?><p>Verbunden: <?=calendarH($settings['google_email'])?></p><?php endif;?></section>
<section class="card"><h2>Apple iCloud</h2><p class="muted">Nutze ein Apple-App-spezifisches Passwort. Dein normales Apple-ID-Passwort wird nicht benötigt. Die Kalender, für die dein Konto Schreibrechte hat, werden auswählbar.</p><form method="post" class="form"><input type="hidden" name="csrf_token" value="<?=calendarH($token)?>"><input type="hidden" name="action" value="icloud"><div class="grid"><label>Apple-ID-E-Mail<input type="email" name="apple_id" required value="<?=calendarH($settings['apple_id']??'')?>"></label><label>App-spezifisches Passwort<input type="password" name="app_password" required autocomplete="new-password"></label></div><button class="button">iCloud verbinden und Kalender laden</button></form></section>
<?php if($calendars):?><section class="card"><h2>Kalender auswählen</h2><form method="post" class="form"><input type="hidden" name="csrf_token" value="<?=calendarH($token)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="provider" value="<?=calendarH($settings['provider'])?>"><label>Beschreibbarer Kalender<select name="kalender_id" required><?php foreach($calendars as $c):?><option value="<?=calendarH($c['id'])?>" data-name="<?=calendarH($c['name'])?>" <?=$settings['kalender_id']===$c['id']?'selected':''?>><?=calendarH($c['name'])?></option><?php endforeach;?></select></label><input type="hidden" name="kalender_name" id="kalender_name" value="<?=calendarH($settings['kalender_name']??'')?>"><label class="check"><input type="checkbox" name="klientenname_anzeigen" value="1" <?=!empty($settings['klientenname_anzeigen'])?'checked':''?>> Klientennamen im Kalender anzeigen</label><p class="muted">Standardmäßig steht dort nur „Coachingtermin“. Sitzungsnotizen und Themen werden nicht übertragen.</p><label class="check"><input type="checkbox" name="aktiv" value="1" <?=!empty($settings['aktiv'])?'checked':''?>> Termine automatisch synchronisieren</label><button class="button">Einstellungen speichern</button></form><form method="post" style="margin-top:14px"><input type="hidden" name="csrf_token" value="<?=calendarH($token)?>"><button class="button" name="action" value="disconnect" onclick="return confirm('Kalenderverbindung trennen? Bereits übertragene Termine bleiben im Kalender erhalten.')">Verbindung trennen</button></form></section><script>const s=document.querySelector('select[name="kalender_id"]'),n=document.getElementById('kalender_name');function setName(){n.value=s.options[s.selectedIndex]?.dataset.name||''}s.addEventListener('change',setName);setName();</script><?php endif;?>
<section class="card"><h2>Hinweis zur Synchronisierung</h2><p class="muted">Der Coaching Tool Kalender ist die führende Quelle. Angelegte oder geänderte Termine werden synchronisiert; abgesagte Termine werden im verbundenen Kalender entfernt. Beim Trennen bleiben bereits übertragene Einträge dort bestehen.</p></section></main></body></html>
