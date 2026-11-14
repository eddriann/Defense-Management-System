CREATE TABLE manuscripts (
    manuscript_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename CHAR(36) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    status ENUM('Submitted', 'In Revision', 'Reviewed') NOT NULL DEFAULT 'Submitted',
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
