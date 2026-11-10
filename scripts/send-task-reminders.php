<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Berlin');
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/mail.php';

$db = coachingDatabase();
$baseUrl = coachingMailAppBaseUrl();
if ($baseUrl === null) {
    fwrite(STDERR, "Task reminders skipped: app_base_url must be a valid HTTPS URL.\n");
    exit(1);
}

$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$today = $now->format('Y-m-d');
$query = $db->prepare(
    "SELECT a.id AS task_id, a.klient_id, a.coach_benutzer_id, a.titel, a.faellig_am,
            p.id AS portal_id, p.email, p.aufgaben_erinnerung_rhythmus,
            p.aufgaben_ruhe_von, p.aufgaben_ruhe_bis,
            s.zuletzt_versendet_am, s.zuletzt_versucht_am
     FROM coaching_aufgaben a
     INNER JOIN coaching_auftraege o ON o.id = a.auftrag_id AND o.klient_id = a.klient_id AND o.status = 'aktiv'
     INNER JOIN klienten k ON k.id = a.klient_id AND k.benutzer_id = a.coach_benutzer_id
     INNER JOIN benutzer b ON b.id = a.coach_benutzer_id AND b.rolle = 'coach' AND b.aktiv = 1
     INNER JOIN coaching_portal_zugaenge p ON p.klient_id = a.klient_id AND p.aktiv = 1
     INNER JOIN coaching_email_einstellungen e ON e.benutzer_id = a.coach_benutzer_id AND e.aktiv = 1
     LEFT JOIN coaching_aufgaben_erinnerung_status s ON s.aufgabe_id = a.id
     WHERE a.status <> 'erledigt'
       AND a.klient_sichtbar = 1
       AND a.faellig_am IS NOT NULL
       AND a.faellig_am <= :today
       AND p.aufgaben_erinnerungen_aktiv = 1
       AND p.email IS NOT NULL AND p.email <> ''
     ORDER BY p.id, a.faellig_am, a.id"
);
$query->execute(['today' => $today]);
$rows = $query->fetchAll(PDO::FETCH_ASSOC);

$inQuietTime = static function (string $from, string $to, string $current): bool {
    $from = substr($from, 0, 8);
    $to = substr($to, 0, 8);
    if ($from === $to) {
        return false;
    }
    if ($from < $to) {
        return $current >= $from && $current < $to;
    }
    return $current >= $from || $current < $to;
};

$eligibleByPortal = [];
foreach ($rows as $row) {
    $currentTime = $now->format('H:i:s');
    if ($inQuietTime((string) $row['aufgaben_ruhe_von'], (string) $row['aufgaben_ruhe_bis'], $currentTime)) {
        continue;
    }
    if (($row['zuletzt_versucht_am'] ?? null) === $today) {
        continue;
    }
    $intervalDays = $row['aufgaben_erinnerung_rhythmus'] === 'woechentlich' ? 7 : 1;
    if (!empty($row['zuletzt_versendet_am'])) {
        $lastSent = new DateTimeImmutable((string) $row['zuletzt_versendet_am'], new DateTimeZone('Europe/Berlin'));
        if ((int) $lastSent->diff($now)->format('%a') < $intervalDays) {
            continue;
        }
    }
    $portalId = (int) $row['portal_id'];
    if (!isset($eligibleByPortal[$portalId])) {
        $eligibleByPortal[$portalId] = [
            'coach_id' => (int) $row['coach_benutzer_id'],
            'email' => (string) $row['email'],
            'task_ids' => [],
        ];
    }
    $eligibleByPortal[$portalId]['task_ids'][] = (int) $row['task_id'];
}

$sent = 0;
foreach ($eligibleByPortal as $portalId => $reminder) {
    $db->beginTransaction();
    try {
        $markAttempt = $db->prepare(
            'INSERT INTO coaching_aufgaben_erinnerung_status (aufgabe_id,zuletzt_versendet_am,zuletzt_versucht_am)
             VALUES (:task,NULL,:today)
             ON DUPLICATE KEY UPDATE zuletzt_versucht_am=VALUES(zuletzt_versucht_am)'
        );
        foreach ($reminder['task_ids'] as $taskId) {
            $markAttempt->execute(['task' => $taskId, 'today' => $today]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Task reminder attempt log failed: ' . $error->getMessage());
        continue;
    }

    $count = count($reminder['task_ids']);
    $body = "Guten Tag,\n\n"
        . "du hast {$count} fällige oder überfällige Coaching-Aufgabe(n). Melde dich im Coachee-Portal an, um sie anzusehen:\n"
        . $baseUrl . "/\n\n"
        . "Häufigkeit und Ruhezeiten kannst du im Portal unter „Aufgabenerinnerungen“ ändern oder die Erinnerungen ganz ausschalten.\n";
    try {
        coachingMailSend($db, $reminder['coach_id'], $reminder['email'], 'Erinnerung an deine Coaching-Aufgaben', $body);
        $markSent = $db->prepare(
            'UPDATE coaching_aufgaben_erinnerung_status
             SET zuletzt_versendet_am=NOW()
             WHERE aufgabe_id=:task'
        );
        foreach ($reminder['task_ids'] as $taskId) {
            $markSent->execute(['task' => $taskId]);
        }
        $sent++;
    } catch (Throwable $error) {
        error_log('Task reminder delivery failed for portal ' . (int) $portalId . ': ' . $error->getMessage());
    }
}

$db->exec(
    'DELETE FROM coaching_aktivitaeten
     WHERE (gelesen_am IS NOT NULL AND gelesen_am < NOW() - INTERVAL 90 DAY)
        OR erstellt_am < NOW() - INTERVAL 180 DAY'
);
fwrite(STDOUT, "Task reminders sent: {$sent}; checked tasks: " . count($rows) . ".\n");
