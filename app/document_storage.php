<?php
declare(strict_types=1);

/** Private document storage. Files are named by random keys and never served directly. */
function coachingDocumentStorageRoot(): string
{
    $config = coachingConfig();
    $configured = $config['document_storage_path'] ?? '';
    $root = is_string($configured) && $configured !== ''
        ? $configured
        : dirname(__DIR__) . '/storage/documents';

    if (!str_starts_with($root, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Der Dokumentenspeicher muss als absoluter Pfad angegeben werden.');
    }
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('Der private Dokumentenspeicher konnte nicht angelegt werden.');
    }
    @chmod($root, 0750);
    $realRoot = realpath($root);
    $publicRoot = realpath(dirname(__DIR__) . '/public');
    if ($realRoot === false || $publicRoot === false) {
        throw new RuntimeException('Der private Dokumentenspeicher ist nicht verfügbar.');
    }
    $publicPrefix = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($realRoot === $publicRoot || str_starts_with($realRoot . DIRECTORY_SEPARATOR, $publicPrefix)) {
        throw new RuntimeException('Der Dokumentenspeicher darf nicht im öffentlichen Webordner liegen.');
    }
    return $realRoot;
}

function coachingDocumentExtension(string $mime): string
{
    return match ($mime) {
        'application/pdf' => '.pdf',
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        default => throw new InvalidArgumentException('Dieses Dateiformat ist nicht erlaubt.'),
    };
}

/** @return array{key:string,mime:string,size:int,name:string,path:string} */
function coachingStoreDocumentUpload(array $upload, array $allowedMimeTypes): array
{
    if (!isset($upload['error'], $upload['tmp_name'], $upload['name'], $upload['size'])
        || (int) $upload['error'] !== UPLOAD_ERR_OK
        || !is_string($upload['tmp_name'])
        || !is_uploaded_file($upload['tmp_name'])) {
        throw new InvalidArgumentException('Der Upload ist fehlgeschlagen. Bitte wähle die Datei erneut aus.');
    }

    $size = (int) $upload['size'];
    if ($size < 1 || $size > 15 * 1024 * 1024) {
        throw new InvalidArgumentException('Dateien dürfen höchstens 15 MB groß sein.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($upload['tmp_name']);
    if (!is_string($mime) || !in_array($mime, $allowedMimeTypes, true)) {
        throw new InvalidArgumentException('Erlaubt sind PDF-Dateien sowie JPG- und PNG-Scans.');
    }

    $originalName = basename(str_replace("\\", '/', (string) $upload['name']));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?? '';
    $originalName = trim($originalName);
    if ($originalName === '') $originalName = 'Dokument' . coachingDocumentExtension($mime);
    if (strlen($originalName) > 255) $originalName = substr($originalName, 0, 255);

    $root = coachingDocumentStorageRoot();
    $key = bin2hex(random_bytes(26));
    $path = $root . DIRECTORY_SEPARATOR . $key . coachingDocumentExtension($mime);
    if (!move_uploaded_file($upload['tmp_name'], $path)) {
        throw new RuntimeException('Die Datei konnte nicht im privaten Speicher abgelegt werden.');
    }
    @chmod($path, 0600);
    return ['key' => $key, 'mime' => $mime, 'size' => $size, 'name' => $originalName, 'path' => $path];
}

function coachingResolveDocumentFile(string $key, string $mime): string
{
    if (!preg_match('/^[a-f0-9]{52}$/', $key)) {
        throw new RuntimeException('Ungültiger Dokumentenschlüssel.');
    }
    $root = coachingDocumentStorageRoot();
    $path = $root . DIRECTORY_SEPARATOR . $key . coachingDocumentExtension($mime);
    $realPath = realpath($path);
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($realPath === false || !is_file($realPath) || is_link($path) || !str_starts_with($realPath, $prefix)) {
        throw new RuntimeException('Die gespeicherte Datei wurde nicht gefunden.');
    }
    return $realPath;
}
