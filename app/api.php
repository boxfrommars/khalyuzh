<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function sendJson(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function readJsonBody(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        throw new InvalidArgumentException('Request body must contain JSON.');
    }

    try {
        $payload = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new InvalidArgumentException('Request body contains invalid JSON.', 0, $error);
    }

    if (!is_array($payload)) {
        throw new InvalidArgumentException('Request body must be a JSON object.');
    }

    return $payload;
}

function runApi(bool $allowWrites): never
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    try {
        if ($method === 'GET') {
            sendJson(['records' => fetchRecords()]);
        }

        if (!$allowWrites) {
            header('Allow: GET');
            sendJson(['error' => 'Method not allowed.'], 405);
        }

        if ($method === 'PUT') {
            $date = validateRecordDate($_GET['date'] ?? null);
            $payload = readJsonBody();
            $dryAmount = validateAmount($payload['dryAmount'] ?? null, 'dryAmount');
            $wetAmount = validateAmount($payload['wetAmount'] ?? null, 'wetAmount');
            $result = saveRecord($date, $dryAmount, $wetAmount);
            sendJson(['record' => $result['record']], $result['created'] ? 201 : 200);
        }

        if ($method === 'DELETE') {
            $date = validateRecordDate($_GET['date'] ?? null);
            deleteRecord($date);
            http_response_code(204);
            header('Cache-Control: no-store');
            exit;
        }

        header('Allow: GET, PUT, DELETE');
        sendJson(['error' => 'Method not allowed.'], 405);
    } catch (InvalidArgumentException $error) {
        sendJson(['error' => $error->getMessage()], 400);
    } catch (Throwable $error) {
        error_log($error->__toString());
        sendJson(['error' => 'Internal server error.'], 500);
    }
}
