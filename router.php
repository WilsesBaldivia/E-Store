<?php
declare(strict_types=1);

$path = rtrim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'), '/');
$path = $path === '' ? '/' : $path;
$publicFile = __DIR__ . $path;

if ($path !== '/' && is_file($publicFile)) {
    return false;
}

$publicViews = [
    '/login' => 'login',
    '/register' => 'register',
    '/forgot-password' => 'forgot',
];

if (isset($publicViews[$path])) {
    $_GET['view'] = $publicViews[$path];
    require __DIR__ . '/index.php';
    return true;
}

if ($path === '/student') {
    require __DIR__ . '/student.php';
    return true;
}

if ($path === '/staff-login') {
    require __DIR__ . '/admin-login.php';
    return true;
}

if ($path === '/manage') {
    require __DIR__ . '/admin.php';
    return true;
}

return false;
