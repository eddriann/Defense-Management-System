-- Apply to the existing application database. Fresh installs use schema.sql.
ALTER TABLE evaluations
    ADD COLUMN decision ENUM('Defended', 'Redefense') DEFAULT NULL AFTER total_score;

ALTER TABLE defense_schedules
    MODIFY final_result ENUM(
        'Passed',
        'Failed',
        'Revisions Needed',
        'Defended',
        'Redefense'
    ) DEFAULT NULL;
