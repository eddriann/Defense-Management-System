<?php
include('../config/db.php');
require_once('../includes/csrf.php');
//admin only

include('../includes/auth_check.php');
$allowed_role = ['Admin'];
auth_check($allowed_role);

$error = '';
$field_errors = [];
$selected_roles = [];
$old = ['fullname' => '', 'email' => '', 'username' => '', 'course' => '', 'year_level' => '', 'adviser_id' => 0];

// Load active Adviser-role users for the student adviser selector.
$getAdviser = mysqli_prepare(
    $conn,
    "SELECT DISTINCT u.user_id, u.full_name
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.user_id
     WHERE ur.role_id = 2 AND u.status = 'active'
     ORDER BY u.full_name"
);
mysqli_stmt_execute($getAdviser);
$adviserResult = mysqli_stmt_get_result($getAdviser);
$option = mysqli_fetch_all($adviserResult, MYSQLI_ASSOC);
$adviserIds = array_map('intval', array_column($option, 'user_id'));

function insertUser(mysqli $conn, string $fullname, string $email, string $username, string $password, array $roles, string $course, string $year_level, int $adviser_id): void
{
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, email, username, password)
             VALUES (?, ?, ?, ?)"
        );
        $hash = password_hash($password, PASSWORD_DEFAULT);
        mysqli_stmt_bind_param($stmt, "ssss", $fullname, $email, $username, $hash);
        mysqli_stmt_execute($stmt);

        $user_id = mysqli_insert_id($conn);

        $insertRole = mysqli_prepare(
            $conn,
            "INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)"
        );
        mysqli_stmt_bind_param($insertRole, 'ii', $user_id, $role_id);
        foreach ($roles as $role_id) {
            mysqli_stmt_execute($insertRole);
        }

        // if the role is STUDENT insert in the students table
        if (in_array(4, $roles, true)) {
            $student_id = str_pad($user_id, 4, '0', STR_PAD_LEFT);
            $selected_course = ($course === "BS Computer Science") ? 'CS' : 'IT';
            $student_num = date('Y') . "-" . $selected_course . "-" . $student_id;

            $insertStudent = mysqli_prepare(
                $conn,
                "INSERT INTO students (user_id, student_number, program, year_level, adviser_id)
                 VALUES (?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($insertStudent, "isssi", $user_id, $student_num, $course, $year_level, $adviser_id);
            mysqli_stmt_execute($insertStudent);
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $old['fullname'] = $fullname = trim($_POST['fullname'] ?? '');
    $old['email'] = $email = trim($_POST['email'] ?? '');
    $old['username'] = $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $old['course'] = $course = $_POST['course'] ?? '';
    $old['year_level'] = $year_level = $_POST['year_level'] ?? '';
    $old['adviser_id'] = $adviser_id = (int)($_POST['adviserId'] ?? 0);

    // Selected roles: 2 = Adviser, 3 = Panel Member, 4 = Student
    $roles = array_map('intval', (array)($_POST['roles'] ?? []));
    $roles = array_values(array_intersect(array_unique($roles), [2, 3, 4]));
    $selected_roles = $roles;
    $isStudent = in_array(4, $roles, true);

    if ($fullname === '') {
        $field_errors['fullname'] = 'Enter the user’s full name.';
    }
    if ($email === '') {
        $field_errors['email'] = 'Enter an email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $field_errors['email'] = 'Enter a valid email address, such as name@example.com.';
    }
    if ($username === '') {
        $field_errors['username'] = 'Enter a username.';
    }
    if ($password === '') {
        $field_errors['password'] = 'Enter an initial password.';
    } elseif (strlen($password) < 8) {
        $field_errors['password'] = 'Use at least 8 characters for the initial password.';
    }
    if (empty($roles)) {
        $field_errors['roles'] = 'Select at least one role.';
    } elseif ($isStudent && count($roles) > 1) {
        $field_errors['roles'] = 'A Student account cannot also be an Adviser or Panel Member.';
    } elseif (
        $isStudent && (
            !in_array($course, ['BS Computer Science', 'BS Information Technology'], true)
            || !in_array($year_level, ['3rd Year', '4th Year'], true)
            || !in_array($adviser_id, $adviserIds, true)
        )
    ) {
        if (!in_array($course, ['BS Computer Science', 'BS Information Technology'], true)) {
            $field_errors['course'] = 'Select the student’s program.';
        }
        if (!in_array($year_level, ['3rd Year', '4th Year'], true)) {
            $field_errors['year_level'] = 'Select the student’s year level.';
        }
        if (!in_array($adviser_id, $adviserIds, true)) {
            $field_errors['adviser_id'] = 'Select an active adviser.';
        }
    }

    if (!$field_errors) {
        try {
            insertUser($conn, $fullname, $email, $username, $password, $roles, $course, $year_level, $adviser_id);
            header("Location: list.php?redirect=1");
            exit();
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                $duplicate = mysqli_prepare($conn, 'SELECT username, email FROM users WHERE username = ? OR email = ?');
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
                $error = 'Review the highlighted fields below.';
            } else {
                throw $e;
            }
        }
    } else {
        $error = 'Review the highlighted fields below.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-3">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-3">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <?php if ($error !== ''): ?><p class="form-error-summary" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

    <h1>User Information</h1>
    <form action="add.php" method="post" novalidate>
        <?= csrf_field() ?>
        <label for="fullname">
            Full Name:
            <input type="text" name="fullname" id="fullname" value="<?= htmlspecialchars($old['fullname'], ENT_QUOTES, 'UTF-8') ?>" required <?= isset($field_errors['fullname']) ? 'aria-invalid="true" aria-describedby="fullname-error"' : '' ?>>
            <?php if (isset($field_errors['fullname'])): ?><small id="fullname-error" class="field-error"><?= htmlspecialchars($field_errors['fullname'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        </label><br>
        <label for="email">
            Email:
            <input type="email" name="email" id="email" value="<?= htmlspecialchars($old['email'], ENT_QUOTES, 'UTF-8') ?>" required <?= isset($field_errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
            <?php if (isset($field_errors['email'])): ?><small id="email-error" class="field-error"><?= htmlspecialchars($field_errors['email'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        </label><br>
        <label for="username">
            Username:
            <input type="text" name="username" id="username" value="<?= htmlspecialchars($old['username'], ENT_QUOTES, 'UTF-8') ?>" required <?= isset($field_errors['username']) ? 'aria-invalid="true" aria-describedby="username-error"' : '' ?>>
            <?php if (isset($field_errors['username'])): ?><small id="username-error" class="field-error"><?= htmlspecialchars($field_errors['username'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        </label><br>
        <label for="password">
            Password:
            <input type="password" name="password" id="password" required <?= isset($field_errors['password']) ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>>
            <?php if (isset($field_errors['password'])): ?><small id="password-error" class="field-error"><?= htmlspecialchars($field_errors['password'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        </label><br>

        <div class="form-group">
            <p>Select Role(s):</p>
            <label><input type="checkbox" name="roles[]" value="2" class="staff-role" <?= in_array(2, $selected_roles, true) ? 'checked' : '' ?>> Adviser</label><br>
            <label><input type="checkbox" name="roles[]" value="3" class="staff-role" <?= in_array(3, $selected_roles, true) ? 'checked' : '' ?>> Panel Member</label>
            <small>(a user who is also an Adviser can sit on other groups' panels but cannot be Chairperson)</small><br>
            <label><input type="checkbox" name="roles[]" value="4" id="roleStudent" <?= in_array(4, $selected_roles, true) ? 'checked' : '' ?>> Student</label>
            <?php if (isset($field_errors['roles'])): ?><small class="field-error"><?= htmlspecialchars($field_errors['roles'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        </div>

        <!-- Only appear if role is student -->
        <div class="form-group" id="courseGroup" style="display: none;">
            <label for="course">Select Course: </label>
            <select name="course" id="course">
                <option value="" disabled <?= $old['course'] === '' ? 'selected' : '' ?>>--- Select Course ---</option>
                <option value="BS Computer Science" <?= $old['course'] === 'BS Computer Science' ? 'selected' : '' ?>>Computer Science</option>
                <option value="BS Information Technology" <?= $old['course'] === 'BS Information Technology' ? 'selected' : '' ?>>Information Technology</option>
            </select>
            <?php if (isset($field_errors['course'])): ?><small class="field-error"><?= htmlspecialchars($field_errors['course'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        </div>
        <div class="form-group" id="yearLevelGroup" style="display: none">
            <label for="year_level">Select Year Level:</label>
            <select id="year_level" name="year_level">
                <option value="" disabled <?= $old['year_level'] === '' ? 'selected' : '' ?>>-- Select Year Level --</option>
                <option value="3rd Year" <?= $old['year_level'] === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                <option value="4th Year" <?= $old['year_level'] === '4th Year' ? 'selected' : '' ?>>4th Year</option>
            </select><br>
            <?php if (isset($field_errors['year_level'])): ?><small class="field-error"><?= htmlspecialchars($field_errors['year_level'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        </div>

        <div class="form-group" id="adviserName" style="display: none;">
            <label for="adviser">Select Student Adviser:</label>
            <select name="adviserId" id="adviser">
                <option value="" disabled <?= $old['adviser_id'] === 0 ? 'selected' : '' ?>>--- Select Adviser ---</option>
                <?php foreach ($option as $name): ?>
                    <option value="<?= (int)$name['user_id'] ?>" <?= $old['adviser_id'] === (int)$name['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($name['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($field_errors['adviser_id'])): ?><small class="field-error"><?= htmlspecialchars($field_errors['adviser_id'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        </div>
        <input type="submit" value="Create User">
    </form>

    <!-- This selector replaces the shared script's old single-role control. -->
    <script>
        const student = document.getElementById('roleStudent');
        const staff = document.querySelectorAll('.staff-role');
        const studentFields = ['courseGroup', 'yearLevelGroup', 'adviserName'].map(id => document.getElementById(id));

        function sync() {
            const isStudent = student.checked;
            studentFields.forEach(el => el.style.display = isStudent ? 'block' : 'none');
            staff.forEach(c => {
                c.disabled = isStudent;
                if (isStudent) c.checked = false;
            });
            student.disabled = Array.from(staff).some(c => c.checked);
        }
        student.addEventListener('change', sync);
        staff.forEach(c => c.addEventListener('change', sync));
        sync();
        if (student.checked) studentFields.forEach(el => el.style.display = 'block');
    </script>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>
