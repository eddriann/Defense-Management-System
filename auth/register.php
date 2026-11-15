<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
start_app_session();

$adviser_result = mysqli_query(
    $conn,
    "SELECT DISTINCT u.user_id, u.full_name
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.user_id
     INNER JOIN roles r ON r.role_id = ur.role_id
     WHERE r.role_name = 'Adviser' AND u.status = 'active'
     ORDER BY u.full_name"
);
$advisers = mysqli_fetch_all($adviser_result, MYSQLI_ASSOC);
$adviser_ids = array_map('intval', array_column($advisers, 'user_id'));

$student_role_result = mysqli_query($conn, "SELECT role_id FROM roles WHERE role_name = 'Student' LIMIT 1");
$student_role_id = (int)(mysqli_fetch_assoc($student_role_result)['role_id'] ?? 0);
if ($student_role_id <= 0) {
    throw new RuntimeException('The Student role is not configured.');
}

$programs = ['BS Computer Science', 'BS Information Technology'];
$year_levels = ['3rd Year', '4th Year'];
$old = [
    'full_name' => '',
    'email' => '',
    'username' => '',
    'program' => '',
    'year_level' => '',
    'adviser_id' => 0,
];
$field_errors = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $post_string = static function (string $key): string {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    };

    $old['full_name'] = $full_name = $post_string('full_name');
    $old['email'] = $email = $post_string('email');
    $old['username'] = $username = $post_string('username');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm_password = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $old['program'] = $program = $post_string('program');
    $old['year_level'] = $year_level = $post_string('year_level');
    $adviser_id_value = $post_string('adviser_id');
    $parsed_adviser_id = filter_var($adviser_id_value, FILTER_VALIDATE_INT);
    $old['adviser_id'] = $adviser_id = $parsed_adviser_id === false ? 0 : $parsed_adviser_id;

    if ($full_name === '' || strlen($full_name) > 150) {
        $field_errors['full_name'] = 'Enter a name of 1 to 150 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $field_errors['email'] = 'Enter a valid email address (up to 100 characters).';
    }
    if ($username === '' || strlen($username) > 50) {
        $field_errors['username'] = 'Enter a username of 1 to 50 characters.';
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        $field_errors['password'] = 'Use a password between 8 and 72 characters.';
    }
    if ($password !== $confirm_password) {
        $field_errors['confirm_password'] = 'The password and confirmation do not match.';
    }
    if (!in_array($program, $programs, true)) {
        $field_errors['program'] = 'Select your program.';
    }
    if (!in_array($year_level, $year_levels, true)) {
        $field_errors['year_level'] = 'Select your year level.';
    }
    if (!$adviser_ids || !in_array($adviser_id, $adviser_ids, true)) {
        $field_errors['adviser_id'] = $adviser_ids
            ? 'Select an active adviser.'
            : 'Student registration is unavailable because no active advisers are listed.';
    }

    if (!$field_errors) {
        mysqli_begin_transaction($conn);
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $insert_user = mysqli_prepare(
                $conn,
                "INSERT INTO users (full_name, email, username, password, status)
                 VALUES (?, ?, ?, ?, 'active')"
            );
            mysqli_stmt_bind_param($insert_user, 'ssss', $full_name, $email, $username, $password_hash);
            mysqli_stmt_execute($insert_user);
            $user_id = mysqli_insert_id($conn);
            mysqli_stmt_close($insert_user);

            $insert_role = mysqli_prepare($conn, 'INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
            mysqli_stmt_bind_param($insert_role, 'ii', $user_id, $student_role_id);
            mysqli_stmt_execute($insert_role);
            mysqli_stmt_close($insert_role);

            $program_code = $program === 'BS Computer Science' ? 'CS' : 'IT';
            $student_number = date('Y') . '-' . $program_code . '-' . str_pad((string)$user_id, 4, '0', STR_PAD_LEFT);
            $insert_student = mysqli_prepare(
                $conn,
                'INSERT INTO students (user_id, student_number, program, year_level, adviser_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            mysqli_stmt_bind_param(
                $insert_student,
                'isssi',
                $user_id,
                $student_number,
                $program,
                $year_level,
                $adviser_id
            );
            mysqli_stmt_execute($insert_student);
            mysqli_stmt_close($insert_student);

            mysqli_commit($conn);
            header('Location: ../index.php?registered=1');
            exit();
        } catch (mysqli_sql_exception $exception) {
            mysqli_rollback($conn);
            if ($exception->getCode() !== 1062) {
                throw $exception;
            }

            $duplicate = mysqli_prepare(
                $conn,
                'SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 2'
            );
            mysqli_stmt_bind_param($duplicate, 'ss', $username, $email);
            mysqli_stmt_execute($duplicate);
            $matches = mysqli_fetch_all(mysqli_stmt_get_result($duplicate), MYSQLI_ASSOC);
            mysqli_stmt_close($duplicate);
            foreach ($matches as $match) {
                if ($match['username'] === $username) {
                    $field_errors['username'] = 'This username is already in use.';
                }
                if ($match['email'] === $email) {
                    $field_errors['email'] = 'This email address is already in use.';
                }
            }
            $error = 'Please review the highlighted fields.';
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            throw $exception;
        }
    } else {
        $error = 'Please review the highlighted fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration | Defense Management System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>&amp;rev=style-20261115-3">
    <link rel="stylesheet" href="../assets/css/register.css?v=3">
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body class="registration-page">
    <main class="registration-shell">
        <header class="registration-header">
            <a class="registration-brand" href="../index.php">
                <img src="../assets/logo/logo.jpg" alt="">
                <span>
                    <strong>Defense Management System</strong>
                    <small>Thesis and Capstone Portal</small>
                </span>
            </a>
            <a class="registration-signin-link" href="../index.php">Back to sign in</a>
        </header>
        <section class="registration-panel" aria-labelledby="registration-title">
            <div class="registration-heading">
                <span class="registration-eyebrow">STUDENT PORTAL</span>
                <h1 id="registration-title">Create your student account</h1>
                <p>Enter your details to get started with your defense workspace.</p>
            </div>
            <form class="registration-form" action="register.php" method="post">
                <?php if ($error !== ''): ?>
                    <p class="registration-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?= csrf_field() ?>
                <label for="full_name">Full name
                    <input type="text" name="full_name" id="full_name" maxlength="150" autocomplete="name" value="<?= htmlspecialchars($old['full_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                    <?php if (isset($field_errors['full_name'])): ?><small class="registration-field-error"><?= htmlspecialchars($field_errors['full_name'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <label for="email">Email
                    <input type="email" name="email" id="email" maxlength="100" autocomplete="email" value="<?= htmlspecialchars($old['email'], ENT_QUOTES, 'UTF-8') ?>" required>
                    <?php if (isset($field_errors['email'])): ?><small class="registration-field-error"><?= htmlspecialchars($field_errors['email'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <label for="username">Username
                    <input type="text" name="username" id="username" maxlength="50" autocomplete="username" value="<?= htmlspecialchars($old['username'], ENT_QUOTES, 'UTF-8') ?>" required>
                    <?php if (isset($field_errors['username'])): ?><small class="registration-field-error"><?= htmlspecialchars($field_errors['username'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <label for="password">Password
                    <input type="password" name="password" id="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                    <small class="registration-help">Use 8 to 72 characters.</small>
                    <?php if (isset($field_errors['password'])): ?><small class="registration-field-error"><?= htmlspecialchars($field_errors['password'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <label for="confirm_password">Confirm password
                    <input type="password" name="confirm_password" id="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required <?= isset($field_errors['confirm_password']) ? 'aria-invalid="true" aria-describedby="confirm-password-error"' : '' ?>>
                    <?php if (isset($field_errors['confirm_password'])): ?><small id="confirm-password-error" class="registration-field-error"><?= htmlspecialchars($field_errors['confirm_password'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <label for="program">Program
                    <select name="program" id="program" required>
                        <option value="" disabled <?= $old['program'] === '' ? 'selected' : '' ?>>Select your program</option>
                        <option value="BS Computer Science" <?= $old['program'] === 'BS Computer Science' ? 'selected' : '' ?>>Computer Science</option>
                        <option value="BS Information Technology" <?= $old['program'] === 'BS Information Technology' ? 'selected' : '' ?>>Information Technology</option>
                    </select>
                    <?php if (isset($field_errors['program'])): ?><small class="registration-field-error"><?= htmlspecialchars($field_errors['program'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <label for="year_level">Year level
                    <select name="year_level" id="year_level" required>
                        <option value="" disabled <?= $old['year_level'] === '' ? 'selected' : '' ?>>Select your year level</option>
                        <option value="3rd Year" <?= $old['year_level'] === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                        <option value="4th Year" <?= $old['year_level'] === '4th Year' ? 'selected' : '' ?>>4th Year</option>
                    </select>
                    <?php if (isset($field_errors['year_level'])): ?><small class="registration-field-error"><?= htmlspecialchars($field_errors['year_level'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <label for="adviser_id">Student adviser
                    <select name="adviser_id" id="adviser_id" required <?= $adviser_ids ? '' : 'disabled' ?>>
                        <option value="" disabled <?= $old['adviser_id'] === 0 ? 'selected' : '' ?>>Select an active adviser</option>
                        <?php foreach ($advisers as $adviser): ?>
                            <option value="<?= (int)$adviser['user_id'] ?>" <?= $old['adviser_id'] === (int)$adviser['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($adviser['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($field_errors['adviser_id'])): ?><small class="registration-field-error"><?= htmlspecialchars($field_errors['adviser_id'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <input type="submit" value="Create student account" <?= $adviser_ids ? '' : 'disabled' ?>>
                <p class="registration-note">Student accounts only. Adviser and panel accounts are managed by an administrator.</p>
            </form>
        </section>
    </main>
</body>

</html>
