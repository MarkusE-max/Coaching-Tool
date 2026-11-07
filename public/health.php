<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'database' => 'unavailable']);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

try {
    coachingDatabase()->query('SELECT 1')->fetchColumn();
    echo json_encode(['status' => 'ok', 'database' => 'connected']);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'database' => 'unavailable']);
}
