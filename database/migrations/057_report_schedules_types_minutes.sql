-- Звіти за розкладом: тип звіту (облік часу / звіт по тікетах), хвилини часу відправки, фільтри звіту по тікетах.
ALTER TABLE report_schedules
    ADD COLUMN IF NOT EXISTS report_type ENUM('time','tickets') NOT NULL DEFAULT 'time' AFTER name,
    ADD COLUMN IF NOT EXISTS send_minute TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER send_hour,
    ADD COLUMN IF NOT EXISTS queue_id INT NULL,
    ADD COLUMN IF NOT EXISTS operator_id INT NULL;
