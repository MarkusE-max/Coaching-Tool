<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'database' => 'unavailable', 'schema' => 'unknown']);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/schema.php';

try {
    $database = coachingDatabase();
    $schema = coachingSchemaStatus($database);
    if (!$schema['ready']) {
        http_response_code(503);
        echo json_encode([
            'status' => 'error',
            'database' => 'connected',
            'schema' => 'incomplete',
            'missing_tables' => $schema['missing_tables'],
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'database' => 'connected',
        'schema' => 'ready',
    ]);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'database' => 'unavailable', 'schema' => 'unknown']);
}
