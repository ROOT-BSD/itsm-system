-- Роль «Керівник відділу» (dept_head): безпосередньо підпорядкована Керівнику ІТ-підрозділу; має власний список підлеглих
-- (users.manager_id — прямий керівник користувача) і бачить/веде проєкти, задачі й тікети підлеглих (також вкладених).
INSERT INTO roles (code, name, description)
SELECT 'dept_head', 'Керівник відділу',
       'Підпорядкований Керівнику ІТ-підрозділу; проєкти, задачі й тікети підлеглих співробітників'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE code = 'dept_head');

ALTER TABLE users
    ADD COLUMN manager_id INT NULL AFTER role_id,
    ADD KEY idx_users_manager (manager_id),
    ADD CONSTRAINT fk_users_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL;
