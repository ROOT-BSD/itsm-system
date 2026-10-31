-- Архівування задач від керівництва: закриту (перевірену) задачу Керівник підрозділу відправляє в «Архів».
-- Для решти задач поведінка не змінюється (закрита задача одразу потрапляє в «Архів»).
ALTER TABLE tasks
    ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER actual_hours,
    ADD COLUMN archived_at DATETIME NULL AFTER is_archived,
    ADD COLUMN archived_by INT NULL AFTER archived_at;
-- Задачі, закриті до цієї міграції, вважаються вже заархівованими.
UPDATE tasks t JOIN task_statuses s ON s.id = t.status_id SET t.is_archived = 1 WHERE s.is_closed = 1;
