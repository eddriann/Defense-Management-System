-- Apply after 002_admin_request_review_and_panel_declines.sql.
ALTER TABLE defense_requests
    ADD CONSTRAINT fk_defense_requests_endorser FOREIGN KEY (endorsed_by)
        REFERENCES users (user_id) ON DELETE RESTRICT;

ALTER TABLE panel_assignment_responses
    ADD CONSTRAINT fk_panel_responses_schedule FOREIGN KEY (schedule_id)
        REFERENCES defense_schedules (schedule_id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_panel_responses_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE CASCADE;
