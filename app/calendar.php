<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function coachingCalendarEncrypt(string $value): string
{
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('PHP OpenSSL ist nicht aktiviert.');
    $key = coachingMailEncryptionKey();
    $nonce = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if (!is_string($cipher)) throw new RuntimeException('Kalender-Zugangsdaten konnten nicht verschlüsselt werden.');
    return base64_encode($nonce . $tag . $cipher);
}
function coachingCalendarDecrypt(?string $value): string
{
    if (!$value) return '';
    $raw = base64_decode($value, true);
    if (!is_string($raw) || strlen($raw) < 29) throw new RuntimeException('Gespeicherte Kalender-Zugangsdaten sind beschädigt.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', coachingMailEncryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), '');
    if (!is_string($plain)) throw new RuntimeException('Kalender-Zugangsdaten können nicht entschlüsselt werden. Prüfe mail_encryption_key.');
    return $plain;
}
function coachingCalendarSettings(PDO $db, int $coachId): array
{
    $q=$db->prepare('SELECT * FROM coaching_kalender_einstellungen WHERE benutzer_id=:id LIMIT 1');
    $q->execute(['id'=>$coachId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: ['provider'=>'google','aktiv'=>0,'google_email'=>null,'apple_id'=>null,'kalender_id'=>null,'kalender_name'=>null,'klientenname_anzeigen'=>0];
}
function coachingCalendarConfig(): array
{
    $c=coachingConfig()['google_calendar']??[];
    return is_array($c)?$c:[];
}
function coachingCalendarHttp(string $url, array $headers=[], ?string $body=null, string $method='GET', ?array $basic=null): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP-cURL ist nicht aktiviert.');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    if($basic!==null)curl_setopt($ch,CURLOPT_USERPWD,$basic[0].':'.$basic[1]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
    if(!is_string($response))throw new RuntimeException('Kalenderdienst nicht erreichbar: '.$err);
    return [$status,$response];
}
function coachingGoogleToken(array $settings, PDO $db): string
{
    $exp=strtotime((string)($settings['token_ablauf_am']??'') ?: '1970-01-01');
    $access=coachingCalendarDecrypt($settings['access_token_verschluesselt']??null);
    if($access!==''&&$exp>time()+60)return $access;
    $cfg=coachingCalendarConfig();$refresh=coachingCalendarDecrypt($settings['refresh_token_verschluesselt']??null);
    if(empty($cfg['client_id'])||empty($cfg['client_secret'])||$refresh==='')throw new RuntimeException('Google Kalender ist nicht vollständig verbunden. Bitte erneut anmelden.');
    [$status,$body]=coachingCalendarHttp('https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret'],'refresh_token'=>$refresh,'grant_type'=>'refresh_token']),'POST');
    $data=json_decode($body,true);
    if($status!==200||!is_array($data)||empty($data['access_token']))throw new RuntimeException('Google-Zugriff abgelaufen. Bitte die Kalenderverbindung erneut herstellen.');
    $access=(string)$data['access_token'];$expAt=date('Y-m-d H:i:s',time()+(int)($data['expires_in']??3600));
    $u=$db->prepare('UPDATE coaching_kalender_einstellungen SET access_token_verschluesselt=:token,token_ablauf_am=:expires WHERE benutzer_id=:id');
    $u->execute(['token'=>coachingCalendarEncrypt($access),'expires'=>$expAt,'id'=>(int)$settings['benutzer_id']]);
    return $access;
}
function coachingGoogleCalendars(string $access): array
{
    [$status,$body]=coachingCalendarHttp('https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250',['Authorization: Bearer '.$access]);
    $data=json_decode($body,true);if($status!==200||!is_array($data))throw new RuntimeException('Google-Kalenderliste konnte nicht geladen werden.');
    $out=[];foreach(($data['items']??[]) as $c){if(in_array($c['accessRole']??'',['owner','writer'],true))$out[]=['id'=>(string)$c['id'],'name'=>(string)($c['summary']??$c['id'])];}
    return $out;
}
function coachingIcloudCalendars(string $appleId, string $password): array
{
    $body='<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:displayname/><d:resourcetype/><d:current-user-privilege-set/></d:prop></d:propfind>';
    [$status,$xml]=coachingCalendarHttp('https://caldav.icloud.com/',['Content-Type: application/xml; charset=utf-8','Depth: 1'],$body,'PROPFIND',[$appleId,$password]);
    if(!in_array($status,[207,200],true))throw new RuntimeException('iCloud-Kalender konnten nicht geladen werden. Prüfe Apple-ID und App-spezifisches Passwort.');
    $doc=new DOMDocument();if(!@$doc->loadXML($xml))throw new RuntimeException('Antwort des iCloud-Kalenders ist ungültig.');
    $xp=new DOMXPath($doc);$xp->registerNamespace('d','DAV:');$xp->registerNamespace('c','urn:ietf:params:xml:ns:caldav');
    $out=[];
    foreach($xp->query('//d:response') as $resp){
        $href=$xp->evaluate('string(d:href)', $resp);$name=$xp->evaluate('string(.//d:displayname)', $resp);$isCal=(bool)$xp->query('.//d:resourcetype/c:calendar', $resp)->length;
        $priv=$xp->evaluate('string(.//d:current-user-privilege-set/d:write)', $resp);
        if($isCal&&$href!==''&&$priv!=='')$out[]=['id'=>$href,'name'=>$name?:$href];
    }
    if($out===[])throw new RuntimeException('Keine beschreibbaren iCloud-Kalender gefunden. Prüfe die Kalenderfreigabe und Berechtigung.');
    return $out;
}
function coachingCalendarIcsEscape(string $v): string
{
    return str_replace(["\\",";",",", "\n"],["\\\\","\\;","\\,","\\n"],$v);
}
function coachingCalendarSyncAppointment(PDO $db, int $coachId, int $appointmentId): void
{
    $s=coachingCalendarSettings($db,$coachId);if((int)($s['aktiv']??0)!==1||empty($s['kalender_id']))return;
    $q=$db->prepare('SELECT t.id,t.beginn,t.dauer_minuten,t.format,t.status,t.thema,k.vorname,k.nachname FROM termine t JOIN klienten k ON k.id=t.klient_id WHERE t.id=:id AND k.benutzer_id=:coach');
    $q->execute(['id'=>$appointmentId,'coach'=>$coachId]);$e=$q->fetch(PDO::FETCH_ASSOC);if(!$e)return;
    $mapq=$db->prepare('SELECT remote_id FROM coaching_kalender_termine WHERE benutzer_id=:coach AND termin_id=:id');
    $mapq->execute(['coach'=>$coachId,'id'=>$appointmentId]);$remoteId=$mapq->fetchColumn();
    if($s['provider']==='google'){
        $access=coachingGoogleToken($s+['benutzer_id'=>$coachId],$db);$cal=rawurlencode((string)$s['kalender_id']);
        if($e['status']==='abgesagt'){
            if($remoteId){[$st]=coachingCalendarHttp('https://www.googleapis.com/calendar/v3/calendars/'.$cal.'/events/'.rawurlencode((string)$remoteId),['Authorization: Bearer '.$access],null,'DELETE');if(!in_array($st,[204,404],true))throw new RuntimeException('Google-Termin konnte nicht entfernt werden.');}
        }else{
            $start=new DateTimeImmutable((string)$e['beginn'],new DateTimeZone('Europe/Berlin'));$end=$start->modify('+'.(int)$e['dauer_minuten'].' minutes');
            $summary=(int)$s['klientenname_anzeigen']===1?trim($e['vorname'].' '.$e['nachname']):'Coachingtermin';
            $event=['summary'=>$summary,'description'=>'Coachingtermin · '.(string)$e['format'],'start'=>['dateTime'=>$start->format(DateTime::RFC3339),'timeZone'=>'Europe/Berlin'],'end'=>['dateTime'=>$end->format(DateTime::RFC3339),'timeZone'=>'Europe/Berlin']];
            $eventId='ct'.substr(hash('sha256',(string)$coachId.':'.$appointmentId),0,40);
            $method=$remoteId?'PATCH':'PUT';$id=$remoteId?:$eventId;
            $url='https://www.googleapis.com/calendar/v3/calendars/'.$cal.'/events/'.rawurlencode($id);
            [$st,$resp]=coachingCalendarHttp($url,['Authorization: Bearer '.$access,'Content-Type: application/json'],json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$method);
            if(!$remoteId&&$st===404){[$st,$resp]=coachingCalendarHttp('https://www.googleapis.com/calendar/v3/calendars/'.$cal.'/events?sendUpdates=none',['Authorization: Bearer '.$access,'Content-Type: application/json'],json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'POST');}
            if(!in_array($st,[200,201],true))throw new RuntimeException('Google-Termin konnte nicht synchronisiert werden (HTTP '.$st.').');
            $data=json_decode($resp,true);$remoteId=(string)($data['id']??$id);
        }
    }elseif($s['provider']==='icloud'){
        $apple=coachingCalendarDecrypt($s['icloud_passwort_verschluesselt']??null);$cal=(string)$s['kalender_id'];$uid=hash('sha256',$coachId.':'.$appointmentId).'@coaching-tool';
        $url='https://caldav.icloud.com'.(str_starts_with($cal,'/')?$cal:'/'.ltrim($cal,'/')).rawurlencode($uid).'.ics';
        if($e['status']==='abgesagt'){
            if($remoteId){[$st]=coachingCalendarHttp($url,['Content-Type: text/calendar'],null,'DELETE',[(string)$s['apple_id'],$apple]);if(!in_array($st,[200,204,404],true))throw new RuntimeException('iCloud-Termin konnte nicht entfernt werden.');}
        }else{
            $start=(new DateTimeImmutable((string)$e['beginn'],new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('UTC'));
            $end=$start->modify('+'.(int)$e['dauer_minuten'].' minutes');$summary=(int)$s['klientenname_anzeigen']===1?trim($e['vorname'].' '.$e['nachname']):'Coachingtermin';
            $ics="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Coaching Tool//Kalender 1.0//DE\r\nBEGIN:VEVENT\r\nUID:".$uid."\r\nDTSTAMP:".gmdate('Ymd\THis\Z')."\r\nDTSTART:".$start->format('Ymd\THis\Z')."\r\nDTEND:".$end->format('Ymd\THis\Z')."\r\nSUMMARY:".coachingCalendarIcsEscape($summary)."\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            [$st]=coachingCalendarHttp($url,['Content-Type: text/calendar; charset=utf-8'],$ics,'PUT',[(string)$s['apple_id'],$apple]);
            if(!in_array($st,[201,204],true))throw new RuntimeException('iCloud-Termin konnte nicht synchronisiert werden (HTTP '.$st.').');$remoteId=$url;
        }
    }else return;
    if($remoteId){$save=$db->prepare('INSERT INTO coaching_kalender_termine (benutzer_id,termin_id,provider,remote_id,kalender_id,abgeglichen_am) VALUES (:coach,:id,:provider,:remote,:calendar,NOW()) ON DUPLICATE KEY UPDATE provider=VALUES(provider),remote_id=VALUES(remote_id),kalender_id=VALUES(kalender_id),abgeglichen_am=NOW()');$save->execute(['coach'=>$coachId,'id'=>$appointmentId,'provider'=>$s['provider'],'remote'=>$remoteId,'calendar'=>$s['kalender_id']]);}
    elseif($e['status']==='abgesagt'){$del=$db->prepare('DELETE FROM coaching_kalender_termine WHERE benutzer_id=:coach AND termin_id=:id');$del->execute(['coach'=>$coachId,'id'=>$appointmentId]);}
}
