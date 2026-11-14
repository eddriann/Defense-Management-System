<?php
include('../config/db.php');
require_once('../includes/availability.php');
require_once('../includes/roles.php');
require_once('../includes/csrf.php');
include('../includes/auth_check.php');
auth_check(['Student']);

$user_id = (int)$_SESSION['user_id'];

$project_id = (int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0);

$stmt = mysqli_prepare(
    $conn,
    "SELECT p.project_id, p.title
     FROM projects p
     INNER JOIN project_members pm ON pm.project_id = p.project_id AND pm.role_in_group = 'Leader'
     INNER JOIN students s ON s.student_id = pm.student_id
     WHERE p.project_id = ? AND s.user_id = ?"
);
mysqli_stmt_bind_param($stmt, 'ii', $project_id, $user_id);
mysqli_stmt_execute($stmt);
$project = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$project) {
    $member_check = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM project_members pm
         JOIN students s ON s.student_id = pm.student_id
         WHERE pm.project_id = ? AND s.user_id = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($member_check, 'ii', $project_id, $user_id);
    mysqli_stmt_execute($member_check);
    $is_member = mysqli_fetch_assoc(mysqli_stmt_get_result($member_check));
    mysqli_stmt_close($member_check);
    $_SESSION['student_flash_error'] = $is_member
        ? 'You cannot request a defense because you are not the project leader. Ask the project leader to submit the request.'
        : 'The selected project is unavailable for a defense request.';
    header('Location: ../dashboard/student_dashboard.php');
    exit();
}

$latest_manuscript_stmt = mysqli_prepare(
    $conn,
    'SELECT status
     FROM manuscripts
     WHERE project_id = ?
     ORDER BY submitted_at DESC, manuscript_id DESC
     LIMIT 1'
);
mysqli_stmt_bind_param($latest_manuscript_stmt, 'i', $project_id);
mysqli_stmt_execute($latest_manuscript_stmt);
$latest_manuscript = mysqli_fetch_assoc(mysqli_stmt_get_result($latest_manuscript_stmt));
mysqli_stmt_close($latest_manuscript_stmt);
if (($latest_manuscript['status'] ?? '') !== 'Approved') {
    $_SESSION['student_flash_error'] = 'Your adviser must approve the latest manuscript before you can request a defense.';
    header('Location: ../dashboard/student_dashboard.php');
    exit();
}

$completed_final = mysqli_prepare(
    $conn,
    "SELECT 1
     FROM defense_schedules
     WHERE project_id = ? AND defense_type = 'Final'
       AND status = 'Completed' AND final_result = 'Defended'
     LIMIT 1"
);
mysqli_stmt_bind_param($completed_final, 'i', $project_id);
mysqli_stmt_execute($completed_final);
$has_completed_final = mysqli_fetch_assoc(mysqli_stmt_get_result($completed_final));
mysqli_stmt_close($completed_final);
if ($has_completed_final) {
    $_SESSION['student_flash_error'] = 'Your project has completed its Final Defense and cannot request another defense.';
    header('Location: ../dashboard/student_dashboard.php');
    exit();
}

$defended_proposal_stmt = mysqli_prepare(
    $conn,
    "SELECT 1
     FROM defense_schedules
     WHERE project_id = ? AND defense_type = 'Proposal'
       AND status = 'Completed' AND final_result = 'Defended'
     LIMIT 1"
);
mysqli_stmt_bind_param($defended_proposal_stmt, 'i', $project_id);
mysqli_stmt_execute($defended_proposal_stmt);
$has_defended_proposal = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($defended_proposal_stmt));
mysqli_stmt_close($defended_proposal_stmt);

$existing_defense = mysqli_prepare(
    $conn,
    "SELECT 1
     FROM defense_requests
     WHERE project_id = ? AND status IN ('Pending', 'Endorsed')
     UNION ALL
     SELECT 1
     FROM defense_schedules
     WHERE project_id = ? AND status IN ('Scheduled', 'Ongoing')
     LIMIT 1"
);
mysqli_stmt_bind_param($existing_defense, 'ii', $project_id, $project_id);
mysqli_stmt_execute($existing_defense);
$has_existing_defense = mysqli_fetch_assoc(mysqli_stmt_get_result($existing_defense));
mysqli_stmt_close($existing_defense);
if ($has_existing_defense) {
    $_SESSION['student_flash_error'] = 'Your project already has a defense request or scheduled defense. Check your dashboard for its status.';
    header('Location: ../dashboard/student_dashboard.php');
    exit();
}

// Requests may be made for any day in the next two weeks with room availability.
$earliestStart = (new DateTime('now'))->format('Y-m-d');
$latestEnd = (new DateTime($earliestStart))->modify('+2 week')->format('Y-m-d');

