-- Destructive reset: rebuilds the database with only the four default accounts, roles, and time slots.
DROP DATABASE IF EXISTS defense_management_system;
CREATE DATABASE defense_management_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE defense_management_system;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE roles (
    role_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_name VARCHAR(50) NOT NULL,
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_roles_name (role_name)
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    KEY idx_user_roles_role (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id)
        REFERENCES roles (role_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE time_slots (
    slot_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slot_name VARCHAR(20) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    PRIMARY KEY (slot_id),
    UNIQUE KEY uq_time_slots_name (slot_name),
    CONSTRAINT chk_time_slot_order CHECK (end_time > start_time)
) ENGINE=InnoDB;

CREATE TABLE rooms (
    room_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_name VARCHAR(100) NOT NULL,
    location VARCHAR(150) DEFAULT NULL,
    capacity INT UNSIGNED DEFAULT NULL,
    status ENUM('Active', 'Under Maintenance') NOT NULL DEFAULT 'Active',
    PRIMARY KEY (room_id),
    UNIQUE KEY uq_rooms_name (room_name)
) ENGINE=InnoDB;

CREATE TABLE room_unavailability (
    unavailability_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id INT UNSIGNED NOT NULL,
    unavailable_date DATE NOT NULL,
    slot_id TINYINT UNSIGNED DEFAULT NULL,
    slot_key TINYINT UNSIGNED
        GENERATED ALWAYS AS (COALESCE(slot_id, 0)) STORED,
    reason VARCHAR(250) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NOT NULL,
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

CREATE TABLE students (
    student_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    student_number VARCHAR(30) NOT NULL,
    program VARCHAR(100) NOT NULL,
    year_level VARCHAR(20) DEFAULT NULL,
    adviser_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (student_id),
    UNIQUE KEY uq_students_user (user_id),
    UNIQUE KEY uq_students_number (student_number),
    KEY idx_students_adviser (adviser_id),
    CONSTRAINT fk_students_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_students_adviser FOREIGN KEY (adviser_id)
        REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE projects (
    project_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    project_type ENUM('Thesis', 'Capstone') NOT NULL,
    adviser_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    status ENUM('Proposed', 'In Progress', 'For Defense', 'Completed', 'Dropped')
        NOT NULL DEFAULT 'Proposed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id),
    KEY idx_projects_adviser (adviser_id),
    KEY idx_projects_creator (created_by),
    KEY idx_projects_status (status),
    CONSTRAINT fk_projects_adviser FOREIGN KEY (adviser_id)
        REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_projects_creator FOREIGN KEY (created_by)
        REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE project_members (
    project_member_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    role_in_group ENUM('Leader', 'Member') NOT NULL DEFAULT 'Member',
    PRIMARY KEY (project_member_id),
    UNIQUE KEY uq_project_student (project_id, student_id),
    KEY idx_project_members_student (student_id),
    CONSTRAINT fk_project_members_project FOREIGN KEY (project_id)
        REFERENCES projects (project_id) ON DELETE CASCADE,
    CONSTRAINT fk_project_members_student FOREIGN KEY (student_id)
        REFERENCES students (student_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE manuscripts (
    manuscript_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename CHAR(36) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    status ENUM('Submitted', 'In Revision', 'Revision Submitted', 'Approved') NOT NULL DEFAULT 'Submitted',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (manuscript_id),
    UNIQUE KEY uq_manuscripts_stored_filename (stored_filename),
    KEY idx_manuscripts_project_submitted (project_id, submitted_at),
    CONSTRAINT fk_manuscripts_project FOREIGN KEY (project_id)
        REFERENCES projects (project_id) ON DELETE CASCADE,
    CONSTRAINT fk_manuscripts_uploader FOREIGN KEY (uploaded_by)
        REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE manuscript_comments (
    comment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    manuscript_id INT UNSIGNED NOT NULL,
    adviser_id INT UNSIGNED NOT NULL,
    page_number INT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id),
    KEY idx_manuscript_comments_page (manuscript_id, page_number, created_at),
    CONSTRAINT chk_manuscript_comment_page CHECK (page_number > 0),
    CONSTRAINT fk_manuscript_comments_manuscript FOREIGN KEY (manuscript_id)
        REFERENCES manuscripts (manuscript_id) ON DELETE CASCADE,
    CONSTRAINT fk_manuscript_comments_adviser FOREIGN KEY (adviser_id)
        REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE defense_requests (
    request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    defense_type ENUM('Proposal', 'Final') NOT NULL,
    preferred_date DATE NOT NULL,
    slot_id TINYINT UNSIGNED NOT NULL,
    status ENUM('Pending', 'Endorsed', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    requested_by INT UNSIGNED NOT NULL,
    endorsed_by INT UNSIGNED DEFAULT NULL,
    reviewed_by INT UNSIGNED DEFAULT NULL,
    remarks VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    endorsed_at TIMESTAMP NULL DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (request_id),
    KEY idx_defense_requests_project_status (project_id, status),
    KEY idx_defense_requests_date_slot_status (preferred_date, slot_id, status),
    KEY idx_defense_requests_requested_by (requested_by),
    KEY idx_defense_requests_reviewed_by (reviewed_by),
    KEY idx_defense_requests_endorsed_by (endorsed_by),
    CONSTRAINT fk_defense_requests_project FOREIGN KEY (project_id)
        REFERENCES projects (project_id) ON DELETE CASCADE,
    CONSTRAINT fk_defense_requests_slot FOREIGN KEY (slot_id)
        REFERENCES time_slots (slot_id) ON DELETE RESTRICT,
    CONSTRAINT fk_defense_requests_requester FOREIGN KEY (requested_by)
        REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_defense_requests_reviewer FOREIGN KEY (reviewed_by)
        REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_defense_requests_endorser FOREIGN KEY (endorsed_by)
        REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE defense_schedules (
    schedule_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED DEFAULT NULL,
    project_id INT UNSIGNED NOT NULL,
    defense_type ENUM('Proposal', 'Final') NOT NULL,
    scheduled_date DATE NOT NULL,
    slot_id TINYINT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NOT NULL,
    status ENUM('Scheduled', 'Ongoing', 'Completed', 'Cancelled')
        NOT NULL DEFAULT 'Scheduled',
    final_score DECIMAL(5,2) DEFAULT NULL,
    -- The verdict and grade outcome are stored separately.
    final_result ENUM('Passed', 'Failed', 'Revisions Needed', 'Defended', 'Redefense') DEFAULT NULL,
    grade_result ENUM('Passed', 'Revisions Needed', 'Failed') DEFAULT NULL,
    recommendations VARCHAR(1000) DEFAULT NULL,
    finalized_by INT UNSIGNED DEFAULT NULL,
    grade_approved_by INT UNSIGNED DEFAULT NULL,
    grade_approved_at TIMESTAMP NULL DEFAULT NULL,
    finalized_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_room_booking TINYINT
        GENERATED ALWAYS AS (CASE WHEN status = 'Cancelled' THEN NULL ELSE 1 END) STORED,
    PRIMARY KEY (schedule_id),
    UNIQUE KEY uq_schedule_request (request_id),
    UNIQUE KEY uq_active_room_slot (room_id, scheduled_date, slot_id, active_room_booking),
    KEY idx_schedules_date_slot_status (scheduled_date, slot_id, status),
    KEY idx_schedules_project (project_id),
    KEY idx_schedules_created_by (created_by),
    KEY idx_schedules_finalized_by (finalized_by),
    KEY idx_schedules_grade_approved_by (grade_approved_by),
    CONSTRAINT chk_schedule_final_score CHECK (final_score IS NULL OR (final_score >= 0 AND final_score <= 100)),
    CONSTRAINT fk_schedules_request FOREIGN KEY (request_id)
        REFERENCES defense_requests (request_id) ON DELETE SET NULL,
    CONSTRAINT fk_schedules_project FOREIGN KEY (project_id)
        REFERENCES projects (project_id) ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_slot FOREIGN KEY (slot_id)
        REFERENCES time_slots (slot_id) ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_room FOREIGN KEY (room_id)
        REFERENCES rooms (room_id) ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_creator FOREIGN KEY (created_by)
        REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_finalizer FOREIGN KEY (finalized_by)
        REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_grade_approver FOREIGN KEY (grade_approved_by)
        REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE panel_assignments (
    panel_assignment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    panel_role ENUM('Chairperson', 'Panel Member', 'Adviser') NOT NULL,
    chairperson_slot TINYINT
        GENERATED ALWAYS AS (CASE WHEN panel_role = 'Chairperson' THEN 1 ELSE NULL END) STORED,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (panel_assignment_id),
    UNIQUE KEY uq_schedule_user (schedule_id, user_id),
    UNIQUE KEY uq_one_chairperson (schedule_id, chairperson_slot),
    KEY idx_panel_user (user_id),
    CONSTRAINT fk_panel_schedule FOREIGN KEY (schedule_id)
        REFERENCES defense_schedules (schedule_id) ON DELETE CASCADE,
    CONSTRAINT fk_panel_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE panel_assignment_responses (
    schedule_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    response ENUM('Declined') NOT NULL DEFAULT 'Declined',
    reason VARCHAR(500) DEFAULT NULL,
    responded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (schedule_id, user_id),
    CONSTRAINT fk_panel_responses_schedule FOREIGN KEY (schedule_id)
        REFERENCES defense_schedules (schedule_id) ON DELETE CASCADE,
    CONSTRAINT fk_panel_responses_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Application logic enforces distinct panel seats and one chairperson per schedule.

CREATE TABLE evaluations (
    evaluation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT UNSIGNED NOT NULL,
    evaluator_id INT UNSIGNED NOT NULL,
    total_score DECIMAL(5,2) DEFAULT NULL,
    decision ENUM('Defended', 'Redefense') DEFAULT NULL,
    comments VARCHAR(1000) DEFAULT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (evaluation_id),
    UNIQUE KEY uq_schedule_evaluator (schedule_id, evaluator_id),
    KEY idx_evaluations_evaluator (evaluator_id),
    CONSTRAINT chk_evaluation_score CHECK (total_score >= 0 AND total_score <= 100),
    CONSTRAINT fk_evaluations_schedule FOREIGN KEY (schedule_id)
        REFERENCES defense_schedules (schedule_id) ON DELETE CASCADE,
    CONSTRAINT fk_evaluations_evaluator FOREIGN KEY (evaluator_id)
        REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO roles (role_id, role_name) VALUES
    (1, 'Admin'),
    (2, 'Adviser'),
    (3, 'Panel Member'),
    (4, 'Student');

INSERT INTO time_slots (slot_id, slot_name, start_time, end_time) VALUES
    (1, '8:00-10:00', '08:00:00', '10:00:00'),
    (2, '10:00-12:00', '10:00:00', '12:00:00'),
    (3, '1:00-3:00', '13:00:00', '15:00:00'),
    (4, '3:00-5:00', '15:00:00', '17:00:00');

-- Change the shared temporary password after first login; passwords must remain hashed.
INSERT INTO users (user_id, username, password, email, full_name, status) VALUES
    (1, 'admin', '$2y$10$fEgD.sja3gIYHZq.eDu6bO8zwyFc9nNhpKPWh2XLUKBWeb0TvPtKu',
     'admin@example.invalid', 'Default Administrator', 'active'),
    (2, 'adviser', '$2y$10$fEgD.sja3gIYHZq.eDu6bO8zwyFc9nNhpKPWh2XLUKBWeb0TvPtKu',
     'adviser@example.invalid', 'Default Adviser', 'active'),
    (3, 'panel', '$2y$10$fEgD.sja3gIYHZq.eDu6bO8zwyFc9nNhpKPWh2XLUKBWeb0TvPtKu',
     'panel@example.invalid', 'Default Panel Member', 'active'),
    (4, 'student', '$2y$10$fEgD.sja3gIYHZq.eDu6bO8zwyFc9nNhpKPWh2XLUKBWeb0TvPtKu',
     'student@example.invalid', 'Default Student', 'active');

INSERT INTO user_roles (user_id, role_id) VALUES
    (1, 1),
    (2, 2),
    (2, 3),
    (3, 3),
    (4, 4);
