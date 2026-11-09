<?php
declare(strict_types=1);
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';
ini_set('session.use_strict_mode','1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__).'/app/bootstrap.php';
function engagementH(string|int|null $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$user=$_SESSION['user']??null;
if(!is_array($user)||($user['role']??'')!=='coach'){http_response_code(403);exit('Coaching-Aufträge sind Coaches vorbehalten.');}
$db=coachingDatabase();$coach=(int)$user['id'];$token=$_SESSION['csrf_token']??'';
if(!is_string($token)||strlen($token)!==64){$token=bin2hex(random_bytes(32));$_SESSION['csrf_token']=$token;}
$error='';$notice=isset($_GET['saved'])?'Änderungen gespeichert.':'';
$clientsQuery=$db->prepare('SELECT id,vorname,nachname FROM klienten WHERE benutzer_id=:coach ORDER BY nachname,vorname');
$clientsQuery->execute(['coach'=>$coach]);$clients=$clientsQuery->fetchAll(PDO::FETCH_ASSOC);

if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($token,(string)($_POST['csrf_token']??''))){http_response_code(400);exit('Ungültige Anfrage.');}
 $action=(string)($_POST['action']??'');
 try{
  if($action==='create'){
   $clientId=filter_var($_POST['klient_id']??null,FILTER_VALIDATE_INT);
   $title=trim((string)($_POST['titel']??''));$description=trim((string)($_POST['beschreibung']??''));
   if($clientId===false||$clientId<1||$title===''||strlen($title)>190||strlen($description)>10000)throw new RuntimeException('Bitte Klient, Titel und eine Beschreibung mit höchstens 10.000 Zeichen prüfen.');
   $check=$db->prepare('SELECT id FROM klienten WHERE id=:id AND benutzer_id=:coach');$check->execute(['id'=>$clientId,'coach'=>$coach]);
   if(!$check->fetchColumn())throw new RuntimeException('Der Klient gehört nicht zu deinem Zugang.');
   $active=$db->prepare("SELECT id FROM coaching_auftraege WHERE klient_id=:client AND coach_benutzer_id=:coach AND status='aktiv' LIMIT 1");
   $active->execute(['client'=>$clientId,'coach'=>$coach]);
   if($active->fetchColumn())throw new RuntimeException('Für diesen Klienten gibt es bereits einen aktiven Auftrag. Schließe ihn zuerst ab, bevor du einen neuen Auftrag anlegst.');
   $insert=$db->prepare("INSERT INTO coaching_auftraege (klient_id,coach_benutzer_id,titel,beschreibung,status) VALUES (:client,:coach,:title,:description,'aktiv')");
   $insert->execute(['client'=>$clientId,'coach'=>$coach,'title'=>$title,'description'=>$description?:null]);
   header('Location: engagements.php?saved=1');exit;
  }
  $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
  if($id===false||$id<1)throw new RuntimeException('Auftrag nicht gefunden.');
  $owned=$db->prepare('SELECT id,status,archiv_freigegeben FROM coaching_auftraege WHERE id=:id AND coach_benutzer_id=:coach FOR UPDATE');
  if($action==='close'||$action==='release'||$action==='revoke'){
   $db->beginTransaction();$owned->execute(['id'=>$id,'coach'=>$coach]);$order=$owned->fetch(PDO::FETCH_ASSOC);
   if(!$order)throw new RuntimeException('Auftrag nicht gefunden oder kein Zugriff.');
   if($action==='close'){
    if($order['status']!=='aktiv')throw new RuntimeException('Dieser Auftrag ist bereits abgeschlossen.');
    $db->prepare("UPDATE coaching_auftraege SET status='abgeschlossen',abgeschlossen_am=CURRENT_TIMESTAMP,archiv_freigegeben=0 WHERE id=:id AND coach_benutzer_id=:coach")->execute(['id'=>$id,'coach'=>$coach]);
   }else{
    if($order['status']!=='abgeschlossen')throw new RuntimeException('Ein aktiver Auftrag kann noch nicht als Archiv freigegeben werden.');
    $release=$action==='release'?1:0;
    $db->prepare('UPDATE coaching_auftraege SET archiv_freigegeben=:release WHERE id=:id AND coach_benutzer_id=:coach')->execute(['release'=>$release,'id'=>$id,'coach'=>$coach]);
   }
   $db->commit();header('Location: engagements.php?saved=1');exit;
  }
  throw new RuntimeException('Unbekannte Aktion.');
 }catch(RuntimeException $exception){if($db->inTransaction())$db->rollBack();$error=$exception->getMessage();}
 catch(Throwable $exception){if($db->inTransaction())$db->rollBack();error_log('Coaching engagement save failed: '.$exception->getMessage());$error='Die Änderung konnte nicht gespeichert werden. Prüfe Migration 012 und die Tabellenrechte.';}
}
$list=$db->prepare('SELECT a.*,k.vorname,k.nachname FROM coaching_auftraege a INNER JOIN klienten k ON k.id=a.klient_id WHERE a.coach_benutzer_id=:coach AND k.benutzer_id=:owner ORDER BY a.klient_id,a.erstellt_am DESC');
$list->execute(['coach'=>$coach,'owner'=>$coach]);$orders=$list->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Coaching-Aufträge</title><link rel="stylesheet" href="assets/layout.css"><style>
.page{max-width:1200px;margin:auto;padding:34px clamp(24px,4vw,58px) 62px}.grid{display:grid;grid-template-columns:minmax(270px,360px) 1fr;gap:18px}.card{background:#fff;border:1px solid #e4ebe6;border-radius:14px;padding:20px;margin:18px 0}.form{display:grid;gap:10px}.form input,.form select,.form textarea{width:100%;padding:11px;border:1px solid #dce5df;border-radius:9px;font:inherit}.form textarea{min-height:90px}.button{background:#245c50;color:#fff;border:0;border-radius:9px;padding:10px 13px;font:inherit;text-decoration:none;cursor:pointer}.button.secondary{background:#edf5ef;color:#245c50}.button.danger{background:#fff1ef;color:#963a30}.order{border-top:1px solid #edf1ee;padding:14px 0}.meta{color:#708078;font-size:13px}.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#edf5ef;color:#245c50;font-size:12px}.row-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.notice,.error{padding:12px;border-radius:8px}.notice{background:#edf5ef}.error{background:#fff3f1}@media(max-width:800px){.grid{grid-template-columns:1fr}}
</style></head><body class="has-app-sidebar"><?php require_once dirname(__DIR__).'/app/navigation.php';renderCoachingSidebar($user,'engagements',$token);?><main class="page">
<h1>Coaching-Aufträge</h1><p>Jeder neue Themenbereich erhält einen eigenen Auftrag. Abgeschlossene Aufträge bleiben für dich verfügbar; das Coachee-Archiv wird erst nach deiner Freigabe sichtbar.</p>
<?php if($notice):?><p class="notice"><?=engagementH($notice)?></p><?php endif;?><?php if($error):?><p class="error"><?=engagementH($error)?></p><?php endif;?>
<div class="grid"><section class="card"><h2>Neuen Auftrag anlegen</h2><form class="form" method="post"><input type="hidden" name="csrf_token" value="<?=engagementH($token)?>"><input type="hidden" name="action" value="create"><label>Klient<select name="klient_id" required><option value="">Bitte auswählen</option><?php foreach($clients as $client):?><option value="<?=(int)$client['id']?>"><?=engagementH($client['vorname'].' '.$client['nachname'])?></option><?php endforeach;?></select></label><label>Titel / Thema<input name="titel" maxlength="190" required placeholder="z. B. berufliche Neuorientierung"></label><label>Vereinbarung / Beschreibung<textarea name="beschreibung" maxlength="10000"></textarea></label><button class="button" type="submit">Auftrag starten</button></form></section>
<section class="card"><h2>Auftragsverlauf (<?=count($orders)?>)</h2><?php if(!$orders):?><p>Noch keine Coaching-Aufträge vorhanden.</p><?php else:foreach($orders as $order):?><article class="order"><strong><?=engagementH($order['titel'])?></strong> · <a href="client-overview.php?id=<?=(int)$order['klient_id']?>"><?=engagementH($order['vorname'].' '.$order['nachname'])?></a><p class="meta"><span class="badge"><?=$order['status']==='aktiv'?'Aktiv':'Abgeschlossen'?></span> · Angelegt <?=engagementH(date('d.m.Y',strtotime($order['erstellt_am'])))?><?= $order['abgeschlossen_am']?' · Beendet '.engagementH(date('d.m.Y',strtotime($order['abgeschlossen_am']))):'' ?><br><?=engagementH($order['beschreibung']??'')?><br>Coachee-Archiv: <?=$order['status']==='aktiv'?'erst nach Abschluss freigebbar':((int)$order['archiv_freigegeben']===1?'freigegeben':'nicht freigegeben')?></p><div class="row-actions"><?php if($order['status']==='aktiv'):?><form method="post" onsubmit="return confirm('Auftrag abschließen? Die zugehörigen Inhalte werden danach als eigener Verlauf archiviert.');"><input type="hidden" name="csrf_token" value="<?=engagementH($token)?>"><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><button class="button danger">Auftrag abschließen</button></form><?php elseif((int)$order['archiv_freigegeben']===1):?><form method="post"><input type="hidden" name="csrf_token" value="<?=engagementH($token)?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><button class="button secondary">Archivfreigabe entziehen</button></form><?php else:?><form method="post" onsubmit="return confirm('Coachees erhalten damit Zugriff auf die Inhalte dieses abgeschlossenen Auftrags, ausgenommen private Coach-Notizen.');"><input type="hidden" name="csrf_token" value="<?=engagementH($token)?>"><input type="hidden" name="action" value="release"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><button class="button">Archiv für Coachee freigeben</button></form><?php endif;?><a class="button secondary" href="client-overview.php?id=<?=(int)$order['klient_id']?>&amp;auftrag=<?=(int)$order['id']?>">Auftrag ansehen</a></div></article><?php endforeach;endif;?></section></div>
</main></body></html>