-- Готові відповіді (шаблони коментарів до тікетів). owner_id NULL — спільний шаблон для всього персоналу.
CREATE TABLE IF NOT EXISTS reply_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NULL,
    name VARCHAR(100) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_reply_templates_owner (owner_id),
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
