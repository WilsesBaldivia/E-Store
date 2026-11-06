<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$requestedPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
if (basename($requestedPath) === 'admin-login.php') {
    redirect('staff-login');
}

if (($current = current_user()) && in_array($current['role'], ['admin', 'staff'], true) && $current['status'] === 'active') {
    redirect('manage');
}
$errors = [];
$adminCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'login');
    if (!verify_csrf()) {
        $errors[] = 'Your form session expired.';
    } elseif ($action === 'setup' && $adminCount === 0) {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
            $errors[] = 'Enter a name, valid email, and password of at least 10 characters.';
        } else {
            $parts = preg_split('/\s+/', $name, 2);
            try {
                $insert = db()->prepare("INSERT INTO users (first_name,last_name,email,password,role,status) VALUES (:first,:last,:email,:password,'admin','active')");
                $insert->execute(['first' => $parts[0], 'last' => $parts[1] ?? 'Administrator', 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT)]);
                set_flash('success', 'Administrator account created. You may now log in.');
                redirect('staff-login');
            } catch (Throwable $exception) { $errors[] = 'The administrator account could not be created.'; }
        }
    } elseif ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $query = db()->prepare("SELECT * FROM users WHERE email = :email AND role IN ('admin','staff') LIMIT 1");
        $query->execute(['email' => $email]);
        $user = $query->fetch();
        if (!$user || !password_verify($password, $user['password'])) $errors[] = 'Invalid staff email or password.';
        elseif ($user['status'] !== 'active') $errors[] = 'This staff account is not active.';
        else { login_user($user); redirect('manage'); }
    }
}
$flash = get_flash();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#064c39"><title>Staff Login | CSCQC E-Store</title><link rel="icon" type="image/png" href="assets/images/cscqc-estore-logo.png"><link rel="apple-touch-icon" href="assets/images/cscqc-estore-logo.png"><link rel="stylesheet" href="assets/admin.css?v=<?= (int) filemtime(__DIR__ . '/assets/admin.css') ?>"></head><body class="login-page"><main class="login-wrap"><section class="login-message"><a class="admin-brand" href="./" aria-label="Return to the CSCQC E-Store homepage"><span class="admin-mark"><img class="site-logo" src="assets/images/cscqc-estore-logo.png" alt=""></span><span>CSCQC ADMIN<small>E-STORE MANAGEMENT</small></span></a><span class="eyebrow">AUTHORIZED PERSONNEL ONLY</span><h1>Run the campus store with confidence.</h1><p>Keep reservations, inventory, products, and student accounts organized in one secure workspace.</p><a class="back-store" href="./"><i class="bx bx-left-arrow-alt"></i> Return to student store</a></section><section class="login-card"><?php if ($flash): ?><div class="admin-alert success"><?= h($flash['message']) ?></div><?php endif; ?><?php if ($errors): ?><div class="admin-alert error"><?= h(implode(' ', $errors)) ?></div><?php endif; ?><?php if ($adminCount === 0): ?><span class="eyebrow">FIRST-TIME SETUP</span><h2>Create administrator</h2><p>Set up the first secure management account.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="setup"><label>Full name</label><input name="name" autocomplete="name" required><label>Email</label><input name="email" type="email" autocomplete="email" required><label>Password</label><input name="password" type="password" minlength="10" autocomplete="new-password" required><button class="primary-button">Create administrator</button></form><?php else: ?><span class="eyebrow">SECURE MANAGEMENT PORTAL</span><h2>Welcome back</h2><p>Sign in with your authorized staff account.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="login"><label>Staff email</label><input name="email" type="email" autocomplete="username" required><label>Password</label><input name="password" type="password" autocomplete="current-password" required><button class="primary-button">Log in to dashboard</button></form><?php endif; ?></section></main></body></html>
