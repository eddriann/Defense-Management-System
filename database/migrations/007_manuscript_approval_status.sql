ALTER TABLE manuscripts
    MODIFY status ENUM('Submitted', 'In Revision', 'Revision Submitted', 'Reviewed', 'Approved')
        NOT NULL DEFAULT 'Submitted';

UPDATE manuscripts
SET status = 'Approved'
WHERE status = 'Reviewed';

ALTER TABLE manuscripts
    MODIFY status ENUM('Submitted', 'In Revision', 'Revision Submitted', 'Approved')
        NOT NULL DEFAULT 'Submitted';
