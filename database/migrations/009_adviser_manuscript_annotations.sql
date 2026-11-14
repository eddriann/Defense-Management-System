CREATE TABLE manuscript_annotations (
    annotation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    manuscript_id INT UNSIGNED NOT NULL,
    adviser_id INT UNSIGNED NOT NULL,
    page_number INT UNSIGNED NOT NULL,
    annotations_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (annotation_id),
    UNIQUE KEY uq_manuscript_annotation_page (manuscript_id, page_number),
    CONSTRAINT chk_manuscript_annotation_page CHECK (page_number > 0),
    CONSTRAINT fk_manuscript_annotations_manuscript FOREIGN KEY (manuscript_id)
        REFERENCES manuscripts (manuscript_id) ON DELETE CASCADE,
    CONSTRAINT fk_manuscript_annotations_adviser FOREIGN KEY (adviser_id)
        REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;
