<?php
echo json_encode([
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'NOT SET',
    'http_content_type' => $_SERVER['HTTP_CONTENT_TYPE'] ?? 'NOT SET',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'NOT SET',
    'raw_input' => file_get_contents('php://input'),
    'post' => $_POST,
]);
