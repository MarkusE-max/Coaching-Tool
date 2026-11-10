<?php
declare(strict_types=1);
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';
ini_set('session.use_strict_mode','1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/mail.php';
function accessH(string|int|null $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$user=$_SESSION['user']??null;
if(!is_array($user)||($user['role']??'')!=='coach'){http_response_code(403);exit('Nur Coaches können Klientenzugänge verwalten.');}
$db=coachingDatabase();
$coach=(int)$user['id'];
$clientId=filter_input(INPUT_GET,'klient_id',FILTER_VALIDATE_INT)?:0;
$q=$db->prepare('SELECT id,vorname,nachname FROM klienten WHERE id=:id AND benutzer_id=:coach');
$q->execute(['id'=>$clientId,'coach'=>$coach]);
$client=$q->fetch(PDO::FETCH_ASSOC);
if(!$client){http_response_code(404);exit('Klient nicht gefunden.');}
$token=$_SESSION['csrf_token']??'';
if(!is_string($token)||strlen($token)!==64){$token=bin2hex(random_bytes(32));$_SESSION['csrf_token']=$token;}
$error=isset($_GET['mail_failed'])?'Der Zugang wurde gespeichert, aber die Einladungs-E-Mail wurde nicht versendet. Prüfe deine E-Mail-Einstellungen und sende die Einladung erneut.':'';
$notice=isset($_GET['invited'])?'Einladung wurde per E-Mail versendet.':(isset($_GET['saved'])?'Der Klientenzugang wurde aktualisiert.':'');
$q=$db->prepare('SELECT id,benutzername,email,aktiv,letzter_login FROM coaching_portal_zugaenge WHERE klient_id=:client');
$q->execute(['client'=>$clientId]);
$access=$q->fetch(PDO::FETCH_ASSOC)?:['id'=>0,'benutzername'=>'','email'=>'','aktiv'=>0,'letzter_login'=>null];

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($token,(string)($_POST['csrf_token']??''))){http_response_code(400);exit('Ungültige Anfrage.');}
    $action=(string)($_POST['action']??'');
    if($action==='revoke'){
        $s=$db->prepare('UPDATE coaching_portal_zugaenge p INNER JOIN klienten k ON k.id=p.klient_id SET p.aktiv=0 WHERE p.klient_id=:client AND k.benutzer_id=:coach');
        $s->execute(['client'=>$clientId,'coach'=>$coach]);
        header('Location: portal-access.php?klient_id='.$clientId.'&saved=1');exit;
    }
    if($action==='send_invitation'){
        try{
            if((int)$access['id']<1||(int)$access['aktiv']!==1)throw new RuntimeException('Es gibt keinen aktiven Coachee-Zugang zum Einladen.');
            coachingMailSendCoacheeInvitation($db,$coach,(int)$access['id']);
            header('Location: portal-access.php?klient_id='.$clientId.'&invited=1');exit;
        }catch(Throwable $e){
            $error=$e instanceof RuntimeException?$e->getMessage():'Die Einladung konnte nicht versendet werden.';
            error_log('Coachee invitation resend failed: '.$e->getMessage());
        }
    }elseif($action==='save'){
        $name=trim((string)($_POST['benutzername']??''));
        $email=trim((string)($_POST['email']??''));
        $password=(string)($_POST['passwort']??'');
        if(!preg_match('/^[A-Za-z0-9._-]{4,80}$/',$name))$error='Benutzername: 4 bis 80 Zeichen, erlaubt sind Buchstaben, Zahlen, Punkt, Bindestrich und Unterstrich.';
        elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)$error='Bitte eine gültige E-Mail-Adresse für den Coachee eingeben.';
        elseif($password!==''&&(strlen($password)<8||strlen($password)>4096||!preg_match('/[^A-Za-z0-9]/',$password)))$error='Das Passwort muss mindestens 8 Zeichen und mindestens ein Sonderzeichen enthalten.';
        else{
            $isNew=trim((string)($access['benutzername']??''))==='';
            try{
                $conflict=$db->prepare('SELECT benutzername FROM benutzer WHERE benutzername=:name UNION ALL SELECT benutzername FROM coaching_portal_zugaenge WHERE benutzername=:name2 AND klient_id<>:client LIMIT 1');
                $conflict->execute(['name'=>$name,'name2'=>$name,'client'=>$clientId]);
                if($conflict->fetchColumn())throw new RuntimeException('Dieser Benutzername ist bereits vergeben.');
                $hash=password_hash($password!==''?$password:bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
                $s=$db->prepare('INSERT INTO coaching_portal_zugaenge (klient_id,benutzername,email,passwort_hash,aktiv) VALUES (:client,:name,:email,:hash,1) ON DUPLICATE KEY UPDATE benutzername=VALUES(benutzername),email=VALUES(email),passwort_hash=IF(:password_set=1,VALUES(passwort_hash),passwort_hash),aktiv=1');
                $s->execute(['client'=>$clientId,'name'=>$name,'email'=>$email,'hash'=>$hash,'password_set'=>$password!==''?1:0]);
                if($isNew){
                    $portalQuery=$db->prepare('SELECT id FROM coaching_portal_zugaenge WHERE klient_id=:client');
                    $portalQuery->execute(['client'=>$clientId]);
                    $portalId=(int)$portalQuery->fetchColumn();
                    try{
                        coachingMailSendCoacheeInvitation($db,$coach,$portalId);
                    }catch(Throwable $mailError){
                        error_log('Coachee invitation delivery failed: '.$mailError->getMessage());
                        header('Location: portal-access.php?klient_id='.$clientId.'&mail_failed=1');exit;
                    }
                    header('Location: portal-access.php?klient_id='.$clientId.'&invited=1');exit;
                }
                header('Location: portal-access.php?klient_id='.$clientId.'&saved=1');exit;
            }catch(Throwable $e){
                $error=$e instanceof RuntimeException?$e->getMessage():'Zugang konnte nicht gespeichert werden. Prüfe Migration 015 und die Tabellenrechte.';
                error_log('Portal access save failed: '.$e->getMessage());
            }
        }
    }else{$error='Unbekannte Aktion.';}
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Klientenzugang einrichten</title><link rel="stylesheet" href="assets/layout.css"><style>.page{max-width:800px;margin:auto;padding:34px clamp(24px,4vw,58px) 62px}.card{background:white;border:1px solid #e4ebe6;border-radius:14px;padding:22px;margin:18px 0}.form{display:grid;gap:12px}.form input{padding:12px;border:1px solid #dce5df;border-radius:8px;font:inherit}.button{padding:11px 15px;border:0;border-radius:8px;background:#245c50;color:white;font:inherit}.danger{background:#fff3f1;color:#8c3434}.notice,.error{padding:12px;border-radius:8px}.notice{background:#edf5ef}.error{background:#fff3f1}</style></head><body class="has-app-sidebar"><?php require_once dirname(__DIR__).'/app/navigation.php';renderCoachingSidebar($user,'clients',$token);?><main class="page"><a href="client-overview.php?id=<?=$clientId?>">← Zur Klientenakte</a><h1>Coachee-Zugang</h1><p><?=accessH($client['vorname'].' '.$client['nachname'])?> erhält ein eigenes Login für Termine, Ziele, Aufgaben und freigegebene Dokumente. Beim Anlegen senden wir Benutzername, Portal-Link und einen einmaligen Link zum Festlegen des Passworts per E-Mail.</p><?php if($notice):?><p class="notice"><?=accessH($notice)?></p><?php endif;?><?php if($error):?><p class="error"><?=accessH($error)?></p><?php endif;?><section class="card"><h2><?=$access['aktiv']?'Zugang aktiv':'Zugang einrichten'?></h2><?php if($access['letzter_login']):?><p>Letzte Anmeldung: <?=accessH(date('d.m.Y H:i',strtotime($access['letzter_login'])))?></p><?php endif;?><form method="post" class="form"><input type="hidden" name="csrf_token" value="<?=accessH($token)?>"><input type="hidden" name="action" value="save"><label>Benutzername<input name="benutzername" required minlength="4" maxlength="80" pattern="[A-Za-z0-9._-]+" value="<?=accessH($access['benutzername'])?>"></label><label>E-Mail für Passwort-Zurücksetzung<input type="email" name="email" maxlength="190" autocomplete="email" required value="<?=accessH($access['email']??'')?>"></label><label><?=$access['benutzername']?'Neues Passwort festlegen (optional)':'Startpasswort (optional)'?> (mindestens 8 Zeichen und ein Sonderzeichen)<input type="password" name="passwort" minlength="8" maxlength="4096" autocomplete="new-password"></label><?php if($access['benutzername']):?><p class="hint">Leer lassen, um das bestehende Passwort beizubehalten. Die E-Mail-Adresse kann unabhängig davon geändert werden.</p><?php endif;?><button class="button">Zugang speichern</button></form><p>Das Passwort selbst wird niemals per E-Mail verschickt. Wenn du kein Startpasswort einträgst, erhält der Zugang ein zufälliges internes Passwort; der Coachee legt sein persönliches Passwort über den einmaligen E-Mail-Link fest.</p><?php if($access['aktiv']):?><form method="post" style="margin:0 0 14px"><input type="hidden" name="csrf_token" value="<?=accessH($token)?>"><input type="hidden" name="action" value="send_invitation"><button class="button">Einladung erneut per E-Mail senden</button></form><form method="post" onsubmit="return confirm('Zugang wirklich sperren?')"><input type="hidden" name="csrf_token" value="<?=accessH($token)?>"><input type="hidden" name="action" value="revoke"><button class="button danger">Zugang sperren</button></form><?php endif;?></section></main></body></html>