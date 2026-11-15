<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/panel_assignment.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/bind_params.php';
require_once __DIR__ . '/../includes/refresh_defense_status.php';

auth_check(['Admin', 'Adviser']);
refresh_defense_day_status($conn);
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role_name'] ?? '';
$message = '';
$error = '';
$search = request_filter_string($_GET, 'search');
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$allowed_review_statuses = $role === 'Admin' ? ['Pending', 'Endorsed'] : ['Pending'];
if (!in_array($status_filter, array_merge([''], $allowed_review_statuses), true)) {
    $status_filter = '';
}
if (!in_array($type_filter, ['', 'Proposal', 'Final'], true)) {
    $type_filter = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $request_id = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');
    if ($request_id < 1 || strlen($remarks) > 500) {
        $error = 'Choose a valid request and keep remarks within 500 characters.';
    } elseif ($role === 'Adviser' && $action !== 'Endorsed') {
        http_response_code(403);
        exit('Advisers may endorse requests but cannot approve or reject them.');
    } elseif ($role === 'Admin' && !in_array($action, ['Approved', 'Rejected'], true)) {
        $error = 'Choose an administrator review decision.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $request_sql =
                "SELECT dr.request_id, dr.project_id, dr.defense_type, dr.preferred_date,
                        dr.slot_id, dr.status, p.adviser_id, ts.end_time
                 FROM defense_requests dr
                 JOIN projects p ON p.project_id = dr.project_id
                 JOIN time_slots ts ON ts.slot_id = dr.slot_id
                 WHERE dr.request_id = ? " .
                ($role === 'Adviser' ? "AND p.adviser_id = ? " : "") .
                "FOR UPDATE";
            $request_stmt = mysqli_prepare($conn, $request_sql);
            if ($role === 'Adviser') {
                mysqli_stmt_bind_param($request_stmt, 'ii', $request_id, $user_id);
            } else {
                mysqli_stmt_bind_param($request_stmt, 'i', $request_id);
            }
            mysqli_stmt_execute($request_stmt);
            $request = mysqli_fetch_assoc(mysqli_stmt_get_result($request_stmt));
            mysqli_stmt_close($request_stmt);
            if (!$request) {
                throw new RuntimeException('This request is unavailable or is not assigned to you.');
            }

            if ($role === 'Adviser') {
                if ($request['status'] !== 'Pending') {
                    throw new RuntimeException('Only pending requests can be endorsed.');
                }
                if (
                    $request['preferred_date'] < date('Y-m-d') ||
                    ($request['preferred_date'] === date('Y-m-d') && $request['end_time'] <= date('H:i:s'))
                ) {
                    throw new RuntimeException('This requested date and time has already passed. Ask the student to submit a new request before endorsing.');
                }
                $update = mysqli_prepare(
                    $conn,
                    "UPDATE defense_requests
                     SET status = 'Endorsed', endorsed_by = ?, endorsed_at = NOW(), remarks = ?
                     WHERE request_id = ? AND status = 'Pending'"
                );
                mysqli_stmt_bind_param($update, 'isi', $user_id, $remarks, $request_id);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);
                $message = 'Request endorsed and sent to the administrator for review.';
            } elseif ($action === 'Rejected') {
                if (!in_array($request['status'], ['Pending', 'Endorsed'], true)) {
                    throw new RuntimeException('Only pending or endorsed requests can be reviewed.');
                }
                $update = mysqli_prepare(
                    $conn,
                    "UPDATE defense_requests
                     SET status = 'Rejected', reviewed_by = ?, remarks = ?, reviewed_at = NOW()
                     WHERE request_id = ? AND status IN ('Pending', 'Endorsed')"
                );
                mysqli_stmt_bind_param($update, 'isi', $user_id, $remarks, $request_id);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);
                $message = 'Defense request rejected.';
            } else {
                if ($request['status'] !== 'Endorsed') {
                    throw new RuntimeException('The adviser must endorse this request before administrator approval.');
                }
                if ($request['preferred_date'] < date('Y-m-d')) {
                    throw new RuntimeException('The requested date has passed. Reject the request and ask the student to submit a new date.');
                }
                if ($request['preferred_date'] === date('Y-m-d') && $request['end_time'] <= date('H:i:s')) {
                    throw new RuntimeException('The requested time slot has already ended. Reject the request and ask the student to submit a new date.');
                }

                $conflict = mysqli_prepare(
                    $conn,
                    "SELECT 1
                     FROM defense_schedules ds
                     JOIN projects old_project ON old_project.project_id = ds.project_id
                     WHERE ds.scheduled_date = ? AND ds.slot_id = ?
                       AND ds.status <> 'Cancelled'
                       AND (
                           ds.project_id = ?
                           OR old_project.adviser_id = ?
                           OR EXISTS (
                               SELECT 1
                               FROM project_members current_pm
                               JOIN project_members old_pm ON old_pm.student_id = current_pm.student_id
                               WHERE current_pm.project_id = ?
                                 AND old_pm.project_id = old_project.project_id
                           )
                       )
                     LIMIT 1"
                );
                mysqli_stmt_bind_param(
                    $conflict,
                    'siiii',
                    $request['preferred_date'],
                    $request['slot_id'],
                    $request['project_id'],
                    $request['adviser_id'],
                    $request['project_id']
                );
                mysqli_stmt_execute($conflict);
                $has_conflict = mysqli_fetch_assoc(mysqli_stmt_get_result($conflict));
                mysqli_stmt_close($conflict);
                if ($has_conflict) {
                    throw new RuntimeException('The students or adviser already have a defense in the requested time slot.');
                }

                $room = getAvailableRoomForSlot(
                    $conn,
                    $request['preferred_date'],
                    (int)$request['slot_id'],
                    true
                );
                if (!$room) {
                    throw new RuntimeException('No room has an open time slot and remaining daily capacity on the requested date.');
                }

                $schedule_status = 'Scheduled';
                $create = mysqli_prepare(
                    $conn,
                    "INSERT INTO defense_schedules
                     (request_id, project_id, defense_type, scheduled_date, slot_id, room_id, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param(
                    $create,
                    'iissiisi',
                    $request_id,
                    $request['project_id'],
                    $request['defense_type'],
                    $request['preferred_date'],
                    $request['slot_id'],
                    $room['room_id'],
                    $schedule_status,
                    $user_id
                );
                mysqli_stmt_execute($create);
                $schedule_id = mysqli_insert_id($conn);
                mysqli_stmt_close($create);

                automatically_assign_panel($conn, $schedule_id);

                $update = mysqli_prepare(
                    $conn,
                    "UPDATE defense_requests
                     SET status = 'Approved', reviewed_by = ?, remarks = ?, reviewed_at = NOW()
                     WHERE request_id = ? AND status = 'Endorsed'"
                );
                mysqli_stmt_bind_param($update, 'isi', $user_id, $remarks, $request_id);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);

                $project_status = 'For Defense';
                $project_update = mysqli_prepare(
                    $conn,
                    "UPDATE projects SET status = ? WHERE project_id = ? AND status <> 'Dropped'"
                );
                mysqli_stmt_bind_param($project_update, 'si', $project_status, $request['project_id']);
                mysqli_stmt_execute($project_update);
                mysqli_stmt_close($project_update);
                refresh_defense_day_status($conn);
                $message = 'Request approved, schedule created, and panel assigned automatically.';
            }

            mysqli_commit($conn);
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            $error = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Could not process this defense request. Check that a room and three eligible panel members are available.';
            if (!$exception instanceof RuntimeException) {
                error_log('Defense request review failed: ' . $exception->getMessage());
            }
        }
    }
}

