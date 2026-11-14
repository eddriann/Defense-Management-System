ALTER TABLE defense_schedules
    ADD COLUMN grade_approved_by INT UNSIGNED DEFAULT NULL AFTER finalized_by,
    ADD COLUMN grade_approved_at TIMESTAMP NULL DEFAULT NULL AFTER grade_approved_by,
    ADD KEY idx_schedules_grade_approved_by (grade_approved_by),
    ADD CONSTRAINT fk_schedules_grade_approver FOREIGN KEY (grade_approved_by)
        REFERENCES users (user_id) ON DELETE SET NULL;
