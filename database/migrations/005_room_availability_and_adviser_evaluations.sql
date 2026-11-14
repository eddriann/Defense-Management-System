CREATE TABLE room_unavailability (
    unavailability_id INT NOT NULL AUTO_INCREMENT,
    room_id INT NOT NULL,
    unavailable_date DATE NOT NULL,
    slot_id INT DEFAULT NULL,
    slot_key INT GENERATED ALWAYS AS (COALESCE(slot_id, 0)) STORED,
    reason VARCHAR(250) NOT NULL DEFAULT '',
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (unavailability_id),
    UNIQUE KEY uq_room_unavailability (room_id, unavailable_date, slot_key),
    KEY idx_room_unavailability_date_slot (unavailable_date, slot_id),
    CONSTRAINT fk_room_unavailability_room FOREIGN KEY (room_id)
        REFERENCES rooms (room_id) ON DELETE CASCADE,
    CONSTRAINT fk_room_unavailability_slot FOREIGN KEY (slot_id)
        REFERENCES time_slots (slot_id) ON DELETE RESTRICT,
    CONSTRAINT fk_room_unavailability_creator FOREIGN KEY (created_by)
        REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE evaluations
    MODIFY total_score DECIMAL(5,2) DEFAULT NULL;
