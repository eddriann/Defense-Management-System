<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/bind_params.php';

auth_check(['Admin']);
$user_id = (int)$_SESSION['user_id'];
$selected_room_id = (int)($_GET['room_id'] ?? 0);
$block_room_filter = max(0, (int)($_GET['block_room_id'] ?? 0));
$block_search = request_filter_string($_GET, 'block_search');
$block_from_date = request_filter_string($_GET, 'from');
$block_to_date = request_filter_string($_GET, 'to');
foreach (['block_from_date', 'block_to_date'] as $date_filter) {
    if ($$date_filter !== '' && !is_valid_filter_date($$date_filter)) {
        $$date_filter = '';
    }
}
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    if (isset($_POST['delete_id'])) {
        $unavailability_id = (int)$_POST['delete_id'];
        $delete = mysqli_prepare($conn, 'DELETE FROM room_unavailability WHERE unavailability_id = ?');
        mysqli_stmt_bind_param($delete, 'i', $unavailability_id);
        mysqli_stmt_execute($delete);
        $deleted = mysqli_stmt_affected_rows($delete) === 1;
        mysqli_stmt_close($delete);
        $message = $deleted
            ? 'Room availability block removed.'
            : 'That room availability block no longer exists.';
    } else {
        $room_id = (int)($_POST['room_id'] ?? 0);
        $date = trim($_POST['unavailable_date'] ?? '');
        $slot_raw = trim($_POST['slot_id'] ?? '');
        $slot_id = $slot_raw === '' ? null : (int)$slot_raw;
        $reason = trim($_POST['reason'] ?? '');
        $date_value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $date_valid = $date_value instanceof DateTimeImmutable && $date_value->format('Y-m-d') === $date;

        if ($room_id < 1 || !$date_valid || $date < date('Y-m-d') ||
            ($slot_raw !== '' && ($slot_id === null || $slot_id < 1)) ||
            strlen($reason) > 250) {
            $error = 'Choose a room and valid current or future date; the reason must be 250 characters or fewer.';
        } else {
            mysqli_begin_transaction($conn);
            try {
                $lock_room = mysqli_prepare($conn, 'SELECT room_id FROM rooms WHERE room_id = ? FOR UPDATE');
                mysqli_stmt_bind_param($lock_room, 'i', $room_id);
                mysqli_stmt_execute($lock_room);
                $room_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($lock_room));
                mysqli_stmt_close($lock_room);
                if (!$room_exists) {
                    throw new RuntimeException('The selected room no longer exists.');
                }

                if ($slot_id !== null) {
                    $slot_check = mysqli_prepare($conn, 'SELECT slot_id FROM time_slots WHERE slot_id = ?');
                    mysqli_stmt_bind_param($slot_check, 'i', $slot_id);
                    mysqli_stmt_execute($slot_check);
                    $slot_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($slot_check));
                    mysqli_stmt_close($slot_check);
                    if (!$slot_exists) {
                        throw new RuntimeException('Choose a valid time slot.');
                    }
                }

                $overlap = mysqli_prepare(
                    $conn,
                    'SELECT 1 FROM room_unavailability
                     WHERE room_id = ? AND unavailable_date = ?
                       AND (slot_id IS NULL OR ? IS NULL OR slot_id = ?)
                     UNION ALL
                     SELECT 1 FROM defense_schedules
                     WHERE room_id = ? AND scheduled_date = ? AND status <> \'Cancelled\'
                       AND (? IS NULL OR slot_id = ?)
                     LIMIT 1'
                );
                mysqli_stmt_bind_param($overlap, 'isiiisii', $room_id, $date, $slot_id, $slot_id, $room_id, $date, $slot_id, $slot_id);
                mysqli_stmt_execute($overlap);
                $has_overlap = mysqli_fetch_assoc(mysqli_stmt_get_result($overlap));
                mysqli_stmt_close($overlap);
                if ($has_overlap) {
                    throw new RuntimeException('This date or time already has a room block or scheduled defense that overlaps it.');
                }

                $insert = mysqli_prepare(
                    $conn,
                    'INSERT INTO room_unavailability (room_id, unavailable_date, slot_id, reason, created_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                mysqli_stmt_bind_param($insert, 'isisi', $room_id, $date, $slot_id, $reason, $user_id);
                mysqli_stmt_execute($insert);
                mysqli_stmt_close($insert);
                mysqli_commit($conn);
                $message = 'Room availability block added.';
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062
                        ? 'An overlapping room availability block already exists.'
                        : 'Could not save the room availability block.');
            }
        }
    }
}

