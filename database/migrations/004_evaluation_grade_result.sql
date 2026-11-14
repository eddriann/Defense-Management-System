-- Apply after the panel verdict migration.
ALTER TABLE defense_schedules
    ADD COLUMN grade_result ENUM('Passed', 'Revisions Needed', 'Failed') DEFAULT NULL
    AFTER final_result;

UPDATE defense_schedules
SET grade_result = CASE
    WHEN final_score > 75 THEN 'Passed'
    WHEN final_score = 75 THEN 'Revisions Needed'
    ELSE 'Failed'
END
WHERE final_score IS NOT NULL;

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.user_id, r.role_id
FROM users u
JOIN roles r ON r.role_name = 'Panel Member'
WHERE u.username = 'adviser';
