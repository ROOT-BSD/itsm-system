<?php

namespace App\Models;

use App\Core\Database;

/**
 * Відгуки «допомогло / не допомогло» до публічних статей «Бази знань». source = 'form' — читач прийшов зі підказки
 * на формі звернення: «допомогло» звідти — ймовірно, зайве звернення не створено (це оцінка, а не точний облік).
 * Без таблиці (оновлення без міграції 060) усе мовчки вимикається.
 */
class KbFeedback
{
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = (int) Database::connection()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kb_feedback'")->fetchColumn() > 0;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    public static function add(int $pageId, bool $helpful, string $source): void
    {
        if (!self::ready()) {
            return;
        }
        Database::connection()->prepare('INSERT INTO kb_feedback (page_id, helpful, source) VALUES (:p, :h, :s)')
            ->execute(['p' => $pageId, 'h' => $helpful ? 1 : 0, 's' => $source === 'form' ? 'form' : 'page']);
    }

    /** @return array<int, array{yes: int, no: int, deflected: int}> за id сторінки (лише сторінки з відгуками) */
    public static function stats(): array
    {
        if (!self::ready()) {
            return [];
        }
        $out = [];
        $rows = Database::connection()->query(
            "SELECT page_id, SUM(helpful = 1) AS yes, SUM(helpful = 0) AS no, SUM(helpful = 1 AND source = 'form') AS deflected FROM kb_feedback GROUP BY page_id"
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(int) $r['page_id']] = ['yes' => (int) $r['yes'], 'no' => (int) $r['no'], 'deflected' => (int) $r['deflected']];
        }
        return $out;
    }

    /** @return array{yes: int, no: int, deflected: int} */
    public static function forPage(int $pageId): array
    {
        return self::stats()[$pageId] ?? ['yes' => 0, 'no' => 0, 'deflected' => 0];
    }
}
