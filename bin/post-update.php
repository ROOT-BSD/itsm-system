<?php
// Кроки після заміни файлів, що не потребують root (каталоги сховища, кеш шрифтів PDF, вікі, індекс файлів бібліотеки).
// Викликається зі сторінки «Оновлення» вже НОВОЮ версією коду, тож кроки нової версії виконуються без SSH.
//   php bin/post-update.php
// Вивід: по рядку на крок. Код виходу: 0.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

foreach (\App\Services\UpdateService::postSteps(dirname(__DIR__), PHP_BINARY) as $line) {
    echo $line, "\n";
}
exit(0);
