<?php
/**
 * Session Debug Endpoint
 * Use this to diagnose session issues
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Collect session diagnostic info
$debug = [
    'session_status' => session_status(),
    'session_status_text' => [
        PHP_SESSION_DISABLED => 'disabled',
        PHP_SESSION_NONE => 'none',
        PHP_SESSION_ACTIVE => 'active'
    ][session_status()] ?? 'unknown',
    'session_id' => session_id(),
    'session_name' => session_name(),
    'session_data_exists' => !empty($_SESSION),
    'user_id_set' => isset($_SESSION['user_id']),
    'user_id_value' => $_SESSION['user_id'] ?? null,
    'user_type' => $_SESSION['user_type'] ?? null,
    'cookies_received' => !empty($_COOKIE),
    'session_cookie_received' => isset($_COOKIE[session_name()]),
    'session_cookie_value' => $_COOKIE[session_name()] ?? null,
    'is_logged_in' => function_exists('isLoggedIn') ? isLoggedIn() : 'function not available',
    'server_info' => [
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? '',
        'http_host' => $_SERVER['HTTP_HOST'] ?? '',
        'https' => $_SERVER['HTTPS'] ?? 'not set',
        'server_port' => $_SERVER['SERVER_PORT'] ?? '',
        'request_scheme' => $_SERVER['REQUEST_SCHEME'] ?? '',
    ],
    'cookie_params' => session_get_cookie_params(),
    'all_cookies' => array_keys($_COOKIE),
];

echo json_encode($debug, JSON_PRETTY_PRINT);
