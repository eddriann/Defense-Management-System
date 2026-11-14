<?php

require_once __DIR__ . '/session.php';

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        start_app_session();

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' .
            htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_validate_request')) {
    function csrf_validate_request(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
            http_response_code(400);
            exit('Invalid request token. Reload the page and try again.');
        }
    }
}
