<?php
declare(strict_types=1);

function coachingMailSettings(PDO $db,int $ownerId): array
{
    $q=$db->prepare('SELECT * FROM coaching_email_einstellungen WHERE benutzer_id=:owner LIMIT 1');
    $q->execute(['owner'=>$ownerId]);
    return $q->fetch(PDO::FETCH_ASSOC)?:[
        'aktiv'=>0,'smtp_host'=>'','smtp_port'=>587,'smtp_sicherheit'=>'starttls',
        'smtp_auth'=>1,'smtp_benutzername'=>'','smtp_passwort_verschluesselt'=>null,
        'absender_name'=>'Coaching Tool','absender_email'=>'','antwort_email'=>null,'antwort_email'=>null
    ];
}

function coachingMailEncryptionKey(): string
{
    $value=(string)(coachingConfig()['mail_encryption_key']??'');
    if(!preg_match('/^[a-f0-9]{64}$/i',$value)){
        throw new RuntimeException('Der private Schlüssel mail_encryption_key fehlt in config.local.php.');
    }
    $key=hex2bin($value);
    if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('Der private Schlüssel für E-Mail-Zugangsdaten ist ungültig.');
    return $key;
}

function coachingMailEncryptPassword(string $password): string
{
    if(!function_exists('openssl_encrypt'))throw new RuntimeException('OpenSSL ist in PHP nicht aktiviert.');
    $nonce=random_bytes(12);$tag='';
    $encrypted=openssl_encrypt($password,'aes-256-gcm',coachingMailEncryptionKey(),OPENSSL_RAW_DATA,$nonce,$tag,'',16);
    if(!is_string($encrypted))throw new RuntimeException('SMTP-Passwort konnte nicht verschlüsselt werden.');
    return base64_encode($nonce.$tag.$encrypted);
}

function coachingMailDecryptPassword(?string $ciphertext): string
{
    if($ciphertext===null||$ciphertext==='')return '';
    if(!function_exists('openssl_decrypt'))throw new RuntimeException('OpenSSL ist in PHP nicht aktiviert.');
    $raw=base64_decode($ciphertext,true);
    if(!is_string($raw)||strlen($raw)<29)throw new RuntimeException('Gespeicherte SMTP-Zugangsdaten sind beschädigt.');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',coachingMailEncryptionKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'');
    if(!is_string($plain))throw new RuntimeException('SMTP-Passwort kann nicht entschlüsselt werden. Prüfe mail_encryption_key in config.local.php.');
    return $plain;
}

function coachingMailAppBaseUrl(): ?string
{
    $value=trim((string)(coachingConfig()['app_base_url']??''));
    if($value===''||preg_match('/[\r\n\s]/',$value))return null;
    $parts=parse_url($value);
    if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])
        ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))return null;
    return rtrim($value,'/');
}

function coachingSmtpRead($socket,array $expected): string
{
    $reply='';
    do{
        $line=fgets($socket,515);
        if(!is_string($line))throw new RuntimeException('Keine gültige Antwort vom SMTP-Server erhalten.');
        $reply.=$line;
        $more=strlen($line)>=4&&$line[3]==='-';
    }while($more);
    $code=(int)substr($reply,0,3);
    if(!in_array($code,$expected,true))throw new RuntimeException('Der SMTP-Server hat einen Schritt abgelehnt (Antwortcode '.$code.').');
    return $reply;
}

function coachingSmtpCommand($socket,string $command,array $expected): string
{
    $written=0;$length=strlen($command."\r\n");
    while($written<$length){
        $n=fwrite($socket,substr($command."\r\n",$written));
        if($n===false||$n===0)throw new RuntimeException('Die SMTP-Verbindung wurde unterbrochen.');
        $written+=$n;
    }
    return coachingSmtpRead($socket,$expected);
}

