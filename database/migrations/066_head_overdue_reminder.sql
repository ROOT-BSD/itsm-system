-- Нагадування Керівнику відділу про прострочені задачі підлеглих (раз на задачу).
ALTER TABLE task_due_reminders MODIFY reminder_type ENUM('2d','1d','due','head_overdue') NOT NULL;
