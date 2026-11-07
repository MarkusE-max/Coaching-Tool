<?php
declare(strict_types=1);

function coachingDatabase(): PDO
{
    $configFile = dirname(__DIR__) . '/config.local.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Private database configuration is missing.');
    }

    $config = require $configFile;
    $database = $config['database'] ?? null;
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
