<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/db.php';

$existingUser = current_user();
if ($existingUser && $existingUser['role'] === 'student' && $existingUser['status'] === 'active') {
    redirect('homepage.php');
}

$errors = [];
$studentId = '';
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = strtoupper(trim((string) ($_POST['student_id'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!verify_csrf()) {
        $errors[] = 'Your form session expired. Please try again.';
    }
    if ($studentId === '' || $password === '') {
        $errors[] = 'Enter your student ID and password.';
    }

    if (!$errors) {
        try {
            $statement = db()->prepare(
                'SELECT id, student_id, first_name, last_name, email, password,
                        academic_level, role, status
                 FROM users
                 WHERE student_id = :student_id
                 LIMIT 1'
            );
            $statement->execute(['student_id' => $studentId]);
            $user = $statement->fetch();

            $validLogin = $user
                && $user['role'] === 'student'
                && password_verify($password, $user['password']);

            if (!$validLogin) {
                $errors[] = 'The student ID or password is incorrect.';
            } elseif ($user['status'] !== 'active') {
                $errors[] = 'This account is suspended. Please contact the E-Store administrator.';
            } else {
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $update = db()->prepare('UPDATE users SET password = :password WHERE id = :id');
                    $update->execute(['password' => $newHash, 'id' => $user['id']]);
                }

                login_user($user);
                redirect('homepage.php');
            }
        } catch (RuntimeException $exception) {
            error_log('CSCQC login error: ' . $exception->getMessage());
            $errors[] = 'Login is temporarily unavailable. Check that MySQL is running and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Login | CSCQC E-Store</title>
  <link rel="stylesheet" href="index.css">
</head>
<body class="auth-page">
  <a class="brand auth-brand" href="index.html"><span class="brand-mark">C</span><span><strong>CSCQC</strong><small>E-STORE</small></span></a>
  <main class="auth-layout">
    <section class="auth-message">
      <span class="eyebrow">Welcome back</span>
      <h1>Everything you need for school is one sign-in away.</h1>
      <p>Access uniforms, books, reservations, and your complete order history.</p>
      <a href="index.html"><i class="bx bx-home-alt"></i> Return to the main page</a>
    </section>
    <section class="auth-card">
      <div class="auth-heading"><span>Student portal</span><h2>Log in to your account</h2><p>Enter your registered student credentials.</p></div>

      <?php if ($flash): ?>
        <div class="form-alert <?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="form-alert error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form id="loginForm" action="login.php" method="post">
        <?= csrf_field() ?>
        <label for="studentId">Student ID</label>
        <input id="studentId" name="student_id" value="<?= h($studentId) ?>" autocomplete="username" placeholder="e.g. 2026-00123" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
        <div class="form-row"><span class="form-help"><i class="bx bx-lock-alt"></i> Secure student login</span><a href="#">Forgot password?</a></div>
        <button class="primary-button full-button" type="submit">Log in</button>
      </form>
      <p class="auth-switch">No student account yet? <a href="register.php">Register here</a></p>
    </section>
  </main>
</body>
</html>
