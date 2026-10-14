<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$view = (string) ($_GET['view'] ?? 'home');
$allowedViews = ['home', 'login', 'register', 'forgot'];
$view = in_array($view, $allowedViews, true) ? $view : 'home';
$errors = [];
$form = ['first_name' => '', 'last_name' => '', 'student_id' => '', 'email' => '', 'academic_level' => ''];
$resetForm = ['student_id' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!verify_csrf()) {
        $errors[] = 'Your form session expired. Please try again.';
    } elseif ($action === 'logout') {
        logout_user();
        redirect('index.php');
    } elseif ($action === 'login') {
        $view = 'login';
        $studentId = strtoupper(trim((string) ($_POST['student_id'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if ($studentId === '' || $password === '') {
            $errors[] = 'Enter your student ID and password.';
        } else {
            try {
                $statement = db()->prepare('SELECT * FROM users WHERE student_id = :student_id AND role = \'student\' LIMIT 1');
                $statement->execute(['student_id' => $studentId]);
                $user = $statement->fetch();

                if (!$user || !password_verify($password, $user['password'])) {
                    $errors[] = 'The student ID or password is incorrect.';
                } elseif ($user['status'] !== 'active') {
                    $errors[] = 'Your account is suspended. Please contact the E-Store staff.';
                } else {
                    login_user($user);
                    redirect('student.php');
                }
            } catch (RuntimeException $exception) {
                $errors[] = 'Login is temporarily unavailable. Please check MySQL.';
            }
        }
    } elseif ($action === 'reset_password') {
        $view = 'forgot';
        $resetForm['student_id'] = strtoupper(trim((string) ($_POST['student_id'] ?? '')));
        $resetForm['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        $now = time();
        $resetAttempts = array_values(array_filter(
            (array) ($_SESSION['password_reset_attempts'] ?? []),
            static fn ($attempt): bool => is_int($attempt) && $attempt > $now - 900
        ));
        if (count($resetAttempts) >= 5) {
            $errors[] = 'Too many reset attempts. Please wait 15 minutes and try again.';
        }
        if (!preg_match('/^[A-Z0-9-]{4,30}$/', $resetForm['student_id'])) {
            $errors[] = 'Enter a valid student ID.';
        }
        if (!filter_var($resetForm['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter the email registered to your account.';
        }
        if (strlen($password) < 8 || $password !== $confirmation) {
            $errors[] = 'Passwords must match and contain at least 8 characters.';
        }

        if (!$errors) {
            $resetAttempts[] = $now;
            $_SESSION['password_reset_attempts'] = $resetAttempts;
            try {
                $accountQuery = db()->prepare(
                    "SELECT id FROM users
                     WHERE student_id = :student_id AND email = :email AND role = 'student' AND status = 'active'
                     LIMIT 1"
                );
                $accountQuery->execute(['student_id' => $resetForm['student_id'], 'email' => $resetForm['email']]);
                $account = $accountQuery->fetch();
                if (!$account) {
                    $errors[] = 'The student ID and registered email do not match an active student account.';
                } else {
                    $passwordUpdate = db()->prepare('UPDATE users SET password = :password WHERE id = :id');
                    $passwordUpdate->execute([
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => (int) $account['id'],
                    ]);
                    unset($_SESSION['password_reset_attempts']);
                    set_flash('success', 'Your password has been changed. You can now log in.');
                    redirect('index.php?view=login');
                }
            } catch (RuntimeException $exception) {
                $errors[] = 'Password recovery is temporarily unavailable. Please check MySQL.';
            }
        }
    } elseif ($action === 'register') {
        $view = 'register';
        foreach (array_keys($form) as $field) {
            $form[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        $form['student_id'] = strtoupper($form['student_id']);
        $form['email'] = strtolower($form['email']);
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (!preg_match("/^[\p{L} .'-]{2,50}$/u", $form['first_name']) || !preg_match("/^[\p{L} .'-]{2,50}$/u", $form['last_name'])) {
            $errors[] = 'Enter a valid first and last name.';
        }
        if (!preg_match('/^[A-Z0-9-]{4,30}$/', $form['student_id'])) {
            $errors[] = 'Enter a valid student ID.';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid school email address.';
        }
        if (!in_array($form['academic_level'], ['elementary', 'college', 'shs', 'jhs'], true)) {
            $errors[] = 'Select your academic level.';
        }
        if (strlen($password) < 8 || $password !== $confirmation) {
            $errors[] = 'Passwords must match and contain at least 8 characters.';
        }

        if (!$errors) {
            try {
                $check = db()->prepare('SELECT id FROM users WHERE student_id = :student_id OR email = :email LIMIT 1');
                $check->execute(['student_id' => $form['student_id'], 'email' => $form['email']]);
                if ($check->fetch()) {
                    $errors[] = 'That student ID or email is already registered.';
                } else {
                    $insert = db()->prepare(
                        "INSERT INTO users (student_id, first_name, last_name, email, password, academic_level, role, status)
                         VALUES (:student_id, :first_name, :last_name, :email, :password, :academic_level, 'student', 'active')"
                    );
                    $insert->execute([
                        'student_id' => $form['student_id'],
                        'first_name' => $form['first_name'],
                        'last_name' => $form['last_name'],
                        'email' => $form['email'],
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'academic_level' => $form['academic_level'],
                    ]);
                    set_flash('success', 'Registration successful. You can now log in.');
                    redirect('index.php?view=login');
                }
            } catch (RuntimeException $exception) {
                $errors[] = 'Registration is temporarily unavailable. Please check MySQL.';
            }
        }
    }
}

if ($view === 'home') {
    $template = file_get_contents(__DIR__ . '/index.html');
    if ($template === false) {
        http_response_code(500);
        exit('The landing page template is unavailable.');
    }
    $template = str_replace('href="index.css"', 'href="assets/front.css"', $template);
    if (current_user() && current_user()['role'] === 'student') {
        $template = str_replace('href="index.php?view=login">Log in', 'href="student.php">Student portal', $template);
    }
    echo $template;
    exit;
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $view === 'login' ? 'Student Login' : ($view === 'forgot' ? 'Reset Password' : 'Student Registration') ?> | CSCQC E-Store</title>
  <link rel="stylesheet" href="assets/front.css">
</head>
<body class="auth-page">
  <a class="brand auth-brand" href="index.php"><span class="brand-mark">C</span><span><strong>CSCQC</strong><small>E-STORE</small></span></a>
  <main class="auth-layout">
    <section class="auth-message">
      <span class="eyebrow">CSCQC STUDENT PORTAL</span>
      <h1><?= $view === 'login' ? 'Welcome back to your campus store.' : ($view === 'forgot' ? 'Recover your student account.' : 'Create your verified student account.') ?></h1>
      <p>Reserve official uniforms and learning materials, then track each order from your dashboard.</p>
      <a href="index.php"><i class="bx bx-home-alt"></i> Return to the main page</a>
    </section>
    <section class="auth-card <?= in_array($view, ['register', 'forgot'], true) ? 'register-card' : '' ?>">
      <div class="auth-heading"><span><?= $view === 'login' ? 'STUDENT LOGIN' : ($view === 'forgot' ? 'ACCOUNT RECOVERY' : 'NEW STUDENT') ?></span><h2><?= $view === 'login' ? 'Log in to your account' : ($view === 'forgot' ? 'Reset your password' : 'Register an account') ?></h2></div>
      <?php if ($flash): ?><div class="form-alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="form-alert error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

      <?php if ($view === 'login'): ?>
        <form method="post" action="index.php?view=login">
          <?= csrf_field() ?><input type="hidden" name="action" value="login">
          <label for="studentId">Student ID</label><input id="studentId" name="student_id" autocomplete="username" required>
          <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
          <button class="primary-button full-button" type="submit">Log in</button>
        </form>
        <p class="auth-switch"><a href="index.php?view=forgot">Forgot your password?</a></p>
        <p class="auth-switch">No account yet? <a href="index.php?view=register">Register here</a></p>
      <?php elseif ($view === 'forgot'): ?>
        <p>Enter the Student ID and email registered to your active account.</p>
        <form method="post" action="index.php?view=forgot">
          <?= csrf_field() ?><input type="hidden" name="action" value="reset_password">
          <label for="resetStudentId">Student ID</label><input id="resetStudentId" name="student_id" value="<?= h($resetForm['student_id']) ?>" autocomplete="username" required>
          <label for="resetEmail">Registered email</label><input id="resetEmail" name="email" type="email" value="<?= h($resetForm['email']) ?>" autocomplete="email" required>
          <div class="input-pair"><div><label for="resetPassword">New password</label><input id="resetPassword" name="password" type="password" minlength="8" autocomplete="new-password" required></div><div><label for="resetConfirmation">Confirm password</label><input id="resetConfirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required></div></div>
          <button class="primary-button full-button" type="submit">Change password</button>
        </form>
        <p class="auth-switch"><a href="index.php?view=login">Return to student login</a></p>
      <?php else: ?>
        <form method="post" action="index.php?view=register">
          <?= csrf_field() ?><input type="hidden" name="action" value="register">
          <div class="input-pair"><div><label for="firstName">First name</label><input id="firstName" name="first_name" value="<?= h($form['first_name']) ?>" required></div><div><label for="lastName">Last name</label><input id="lastName" name="last_name" value="<?= h($form['last_name']) ?>" required></div></div>
          <label for="studentId">Student ID</label><input id="studentId" name="student_id" value="<?= h($form['student_id']) ?>" required>
          <label for="email">School email</label><input id="email" name="email" type="email" value="<?= h($form['email']) ?>" required>
          <label for="level">Academic level</label><select id="level" name="academic_level" required><option value="">Select level</option><option value="elementary">Elementary</option><option value="jhs">Junior High School</option><option value="shs">Senior High School</option><option value="college">College</option></select>
          <div class="input-pair"><div><label for="newPassword">Password</label><input id="newPassword" name="password" type="password" minlength="8" required></div><div><label for="confirmPassword">Confirm password</label><input id="confirmPassword" name="password_confirmation" type="password" minlength="8" required></div></div>
          <button class="primary-button full-button" type="submit">Submit registration</button>
        </form>
        <p class="auth-switch">Already registered? <a href="index.php?view=login">Log in</a></p>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
