<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/document_storage.php';

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || ($user['role'] ?? '') !== 'coach' || !isset($user['id'])) {
    http_response_code(403);
    exit('Der Datenexport ist nur für den zuständigen Coach verfügbar.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Methode nicht unterstützt.');
}
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (!is_string($sessionToken) || !hash_equals($sessionToken, (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    exit('Ungültige Anfrage. Bitte lade die Klientenakte neu.');
}
if (!class_exists(ZipArchive::class)) {
    http_response_code(503);
    exit('Der Datenexport benötigt die PHP-Erweiterung zip. Bitte aktiviere sie im PHP-8.2-Profil.');
}

final class ClientExportException extends RuntimeException {}

function exportIdentifier(string $value): string
{
    return chr(96) . str_replace(chr(96), chr(96) . chr(96), $value) . chr(96);
}
function exportSafeFilename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'datei';
    return trim($name, '._-') ?: 'datei';
}

try {
    $database = coachingDatabase();
    $coachId = (int) $user['id'];
    $clientId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($clientId === false || $clientId === null || $clientId < 1) {
        http_response_code(400);
        exit('Ungültiger Klient.');
    }
    $owner = $database->prepare('SELECT * FROM klienten WHERE id=:client AND benutzer_id=:coach LIMIT 1');
    $owner->execute(['client' => (int) $clientId, 'coach' => $coachId]);
    $client = $owner->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        http_response_code(404);
        exit('Klient nicht gefunden oder kein Zugriff.');
    }

    $excluded = ['coaching_passwort_resets', 'login_versuche'];
    $tables = $database->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $records = ['klienten' => [$client]];
    $documentIds = [];
    $files = [];
    foreach ($tables as $tableRow) {
        $table = (string) ($tableRow[0] ?? '');
        if (strtoupper((string) ($tableRow[1] ?? '')) !== 'BASE TABLE' || $table === 'klienten' || in_array($table, $excluded, true)) continue;
        $columns = $database->query('SHOW COLUMNS FROM ' . exportIdentifier($table))->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(static fn(array $column): string => (string) $column['Field'], $columns);
        $foreignKey = in_array('klient_id', $names, true) ? 'klient_id' : (in_array('client_id', $names, true) ? 'client_id' : null);
        if ($foreignKey === null) continue;
        $sql = 'SELECT * FROM ' . exportIdentifier($table) . ' WHERE ' . exportIdentifier($foreignKey) . '=:client';
        $params = ['client' => (int) $clientId];
        if (in_array('coach_benutzer_id', $names, true)) {
            $sql .= ' AND coach_benutzer_id=:coach';
            $params['coach'] = $coachId;
        }
        $sql .= ' ORDER BY ' . exportIdentifier($names[0]) . ' LIMIT 20001';
        $query = $database->prepare($sql);
        $query->execute($params);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 20000) throw new ClientExportException('Der Datenbestand ist für einen einzelnen Download zu groß. Bitte wende dich an die Systembetreuung.');
        if ($rows) $records[$table] = $rows;

        if ($table === 'coaching_klientendokumente') {
            foreach ($rows as $row) if (isset($row['id'])) $documentIds[] = (int) $row['id'];
        }
    }

    if ($documentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $q = $database->prepare('SELECT * FROM coaching_dokumentdateien WHERE coach_benutzer_id=? AND klientendokument_id IN (' . $placeholders . ') ORDER BY id');
        $q->execute(array_merge([$coachId], $documentIds));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 20000) throw new ClientExportException('Zu viele Dokumentdateien für einen einzelnen Download.');
        if ($rows) $records['coaching_dokumentdateien'] = $rows;
    }

    if (in_array('coaching_portal_zugaenge', array_column($tables, 0), true)) {
        $portalColumns = $database->query('SHOW COLUMNS FROM coaching_portal_zugaenge')->fetchAll(PDO::FETCH_ASSOC);
        $safePortalNames = array_values(array_filter(
            array_map(static fn(array $column): string => (string) $column['Field'], $portalColumns),
            static fn(string $name): bool => !preg_match('/pass|password|token|secret|hash/i', $name)
        ));
        if ($safePortalNames !== [] && in_array('klient_id', $safePortalNames, true)) {
            $portalSql = 'SELECT ' . implode(',', array_map('exportIdentifier', $safePortalNames)) . ' FROM coaching_portal_zugaenge WHERE klient_id=:client';
            $portalParams = ['client' => (int) $clientId];
            if (in_array('coach_benutzer_id', $safePortalNames, true)) {
                $portalSql .= ' AND coach_benutzer_id=:coach';
                $portalParams['coach'] = $coachId;
            }
            $portalQuery = $database->prepare($portalSql);
            $portalQuery->execute($portalParams);
            $portalAccess = $portalQuery->fetchAll(PDO::FETCH_ASSOC);
            if ($portalAccess) $records['coaching_portal_zugaenge'] = $portalAccess;
        }
    }

    $storageRoot = coachingDocumentStorageRoot();
    $fileEntries = [];
    foreach ($records as $table => $rows) {
        foreach ($rows as $row) {
            $originalName = (string) ($row['originalname'] ?? $row['vorlage_originalname'] ?? 'datei');
            foreach ($row as $field => $value) {
                if (!is_string($value) || !str_contains(strtolower((string) $field), 'speicher_schluessel') || !preg_match('/^[A-Za-z0-9]{52}$/', $value)) continue;
                if (isset($fileEntries[$value])) continue;
                $path = $storageRoot . DIRECTORY_SEPARATOR . $value;
                $realPath = realpath($path);
                if ($realPath === false || !is_file($realPath) || !str_starts_with($realPath, rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) continue;
                $archiveName = 'dateien/' . str_pad((string) (count($fileEntries) + 1), 4, '0', STR_PAD_LEFT) . '-' . exportSafeFilename($originalName);
                $fileEntries[$value] = ['path' => $realPath, 'archive_name' => $archiveName, 'originalname' => $originalName, 'source_table' => $table];
            }
        }
    }

    $records['_export'] = [
        'created_at' => date(DATE_ATOM),
        'client_id' => (int) $clientId,
        'requested_by_coach_id' => $coachId,
        'included' => 'Client record, directly client-linked coaching records including private coach notes and invoices, plus files found in private storage.',
        'excluded' => 'Account password hashes, reset tokens, login IP log, and coach-wide reference templates/settings.',
        'files' => array_map(static fn(array $entry): array => ['archive_path' => $entry['archive_name'], 'original_name' => $entry['originalname'], 'source_table' => $entry['source_table']], array_values($fileEntries)),
    ];
    $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    $temporary = tempnam(sys_get_temp_dir(), 'coaching-export-');
    if (!is_string($temporary)) throw new ClientExportException('Temporäre Exportdatei konnte nicht erstellt werden.');
    chmod($temporary, 0600);
    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true) throw new ClientExportException('Exportarchiv konnte nicht erstellt werden.');
    $zip->addFromString('datenexport.json', $json);
    foreach ($fileEntries as $entry) {
        if (!$zip->addFile($entry['path'], $entry['archive_name'])) throw new ClientExportException('Eine private Datei konnte nicht in den Export aufgenommen werden.');
    }
    if (!$zip->close()) throw new ClientExportException('Exportarchiv konnte nicht abgeschlossen werden.');

    $name = exportSafeFilename(trim((string) ($client['nachname'] ?? 'klient')) . '-' . trim((string) ($client['vorname'] ?? '')) . '-datenexport-' . date('Y-m-d') . '.zip');
    header('Content-Type: application/zip');
    header('Content-Length: ' . (string) filesize($temporary));
    header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    readfile($temporary);
    @unlink($temporary);
} catch (Throwable $error) {
    if (isset($temporary) && is_file($temporary)) @unlink($temporary);
    error_log('Client data export failed: ' . $error->getMessage());
    http_response_code($error instanceof ClientExportException ? 422 : 500);
    exit($error instanceof ClientExportException ? $error->getMessage() : 'Der Datenexport konnte nicht erstellt werden.');
}
