<?php
declare(strict_types=1);

function coachingConfig(): array
{
    $configFile = dirname(__DIR__) . '/config.local.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Private application configuration is missing.');
    }

    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('Private application configuration is invalid.');
    }

    return $config;
}

function coachingActiveEngagementId(PDO $database, int $clientId): int
{
    $query = $database->prepare("SELECT id FROM coaching_auftraege WHERE klient_id = :client AND status = 'aktiv' ORDER BY id DESC LIMIT 1");
    $query->execute(['client' => $clientId]);
    $id = $query->fetchColumn();
    if ($id === false) {
        throw new RuntimeException('Für diesen Klienten gibt es keinen aktiven Coaching-Auftrag. Lege zuerst über Coaching-Aufträge einen neuen Auftrag an.');
    }
    return (int) $id;
}

function coachingDatabase(): PDO
{
    $database = coachingConfig()['database'] ?? null;
    if (!is_array($database)) {
        throw new RuntimeException('Database configuration is invalid.');
    }

    $host = (string) ($database['host'] ?? '');
    $port = filter_var($database['port'] ?? null, FILTER_VALIDATE_INT);
    $name = (string) ($database['name'] ?? '');
    $username = (string) ($database['username'] ?? '');
    $password = (string) ($database['password'] ?? '');

    if ($host === '' || $port === false || $port < 1 || $port > 65535
        || !preg_match('/^[A-Za-z0-9_]+$/', $name)
        || $username === '' || $password === '' || $password === 'SET_A_PRIVATE_PASSWORD') {
        throw new RuntimeException('Database configuration is incomplete.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $name,
    );

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 3,
    ]);
}
