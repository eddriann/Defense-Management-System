<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/panel_assignment.php';
auth_check(['Admin']);

$schedule_id = (int)($_GET['schedule_id'] ?? $_POST['schedule_id'] ?? 0);
$stmt = mysqli_prepare(
    $conn,
    "SELECT ds.schedule_id, ds.scheduled_date, ds.slot_id, ds.room_id,
            p.title, p.adviser_id, ds.defense_type
     FROM defense_schedules ds
     JOIN projects p ON p.project_id = ds.project_id
     WHERE ds.schedule_id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $schedule_id);
mysqli_stmt_execute($stmt);
$schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$schedule) {
    http_response_code(404);
    exit('Schedule not found.');
}

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    try {
        mysqli_begin_transaction($conn);
        $lock = mysqli_prepare(
            $conn,
            "SELECT ds.schedule_id, ds.final_result,
                    EXISTS (
                        SELECT 1
                        FROM evaluations e
                        JOIN panel_assignments evaluated_pa
                          ON evaluated_pa.schedule_id = e.schedule_id
                         AND evaluated_pa.user_id = e.evaluator_id
                        WHERE e.schedule_id = ds.schedule_id
                          AND evaluated_pa.panel_role IN ('Chairperson', 'Panel Member')
                    ) AS has_evaluations
             FROM defense_schedules ds
             WHERE ds.schedule_id = ?
             FOR UPDATE"
        );
        mysqli_stmt_bind_param($lock, 'i', $schedule_id);
        mysqli_stmt_execute($lock);
        $locked_schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
        mysqli_stmt_close($lock);
        if (!$locked_schedule) {
            throw new RuntimeException('Schedule not found.');
        }
        if ($locked_schedule['final_result'] !== null || $locked_schedule['has_evaluations']) {
            throw new RuntimeException('Panel assignments cannot be changed after an evaluation has been submitted.');
        }

        if (isset($_POST['auto_assign'])) {
            $declined = mysqli_prepare(
                $conn,
                "DELETE pa
                 FROM panel_assignments pa
                 JOIN panel_assignment_responses response
                   ON response.schedule_id = pa.schedule_id
                  AND response.user_id = pa.user_id
                 WHERE pa.schedule_id = ? AND pa.panel_role <> 'Adviser'"
            );
            mysqli_stmt_bind_param($declined, 'i', $schedule_id);
            mysqli_stmt_execute($declined);
            $declined_removed = mysqli_stmt_affected_rows($declined);
            mysqli_stmt_close($declined);
            automatically_assign_panel($conn, $schedule_id);
            $message = $declined_removed > 0
                ? 'Declined assignments were removed and available replacements were assigned.'
                : 'The project adviser and available panel seats were assigned.';
        } elseif (isset($_POST['remove_id'])) {
            $assignment_id = (int)$_POST['remove_id'];
            $remove = mysqli_prepare(
                $conn,
                "DELETE FROM panel_assignments
                 WHERE panel_assignment_id = ? AND schedule_id = ? AND panel_role <> 'Adviser'"
            );
            mysqli_stmt_bind_param($remove, 'ii', $assignment_id, $schedule_id);
            mysqli_stmt_execute($remove);
            $removed = mysqli_stmt_affected_rows($remove);
            mysqli_stmt_close($remove);
            if ($removed !== 1) {
                throw new RuntimeException('The adviser assignment is required and cannot be removed.');
            }
            $message = 'Panel assignment removed. Use automatic assignment to fill the open seat.';
        } else {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $panel_role = $_POST['panel_role'] ?? '';
            if (!in_array($panel_role, ['Chairperson', 'Panel Member'], true)) {
                throw new RuntimeException('Choose Chairperson or Panel Member.');
            }

            $count_stmt = mysqli_prepare(
                $conn,
                'SELECT panel_role, COUNT(*) AS total FROM panel_assignments WHERE schedule_id = ? GROUP BY panel_role'
            );
            mysqli_stmt_bind_param($count_stmt, 'i', $schedule_id);
            mysqli_stmt_execute($count_stmt);
            $seat_counts = ['Chairperson' => 0, 'Panel Member' => 0];
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($count_stmt), MYSQLI_ASSOC) as $row) {
                if (isset($seat_counts[$row['panel_role']])) {
                    $seat_counts[$row['panel_role']] = (int)$row['total'];
                }
            }
            mysqli_stmt_close($count_stmt);
            if (($panel_role === 'Chairperson' && $seat_counts['Chairperson'] >= 1) ||
                ($panel_role === 'Panel Member' && $seat_counts['Panel Member'] >= 2)
            ) {
                throw new RuntimeException('That panel seat is already filled.');
            }

            $role_id = $panel_role === 'Chairperson' ? 3 : 0;
            $eligible_sql =
                "SELECT 1
                 FROM users u
                 JOIN user_roles ur ON ur.user_id = u.user_id
                 WHERE u.user_id = ? AND u.status = 'active'
                   AND u.user_id <> ?
                   AND " . ($role_id ? 'ur.role_id = ?' : 'ur.role_id IN (2, 3)') . "
                 LIMIT 1";
            $eligible = mysqli_prepare($conn, $eligible_sql);
            if ($role_id) {
                mysqli_stmt_bind_param($eligible, 'iii', $user_id, $schedule['adviser_id'], $role_id);
            } else {
                mysqli_stmt_bind_param($eligible, 'ii', $user_id, $schedule['adviser_id']);
            }
            mysqli_stmt_execute($eligible);
            $is_eligible = mysqli_fetch_assoc(mysqli_stmt_get_result($eligible));
            mysqli_stmt_close($eligible);
            if (!$is_eligible) {
                throw new RuntimeException('The chairperson must have the Panel Member role. Other seats may be Panel Members or advisers, but not this project’s adviser.');
            }

            $availability = mysqli_prepare(
                $conn,
                "SELECT 1
                 WHERE NOT EXISTS (
                     SELECT 1 FROM panel_assignment_responses
                     WHERE schedule_id = ? AND user_id = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1
                     FROM panel_assignments pa
                     JOIN defense_schedules ds ON ds.schedule_id = pa.schedule_id
                     WHERE pa.user_id = ? AND ds.status <> 'Cancelled'
                       AND ds.scheduled_date = ?
                     AND ds.slot_id = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1
                     FROM defense_schedules advised_ds
                     JOIN projects advised_project ON advised_project.project_id = advised_ds.project_id
                     WHERE advised_project.adviser_id = ?
                       AND advised_ds.schedule_id <> ?
                       AND advised_ds.status <> 'Cancelled'
                       AND advised_ds.scheduled_date = ?
                       AND advised_ds.slot_id = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM panel_assignments
                     WHERE schedule_id = ? AND user_id = ?
                 )"
            );
            mysqli_stmt_bind_param(
                $availability,
                'iiisiiisiii',
                $schedule_id,
                $user_id,
                $user_id,
                $schedule['scheduled_date'],
                $schedule['slot_id'],
                $user_id,
                $schedule_id,
                $schedule['scheduled_date'],
                $schedule['slot_id'],
                $schedule_id,
                $user_id
            );
            mysqli_stmt_execute($availability);
            $is_available = mysqli_fetch_assoc(mysqli_stmt_get_result($availability));
            mysqli_stmt_close($availability);
            if (!$is_available) {
                throw new RuntimeException('That panel member declined this defense or has another defense at the same time.');
            }

            $insert = mysqli_prepare(
                $conn,
                'INSERT INTO panel_assignments (schedule_id, user_id, panel_role) VALUES (?, ?, ?)'
            );
            mysqli_stmt_bind_param($insert, 'iis', $schedule_id, $user_id, $panel_role);
            mysqli_stmt_execute($insert);
            mysqli_stmt_close($insert);
            $message = 'Panel member assigned.';
        }
        mysqli_commit($conn);
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062
                ? 'The panel member is already assigned to this defense.'
                : 'Could not update the panel assignment.');
    }
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT pa.panel_assignment_id, pa.user_id, pa.panel_role, u.full_name,
            response.user_id IS NOT NULL AS decline_requested,
            response.reason AS decline_reason
     FROM panel_assignments pa
     JOIN users u ON u.user_id = pa.user_id
     LEFT JOIN panel_assignment_responses response
       ON response.schedule_id = pa.schedule_id AND response.user_id = pa.user_id
     WHERE pa.schedule_id = ?
     ORDER BY FIELD(pa.panel_role, 'Chairperson', 'Panel Member'), u.full_name"
);
mysqli_stmt_bind_param($stmt, 'i', $schedule_id);
mysqli_stmt_execute($stmt);
$assignments = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare(
    $conn,
    "SELECT u.user_id, u.full_name, GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_id SEPARATOR ', ') AS roles
     FROM users u
     JOIN user_roles ur ON ur.user_id = u.user_id
     JOIN roles r ON r.role_id = ur.role_id
     WHERE u.status = 'active' AND ur.role_id IN (2, 3) AND u.user_id <> ?
       AND NOT EXISTS (
           SELECT 1 FROM panel_assignment_responses declined
           WHERE declined.schedule_id = ? AND declined.user_id = u.user_id
       )
       AND NOT EXISTS (
           SELECT 1 FROM panel_assignments pa
           JOIN defense_schedules ds ON ds.schedule_id = pa.schedule_id
           WHERE pa.user_id = u.user_id AND ds.status <> 'Cancelled'
             AND ds.scheduled_date = ?
           AND ds.slot_id = ?
       )
       AND NOT EXISTS (
           SELECT 1
           FROM defense_schedules advised_ds
           JOIN projects advised_project ON advised_project.project_id = advised_ds.project_id
           WHERE advised_project.adviser_id = u.user_id
             AND advised_ds.schedule_id <> ?
             AND advised_ds.status <> 'Cancelled'
             AND advised_ds.scheduled_date = ?
             AND advised_ds.slot_id = ?
       )
       AND NOT EXISTS (
           SELECT 1 FROM panel_assignments pa
           WHERE pa.schedule_id = ? AND pa.user_id = u.user_id
       )
     GROUP BY u.user_id, u.full_name
     ORDER BY u.full_name"
);
mysqli_stmt_bind_param(
    $stmt,
    'iisiisii',
    $schedule['adviser_id'],
    $schedule_id,
    $schedule['scheduled_date'],
    $schedule['slot_id'],
    $schedule_id,
    $schedule['scheduled_date'],
    $schedule['slot_id'],
    $schedule_id
);
mysqli_stmt_execute($stmt);
$users = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$chairperson_assigned = false;
$panel_member_count = 0;
foreach ($assignments as $assignment) {
    if ($assignment['panel_role'] === 'Chairperson') {
        $chairperson_assigned = true;
    } elseif ($assignment['panel_role'] === 'Panel Member') {
        $panel_member_count++;
    }
}
$panel_seat_count = (int)$chairperson_assigned + $panel_member_count;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Assignment</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-2">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Panel Assignment</h1>
    <p><?= htmlspecialchars($schedule['title'] . ' — ' . $schedule['defense_type'] . ' (' . $schedule['scheduled_date'] . ')', ENT_QUOTES, 'UTF-8') ?></p>
    <p>The project adviser attends in addition to one Chairperson (Panel Member role) and two panel members. Other advisers may fill panel-member seats, but the project’s own adviser cannot.</p>
    <?php if ($message !== ''): ?><p class="dashboard-alert" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <section class="content-card">
        <h2>Current panel (<?= $panel_seat_count ?>/3 panel seats, plus adviser)</h2>
        <div class="table-wrap">
            <table class="defense-table">
                <thead>
                    <tr>
                        <th>Panelist</th>
                        <th>Assignment</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$assignments): ?><tr>
                            <td colspan="3" class="empty-state">No panel members assigned yet.</td>
                        </tr>
                        <?php else: foreach ($assignments as $assignment): ?>
                            <tr>
                                <td><?= htmlspecialchars($assignment['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($assignment['panel_role'], ENT_QUOTES, 'UTF-8') ?><?php if ($assignment['decline_requested']): ?><span class="account-status status-inactive">Declined · admin review</span><?php if ($assignment['decline_reason'] !== null && $assignment['decline_reason'] !== ''): ?><span class="course-tag"><?= htmlspecialchars($assignment['decline_reason'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?><?php endif; ?></td>
                                <td><?php if ($assignment['panel_role'] !== 'Adviser'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="schedule_id" value="<?= $schedule_id ?>"><button class="button-secondary" name="remove_id" value="<?= (int)$assignment['panel_assignment_id'] ?>">Remove</button></form><?php else: ?>Required attendee<?php endif; ?></td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
            <button type="submit" name="auto_assign" value="1">Process declines and fill open seats</button>
        </form>
    </section>
    <?php if (!$chairperson_assigned || $panel_member_count < 2): ?>
        <section class="content-card">
            <h2>Assign a panelist</h2>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
                <label for="panel_role">Seat
                    <select name="panel_role" id="panel_role" required>
                        <?php if (!$chairperson_assigned): ?><option value="Chairperson">Chairperson (Panel Member role)</option><?php endif; ?>
                        <?php if ($panel_member_count < 2): ?><option value="Panel Member">Panel Member (Panel Member or adviser role)</option><?php endif; ?>
                    </select>
                </label>
                <label for="user_id">Eligible panelist
                    <select name="user_id" id="user_id" required>
                        <option value="">Choose a panelist</option>
                        <?php foreach ($users as $person): ?>
                            <option value="<?= (int)$person['user_id'] ?>" data-roles="<?= htmlspecialchars($person['roles'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($person['full_name'] . ' (' . $person['roles'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (!$users): ?><p>No available, eligible panelists have been found for this date and room.</p><?php endif; ?>
                <button type="submit" <?= !$users ? 'disabled' : '' ?>>Assign panelist</button>
            </form>
        </section>
    <?php endif; ?>
    <p><a class="button-secondary" href="list.php">All scheduled defenses</a></p>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const role = document.getElementById('panel_role');
            const users = document.getElementById('user_id');
            if (!role || !users) return;
            const filter = () => {
                const chair = role.value === 'Chairperson';
                for (const option of users.options) {
                    if (!option.value) continue;
                    option.hidden = chair && !option.dataset.roles.split(', ').includes('Panel Member');
                    if (option.selected && option.hidden) users.value = '';
                }
            };
            role.addEventListener('change', filter);
            filter();
        });
    </script>
</body>

</html>