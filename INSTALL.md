# Інсталяція ITSM System

Вимоги до сервера — у [README.md](https://github.com/ROOT-BSD/itsm-system/blob/main/README.md#вимоги-до-сервера).

## Процес інсталяції

### Варіант А — автоматична інсталяція (рекомендовано)

Скрипт `install.sh` перевіряє всі залежності, створює БД і користувача, застосовує схему та генерує `.env`:

```bash
chmod +x install.sh

# Спершу — тільки перевірка, без жодних змін у системі:
./install.sh --check-only

# Повна інсталяція:
sudo ./install.sh

# Якщо БД уже створена і потрібна лише перевірка середовища:
sudo ./install.sh --skip-db
```

Що перевіряє скрипт:
- версію PHP (мінімум 8.1) та обов'язкові розширення (`pdo`, `pdo_mysql`, `mbstring`, `json`, `session`);
- опційні розширення (`ldap` для AD-інтеграції, `openssl` та `iconv` для пошти) — попередження, не помилка;
- поточні `upload_max_filesize` і `post_max_size` PHP — це стеля розміру вкладень (CLI-налаштування можуть відрізнятися від PHP-FPM/Apache, тому це орієнтир);
- наявність і доступність MySQL/MariaDB;
- наявність веб-сервера (Nginx + PHP-FPM або Apache);
- цілісність структури проєкту та синтаксис усіх PHP-файлів;
- **власника та права доступу файлів** — автоматично визначає користувача веб-сервера
  (`www-data`/`apache`/`nginx`/`_www` залежно від ОС) і виставляє: директорії 750,
  файли 640, `storage/uploads` та кеш шрифтів PDF 770, `.env` 600.

Додаткові прапорці:
- `--skip-perms` — не чіпати власника/права файлів (якщо керуєте цим окремо, наприклад Ansible/Puppet);
- `--web-user=ІМ_'Я` — задати користувача веб-сервера вручну, якщо автовизначення не підійшло.

За наявності критичних помилок інсталяція зупиняється з ненульовим кодом виходу і підказками щодо встановлення відсутніх пакетів для вашої ОС (Debian/Ubuntu, RHEL/Rocky/Alma, macOS).

### Варіант Б — ручне розгортання

1. Скопіювати проєкт на сервер, наприклад у `/var/www/itsm-system`.
2. Налаштувати веб-сервер так, щоб **document root вказував на папку `public/`** (не на корінь проєкту!).
3. Створити базу даних і застосувати схему:
   ```bash
   mysql -u root -p -e "CREATE DATABASE itsm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql --default-character-set=utf8mb4 -u root -p itsm < database/schema.sql
   mysql --default-character-set=utf8mb4 -u root -p itsm < database/seed.sql
   ```
   ⚠️ **Прапорець `--default-character-set=utf8mb4` обов'язковий.** Без нього клієнт `mysql`
   може прочитати файл в іншому кодуванні (типово latin1) і записати кирилицю з `seed.sql`
   у БД пошкодженою — подвійно перекодованою (див. розділ «Усунення несправностей» в [docs/ADMIN_GUIDE.md](https://github.com/ROOT-BSD/itsm-system/blob/main/docs/ADMIN_GUIDE.md)).

   Файли `schema.sql` / `seed.sql` не містять `CREATE DATABASE` та `USE`, тому назва БД
   задається аргументом команди — можна розгорнути кілька інстансів (test/prod) з тих самих файлів.
4. Створити окремого користувача БД для застосунку (не root):
   ```sql
   CREATE USER 'itsm_user'@'localhost' IDENTIFIED BY 'надійний_пароль';
   GRANT ALL PRIVILEGES ON itsm.* TO 'itsm_user'@'localhost';
   FLUSH PRIVILEGES;
   ```
5. Файл `.env` (створюється автоматично `install.sh`, або скопіюйте вручну з прикладу нижче) зчитується застосунком самостійно — **жодних додаткових `SetEnv`/`env[]` у конфігурації Apache чи PHP-FPM не потрібно**:
   ```
   DB_HOST=127.0.0.1
   DB_DATABASE=itsm
   DB_USERNAME=itsm_user
   DB_PASSWORD=надійний_пароль
   APP_ENV=production
   APP_URL=https://itsm.example.local
   APP_TIMEZONE=Europe/Kyiv
   ```
   Головне — щоб файл `.env` лежав у корені проєкту (поруч із `app/`, `public/`, `config/`) і був читабельний користувачем, від імені якого працює веб-сервер (`www-data`, `apache` тощо).
6. Переконатися, що папки `storage/uploads` та `app/Vendor/tfpdf/font/unifont` доступні для запису веб-сервером (перша — для файлів вкладень, друга — для кешу метрик шрифтів PDF-звітів).
7. Виставити власника і права доступу. Найпростіше — викликати лише відповідну частину `install.sh`:
   ```bash
   sudo ./install.sh --skip-db --web-user=www-data
   ```
   Або вручну:
   ```bash
   sudo chown -R www-data:www-data /var/www/itsm-system
   sudo find /var/www/itsm-system -type d -exec chmod 750 {} \;
   sudo find /var/www/itsm-system -type f -exec chmod 640 {} \;
   sudo chmod 770 /var/www/itsm-system/storage/uploads
   sudo chmod 770 /var/www/itsm-system/app/Vendor/tfpdf/font/unifont
   sudo chmod 600 /var/www/itsm-system/.env
   ```
8. Обов'язково змінити пароль тестового адміністратора (`admin@example.local` / `admin123`) або видалити цей рядок із `seed.sql` перед продуктивним використанням.

### Оновлення вже розгорнутої системи

Якщо система вже встановлена і потрібно лише накотити нові файли/виправлення, використовуйте `update.sh` — окремий скрипт, який **не питає пароль БД повторно** (читає його з вашого існуючого `.env`):

```bash
# 1. Скопіюйте оновлені файли поверх старих (окрім .env — його не чіпайте)
# 2. Потім одна команда:
sudo bash update.sh
```

Скрипт послідовно перевіряє й, за потреби, виправляє схему БД (міграції), права на `storage/` та інше; безпечний для повторного запуску — якщо виправляти нічого, просто про це повідомить.

Сам `update.sh` — «диригент»: кроки лежать у `scripts/update/` (БД, каталоги, вікі, права). Зміни схеми БД застосовує `php bin/migrate.php` і веде їх облік у таблиці `schema_migrations`; старі міграції 001–040 стиснуто в `database/migrations/legacy/`, нові додаються як `database/migrations/041_*.sql` і більше (див. `database/migrations/README.md`). Копіюйте оновлення **разом з папкою `scripts/`**.

### Локальний запуск для розробки

```bash
DB_HOST=127.0.0.1 DB_DATABASE=itsm DB_USERNAME=itsm_user DB_PASSWORD=change_me php -S 127.0.0.1:8000 -t public
```

Після інсталяції відкрийте `/login`. Тестовий обліковий запис із `seed.sql`: `admin@example.local` / `admin123` — **обов'язково змініть пароль або видаліть запис** перед продуктивним використанням.
