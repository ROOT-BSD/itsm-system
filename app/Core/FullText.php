<?php

namespace App\Core;

/**
 * Допоміжне для повнотекстового пошуку (FULLTEXT, InnoDB). Запит розбивається на слова; кожне слово — префіксне
 * (`+слово*`), усі обов'язкові. Мінімальна довжина слова в індексі — innodb_ft_min_token_size (за замовчуванням 3),
 * тому коротші слова повнотекстово не шукаються — тоді (і коли індексу ще немає, міграція 067) діє пошук LIKE.
 */
final class FullText
{
    public const MIN_TOKEN = 3;

    /** @var array<string, bool> */
    private static array $cache = [];

    /** Чи існує FULLTEXT-індекс $index у таблиці $table. */
    public static function ready(string $table, string $index): bool
    {
        $key = $table . '.' . $index;
        if (!isset(self::$cache[$key])) {
            try {
                $stmt = Database::connection()->prepare(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i AND INDEX_TYPE = 'FULLTEXT'"
                );
                $stmt->execute(['t' => $table, 'i' => $index]);
                self::$cache[$key] = (int) $stmt->fetchColumn() > 0;
            } catch (\Throwable) {
                self::$cache[$key] = false;
            }
        }
        return self::$cache[$key];
    }

    /** Слова запиту (літери й цифри, без розділових знаків): [слово, ...]. */
    public static function terms(string $q): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($parts));
    }

    /**
     * Рядок для MATCH … AGAINST (IN BOOLEAN MODE) або NULL, якщо повнотекстовий пошук неможливий
     * (немає слів чи якесь слово коротше за MIN_TOKEN) — тоді викликач використовує LIKE.
     */
    public static function boolean(string $q): ?string
    {
        $terms = self::terms($q);
        if (!$terms) {
            return null;
        }
        $out = [];
        foreach (array_slice($terms, 0, 8) as $t) {
            if (mb_strlen($t) < self::MIN_TOKEN) {
                return null;
            }
            $out[] = '+' . $t . '*';
        }
        return implode(' ', $out);
    }
}
