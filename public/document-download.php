<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/document_storage.php';

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || !in_array(($user['role'] ?? ''), ['coach', 'client'], true) || !isset($user['id'])) {
    http_response_code(403);
    exit('Kein Zugriff.');
}
$database = coachingDatabase();
$coachId = (int) $user['id'];
$clientId = (int) ($user['client_id'] ?? 0);
$isClient = ($user['role'] ?? '') === 'client';
if ($isClient) {
    $activeCheck = $database->prepare('SELECT aktiv FROM coaching_portal_zugaenge WHERE id = :id AND klient_id = :client');
    $activeCheck->execute(['id' => $coachId, 'client' => $clientId]);
    if ((int) $activeCheck->fetchColumn() !== 1) {
        unset($_SESSION['user']);
        session_regenerate_id(true);
        http_response_code(403);
        exit('Der Coachee-Zugang ist nicht aktiv.');
    }
}
$type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || (int) $id < 1) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

try {
    if ($type === 'template' && !$isClient) {
        $query = $database->prepare('SELECT speicher_schluessel, originalname, mime_typ FROM coaching_dokumentvorlagen WHERE id = :id AND coach_benutzer_id = :coach');
        $query->execute(['id' => (int) $id, 'coach' => $coachId]);
    } elseif ($type === 'assigned-template') {
        if ($isClient) {
            $query = $database->prepare("SELECT d.vorlage_speicher_schluessel AS speicher_schluessel, d.vorlage_originalname AS originalname, v.mime_typ FROM coaching_klientendokumente d INNER JOIN klienten k ON k.id=d.klient_id INNER JOIN coaching_dokumentvorlagen v ON v.id=d.vorlage_id AND v.coach_benutzer_id=d.coach_benutzer_id INNER JOIN coaching_auftraege a ON a.id=d.auftrag_id AND a.klient_id=d.klient_id WHERE d.id=:id AND d.klient_id=:client AND d.status IN ('zugewiesen','ausgegeben','zurueckgekommen') AND (a.status='aktiv' OR (a.status='abgeschlossen' AND a.archiv_freigegeben=1))");
            $query->execute(['id'=>(int)$id,'client'=>$clientId]);
        } else {
            $query = $database->prepare('SELECT d.vorlage_speicher_schluessel AS speicher_schluessel, d.vorlage_originalname AS originalname, v.mime_typ FROM coaching_klientendokumente d INNER JOIN klienten k ON k.id = d.klient_id INNER JOIN coaching_dokumentvorlagen v ON v.id = d.vorlage_id AND v.coach_benutzer_id = d.coach_benutzer_id WHERE d.id = :id AND d.coach_benutzer_id = :coach AND k.benutzer_id = :client_owner');
            $query->execute(['id' => (int) $id, 'coach' => $coachId, 'client_owner' => $coachId]);
        }
    } elseif ($type === 'return' && $isClient) {
        $query = $database->prepare("SELECT f.speicher_schluessel,f.originalname,f.mime_typ FROM coaching_dokumentdateien f INNER JOIN coaching_klientendokumente d ON d.id=f.klientendokument_id AND d.coach_benutzer_id=f.coach_benutzer_id INNER JOIN coaching_auftraege a ON a.id=d.auftrag_id AND a.klient_id=d.klient_id WHERE f.id=:id AND d.klient_id=:client AND d.status IN ('zugewiesen','ausgegeben','zurueckgekommen') AND (a.status='aktiv' OR (a.status='abgeschlossen' AND a.archiv_freigegeben=1))");
        $query->execute(['id'=>(int)$id,'client'=>$clientId]);
    } elseif ($type === 'return') {
        $query = $database->prepare('SELECT f.speicher_schluessel, f.originalname, f.mime_typ FROM coaching_dokumentdateien f INNER JOIN coaching_klientendokumente d ON d.id = f.klientendokument_id AND d.coach_benutzer_id = f.coach_benutzer_id INNER JOIN klienten k ON k.id = d.klient_id WHERE f.id = :id AND f.coach_benutzer_id = :coach AND d.coach_benutzer_id = :document_owner AND k.benutzer_id = :client_owner');
        $query->execute(['id' => (int) $id, 'coach' => $coachId, 'document_owner' => $coachId, 'client_owner' => $coachId]);
    } else {
        http_response_code(404);
        exit('Datei nicht gefunden.');
    }

    $file = $query->fetch(PDO::FETCH_ASSOC);
    if (!$file || !in_array($file['mime_typ'], ['application/pdf', 'image/jpeg', 'image/png'], true)) {
        http_response_code(404);
        exit('Datei nicht gefunden.');
    }
    $path = coachingResolveDocumentFile((string) $file['speicher_schluessel'], (string) $file['mime_typ']);
    $name = basename(str_replace("\\", '/', (string) $file['originalname']));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'Dokument';
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'Dokument';
    header('Content-Type: ' . $file['mime_typ']);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fallback) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    readfile($path);
    exit;
} catch (Throwable $exception) {
    error_log('Coaching Tool protected document download failed: ' . $exception->getMessage());
    http_response_code(404);
    exit('Datei nicht gefunden.');
}
