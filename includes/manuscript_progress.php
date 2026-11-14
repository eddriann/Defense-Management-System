<?php

function get_manuscript_progress_steps(mysqli $conn, int $project_id): array
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT m.status, m.submitted_at,
                (SELECT COUNT(*) FROM manuscript_comments mc
                 WHERE mc.manuscript_id = m.manuscript_id) AS comment_count,
                (SELECT MAX(mc.created_at) FROM manuscript_comments mc
                 WHERE mc.manuscript_id = m.manuscript_id) AS latest_comment_at
         FROM manuscripts m
         WHERE m.project_id = ?
         ORDER BY m.submitted_at, m.manuscript_id"
    );
    mysqli_stmt_bind_param($stmt, 'i', $project_id);
    mysqli_stmt_execute($stmt);
    $manuscripts = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $steps = [];
    foreach ($manuscripts as $index => $manuscript) {
        $steps[] = [
            'label' => $index === 0 ? 'File submission' : 'Revised file submission',
            'date' => $manuscript['submitted_at'],
            'state' => 'complete',
        ];
        if ((int)$manuscript['comment_count'] > 0) {
            $steps[] = [
                'label' => 'Adviser comments',
                'date' => $manuscript['latest_comment_at'] ?? $manuscript['submitted_at'],
                'state' => 'complete',
            ];
        }
    }

    $latest = $manuscripts ? $manuscripts[count($manuscripts) - 1] : null;
    if (!$latest) {
        $steps[] = ['label' => 'File submission', 'date' => null, 'state' => 'current'];
        $steps[] = ['label' => 'Adviser comments', 'date' => null, 'state' => 'upcoming'];
        $steps[] = ['label' => 'Revised file submission', 'date' => null, 'state' => 'upcoming'];
        $steps[] = ['label' => 'Approved', 'date' => null, 'state' => 'upcoming'];
    } elseif ($latest['status'] === 'Approved') {
        $steps[] = ['label' => 'Approved', 'date' => null, 'state' => 'complete'];
    } elseif ($latest['status'] === 'In Revision') {
        if ((int)$latest['comment_count'] === 0) {
            $steps[] = ['label' => 'Adviser comments', 'date' => null, 'state' => 'current'];
        }
        $steps[] = [
            'label' => 'Revised file submission',
            'date' => null,
            'state' => (int)$latest['comment_count'] === 0 ? 'upcoming' : 'current',
        ];
        $steps[] = ['label' => 'Approved', 'date' => null, 'state' => 'upcoming'];
    } elseif ($latest['status'] === 'Revision Submitted') {
        $steps[] = ['label' => 'Awaiting adviser approval', 'date' => null, 'state' => 'current'];
        $steps[] = ['label' => 'Approved', 'date' => null, 'state' => 'upcoming'];
    } else {
        $steps[] = ['label' => 'Adviser comments', 'date' => null, 'state' => 'current'];
        $steps[] = ['label' => 'Revised file submission', 'date' => null, 'state' => 'upcoming'];
        $steps[] = ['label' => 'Approved', 'date' => null, 'state' => 'upcoming'];
    }

    return $steps;
}

function render_manuscript_progress(mysqli $conn, int $project_id, bool $show_heading = false): void
{
    $steps = get_manuscript_progress_steps($conn, $project_id);
    ?>
    <div class="manuscript-progress" aria-label="Manuscript progress">
        <?php if ($show_heading): ?><h2>Manuscript progress</h2><?php endif; ?>
        <ol class="manuscript-timeline">
            <?php foreach ($steps as $index => $step): ?>
                <li class="manuscript-timeline-step is-<?= htmlspecialchars($step['state'], ENT_QUOTES, 'UTF-8') ?>"<?= $step['state'] === 'current' ? ' aria-current="step"' : '' ?>>
                    <span class="manuscript-timeline-marker" aria-hidden="true"><?= $step['state'] === 'complete' ? '&#10003;' : '' ?></span>
                    <span class="manuscript-timeline-label"><?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($step['date'] !== null): ?>
                        <time datetime="<?= htmlspecialchars(date('Y-m-d\TH:i:s', strtotime($step['date'])), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, Y', strtotime($step['date'])), ENT_QUOTES, 'UTF-8') ?></time>
                    <?php endif; ?>
                    <?php if ($index < count($steps) - 1): ?><span class="manuscript-timeline-connector" aria-hidden="true"></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
    <?php
}

function render_latest_manuscript_progress(?string $status): void
{
    $label = $status ?? 'Not submitted';
    $state = $status === null ? 'upcoming' : ($status === 'Approved' ? 'complete' : 'current');
    ?>
    <span class="manuscript-latest-progress is-<?= htmlspecialchars($state, ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    </span>
    <?php
}
