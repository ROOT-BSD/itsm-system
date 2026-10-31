<?php

namespace App\Core;

/**
 * Підлеглі «Керівника відділу» (dept_head): users.manager_id — прямий керівник співробітника. Підлеглі охоплюють
 * усю гілку вниз (підлеглі підлеглих, до MAX_DEPTH рівнів). Керівник відділу бачить і веде проєкти, задачі та тікети,
 * де його підлеглий — автор, відповідальний, учасник, виконавець чи заявник/оператор. Хто не Керівник відділу
 * (або колонки manager_id ще немає — міграція 063), підлеглих не має, і всі функції нічого не додають.
 *
 * Це ДРУГИЙ спосіб розширення видимості поряд з App\Core\Unit (там — за AD OU): місця, що викликають
 * Unit::userCondition, викликають і Team::userCondition (див. Project::accessCondition, Ticket::visibilityCondition).
 */
final class Team
{
    public const ROLE = 'dept_head';
    public const MAX_DEPTH = 8;

    /** @var array<int, int[]> */
    private static array $cache = [];
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Database::columnExists('users', 'manager_id');
    }

    public static function reset(): void
    {
        self::$cache = [];
    }

    /** Чи користувач — активний Керівник відділу. */
    public static function isHead(int $userId): bool
    {
        if (!self::ready() || $userId <= 0) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND u.is_active = 1 AND r.code = :c"
        );
        $stmt->execute(['id' => $userId, 'c' => self::ROLE]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Id усіх підлеглих (без самого керівника) для Керівника відділу; порожньо для решти.
     * @return int[]
     */
    public static function subordinateIds(int $headId): array
    {
        if (!array_key_exists($headId, self::$cache)) {
            $ids = [];
            if (self::isHead($headId)) {
                $seen = [$headId => true];
                $level = [$headId];
                for ($d = 0; $d < self::MAX_DEPTH && $level; $d++) {
                    $in = implode(',', array_map('intval', $level));
                    $next = [];
                    foreach (Database::connection()->query("SELECT id FROM users WHERE manager_id IN ({$in}) AND is_active = 1")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                        $id = (int) $id;
                        if (!isset($seen[$id])) {
                            $seen[$id] = true;
                            $ids[] = $id;
                            $next[] = $id;
                        }
                    }
                    $level = $next;
                }
            }
            self::$cache[$headId] = $ids;
        }
        return self::$cache[$headId];
    }

    /**
     * Умова «користувач із id-виразом $idExpr — підлеглий Керівника відділу $headId». null, якщо $headId не керівник
     * відділу; якщо підлеглих немає — умова «ніхто» (1 = 0), щоб виклик, що очікує умову, лишався коректним.
     *
     * @param string $idExpr лише внутрішній SQL-вираз (стовпець), не ввід користувача
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public static function userCondition(string $idExpr, string $prefix, int $headId): ?array
    {
        if (!self::isHead($headId)) {
            return null;
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $idExpr)) {
            throw new \InvalidArgumentException('Недопустимий SQL-вираз');
        }
        $ids = self::subordinateIds($headId);
        if (!$ids) {
            return ['1 = 0', []];
        }
        return ["{$idExpr} IN (" . implode(',', array_map('intval', $ids)) . ')', []];
    }

    /** Найближчий вгору по підпорядкуванню активний Керівник відділу для користувача (NULL — немає). */
    public static function nearestHead(int $userId): ?int
    {
        if (!self::ready() || $userId <= 0) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT u.manager_id, m.is_active, r.code FROM users u LEFT JOIN users m ON m.id = u.manager_id LEFT JOIN roles r ON r.id = m.role_id WHERE u.id = :id'
        );
        $cur = $userId;
        for ($d = 0; $d < self::MAX_DEPTH; $d++) {
            $stmt->execute(['id' => $cur]);
            $row = $stmt->fetch();
            if (!$row || empty($row['manager_id'])) {
                return null;
            }
            $cur = (int) $row['manager_id'];
            if ($row['code'] === 'dept_head' && (int) $row['is_active'] === 1) {
                return $cur;
            }
        }
        return null;
    }

    /** Чи є $userId підлеглим Керівника відділу $headId. */
    public static function containsUser(int $headId, int $userId): bool
    {
        return $userId > 0 && in_array($userId, self::subordinateIds($headId), true);
    }

    /** Умова з Unit або Team (роль одна, тож застосовується щонайбільше одна). */
    public static function scopeCondition(string $idExpr, string $prefix, int $userId): ?array
    {
        return Unit::userCondition($idExpr, $prefix, $userId) ?? self::userCondition($idExpr, $prefix, $userId);
    }

    /** Чи належить $userId до підрозділу адміністратора або до команди керівника відділу $viewerId. */
    public static function scopeContains(int $viewerId, int $userId): bool
    {
        return Unit::containsUser($viewerId, $userId) || self::containsUser($viewerId, $userId);
    }

    /** Чи має користувач розширений (підрозділ/команда) обсяг видимості. */
    public static function hasScope(int $userId): bool
    {
        return Unit::scopeOu($userId) !== null || self::isHead($userId);
    }
}
