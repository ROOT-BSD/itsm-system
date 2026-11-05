-- Кілька керівників відділів у одного користувача (багато-до-багатьох).
-- users.manager_id лишається «основним» керівником (для сумісності), повний перелік — у user_managers.
CREATE TABLE IF NOT EXISTS user_managers (
    user_id    INT NOT NULL,
    manager_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, manager_id),
    KEY idx_user_managers_manager (manager_id),
    CONSTRAINT fk_user_managers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_managers_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO user_managers (user_id, manager_id)
SELECT id, manager_id FROM users WHERE manager_id IS NOT NULL;
