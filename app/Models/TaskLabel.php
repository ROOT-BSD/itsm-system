<?php

namespace App\Models;

use App\Core\Database;

/** Мітки задач (міграція 062): спільний словник із мітками тікетів (ticket_labels), зв'язки — task_label_links. */
class TaskLabel
{
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM task_label_links LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** @return array<int, array<string, mixed>> */
    public static function forTask(int $taskId): array
    {
        return self::forTasks([$taskId])[$taskId] ?? [];
    }

    /** @param int[] $ids @return array<int, array<int, array<string, mixed>>> [task_id => [label…]] */
    public static function forTasks(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !self::ready()) {
            return [];
        }
        $rows = Database::connection()->query(
            'SELECT l.task_id, b.id, b.name, b.color FROM task_label_links l JOIN ticket_labels b ON b.id = l.label_id WHERE l.task_id IN (' . implode(',', $ids) . ') ORDER BY b.name'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['task_id']][] = $r;
        }
        return $out;
    }

    public static function add(int $taskId, int $labelId): bool
    {
        if (!self::ready()) {
            return false;
        }
        $stmt = Database::connection()->prepare('INSERT IGNORE INTO task_label_links (task_id, label_id) SELECT :t, id FROM ticket_labels WHERE id = :l');
        $stmt->execute(['t' => $taskId, 'l' => $labelId]);
        return $stmt->rowCount() > 0;
    }

    /** Замінює набір міток задачі. @param int[] $labelIds */
    public static function set(int $taskId, array $labelIds, int $actingUserId): void
    {
        if (!self::ready()) {
            return;
        }
        Database::connection()->prepare('DELETE FROM task_label_links WHERE task_id = :t')->execute(['t' => $taskId]);
        $ids = array_values(array_unique(array_map('intval', $labelIds)));
        foreach ($ids as $lid) {
            self::add($taskId, $lid);
        }
        Audit::log('task', $taskId, 'labels_changed', $actingUserId, ['labels' => $ids]);
    }
}