$rooms = getActiveRooms($conn);
$booked = getBookedRooms($conn, $earliestStart, $latestEnd);
$allSlots = mysqli_fetch_all(
    mysqli_query($conn, "SELECT slot_id, start_time, end_time FROM time_slots ORDER BY start_time"),
    MYSQLI_ASSOC
);
$availableSlotsForDate = function (string $date) use ($allSlots, $rooms, $booked): array {
    return openSlots($allSlots, $rooms, $booked, $date);
};

$availableDates = [];
for (
    $date = new DateTimeImmutable($earliestStart), $end = new DateTimeImmutable($latestEnd);
    $date <= $end;
    $date = $date->modify('+1 day')
) {
    $date_string = $date->format('Y-m-d');
    if (count($availableSlotsForDate($date_string)) > 0) {
        $availableDates[] = $date_string;
    }
}

// STEP 1: date (must be one of the offered dates)
$requestedDate = $_POST['start_date'] ?? $_GET['start_date'] ?? '';
$selectedDate = in_array($requestedDate, $availableDates, true) ? $requestedDate : '';
$dateError = ($requestedDate !== '' && $requestedDate !== 'none' && $selectedDate === '')
    ? 'That date is fully booked. Please pick another one.'
    : '';

// STEP 2: time slot (must still be available on that date)
$AvailableTime = $selectedDate !== '' ? $availableSlotsForDate($selectedDate) : [];
$requestedSlot = (int)($_POST['time_slot'] ?? $_GET['time_slot'] ?? 0);
$selectedSlot = in_array($requestedSlot, array_map('intval', array_column($AvailableTime, 'slot_id')), true)
    ? $requestedSlot
    : 0;
$slotError = ($requestedSlot !== 0 && $selectedDate !== '' && $selectedSlot === 0)
    ? 'That time slot is no longer available. Please choose another.'
    : '';