function coachingMailSend(PDO $db,int $ownerId,string $recipient,string $subject,string $body): void
{
    $recipient=trim($recipient);
    if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Bitte eine gültige Ziel-E-Mail-Adresse eingeben.');
    $settings=coachingMailSettings($db,$ownerId);
    if((int)$settings['aktiv']!==1)throw new RuntimeException('Der E-Mail-Versand ist nicht aktiviert.');
    $host=trim((string)$settings['smtp_host']);
    $port=(int)$settings['smtp_port'];
    $security=(string)$settings['smtp_sicherheit'];
    $from=trim((string)$settings['absender_email']);
    $replyTo=trim((string)($settings['antwort_email']??''))?:$from;
    $fromName=trim((string)$settings['absender_name']);
    if($host===''||strlen($host)>253||!preg_match('/^[A-Za-z0-9.-]+$/',$host)||$port<1||$port>65535
        ||!in_array($security,['keine','starttls','ssl'],true)||!filter_var($from,FILTER_VALIDATE_EMAIL)||!filter_var($replyTo,FILTER_VALIDATE_EMAIL)){
        throw new RuntimeException('Die SMTP-Einstellungen sind unvollständig oder ungültig.');
    }
    $auth=(int)$settings['smtp_auth']===1;
    $username=trim((string)$settings['smtp_benutzername']);
    $password=$auth?coachingMailDecryptPassword($settings['smtp_passwort_verschluesselt']??null):'';
    if($auth&&($username===''||$password===''))throw new RuntimeException('SMTP-Anmeldung ist aktiviert, aber Benutzername oder Passwort fehlt.');
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true]]);
    $transport=$security==='ssl'?'ssl://':'tcp://';
    $socket=@stream_socket_client($transport.$host.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$context);
    if(!is_resource($socket))throw new RuntimeException('SMTP-Server nicht erreichbar (Verbindungsfehler '.$errno.').');
    stream_set_timeout($socket,15);
    try{
        coachingSmtpRead($socket,[220]);
        $clientName=(string)(parse_url((string)(coachingConfig()['app_base_url']??''),PHP_URL_HOST)?:'localhost');
        coachingSmtpCommand($socket,'EHLO '.$clientName,[250]);
        if($security==='starttls'){
            coachingSmtpCommand($socket,'STARTTLS',[220]);
            $crypto=stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if($crypto!==true)throw new RuntimeException('Die verschlüsselte SMTP-Verbindung konnte nicht gestartet werden.');
            coachingSmtpCommand($socket,'EHLO '.$clientName,[250]);
        }
        if($auth){
            coachingSmtpCommand($socket,'AUTH LOGIN',[334]);
            coachingSmtpCommand($socket,base64_encode($username),[334]);
            coachingSmtpCommand($socket,base64_encode($password),[235]);
        }
        coachingSmtpCommand($socket,'MAIL FROM:<'.$from.'>',[250]);
        coachingSmtpCommand($socket,'RCPT TO:<'.$recipient.'>',[250,251]);
        coachingSmtpCommand($socket,'DATA',[354]);
        $encodedName='=?UTF-8?B?'.base64_encode($fromName!==''?$fromName:'Coaching Tool').'?=';
        $encodedSubject='=?UTF-8?B?'.base64_encode($subject).'?=';
        $messageId=bin2hex(random_bytes(16)).'@'.$host;
        $headers=[
            'Date: '.gmdate('D, d M Y H:i:s').' +0000',
            'From: '.$encodedName.' <'.$from.'>',
            'To: <'.$recipient.'>',
            'Reply-To: <'.$replyTo.'>',
            'Subject: '.$encodedSubject,
            'Message-ID: <'.$messageId.'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64'
        ];
        $payload=implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");
        $payload=preg_replace('/^\./m','..',$payload)??$payload;
        $written=0;$data=$payload."\r\n.\r\n";$length=strlen($data);
        while($written<$length){$n=fwrite($socket,substr($data,$written));if($n===false||$n===0)throw new RuntimeException('Die SMTP-Verbindung wurde während des Versands unterbrochen.');$written+=$n;}
        coachingSmtpRead($socket,[250]);
        @fwrite($socket,"QUIT\r\n");
    }finally{
        fclose($socket);
    }
}

