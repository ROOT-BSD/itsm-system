<?php

namespace App\Models;

use App\Core\Database;

/**
 * Центр сповіщень (міграція 054): сповіщення «в системі» без пошти. Створюються автоматичними правилами черги
 * (та можуть створюватися будь-яким кодом через notify()). Кожне — рядок user_notifications; read_at = NULL — не прочитане.
 */
class UserNotification
{
    /** Скільки сповіщень зберігаємо на користувача (старіші прочитані видаляються). */
    private const KEEP = 200;

    /** Категорії, які користувач може вимкнути в налаштуваннях (повідомлення від автоматичних правил не вимикаються). */
    public const CATEGORIES = [
        'tickets' => 'Тікети — призначення, зміна статусу, нові коментарі',
        'tasks' => 'Задачі — призначення виконавцем, перенесення терміну',
        'projects' => 'Проєкти — призначення відповідальним, доступ до проєкту',
        'forum' => 'Форум — нові теми й відповіді',
        'reminders' => 'Нагадування про терміни задач',
        'changes' => 'Зміни (Change) — погодження, рішення, виконання',
    ];

    public static function prefsReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM user_notify_prefs LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** @return string[] вимкнені користувачем категорії */
    public static function disabledCategories(int $userId): array
    {
        if (!self::prefsReady()) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT category FROM user_notify_prefs WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param string[] $enabled категорії, які користувач хоче отримувати; решта вимикаються */
    public static function saveEnabledCategories(int $userId, array $enabled): void
    {
        if (!self::prefsReady()) {
            return;
        }
        $db = Database::connection();
        $db->prepare('DELETE FROM user_notify_prefs WHERE user_id = :u')->execute(['u' => $userId]);
        $ins = $db->prepare('INSERT INTO user_notify_prefs (user_id, category) VALUES (:u, :c)');
        foreach (array_keys(self::CATEGORIES) as $c) {
            if (!in_array($c, $enabled, true)) {
                $ins->execute(['u' => $userId, 'c' => $c]);
            }
        }
    }

    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM user_notifications LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** Додає сповіщення активному користувачу. $url — лише внутрішня адреса (починається з «/»). $category — для особистих налаштувань (null — завжди). */
    public static function notify(int $userId, string $title, ?string $body = null, ?string $url = null, ?string $category = null): void
    {
        if (!self::ready()) {
            return;
        }
        if ($category !== null && in_array($category, self::disabledCategories($userId), true)) {
            return; // користувач вимкнув цю категорію в налаштуваннях центру
        }
        if ($url !== null && !preg_match('#^/[A-Za-z0-9/_\-?=&%.\#]*$#', $url)) {
            $url = null;
        }
        $db = Database::connection();
        $db->prepare('INSERT INTO user_notifications (user_id, title, body, url) SELECT id, :t, :b, :u FROM users WHERE id = :id AND is_active = 1')
           ->execute(['t' => mb_substr($title, 0, 200), 'b' => $body !== null ? mb_substr($body, 0, 500) : null, 'u' => $url, 'id' => $userId]);
        // Обмежуємо розмір: найстаріші прочитані понад KEEP — геть.
        $db->prepare('DELETE FROM user_notifications WHERE user_id = :u AND read_at IS NOT NULL AND id < (SELECT m FROM (SELECT COALESCE(MIN(id), 0) m FROM (SELECT id FROM user_notifications WHERE user_id = :u2 ORDER BY id DESC LIMIT ' . self::KEEP . ') x) y)')
           ->execute(['u' => $userId, 'u2' => $userId]);
    }

    public static function unreadCount(int $userId): int
    {
        if (!self::ready()) {
            return 0;
        }
        try {
            $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = :u AND read_at IS NULL');
            $stmt->execute(['u' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function forUser(int $userId, int $limit = 100): array
    {
        if (!self::ready()) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT * FROM user_notifications WHERE user_id = :u ORDER BY id DESC LIMIT ' . (int) $limit);
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id, int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM user_notifications WHERE id = :id AND user_id = :u');
        $stmt->execute(['id' => $id, 'u' => $userId]);
        return $stmt->fetch() ?: null;
    }

    public static function markRead(int $id, int $userId): void
    {
        Database::connection()->prepare('UPDATE user_notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $userId]);
    }

    public static function markAllRead(int $userId): void
    {
        Database::connection()->prepare('UPDATE user_notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL')->execute(['u' => $userId]);
    }

    public static function deleteRead(int $userId): void
    {
        Database::connection()->prepare('DELETE FROM user_notifications WHERE user_id = :u AND read_at IS NOT NULL')->execute(['u' => $userId]);
    }
}
