<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';
ini_set('session.use_strict_mode','1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);
session_start();
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/document_storage.php';
$user=$_SESSION['user']??null;
if(!is_array($user)||($user['role']??'')!=='coach'){http_response_code(403);exit('Kein Zugriff.');}
$coach=(int)$user['id'];
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0;
$settingsPreview=($_GET['settings']??'')==='1';
try{
 $db=coachingDatabase();
 if($settingsPreview){
  $q=$db->prepare('SELECT logo_speicher_schluessel,logo_mime FROM coaching_rechnungseinstellungen WHERE coach_benutzer_id=:coach');
  $q->execute(['coach'=>$coach]);
  $logo=$q->fetch(PDO::FETCH_ASSOC);
 }else{
  $q=$db->prepare("SELECT r.logo_speicher_schluessel,r.logo_mime FROM coaching_rechnungen r INNER JOIN klienten k ON k.id=r.klient_id WHERE r.id=:id AND r.coach_benutzer_id=:coach AND k.benutzer_id=:owner");
  $q->execute(['id'=>$id,'coach'=>$coach,'owner'=>$coach]);
  $logo=$q->fetch(PDO::FETCH_ASSOC);
 }
 if(!$logo||!$logo['logo_speicher_schluessel']||!in_array($logo['logo_mime'],['image/jpeg','image/png'],true)){
  http_response_code(404);exit('Logo nicht gefunden.');
 }
 try{
  $path=coachingResolveDocumentFile((string)$logo['logo_speicher_schluessel'],(string)$logo['logo_mime']);
 }catch(Throwable $snapshotError){
  if($settingsPreview)throw $snapshotError;
  $fallback=$db->prepare('SELECT logo_speicher_schluessel,logo_mime FROM coaching_rechnungseinstellungen WHERE coach_benutzer_id=:coach');
  $fallback->execute(['coach'=>$coach]);
  $logo=$fallback->fetch(PDO::FETCH_ASSOC);
  if(!$logo||!$logo['logo_speicher_schluessel']||!in_array($logo['logo_mime'],['image/jpeg','image/png'],true))throw $snapshotError;
  $path=coachingResolveDocumentFile((string)$logo['logo_speicher_schluessel'],(string)$logo['logo_mime']);
 }
 header('Content-Type: '.$logo['logo_mime']);
 header('Content-Length: '.(string)filesize($path));
 header('Content-Disposition: inline; filename="coach-logo"');
 readfile($path);
 exit;
}catch(Throwable $e){
 error_log('Invoice logo access failed: '.$e->getMessage());
 http_response_code(404);
 exit('Logo nicht gefunden.');
}
