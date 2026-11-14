<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
auth_check(['Admin']);

$room_id = (int)($_GET['id'] ?? $_POST['room_id'] ?? 0);
$stmt = mysqli_prepare(
    $conn,
    'SELECT room_id, room_name, location, status FROM rooms WHERE room_id = ?'
);
mysqli_stmt_bind_param($stmt, 'i', $room_id);
mysqli_stmt_execute($stmt);
$room = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$room) {
    http_response_code(404);
    exit('Room not found.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $room_name = trim($_POST['room_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $status = $_POST['status'] ?? '';

    if ($room_name === '' || strlen($room_name) > 100 || strlen($location) > 150 ||
        !in_array($status, ['Active', 'Under Maintenance'], true)) {
        $error = 'Enter a room name up to 100 characters, a location up to 150 characters, and a valid status.';
    } else {
        try {
            $update = mysqli_prepare(
                $conn,
                'UPDATE rooms SET room_name = ?, location = ?, status = ? WHERE room_id = ?'
            );
            mysqli_stmt_bind_param($update, 'sssi', $room_name, $location, $status, $room_id);
            mysqli_stmt_execute($update);
            mysqli_stmt_close($update);
            header('Location: list.php');
            exit();
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) {
                $error = 'A room with that name already exists.';
            } else {
                throw $exception;
            }
        }
    }

    $room['room_name'] = $room_name;
    $room['location'] = $location;
    $room['status'] = $status;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Room</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261112-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>
<body>
<?php require __DIR__ . '/../includes/module_page_start.php'; ?>
<h1>Edit Room</h1>
<?php if ($error !== ''): ?><p class="form-error-summary" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="room_id" value="<?= $room_id ?>">
    <label for="room_name">Room name
        <input type="text" id="room_name" name="room_name" maxlength="100" required value="<?= htmlspecialchars($room['room_name'], ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label for="location">Location
        <input type="text" id="location" name="location" maxlength="150" value="<?= htmlspecialchars($room['location'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label for="status">Availability
        <select id="status" name="status" required>
            <option value="Active" <?= $room['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
            <option value="Under Maintenance" <?= $room['status'] === 'Under Maintenance' ? 'selected' : '' ?>>Under Maintenance</option>
        </select>
    </label>
    <p>Daily defense capacity is fixed at 4 groups (one per time slot).</p>
    <button type="submit">Save Room</button>
    <a class="button-secondary" href="list.php">Cancel</a>
</form>
<?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>
</html>