$room_result = mysqli_query($conn, 'SELECT room_id, room_name FROM rooms ORDER BY room_name');
$rooms = mysqli_fetch_all($room_result, MYSQLI_ASSOC);
$slots = mysqli_query($conn, 'SELECT slot_id, slot_name, start_time, end_time FROM time_slots ORDER BY start_time');
$blocks_stmt = mysqli_prepare(
    $conn,
    "SELECT availability.unavailability_id, availability.unavailable_date, availability.slot_id,
            availability.reason, room.room_name, slot.slot_name, creator.full_name AS created_by_name
     FROM room_unavailability availability
     JOIN rooms room ON room.room_id = availability.room_id
     LEFT JOIN time_slots slot ON slot.slot_id = availability.slot_id
     JOIN users creator ON creator.user_id = availability.created_by
     WHERE (? = 0 OR room.room_id = ?)
       AND (? = '' OR availability.unavailable_date >= ?)
       AND (? = '' OR availability.unavailable_date <= ?)
       AND (? = '' OR room.room_name LIKE CONCAT('%', ?, '%')
            OR availability.reason LIKE CONCAT('%', ?, '%'))
     ORDER BY availability.unavailable_date, room.room_name, availability.slot_id"
);
bind_stmt_params(
    $blocks_stmt,
    'iisssssss',
    [
        $block_room_filter, $block_room_filter, $block_from_date, $block_from_date,
        $block_to_date, $block_to_date, $block_search, $block_search, $block_search,
    ]
);
mysqli_stmt_execute($blocks_stmt);
$blocks = mysqli_stmt_get_result($blocks_stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Availability</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261112-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>
<body>
<?php require __DIR__ . '/../includes/module_page_start.php'; ?>
<h1>Room Availability</h1>
<p>Block a room for a full date or one time slot. Blocked rooms will not be offered for defense requests or scheduling.</p>
<?php if ($message !== ''): ?><p class="form-success" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="form-error-summary" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
    <?= csrf_field() ?>
    <label for="room_id">Room
        <select id="room_id" name="room_id" required>
            <option value="">Choose a room</option>
            <?php foreach ($rooms as $room): ?>
                <option value="<?= (int)$room['room_id'] ?>" <?= $selected_room_id === (int)$room['room_id'] ? 'selected' : '' ?>><?= htmlspecialchars($room['room_name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label for="unavailable_date">Unavailable date
        <input id="unavailable_date" name="unavailable_date" type="date" min="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
    </label>
    <label for="slot_id">Time slot
        <select id="slot_id" name="slot_id">
            <option value="">All day</option>
            <?php while ($slot = mysqli_fetch_assoc($slots)): ?>
                <option value="<?= (int)$slot['slot_id'] ?>">
                    <?= htmlspecialchars($slot['slot_name'] . ' (' . date('g:i A', strtotime($slot['start_time'])) . '–' . date('g:i A', strtotime($slot['end_time'])) . ')', ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endwhile; ?>
        </select>
    </label>
    <label for="reason">Reason (optional)
        <input id="reason" name="reason" type="text" maxlength="250">
    </label>
    <button type="submit">Block availability</button>
</form>
<h2>Existing blocks</h2>
<form method="get" class="filter-form">
    <label for="block_room_id">Room</label>
    <select name="block_room_id" id="block_room_id">
        <option value="0">All rooms</option>
        <?php foreach ($rooms as $room): ?><option value="<?= (int)$room['room_id'] ?>" <?= $block_room_filter === (int)$room['room_id'] ? 'selected' : '' ?>><?= htmlspecialchars($room['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
    </select>
    <label for="block_search">Room or reason</label><input type="search" name="block_search" id="block_search" value="<?= htmlspecialchars($block_search, ENT_QUOTES, 'UTF-8') ?>">
    <label for="from">From</label><input type="date" name="from" id="from" value="<?= htmlspecialchars($block_from_date, ENT_QUOTES, 'UTF-8') ?>">
    <label for="to">To</label><input type="date" name="to" id="to" value="<?= htmlspecialchars($block_to_date, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">Filter</button><a class="button-secondary" href="availability.php">Clear</a>
</form>
<table>
    <thead><tr><th>Room</th><th>Date</th><th>Time</th><th>Reason</th><th>Added by</th><th>Action</th></tr></thead>
    <tbody>
    <?php $has_blocks = false; while ($block = mysqli_fetch_assoc($blocks)): $has_blocks = true; ?>
        <tr>
            <td><?= htmlspecialchars($block['room_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(date('M j, Y', strtotime($block['unavailable_date'])), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= $block['slot_id'] === null ? 'All day' : htmlspecialchars($block['slot_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($block['reason'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($block['created_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td>
                <form method="post">
                    <?= csrf_field() ?>
                    <button type="submit" class="button-secondary" name="delete_id" value="<?= (int)$block['unavailability_id'] ?>">Remove</button>
                </form>
            </td>
        </tr>
    <?php endwhile; if (!$has_blocks): ?><tr><td colspan="6">No room availability blocks.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>
</html>
