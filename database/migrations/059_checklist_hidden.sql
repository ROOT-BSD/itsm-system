-- Чек-лист задачі: «прибрати виконані» тепер ховає пункти (is_hidden), а не видаляє; пункти лишаються в задачі й у прогресі.
ALTER TABLE task_checklist_items ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER is_done;
