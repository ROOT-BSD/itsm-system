-- Мітки для задач (спільний словник міток із тікетами: таблиця ticket_labels) і дії «мітка» та «коментар» у правилах для задач.
CREATE TABLE IF NOT EXISTS task_label_links (
    task_id INT NOT NULL,
    label_id INT NOT NULL,
    PRIMARY KEY (task_id, label_id),
    KEY idx_tasklab_label (label_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (label_id) REFERENCES ticket_labels(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE task_rules
    ADD COLUMN IF NOT EXISTS action_label_id INT NULL,
    ADD COLUMN IF NOT EXISTS action_comment VARCHAR(1000) NULL;
