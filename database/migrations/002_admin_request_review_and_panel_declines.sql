-- Adds adviser endorsement, admin review, and panel-decline tracking to legacy databases.
ALTER TABLE defense_requests
    MODIFY status ENUM('Pending', 'Endorsed', 'Approved', 'Rejected')
    NOT NULL DEFAULT 'Pending';

ALTER TABLE defense_requests
    ADD COLUMN endorsed_by INT DEFAULT NULL AFTER requested_by,
    ADD COLUMN endorsed_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
    ADD KEY idx_defense_requests_endorsed_by (endorsed_by);

CREATE TABLE IF NOT EXISTS panel_assignment_responses (
    schedule_id INT NOT NULL,
    user_id INT NOT NULL,
    response ENUM('Declined') NOT NULL DEFAULT 'Declined',
    reason VARCHAR(500) DEFAULT NULL,
    responded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (schedule_id, user_id)
) ENGINE=InnoDB;

ALTER TABLE defense_schedules
    ADD COLUMN final_score DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN final_result ENUM('Passed', 'Failed', 'Revisions Needed') DEFAULT NULL,
    ADD COLUMN recommendations VARCHAR(1000) DEFAULT NULL,
    ADD COLUMN finalized_by INT DEFAULT NULL,
    ADD COLUMN finalized_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN active_room_booking TINYINT
        GENERATED ALWAYS AS (CASE WHEN status = 'Cancelled' THEN NULL ELSE 1 END) STORED,
    DROP INDEX uq_one_per_day,
    DROP INDEX uq_room_dateslot,
    ADD UNIQUE KEY uq_active_room_slot (room_id, scheduled_date, slot_id, active_room_booking);

ALTER TABLE panel_assignments
    ADD COLUMN chairperson_slot TINYINT
        GENERATED ALWAYS AS (CASE WHEN panel_role = 'Chairperson' THEN 1 ELSE NULL END) STORED,
    ADD UNIQUE KEY uq_one_chairperson (schedule_id, chairperson_slot);

UPDATE rooms SET capacity = 4;
