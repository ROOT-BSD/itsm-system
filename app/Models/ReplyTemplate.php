<?php

namespace App\Models;

use App\Core\Database;

/** Готові відповіді (шаблони коментарів до тікетів, міграція 058): спільні (owner_id NULL) і особисті. */
class ReplyTemplate
{
    public const PLACEHOLDERS = [
        '{заявник}' => 'ім\'я заявника', '{номер}' => 'номер тікета', '{тема}' => 'тема тікета', '{черга}' => 'назва черги',
        '{статус}' => 'поточний статус', '{оператор}' => 'ваше ім\'я', '{посилання}' => 'посилання на тікет',
    ];

    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM reply_templates LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** Спільні та особисті шаблони користувача (спільні — першими). */
    public static function visibleTo(int $userId): array
    {
        if (!self::ready()) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT t.*, u.full_name AS owner_name FROM reply_templates t LEFT JOIN users u ON u.id = t.owner_id
                                                 WHERE t.owner_id IS NULL OR t.owner_id = :u ORDER BY t.owner_id IS NOT NULL, t.name');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM reply_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function save(?int $id, ?int $ownerId, string $name, string $body): int
    {
        $db = Database::connection();
        if ($id === null) {
            $db->prepare('INSERT INTO reply_templates (owner_id, name, body) VALUES (:o, :n, :b)')->execute(['o' => $ownerId, 'n' => $name, 'b' => $body]);
            return (int) $db->lastInsertId();
        }
        $db->prepare('UPDATE reply_templates SET name = :n, body = :b WHERE id = :id')->execute(['n' => $name, 'b' => $body, 'id' => $id]);
        return $id;
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM reply_templates WHERE id = :id')->execute(['id' => $id]);
    }

    /** Підстановка змінних у текст шаблону для конкретного тікета. */
    public static function fill(string $body, array $ticket): string
    {
        $map = [
            '{заявник}' => (string) $ticket['requester_name'], '{номер}' => (string) $ticket['id'], '{тема}' => (string) $ticket['subject'],
            '{черга}' => (string) ($ticket['queue_name'] ?? ''), '{статус}' => Ticket::statusLabel((string) $ticket['status']),
            '{оператор}' => (string) (\App\Core\Auth::name() ?? ''), '{посилання}' => rtrim(Setting::appUrl(), '/') . '/tickets/' . (int) $ticket['id'],
        ];
        return strtr($body, $map);
    }

    /** Шаблони користувача з уже підставленими даними тікета (для списку на сторінці тікета). */
    public static function renderedFor(int $userId, array $ticket): array
    {
        $out = [];
        foreach (self::visibleTo($userId) as $t) {
            $t['body'] = self::fill((string) $t['body'], $ticket);
            $out[] = $t;
        }
        return $out;
    }
}
