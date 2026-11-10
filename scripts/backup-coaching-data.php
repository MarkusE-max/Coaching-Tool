<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Europe/Berlin');
require_once dirname(__DIR__) . '/app/bootstrap.php';

function backupIdentifier(string $value): string
{
    $tick = chr(96);
    return $tick . str_replace($tick, $tick . $tick, $value) . $tick;
}
function backupSqlValue(PDO $database, mixed $value, string $type): string
{
    if ($value === null) return 'NULL';
    $type = strtolower($type);
    if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)/', $type)) {
        $text = (string) $value;
        if (preg_match('/^-?\d+(?:\.\d+)?(?:e[+-]?\d+)?$/i', $text)) return $text;
    }
    if (preg_match('/^(bit|binary|varbinary|tinyblob|blob|mediumblob|longblob)/', $type)) return '0x' . bin2hex((string) $value);
    return $database->quote((string) $value);
}
function backupCopyPrivateFiles(string $source, string $destination): int
{
    if (!is_dir($source) && !mkdir($source, 0700, true) && !is_dir($source)) throw new RuntimeException('Private document folder is not available.');
    if (is_link($source)) throw new RuntimeException('Private document folder cannot be a symbolic link.');
    $count = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if (is_link($path)) continue;
        $relative = substr($path, strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
        $target = $destination . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) throw new RuntimeException('Could not create a private backup folder.');
            continue;
        }
        if (!$item->isFile() || !is_readable($path)) throw new RuntimeException('A private document could not be read for backup.');
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) throw new RuntimeException('Could not create a private backup folder.');
        if (!copy($path, $target)) throw new RuntimeException('A private document could not be copied.');
        chmod($target, 0600);
        $count++;
    }
    return $count;
}
function backupRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) { if (is_file($path) || is_link($path)) @unlink($path); return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname()); else @unlink($item->getPathname()); }
    @rmdir($path);
}

try {
    $config = coachingConfig();
    $databaseConfig = $config['database'] ?? [];
    $databaseName = (string) ($databaseConfig['name'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $databaseName)) throw new RuntimeException('Database name is invalid.');
    if (!extension_loaded('zlib')) throw new RuntimeException('PHP zlib is required to create compressed backups.');

    $root = dirname(__DIR__);
    $storagePath = (string) ($config['document_storage_path'] ?? ($root . '/storage/documents'));
    $backupPath = (string) ($config['backup_storage_path'] ?? ($root . '/storage/backups'));
    $retentionDays = filter_var($config['backup_retention_days'] ?? 30, FILTER_VALIDATE_INT);
    if ($retentionDays === false || $retentionDays < 7 || $retentionDays > 3650) throw new RuntimeException('Backup retention must be between 7 and 3650 days.');
    $publicRoot = realpath($root . '/public');
    $backupReal = realpath($backupPath);
    if ($publicRoot !== false && $backupReal !== false && ($backupReal === $publicRoot || str_starts_with($backupReal . DIRECTORY_SEPARATOR, $publicRoot . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Backup folder must be outside the public web folder.');
    }
    if (!is_dir($backupPath) && !mkdir($backupPath, 0700, true) && !is_dir($backupPath)) throw new RuntimeException('Backup folder cannot be created.');
    if (is_link($backupPath)) throw new RuntimeException('Backup folder cannot be a symbolic link.');
    chmod($backupPath, 0700);

    $stamp = date('Y-m-d_His');
    $final = rtrim($backupPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'coaching-' . $stamp;
    $working = $final . '.incomplete';
    if (file_exists($final) || file_exists($working)) throw new RuntimeException('A backup with this timestamp already exists.');
    if (!mkdir($working, 0700, true)) throw new RuntimeException('Temporary backup folder cannot be created.');

    $database = coachingDatabase();
    $sqlPath = $working . DIRECTORY_SEPARATOR . 'database.sql.gz';
    $gzip = gzopen($sqlPath, 'wb6');
    if ($gzip === false) throw new RuntimeException('Compressed database backup cannot be opened.');
    chmod($sqlPath, 0600);
    $write = static function (string $chunk) use ($gzip): void {
        if (gzwrite($gzip, $chunk) === false) throw new RuntimeException('Database backup could not be written.');
    };
    $write("-- Coaching Tool MariaDB backup\n-- Created: " . date(DATE_ATOM) . "\n-- Database: " . $databaseName . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $tables = $database->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $tableRow) {
        $tableName = (string) ($tableRow[0] ?? '');
        $tableType = strtoupper((string) ($tableRow[1] ?? ''));
        if ($tableName === '' || $tableType !== 'BASE TABLE') continue;
        $quotedTable = backupIdentifier($tableName);
        $create = $database->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
        if (!is_array($create) || !isset($create[1])) throw new RuntimeException('A database table definition could not be read.');
        $write("\nDROP TABLE IF EXISTS {$quotedTable};\n" . rtrim((string) $create[1], ';') . ";\n");
        $columns = $database->query('SHOW COLUMNS FROM ' . $quotedTable)->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map(static fn(array $column): string => (string) $column['Field'], $columns);
        $columnTypes = array_map(static fn(array $column): string => (string) $column['Type'], $columns);
        $columnSql = implode(',', array_map('backupIdentifier', $columnNames));
        $rows = $database->query('SELECT * FROM ' . $quotedTable);
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            $values = [];
            foreach ($row as $index => $value) $values[] = backupSqlValue($database, $value, $columnTypes[$index] ?? '');
            $write('INSERT INTO ' . $quotedTable . ' (' . $columnSql . ') VALUES (' . implode(',', $values) . ");\n");
        }
    }
    $write("\nSET FOREIGN_KEY_CHECKS=1;\n");
    if (!gzclose($gzip)) throw new RuntimeException('Compressed database backup could not be finalized.');

    $documentBackup = $working . DIRECTORY_SEPARATOR . 'documents';
    if (!mkdir($documentBackup, 0700, true)) throw new RuntimeException('Private document backup folder cannot be created.');
    $documentCount = backupCopyPrivateFiles($storagePath, $documentBackup);
    $manifest = ['created_at' => date(DATE_ATOM), 'database' => $databaseName, 'database_dump' => 'database.sql.gz', 'private_documents_directory' => 'documents', 'private_document_count' => $documentCount];
    file_put_contents($working . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    chmod($working . DIRECTORY_SEPARATOR . 'manifest.json', 0600);
    if (!rename($working, $final)) throw new RuntimeException('Completed backup could not be finalized.');
    chmod($final, 0700);

    $cutoff = time() - ((int) $retentionDays * 86400);
    $removed = 0;
    foreach (new DirectoryIterator($backupPath) as $entry) {
        if ($entry->isDot() || !$entry->isDir() || $entry->isLink() || !preg_match('/^coaching-\d{4}-\d{2}-\d{2}_\d{6}$/', $entry->getFilename()) || $entry->getMTime() >= $cutoff) continue;
        backupRemoveTree($entry->getPathname());
        $removed++;
    }
    fwrite(STDOUT, 'Backup created: ' . $final . '; private files: ' . $documentCount . '; expired backups removed: ' . $removed . PHP_EOL);
} catch (Throwable $error) {
    if (isset($gzip) && is_resource($gzip)) @gzclose($gzip);
    if (isset($working) && is_dir($working)) backupRemoveTree($working);
    error_log('Coaching Tool backup failed: ' . $error->getMessage());
    fwrite(STDERR, "Coaching Tool backup failed. Check the PHP error log.\n");
    exit(1);
}