// Save the request
$error = '';
if (isset($_POST['submit'])) {
    csrf_validate_request();
    $requested_defense_type = $_POST['defense_type'] ?? '';
    $defense_type = $has_defended_proposal ? 'Final' : $requested_defense_type;

    if (!in_array($defense_type, ['Proposal', 'Final'], true)) {
        $error = 'Please choose a valid defense type.';
    } elseif ($selectedDate === '') {
        $error = 'Please choose an available date.';
    } elseif ($selectedSlot === 0) {
        $error = 'Please choose an available time slot.';
    } else {
        try {
            mysqli_begin_transaction($conn);
            $project_lock = mysqli_prepare($conn, "SELECT project_id FROM projects WHERE project_id = ? FOR UPDATE");
            mysqli_stmt_bind_param($project_lock, 'i', $project_id);
            mysqli_stmt_execute($project_lock);
            $locked_project = mysqli_fetch_assoc(mysqli_stmt_get_result($project_lock));
            mysqli_stmt_close($project_lock);
            if (!$locked_project) {
                throw new RuntimeException('The project is no longer available.');
            }

            $defended_proposal_lock = mysqli_prepare(
                $conn,
                "SELECT schedule_id
                 FROM defense_schedules
                 WHERE project_id = ? AND defense_type = 'Proposal'
                   AND status = 'Completed' AND final_result = 'Defended'
                 LIMIT 1
                 FOR UPDATE"
            );
            mysqli_stmt_bind_param($defended_proposal_lock, 'i', $project_id);
            mysqli_stmt_execute($defended_proposal_lock);
            $current_defended_proposal = mysqli_fetch_assoc(mysqli_stmt_get_result($defended_proposal_lock));
            mysqli_stmt_close($defended_proposal_lock);
            if ($current_defended_proposal) {
                $defense_type = 'Final';
            }

            $manuscript_lock = mysqli_prepare(
                $conn,
                'SELECT status
                 FROM manuscripts
                 WHERE project_id = ?
                 ORDER BY submitted_at DESC, manuscript_id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            mysqli_stmt_bind_param($manuscript_lock, 'i', $project_id);
            mysqli_stmt_execute($manuscript_lock);
            $current_manuscript = mysqli_fetch_assoc(mysqli_stmt_get_result($manuscript_lock));
            mysqli_stmt_close($manuscript_lock);
            if (($current_manuscript['status'] ?? '') !== 'Approved') {
                mysqli_rollback($conn);
                $_SESSION['student_flash_error'] = 'Your adviser must approve the latest manuscript before you can request a defense.';
                header('Location: ../dashboard/student_dashboard.php');
                exit();
            }

            $slot_lock = mysqli_prepare($conn, "SELECT slot_id FROM time_slots WHERE slot_id = ? FOR UPDATE");
            mysqli_stmt_bind_param($slot_lock, 'i', $selectedSlot);
            mysqli_stmt_execute($slot_lock);
            $locked_slot = mysqli_fetch_assoc(mysqli_stmt_get_result($slot_lock));
            mysqli_stmt_close($slot_lock);
            $current_rooms = getActiveRooms($conn);
            $current_bookings = getBookedRooms($conn, $selectedDate, $selectedDate);
            if (!$locked_slot) {
                mysqli_rollback($conn);
                $error = 'That time slot is no longer available. Please choose another.';
            } elseif (count(freeRooms($current_rooms, $current_bookings, $selectedDate, $selectedSlot)) === 0) {
                mysqli_rollback($conn);
                $error = 'No room is available for that defense slot. Please choose another.';
            } else {
                $check = mysqli_prepare(
                    $conn,
                    "SELECT 1 FROM defense_requests
                 WHERE project_id = ? AND status IN ('Pending', 'Endorsed')
                 UNION ALL
                 SELECT 1 FROM defense_schedules
                 WHERE project_id = ? AND status IN ('Scheduled', 'Ongoing')
                 LIMIT 1"
                );
                mysqli_stmt_bind_param($check, 'ii', $project_id, $project_id);
                mysqli_stmt_execute($check);
                $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
                mysqli_stmt_close($check);

                if ($existing) {
                    mysqli_rollback($conn);
                    $_SESSION['student_flash_error'] = 'Your project already has a defense request or scheduled defense. Check your dashboard for its status.';
                    header('Location: ../dashboard/student_dashboard.php');
                    exit();
                } else {
                    $request = mysqli_prepare(
                        $conn,
                        "INSERT INTO defense_requests (project_id, defense_type, preferred_date, slot_id, requested_by)
                     VALUES (?, ?, ?, ?, ?)"
                    );
                    mysqli_stmt_bind_param($request, 'issii', $project_id, $defense_type, $selectedDate, $selectedSlot, $user_id);
                    mysqli_stmt_execute($request);
                    mysqli_stmt_close($request);
                    mysqli_commit($conn);
                    header('Location: ../projects/list.php');
                    exit;
                }
            }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Could not save the request. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defense Request</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261112-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Defense Request</h1>
    <p>Project: <strong><?= htmlspecialchars($project['title']) ?></strong></p>

    <?php foreach ([$dateError, $slotError, $error] as $msg): ?>
        <?php if ($msg !== ''): ?>
            <p style="color: red;"><?= htmlspecialchars($msg) ?></p>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- Step 1: Select Date -->
    <?php if (empty($availableDates)): ?>
        <p><em>No defense dates are open in the next two weeks.</em></p>
    <?php else: ?>
        <form action="" method="get">
            <input type="hidden" name="project_id" value="<?= $project_id ?>">
            <label for="start_date">
                <strong>Step 1:</strong> Choose Available Defense Date:
                <select name="start_date" id="start_date">
                    <option value="none">-- Select a Date --</option>
                    <?php foreach ($availableDates as $date): ?>
                        <option value="<?= htmlspecialchars($date) ?>" <?= ($selectedDate === $date) ? 'selected' : '' ?>>
                            <?= date('F j, Y', strtotime($date)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Check Available Time Slots</button>
        </form>
    <?php endif; ?>

    <!-- Step 2: Select an available time slot -->
    <?php if ($selectedDate !== ''): ?>
        <br>
        <form action="" method="get">
            <input type="hidden" name="project_id" value="<?= $project_id ?>">
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($selectedDate) ?>">
            <label for="time_slot">
                <strong>Step 2:</strong>
                Select Available Time Slot for <?= date('F j, Y', strtotime($selectedDate)) ?>:
                <select name="time_slot" id="time_slot" required>
                    <option value="">-- Select Time Slot --</option>
                    <?php foreach ($AvailableTime as $timeslot): ?>
                        <option value="<?= (int)$timeslot['slot_id'] ?>" <?= ($selectedSlot === (int)$timeslot['slot_id']) ? 'selected' : '' ?>>
                            <?= date('g:i A', strtotime($timeslot['start_time'])) ?> - <?= date('g:i A', strtotime($timeslot['end_time'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Check Available Slot</button>
        </form>
    <?php elseif (!empty($availableDates)): ?>
        <p><em>Please pick a date above and click "Check Available Time Slots" to view open time slots.</em></p>
    <?php endif; ?>

    <!-- Step 3: Select defense type and submit -->
    <?php if ($selectedDate !== '' && $selectedSlot !== 0): ?>
        <br>
        <form action="" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= $project_id ?>">
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($selectedDate) ?>">
            <input type="hidden" name="time_slot" value="<?= $selectedSlot ?>">
            <strong>Step 3:</strong>
            <?php if ($has_defended_proposal): ?>
                <p><strong>Defense type:</strong> Final Defense (required after a defended Proposal Defense).</p>
                <input type="hidden" name="defense_type" value="Final">
            <?php else: ?>
                <label for="defense_type">Defense type:
                    <select name="defense_type" id="defense_type" required>
                        <option value="Proposal">Proposal Defense</option>
                        <option value="Final">Final Defense</option>
                    </select>
                </label>
            <?php endif; ?>
            <input type="submit" value="Submit Request" name="submit">
        </form>
    <?php endif; ?>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>