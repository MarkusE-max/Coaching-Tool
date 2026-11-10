<?php
declare(strict_types=1);
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';
ini_set('session.use_strict_mode','1');session_name('coaching_tool_session');
session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/mail.php';
function emailSettingsH(string|int|null $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$user=$_SESSION['user']??null;
if(!is_array($user)||!in_array(($user['role']??''),['admin','coach','verwalter'],true)){http_response_code(403);exit('Nur angemeldete Coaches und Verwaltungskonten können ihre E-Mail-Anbindung verwalten.');}
$db=coachingDatabase();$ownerId=(int)$user['id'];
$token=$_SESSION['csrf_token']??'';if(!is_string($token)||strlen($token)!==64){$token=bin2hex(random_bytes(32));$_SESSION['csrf_token']=$token;}
$error='';$notice=isset($_GET['saved'])?'E-Mail-Einstellungen wurden gespeichert.':'';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($token,(string)($_POST['csrf_token']??''))){http_response_code(400);exit('Ungültige Anfrage.');}
 $action=(string)($_POST['action']??'');
 try{
  if($action==='save'){
   $host=trim((string)($_POST['smtp_host']??''));$port=filter_var($_POST['smtp_port']??null,FILTER_VALIDATE_INT);
   $security=(string)($_POST['smtp_sicherheit']??'starttls');$username=trim((string)($_POST['smtp_benutzername']??''));
   $fromName=trim((string)($_POST['absender_name']??''));$fromEmail=trim((string)($_POST['absender_email']??''));$replyEmail=trim((string)($_POST['antwort_email']??''));
   $enabled=isset($_POST['aktiv'])?1:0;$auth=isset($_POST['smtp_auth'])?1:0;
   $password=(string)($_POST['smtp_passwort']??'');$oldQuery=$db->prepare('SELECT smtp_passwort_verschluesselt FROM coaching_email_einstellungen WHERE benutzer_id=:owner');$oldQuery->execute(['owner'=>$ownerId]);$old=$oldQuery->fetchColumn();
   $cipher=is_string($old)&&$old!==''?$old:null;
   if($password!=='')$cipher=coachingMailEncryptPassword($password);
   if($host===''||strlen($host)>253||!preg_match('/^[A-Za-z0-9.-]+$/',$host))throw new InvalidArgumentException('Bitte den SMTP-Servernamen ohne Leerzeichen eingeben.');
   if($port===false||$port<1||$port>65535)throw new InvalidArgumentException('Der SMTP-Port muss zwischen 1 und 65535 liegen.');
   if(!in_array($security,['keine','starttls','ssl'],true))throw new InvalidArgumentException('Bitte eine gültige Verbindungsverschlüsselung auswählen.');
   if(strlen($username)>190||strlen($fromName)>190||!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)||($replyEmail!==''&&!filter_var($replyEmail,FILTER_VALIDATE_EMAIL)))throw new InvalidArgumentException('Bitte Absender- und Antwortadresse prüfen.');
   if($auth&&$username==='')throw new InvalidArgumentException('Für SMTP-Anmeldung ist ein Benutzername erforderlich.');
   if($auth&&$cipher===null)throw new InvalidArgumentException('Für SMTP-Anmeldung muss ein Passwort hinterlegt werden.');
   if($enabled&&$auth&&$password==='')coachingMailDecryptPassword($cipher);
   $save=$db->prepare('INSERT INTO coaching_email_einstellungen (benutzer_id,aktiv,smtp_host,smtp_port,smtp_sicherheit,smtp_auth,smtp_benutzername,smtp_passwort_verschluesselt,absender_name,absender_email,antwort_email) VALUES (:owner,:aktiv,:host,:port,:security,:auth,:username,:password,:from_name,:from_email,:reply_email) ON DUPLICATE KEY UPDATE aktiv=VALUES(aktiv),smtp_host=VALUES(smtp_host),smtp_port=VALUES(smtp_port),smtp_sicherheit=VALUES(smtp_sicherheit),smtp_auth=VALUES(smtp_auth),smtp_benutzername=VALUES(smtp_benutzername),smtp_passwort_verschluesselt=VALUES(smtp_passwort_verschluesselt),absender_name=VALUES(absender_name),absender_email=VALUES(absender_email),antwort_email=VALUES(antwort_email)');
   $save->execute(['owner'=>$ownerId,'aktiv'=>$enabled,'host'=>$host,'port'=>$port,'security'=>$security,'auth'=>$auth,'username'=>$username?:null,'password'=>$cipher,'from_name'=>$fromName?:'Coaching Tool','from_email'=>$fromEmail,'reply_email'=>$replyEmail?:null]);
   header('Location: email-settings.php?saved=1');exit;
  }
  if($action==='test'){
   $testTo=trim((string)($_POST['test_email']??''));
   if(!filter_var($testTo,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Bitte eine gültige Zieladresse für den Test eingeben.');
   coachingMailSend($db,$ownerId,$testTo,'Coaching Tool – SMTP-Test','Dies ist eine Testnachricht aus dem Coaching Tool. Der SMTP-Versand ist erreichbar.');
   $notice='Testmail wurde versendet.';
  }else{
   if($action!=='save')throw new InvalidArgumentException('Unbekannte Aktion.');
  }
 }catch(InvalidArgumentException $e){$error=$e->getMessage();}
 catch(Throwable $e){error_log('Email settings operation failed: '.$e->getMessage());$error=$action==='test'?'Testmail konnte nicht versendet werden. Prüfe SMTP-Server, Port, Verschlüsselung und Anmeldung. Technische Details stehen im PHP-Fehlerprotokoll.':'E-Mail-Einstellungen konnten nicht gespeichert werden. Prüfe Migration 015 und die Tabellenrechte.';}
}
$settings=coachingMailSettings($db,$ownerId);
$keyConfigured=true;try{coachingMailEncryptionKey();}catch(Throwable $e){$keyConfigured=false;}
$appBaseUrl=coachingMailAppBaseUrl();
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>E-Mail-Anbindung</title><link rel="stylesheet" href="assets/layout.css"><style>
.page{max-width:960px;margin:auto;padding:32px clamp(20px,4vw,56px) 64px}.card{background:#fff;border:1px solid #e4ebe6;border-radius:14px;padding:22px;margin:18px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.form{display:grid;gap:12px}.form label{display:grid;gap:5px;font-weight:600}.form input,.form select{box-sizing:border-box;width:100%;padding:10px;border:1px solid #dce5df;border-radius:8px;font:inherit}.check{display:flex;align-items:center;gap:8px}.check input{width:auto}.button{background:#245c50;color:white;border:0;border-radius:8px;padding:10px 14px;font:inherit;cursor:pointer}.notice,.error,.hintbox{padding:12px;border-radius:9px}.notice{background:#edf5ef}.error{background:#fff3f1}.hintbox{background:#f6f8f6}.hint{font-size:13px;color:#718078}.warning{background:#fff4e8;color:#704313;padding:12px;border-radius:9px}@media(max-width:720px){.grid{grid-template-columns:1fr}}
</style></head><body class="has-app-sidebar"><?php require_once dirname(__DIR__).'/app/navigation.php';renderCoachingSidebar($user,'email-settings',$token);?><main class="page"><h1>Meine E-Mail-Anbindung</h1><p>Diese SMTP-Einstellungen gehören zu deinem Konto. Coachees, die deinem Coaching zugeordnet sind, erhalten ihre Rücksetzlinks über deinen Absender. Die Zugangsdaten werden verschlüsselt gespeichert.</p>
<?php if($notice):?><p class="notice"><?=emailSettingsH($notice)?></p><?php endif;?><?php if($error):?><p class="error"><?=emailSettingsH($error)?></p><?php endif;?>
<?php if(!$keyConfigured):?><p class="warning">Für SMTP-Passwörter fehlt noch <code>mail_encryption_key</code> in der privaten <code>config.local.php</code>. Trage dort einen zufälligen Schlüssel mit 64 Hex-Zeichen ein. Die Passwort-Zurücksetzung benötigt außerdem eine gültige HTTPS-Adresse in <code>app_base_url</code>.</p><?php elseif(!$appBaseUrl):?><p class="warning">Bitte ergänze <code>app_base_url</code> in der privaten <code>config.local.php</code> mit der HTTPS-Adresse, unter der das Coaching Tool erreichbar ist. Ohne HTTPS werden keine Rücksetzlinks versendet.</p><?php endif;?>
<section class="card"><h2>SMTP-Server</h2><form method="post" class="form"><input type="hidden" name="csrf_token" value="<?=emailSettingsH($token)?>"><input type="hidden" name="action" value="save"><div class="grid"><label><span>SMTP-Server</span><input name="smtp_host" required maxlength="253" value="<?=emailSettingsH($settings['smtp_host'])?>" placeholder="Servername des E-Mail-Anbieters"></label><label><span>Port</span><input type="number" name="smtp_port" required min="1" max="65535" value="<?=(int)$settings['smtp_port']?>"></label><label><span>Verbindungsschutz</span><select name="smtp_sicherheit"><option value="starttls" <?=$settings['smtp_sicherheit']==='starttls'?'selected':''?>>STARTTLS</option><option value="ssl" <?=$settings['smtp_sicherheit']==='ssl'?'selected':''?>>SSL/TLS</option><option value="keine" <?=$settings['smtp_sicherheit']==='keine'?'selected':''?>>Keine (nur internes, geschütztes Netzwerk)</option></select></label><label><span>SMTP-Benutzername</span><input name="smtp_benutzername" maxlength="190" autocomplete="username" value="<?=emailSettingsH($settings['smtp_benutzername'])?>"></label><label><span>Absendername</span><input name="absender_name" maxlength="190" value="<?=emailSettingsH($settings['absender_name'])?>"></label><label><span>Absender-E-Mail</span><input type="email" name="absender_email" required maxlength="190" value="<?=emailSettingsH($settings['absender_email'])?>"></label><label><span>Antwortadresse (optional)</span><input type="email" name="antwort_email" maxlength="190" value="<?=emailSettingsH($settings['antwort_email']??'')?>"></label></div><p class="hint">Antworten gehen an diese Adresse. Leer lassen, um die Absender-E-Mail zu verwenden.</p><label class="check"><input type="checkbox" name="smtp_auth" value="1" <?=$settings['smtp_auth']?'checked':''?>> SMTP-Anmeldung verwenden</label><label><span>SMTP-Passwort<?=!empty($settings['smtp_passwort_verschluesselt'])?' (leer lassen, um gespeichertes Passwort beizubehalten)':''?></span><input type="password" name="smtp_passwort" maxlength="1000" autocomplete="new-password" placeholder="<?=!empty($settings['smtp_passwort_verschluesselt'])?'Gespeichert':'Nur bei SMTP-Anmeldung erforderlich'?>"></label><label class="check"><input type="checkbox" name="aktiv" value="1" <?=$settings['aktiv']?'checked':''?>> E-Mail-Versand aktivieren</label><p class="hint">Für Gmail, WEB.DE, GMX und andere Anbieter gelten deren aktuelle SMTP-Serverdaten. Falls der Anbieter App-Passwörter verlangt, verwende ein App-Passwort statt deines normalen Kontopassworts. Ein lokaler SMTP-Server kann genauso über Servername, Port und Verschlüsselung eingetragen werden.</p><button class="button" type="submit">Einstellungen speichern</button></form></section>
<section class="card"><h2>Verbindung testen</h2><p>Die Testmail wird über die gespeicherten Einstellungen versendet.</p><form method="post" class="form"><input type="hidden" name="csrf_token" value="<?=emailSettingsH($token)?>"><input type="hidden" name="action" value="test"><label><span>Testempfänger</span><input type="email" name="test_email" required maxlength="190" placeholder="deine E-Mail-Adresse"></label><button class="button" type="submit">Testmail senden</button></form></section>
<section class="hintbox"><h2>Passwort-Zurücksetzung</h2><p>Dein persönlicher SMTP-Server versendet deine Rücksetzlinks. Coachee-Rücksetzlinks verwenden die Einstellungen des aktuell zugeordneten Coaches, damit Antworten direkt bei diesem Coach ankommen. Links sind einmalig verwendbar und 60 Minuten gültig. Der Link nutzt die konfigurierte HTTPS-Adresse: <strong><?=emailSettingsH($appBaseUrl?:'Noch nicht eingerichtet')?></strong></p><p>Coachee-Konten benötigen zusätzlich eine E-Mail-Adresse im Bereich „Coachee-Zugang“ der jeweiligen Klientenakte.</p></section></main></body></html>
