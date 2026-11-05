# ITSM System

## Короткий опис

Легкий PHP-застосунок (без Composer і фреймворку) для управління ІТ-проєктами, задачами та зверненнями, згідно з ТЗ v3.1. Розроблений для системних адміністраторів та керівників підрозділу — не для команд розробки ПЗ.

Основне: реєстр обладнання й ПЗ (CMDB-мінімум), проєкти (список, канбан, Гант, дорожня карта), задачі, облік часу, тікети з чергами, SLA та порталом самообслуговування, email-to-ticket, вікі, бібліотека документів, форум, звіти (PDF/CSV), REST API та вебхуки, вхід через локальні облікові записи або Active Directory. Ролі: адміністратор системи, керівник підрозділу, керівник відділу (з командою підлеглих), адміністратор підрозділу, оператор підтримки, співробітник, заявник. Задачі від керівництва проходять перевірку (вирішена → закрита → архів); дати скрізь у форматі ДД.ММ.РРРР.

Стек: PHP 8.1+ (перевірено на 8.3), MySQL 8 / MariaDB 10.6+, Bootstrap 5. Поточну версію видно у футері й у файлі `VERSION`. Історія змін — `CHANGELOG.md`.

Детальні інструкції: [docs/ADMIN_GUIDE.md](https://github.com/ROOT-BSD/itsm-system/blob/main/docs/ADMIN_GUIDE.md) (адміністратор), [docs/USER_GUIDE.md](https://github.com/ROOT-BSD/itsm-system/blob/main/docs/USER_GUIDE.md) (користувачі), [docs/API_GUIDE.md](https://github.com/ROOT-BSD/itsm-system/blob/main/docs/API_GUIDE.md) (REST API), [docs/GIT_GUIDE.md](https://github.com/ROOT-BSD/itsm-system/blob/main/docs/GIT_GUIDE.md) (git); Word-версії — у `docs/`.

## Вимоги до сервера

- PHP 8.1+ (розроблено та перевірено на 8.3) з розширенням `pdo_mysql`
- MySQL 8.x або MariaDB 10.6+
- Apache (з `mod_php` або `mod_proxy_fcgi`) або Nginx + PHP-FPM
- Контейнеризація не використовується (класичне розгортання на ОС)

## Встановлення

Покрокова інсталяція (автоматична, ручна), оновлення та локальний запуск — у файлі [INSTALL.md](https://github.com/ROOT-BSD/itsm-system/blob/main/INSTALL.md).
