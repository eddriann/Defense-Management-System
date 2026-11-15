<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/csrf.php');
$allowed_role = ['Admin'];
auth_check($allowed_role);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $room_name = trim($_POST['room_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    if ($room_name === '') {
        $error = 'Room name is required.';
    } else {
        try {
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO rooms (room_name, location, capacity) VALUES (?, ?, 4)"
            );
            mysqli_stmt_bind_param($stmt, 'ss', $room_name, $location);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            header("Location: list.php");
            exit;
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) {
                $error = 'A room with that name already exists.';
            } else {
                throw $exception;
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
    <title>Rooms</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-2">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>
<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Add Room</h1>
    <?php if ($error !== ''): ?>
        <p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form action="add.php" method="post">
        <?= csrf_field() ?>
        <label>
            Room Name:
            <input type="text" name="room_name" required>
        </label><br>
        <label>
            Location (optional):
            <input type="text" name="location">
        </label><br>
        <p>Daily defense capacity: 4 groups (one per time slot).</p>
        <input type="submit" value="Add" name="submit">

    </form>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>