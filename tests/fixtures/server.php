<?php

declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'];
header('Content-Type: application/json');

if (str_starts_with($uri, '/slow')) {
    sleep(2);
    echo '{"data":null}';
    return;
}
if (str_starts_with($uri, '/cms/api/cdn/v1/limited')) {
    http_response_code(429);
    header('Retry-After: 23');
    echo '{"error":{"code":"rate_limited","message":"Too many requests"}}';
    return;
}
if (str_starts_with($uri, '/cms/api/cdn/v1/content')) {
    http_response_code(404);
    echo '{"error":{"code":"not_found","message":"Content not found"}}';
    return;
}

echo json_encode(['data' => [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $uri,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'body' => file_get_contents('php://input'),
]]);