$query =
    "SELECT dr.request_id, dr.project_id, dr.slot_id, p.title AS project_title, p.project_type,
            dr.defense_type, dr.preferred_date, ts.start_time,
            ts.end_time, dr.status, dr.remarks, requester.full_name AS requester,
            endorser.full_name AS endorser
     FROM defense_requests dr
     JOIN projects p ON p.project_id = dr.project_id
     JOIN time_slots ts ON ts.slot_id = dr.slot_id
     JOIN users requester ON requester.user_id = dr.requested_by
     LEFT JOIN users endorser ON endorser.user_id = dr.endorsed_by
     WHERE " . ($role === 'Adviser' ? "p.adviser_id = ? AND dr.status = 'Pending'" : "dr.status IN ('Pending', 'Endorsed')") . "
       AND (? = '' OR p.title LIKE CONCAT('%', ?, '%') OR requester.full_name LIKE CONCAT('%', ?, '%'))
       AND (? = '' OR dr.status = ?)
       AND (? = '' OR dr.defense_type = ?)
     ORDER BY dr.created_at DESC";
$stmt = mysqli_prepare($conn, $query);
if ($role === 'Adviser') {
    bind_stmt_params(
        $stmt,
        'isssssss',
        [$user_id, $search, $search, $search, $status_filter, $status_filter, $type_filter, $type_filter]
    );
} else {
    bind_stmt_params($stmt, 'sssssss', [$search, $search, $search, $status_filter, $status_filter, $type_filter, $type_filter]);
}
mysqli_stmt_execute($stmt);
$requests = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
$available_rooms = [];
foreach ($requests as $request) {
    $available_rooms[(int)$request['request_id']] = getAvailableRoomForSlot(
        $conn,
        $request['preferred_date'],
        (int)$request['slot_id']
    );
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $role === 'Admin' ? 'Review Defense Requests' : 'Endorse Defense Requests' ?></title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-3">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-3">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1><?= $role === 'Admin' ? 'Administrator Request Review' : 'Adviser Endorsements' ?></h1>
    <p><?= $role === 'Admin'
            ? 'Review requests endorsed by advisers. Approval creates the schedule and assigns an available panel.'
            : 'You can endorse a request when it is Pending and its project is assigned to you as adviser. Endorsements send the request to Admin for review; only Admin can approve, reject, and schedule it. Requests for dates that have passed cannot be endorsed.' ?></p>
    <form method="get" class="filter-form compact-filter">
        <label for="search">Project or requester</label><input type="search" name="search" id="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <label for="type">Defense type</label><select name="type" id="type"><option value="">All types</option><?php foreach (['Proposal', 'Final'] as $type): ?><option value="<?= $type ?>" <?= $type_filter === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select>
        <label for="status">Request status</label><select name="status" id="status"><option value="">All statuses</option><?php foreach ($allowed_review_statuses as $status): ?><option value="<?= $status ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select>
        <button type="submit">Filter</button><a class="button-secondary" href="review.php">Clear</a>
    </form>
    <?php if ($message !== ''): ?><p class="dashboard-alert" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <div class="table-wrap">
        <table class="defense-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Defense</th>
                    <th>Requested By</th>
                    <th>Preferred Time</th>
                    <th>Available Room</th>
                    <th>Status</th>
                    <th>Remarks</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$requests): ?>
                    <tr>
                        <td class="empty-state" colspan="8">No requests are waiting for your action.</td>
                    </tr>
                    <?php else: foreach ($requests as $request): ?>
                        <tr>
                            <td><?= htmlspecialchars($request['project_title'], ENT_QUOTES, 'UTF-8') ?><span class="course-tag"><?= htmlspecialchars($request['project_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars($request['defense_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($request['requester'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(date('M j, Y', strtotime($request['preferred_date'])), ENT_QUOTES, 'UTF-8') ?><span class="course-tag"><?= htmlspecialchars(date('g:i A', strtotime($request['start_time'])) . ' - ' . date('g:i A', strtotime($request['end_time'])), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?php $room = $available_rooms[(int)$request['request_id']] ?? null; ?>
                                <?php if ($room): ?>
                                    <?= htmlspecialchars($room['room_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <span class="course-tag"><?= htmlspecialchars($room['location'] ?: 'Location not specified', ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <span class="course-tag">No room available</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="status-badge <?= $request['status'] === 'Endorsed' ? 'status-completed' : 'status-pending' ?>"><?= htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8') ?></span><?php if ($request['endorser']): ?><span class="course-tag">By <?= htmlspecialchars($request['endorser'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></td>
                            <td><?= htmlspecialchars($request['remarks'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($role === 'Adviser' && $request['status'] === 'Pending'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
                                        <input type="text" name="remarks" maxlength="500" placeholder="Optional note">
                                        <button type="submit" name="action" value="Endorsed">Endorse</button>
                                    </form>
                                <?php elseif ($role === 'Admin' && $request['status'] === 'Endorsed'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
                                        <input type="text" name="remarks" maxlength="500" placeholder="Optional decision note">
                                        <?php if ($room): ?>
                                            <button type="submit" name="action" value="Approved">Approve &amp; schedule</button>
                                        <?php else: ?>
                                            <span class="course-tag">Cannot approve: no room slot or daily capacity remains.</span>
                                        <?php endif; ?>
                                        <button type="submit" name="action" value="Rejected" class="button-secondary">Reject</button>
                                    </form>
                                <?php else: ?>
                                    <span class="course-tag">Waiting for adviser endorsement</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                <?php endforeach;
                endif; ?>
            </tbody>
        </table>
    </div>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>