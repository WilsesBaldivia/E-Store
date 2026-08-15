<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/db.php';

if (current_user()) {
    redirect('homepage.php');
}

$errors = [];
$form = [
    'first_name' => '',
    'last_name' => '',
    'student_id' => '',
    'email' => '',
    'academic_level' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $field) {
        $form[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $form['student_id'] = strtoupper($form['student_id']);
    $form['email'] = strtolower($form['email']);
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!verify_csrf()) {
        $errors[] = 'Your form session expired. Please try again.';
    }
    if (!preg_match("/^[\p{L} .'-]{2,50}$/u", $form['first_name'])) {
        $errors[] = 'Enter a valid first name.';
    }
    if (!preg_match("/^[\p{L} .'-]{2,50}$/u", $form['last_name'])) {
        $errors[] = 'Enter a valid last name.';
    }
    if (!preg_match('/^[A-Z0-9-]{4,30}$/', $form['student_id'])) {
        $errors[] = 'Student ID may contain letters, numbers, and hyphens.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 150) {
        $errors[] = 'Enter a valid school email address.';
    }
    if (!in_array($form['academic_level'], ['college', 'shs', 'jhs'], true)) {
        $errors[] = 'Select your academic level.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must contain at least 8 characters.';
    }
    if ($password !== $passwordConfirmation) {
        $errors[] = 'Password confirmation does not match.';
    }
    if (empty($_POST['agreement'])) {
        $errors[] = 'Confirm that your registration information is correct.';
    }

    if (!$errors) {
        try {
            $duplicateCheck = db()->prepare(
                'SELECT student_id, email
                 FROM users
                 WHERE student_id = :student_id OR email = :email
                 LIMIT 1'
            );
            $duplicateCheck->execute([
                'student_id' => $form['student_id'],
                'email' => $form['email'],
            ]);
            $existing = $duplicateCheck->fetch();

            if ($existing) {
                if (strcasecmp((string) $existing['student_id'], $form['student_id']) === 0) {
                    $errors[] = 'That student ID is already registered.';
                }
                if (strcasecmp((string) $existing['email'], $form['email']) === 0) {
                    $errors[] = 'That email address is already registered.';
                }
            } else {
                $insert = db()->prepare(
                    'INSERT INTO users
                        (student_id, first_name, last_name, email, password, academic_level, role, status)
                     VALUES
                        (:student_id, :first_name, :last_name, :email, :password, :academic_level, :role, :status)'
                );
                $insert->execute([
                    'student_id' => $form['student_id'],
                    'first_name' => $form['first_name'],
                    'last_name' => $form['last_name'],
                    'email' => $form['email'],
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'academic_level' => $form['academic_level'],
                    'role' => 'student',
                    'status' => 'active',
                ]);

                set_flash('success', 'Registration successful. You can now log in.');
                redirect('login.php');
            }
        } catch (RuntimeException $exception) {
            error_log('CSCQC registration error: ' . $exception->getMessage());
            $errors[] = 'Registration is temporarily unavailable. Check that MySQL is running and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Registration | CSCQC E-Store</title>
  <link rel="stylesheet" href="index.css">
</head>
<body class="auth-page">
  <a class="brand auth-brand" href="index.html"><span class="brand-mark">C</span><span><strong>CSCQC</strong><small>E-STORE</small></span></a>
  <main class="auth-layout">
    <section class="auth-message">
      <span class="eyebrow">Join the E-Store</span>
      <h1>Create your student shopping account.</h1>
      <p>Register with your student information to reserve official school products.</p>
      <a href="index.html"><i class="bx bx-home-alt"></i> Return to the main page</a>
    </section>
    <section class="auth-card register-card">
      <div class="auth-heading"><span>New student</span><h2>Register an account</h2><p>Complete the information below.</p></div>

      <?php if ($errors): ?>
        <div class="form-alert error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form id="registerForm" action="register.php" method="post">
        <?= csrf_field() ?>
        <div class="input-pair"><div><label for="firstName">First name</label><input id="firstName" name="first_name" value="<?= h($form['first_name']) ?>" autocomplete="given-name" required></div><div><label for="lastName">Last name</label><input id="lastName" name="last_name" value="<?= h($form['last_name']) ?>" autocomplete="family-name" required></div></div>
        <label for="regStudentId">Student ID</label><input id="regStudentId" name="student_id" value="<?= h($form['student_id']) ?>" autocomplete="username" placeholder="e.g. 2026-00123" required>
        <label for="email">School email</label><input id="email" name="email" value="<?= h($form['email']) ?>" type="email" autocomplete="email" placeholder="student@cscqc.edu.ph" required>
        <label for="academicLevel">Academic level</label><select id="academicLevel" name="academic_level" required><option value="">Select academic level</option><option value="college" <?= $form['academic_level'] === 'college' ? 'selected' : '' ?>>College</option><option value="shs" <?= $form['academic_level'] === 'shs' ? 'selected' : '' ?>>Senior High School</option><option value="jhs" <?= $form['academic_level'] === 'jhs' ? 'selected' : '' ?>>Junior High School</option></select>
        <div class="input-pair"><div><label for="regPassword">Password</label><input id="regPassword" name="password" type="password" autocomplete="new-password" minlength="8" placeholder="At least 8 characters" required></div><div><label for="passwordConfirmation">Confirm password</label><input id="passwordConfirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div></div>
        <label class="check-label agreement"><input type="checkbox" name="agreement" value="1" required> I confirm that the information provided is correct.</label>
        <button class="primary-button full-button" type="submit">Create account</button>
      </form>
      <p class="auth-switch">Already registered? <a href="login.php">Log in</a></p>
    </section>
  </main>
</body>
</html>
