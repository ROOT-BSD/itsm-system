<?php
// Пошук усередині файлів: витягує текст із версій файлів бібліотеки, які ще не проіндексовано (міграція 068).
//
//   php bin/index-library.php          # лише нові (content_indexed = 0)
//   php bin/index-library.php --all    # переіндексувати всі версії (напр., після встановлення pdftotext)
//
// Безпечний для повторного запуску. update.sh викликає його сам. Нові файли індексуються під час завантаження.
// PDF читає pdftotext (пакет poppler-utils); без нього PDF не потрапляють у пошук за вмістом.
// Код виходу: 0 — успіх, 1 — помилка.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Core\Database;
use App\Models\LibraryDocument;

try {
    if (!LibraryDocument::contentReady()) {
        echo "Індексів вмісту ще немає (міграція 068) — пропущено.\n";
        exit(0);
    }
    $all = in_array('--all', $argv ?? [], true);
    $rows = Database::connection()->query(
        'SELECT id, stored_name, original_name FROM library_versions' . ($all ? '' : ' WHERE content_indexed = 0') . ' ORDER BY id'
    )->fetchAll();
    $done = 0;
    $withText = 0;
    foreach ($rows as $r) {
        if (LibraryDocument::indexContent((int) $r['id'], (string) $r['stored_name'], (string) $r['original_name'])) {
            $done++;
            if (LibraryDocument::versionText((int) $r['id']) !== null) {
                $withText++;
            }
        }
    }
    echo "Вміст файлів бібліотеки: оброблено {$done} із " . count($rows) . ", з текстом — {$withText}.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
