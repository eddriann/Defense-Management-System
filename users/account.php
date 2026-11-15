<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
auth_check(['Student', 'Adviser', 'Panel Member']);

$user_id = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare($conn, 'SELECT email FROM users WHERE user_id = ?');
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$account) {
    throw new RuntimeException('The signed-in student account could not be found.');
}

$email = $account['email'];
$error = '';
$field_errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $email = trim($_POST['email'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $field_errors['email'] = 'Enter a valid email address.';
        $error = 'Please correct the highlighted field.';
    } elseif ($current_password === '') {
        $error = 'Enter your current password to confirm account changes.';
    } elseif ($new_password !== '' && strlen($new_password) < 8) {
        $field_errors['new_password'] = 'Use at least 8 characters for a new password.';
        $error = 'Please correct the highlighted field.';
    } elseif ($new_password !== $confirm_password) {
        $field_errors['confirm_password'] = 'The new password and confirmation do not match.';
        $error = 'Please correct the highlighted field.';
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT password FROM users WHERE user_id = ? AND status = ?');
        $active_status = 'active';
        mysqli_stmt_bind_param($stmt, 'is', $user_id, $active_status);
        mysqli_stmt_execute($stmt);
        $credentials = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        $stored_password = (string)($credentials['password'] ?? '');
        $password_valid = password_verify($current_password, $stored_password)
            || (preg_match('/^[a-f0-9]{32}$/i', $stored_password)
                && hash_equals(strtolower($stored_password), md5($current_password)));
        if (!$credentials || !$password_valid) {
            $error = 'Your current password is incorrect.';
        } else {
            try {
                if ($new_password !== '') {
                    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $update = mysqli_prepare($conn, 'UPDATE users SET email = ?, password = ? WHERE user_id = ?');
                    mysqli_stmt_bind_param($update, 'ssi', $email, $password_hash, $user_id);
                } else {
                    $update = mysqli_prepare($conn, 'UPDATE users SET email = ? WHERE user_id = ?');
                    mysqli_stmt_bind_param($update, 'si', $email, $user_id);
                }
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);
                $success = 'Your account details were updated.';
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() !== 1062) {
                    throw $exception;
                }
                $field_errors['email'] = 'This email address is already used by another account.';
                $error = 'Please correct the highlighted field.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-3">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-3">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>
<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Account Settings</h1>
    <?php if ($error !== ''): ?><p class="form-error-summary" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($success !== ''): ?><p class="form-success" role="status"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post" action="account.php" novalidate>
        <?= csrf_field() ?>
        <label for="email">Email address
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required <?= isset($field_errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
        </label>
        <?php if (isset($field_errors['email'])): ?><small id="email-error" class="field-error"><?= htmlspecialchars($field_errors['email'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        <label for="current_password">Current password
            <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        </label>
        <label for="new_password">New password <span class="sub-note">(leave blank to keep your current password)</span>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" <?= isset($field_errors['new_password']) ? 'aria-invalid="true" aria-describedby="new-password-error"' : '' ?>>
        </label>
        <?php if (isset($field_errors['new_password'])): ?><small id="new-password-error" class="field-error"><?= htmlspecialchars($field_errors['new_password'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        <label for="confirm_password">Confirm new password
            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" <?= isset($field_errors['confirm_password']) ? 'aria-invalid="true" aria-describedby="confirm-password-error"' : '' ?>>
        </label>
        <?php if (isset($field_errors['confirm_password'])): ?><small id="confirm-password-error" class="field-error"><?= htmlspecialchars($field_errors['confirm_password'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
        <button type="submit">Save account settings</button>
    </form>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>
</html>
