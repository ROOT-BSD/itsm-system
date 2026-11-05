-- Запити на зміни (Change) із погодженням: опис, ризик, план відкату, вікно робіт, погоджувачі, коментарі.
CREATE TABLE IF NOT EXISTS change_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    change_type ENUM('standard','normal','emergency') NOT NULL DEFAULT 'normal',
    risk ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    status ENUM('draft','pending','approved','rejected','in_progress','implemented','rolled_back','cancelled') NOT NULL DEFAULT 'draft',
    description TEXT NOT NULL,
    reason TEXT NULL,
    impact TEXT NULL,
    rollback_plan TEXT NULL,
    window_start DATETIME NULL,
    window_end DATETIME NULL,
    requester_id INT NULL,
    ticket_id INT NULL,
    submitted_at DATETIME NULL,
    decided_at DATETIME NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_change_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_change_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    KEY idx_change_status (status),
    KEY idx_change_window (window_start, window_end),
    KEY idx_change_requester (requester_id),
    FULLTEXT KEY ft_change_text (title, description)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS change_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    change_id INT NOT NULL,
    approver_id INT NOT NULL,
    decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    comment VARCHAR(500) NULL,
    decided_at DATETIME NULL,
    CONSTRAINT fk_change_appr_change FOREIGN KEY (change_id) REFERENCES change_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_change_appr_user FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_change_approver (change_id, approver_id),
    KEY idx_change_appr_user (approver_id, decision)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS change_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    change_id INT NOT NULL,
    user_id INT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_change_comment_change FOREIGN KEY (change_id) REFERENCES change_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_change_comment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_change_comment (change_id, id)
) ENGINE=InnoDB;
