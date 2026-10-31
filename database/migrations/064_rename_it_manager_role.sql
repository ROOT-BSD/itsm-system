-- Роль it_manager перейменовано: «Керівник ІТ-підрозділу» → «Керівник підрозділу» (код не змінюється).
UPDATE roles SET name = 'Керівник підрозділу' WHERE code = 'it_manager';
UPDATE roles SET description = 'Підпорядкований Керівнику підрозділу; проєкти, задачі й тікети підлеглих співробітників' WHERE code = 'dept_head';