/**
 * Sends the assigned Coach's Coachee an invitation with the login name and a
 * one-time link for setting a password. The password itself is never emailed.
 */
function coachingMailSendCoacheeInvitation(PDO $db, int $coachId, int $portalId): void
{
    $query = $db->prepare(
        "SELECT p.id, p.benutzername, p.email
         FROM coaching_portal_zugaenge p
         INNER JOIN klienten k ON k.id = p.klient_id AND k.benutzer_id = :coach
         WHERE p.id = :portal AND p.aktiv = 1
         LIMIT 1"
    );
    $query->execute(['coach' => $coachId, 'portal' => $portalId]);
    $account = $query->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        throw new RuntimeException('Der aktive Coachee-Zugang wurde nicht gefunden.');
    }
    $email = trim((string) ($account['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Für diesen Coachee-Zugang ist keine gültige E-Mail-Adresse gespeichert.');
    }
    $baseUrl = coachingMailAppBaseUrl();
    if ($baseUrl === null) {
        throw new RuntimeException('Bitte zuerst die HTTPS-App-Adresse in app_base_url speichern.');
    }
    $settings = coachingMailSettings($db, $coachId);
    if ((int) ($settings['aktiv'] ?? 0) !== 1) {
        throw new RuntimeException('Bitte zuerst den E-Mail-Versand in deinen Einstellungen aktivieren.');
    }
    $limit = $db->prepare(
        "SELECT COUNT(*) FROM coaching_passwort_resets
         WHERE konto_typ='coachee' AND konto_id=:portal
           AND erstellt_am >= NOW() - INTERVAL 1 HOUR"
    );
    $limit->execute(['portal' => $portalId]);
    if ((int) $limit->fetchColumn() >= 3) {
        throw new RuntimeException('Für diesen Zugang wurden bereits drei Einladungen in der letzten Stunde versendet. Bitte später erneut versuchen.');
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $insert = $db->prepare(
        "INSERT INTO coaching_passwort_resets (konto_typ,konto_id,token_hash,ablauf_am)
         VALUES ('coachee',:portal,:hash,DATE_ADD(NOW(),INTERVAL 60 MINUTE))"
    );
    $insert->execute(['portal' => $portalId, 'hash' => $tokenHash]);
    $resetId = (int) $db->lastInsertId();
    $loginUrl = $baseUrl . '/';
    $setPasswordUrl = $baseUrl . '/password-reset.php?token=' . $rawToken;
    $body = "Hallo,\n\n"
        . "dein Zugang zum Coaching Tool ist eingerichtet.\n\n"
        . "Benutzername: " . (string) $account['benutzername'] . "\n"
        . "Anmeldung: " . $loginUrl . "\n\n"
        . "Lege über diesen einmaligen Link dein persönliches Passwort fest:\n"
        . $setPasswordUrl . "\n\n"
        . "Der Link ist 60 Minuten gültig. Falls du diese Nachricht nicht erwartet hast, wende dich bitte an deinen Coach.\n";
    try {
        coachingMailSend($db, $coachId, $email, 'Dein Zugang zum Coaching Tool', $body);
        $invalidate = $db->prepare(
            "UPDATE coaching_passwort_resets
             SET verwendet_am=NOW()
             WHERE konto_typ='coachee' AND konto_id=:portal
               AND id<>:current AND verwendet_am IS NULL"
        );
        $invalidate->execute(['portal' => $portalId, 'current' => $resetId]);
    } catch (Throwable $error) {
        $remove = $db->prepare('DELETE FROM coaching_passwort_resets WHERE id=:id');
        $remove->execute(['id' => $resetId]);
        throw $error;
    }
}

