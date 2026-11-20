<?php
declare(strict_types=1);
function coachingModuleDefinitions(): array { return [
'termine'=>['column'=>'termine_aktiv','label'=>'Termine','description'=>'Sitzungen anlegen und den Terminverlauf verwalten.'],
'aufgaben'=>['column'=>'aufgaben_aktiv','label'=>'Aufgaben','description'=>'Übungen und nächste Schritte mit Fälligkeit verwalten.'],
'coachee_chat'=>['column'=>'chat_aktiv','label'=>'Chat für Coachees','description'=>'Nachrichten und Anhänge im gemeinsamen Verlauf verwenden.'],
'ziele'=>['column'=>'ziele_aktiv','label'=>'Ziele','description'=>'Coaching-Ziele erfassen und ihren Status verfolgen.'],
'dokumente'=>['column'=>'dokumente_aktiv','label'=>'Dokumente','description'=>'Vorlagen zuweisen und Rückläufe sicher ablegen.'],
'auftraege'=>['column'=>'auftraege_aktiv','label'=>'Coaching-Aufträge','description'=>'Aktive und archivierte Coaching-Vorgänge verwalten.'],
'rechnungen'=>['column'=>'rechnungen_aktiv','label'=>'Rechnungen','description'=>'Sitzungsrechnungen und Auftragsübersichten erstellen.'],
]; }
function coachingCoachModuleSettings(PDO $database,int $coachId):array {
 $defs=coachingModuleDefinitions();$out=array_fill_keys(array_keys($defs),1);if($coachId<1)return $out;$columns=array_map(static fn(array $m):string=>$m['column'],$defs);
 try{$q=$database->prepare('SELECT '.implode(', ',$columns).' FROM coaching_coach_moduleeinstellungen WHERE coach_benutzer_id=:coach LIMIT 1');$q->execute(['coach'=>$coachId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(is_array($row))foreach($defs as $key=>$def)$out[$key]=(int)($row[$def['column']]??1)===1?1:0;}catch(Throwable $e){/* Migration 023 not yet imported: preserve existing behavior. */}return $out;
}
function coachingModuleEnabled(PDO $database,int $coachId,string $module):bool { if(!isset(coachingModuleDefinitions()[$module]))return false;return (coachingCoachModuleSettings($database,$coachId)[$module]??1)===1; }
function coachingModuleForRoute(string $path):?string { return ['appointments.php'=>'termine','tasks.php'=>'aufgaben','message-download.php'=>'coachee_chat','goals.php'=>'ziele','documents.php'=>'dokumente','document-download.php'=>'dokumente','engagements.php'=>'auftraege','engagement-archive.php'=>'auftraege','invoices.php'=>'rechnungen','invoice-view.php'=>'rechnungen','invoice-order-summary.php'=>'rechnungen'][$path]??null; }
function coachingEnforceRouteModule():void {
 $user=$_SESSION['user']??null;if(!is_array($user)||($user['role']??'')!=='coach'||!isset($user['id']))return;$module=coachingModuleForRoute(basename((string)($_SERVER['SCRIPT_NAME']??'')));if($module===null)return;
 try{if(!coachingModuleEnabled(coachingDatabase(),(int)$user['id'],$module)){http_response_code(403);exit('Diese Funktion ist in deinen Moduleinstellungen deaktiviert.');}}catch(Throwable $e){/* Page-specific database handling reports connection failures. */}
}
