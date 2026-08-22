<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$view = (string) ($_GET['view'] ?? 'home');
$allowedViews = ['home', 'login', 'register'];
$view = in_array($view, $allowedViews, true) ? $view : 'home';
$errors = [];
$form = ['first_name' => '', 'last_name' => '', 'student_id' => '', 'email' => '', 'academic_level' => ''];

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
                } elseif ($user['status'] === 'pending') {
                    $errors[] = 'Your account is awaiting administrator verification.';
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
        if (!in_array($form['academic_level'], ['college', 'shs', 'jhs'], true)) {
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
                         VALUES (:student_id, :first_name, :last_name, :email, :password, :academic_level, 'student', 'pending')"
                    );
                    $insert->execute([
                        'student_id' => $form['student_id'],
                        'first_name' => $form['first_name'],
                        'last_name' => $form['last_name'],
                        'email' => $form['email'],
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'academic_level' => $form['academic_level'],
                    ]);
                    set_flash('success', 'Registration submitted. An administrator must verify your account before login.');
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
    $template = str_replace(
        ['href="index.css"', 'href="index.html"', 'href="login.php"', 'href="register.php"', 'href="admin/login.html"'],
        ['href="assets/front.css"', 'href="index.php"', 'href="index.php?view=login"', 'href="index.php?view=register"', 'href="admin-login.php"'],
        $template
    );
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
  <title><?= $view === 'login' ? 'Student Login' : 'Student Registration' ?> | CSCQC E-Store</title>
  <link rel="stylesheet" href="assets/front.css">
</head>
<body class="auth-page">
  <a class="brand auth-brand" href="index.php"><span class="brand-mark">C</span><span><strong>CSCQC</strong><small>E-STORE</small></span></a>
  <main class="auth-layout">
    <section class="auth-message">
      <span class="eyebrow">CSCQC STUDENT PORTAL</span>
      <h1><?= $view === 'login' ? 'Welcome back to your campus store.' : 'Create your verified student account.' ?></h1>
      <p>Reserve official uniforms and learning materials, then track each order from your dashboard.</p>
      <a href="index.php"><i class="bx bx-home-alt"></i> Return to the main page</a>
    </section>
    <section class="auth-card <?= $view === 'register' ? 'register-card' : '' ?>">
      <div class="auth-heading"><span><?= $view === 'login' ? 'STUDENT LOGIN' : 'NEW STUDENT' ?></span><h2><?= $view === 'login' ? 'Log in to your account' : 'Register an account' ?></h2></div>
      <?php if ($flash): ?><div class="form-alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="form-alert error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

      <?php if ($view === 'login'): ?>
        <form method="post" action="index.php?view=login">
          <?= csrf_field() ?><input type="hidden" name="action" value="login">
          <label for="studentId">Student ID</label><input id="studentId" name="student_id" autocomplete="username" required>
          <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
          <button class="primary-button full-button" type="submit">Log in</button>
        </form>
        <p class="auth-switch">No account yet? <a href="index.php?view=register">Register here</a></p>
      <?php else: ?>
        <form method="post" action="index.php?view=register">
          <?= csrf_field() ?><input type="hidden" name="action" value="register">
          <div class="input-pair"><div><label for="firstName">First name</label><input id="firstName" name="first_name" value="<?= h($form['first_name']) ?>" required></div><div><label for="lastName">Last name</label><input id="lastName" name="last_name" value="<?= h($form['last_name']) ?>" required></div></div>
          <label for="studentId">Student ID</label><input id="studentId" name="student_id" value="<?= h($form['student_id']) ?>" required>
          <label for="email">School email</label><input id="email" name="email" type="email" value="<?= h($form['email']) ?>" required>
          <label for="level">Academic level</label><select id="level" name="academic_level" required><option value="">Select level</option><option value="college">College</option><option value="shs">Senior High School</option><option value="jhs">Junior High School</option></select>
          <div class="input-pair"><div><label for="newPassword">Password</label><input id="newPassword" name="password" type="password" minlength="8" required></div><div><label for="confirmPassword">Confirm password</label><input id="confirmPassword" name="password_confirmation" type="password" minlength="8" required></div></div>
          <button class="primary-button full-button" type="submit">Submit registration</button>
        </form>
        <p class="auth-switch">Already registered? <a href="index.php?view=login">Log in</a></p>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
