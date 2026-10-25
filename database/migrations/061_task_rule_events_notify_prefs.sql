-- Правила для задач: нові події (новий запис у журналі робіт задачі, перенесення терміну); особисті налаштування центру сповіщень
-- (user_notify_prefs зберігає лише ВИМКНЕНІ категорії: tickets, forum, projects, tasks, reminders).
ALTER TABLE task_rules MODIFY COLUMN trigger_event ENUM('created','status','comment','due') NOT NULL DEFAULT 'created';

CREATE TABLE IF NOT EXISTS user_notify_prefs (
    user_id INT NOT NULL,
    category VARCHAR(20) NOT NULL,
    PRIMARY KEY (user_id, category),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
