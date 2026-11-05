<?php

namespace App\Models;

use App\Core\Access;
use App\Core\Database;
use App\Core\Team;

/**
 * Запити на зміни (Change, міграція 069): чернетка → погодження → вікно робіт → виконано / відкат.
 *
 * Погоджувачі: найближчий Керівник відділу автора; при високому ризику додатково Керівники підрозділу; якщо нікого —
 * Керівники підрозділу, а за їх відсутності — адміністратор. Автор може змінити список перед надсиланням, але не може
 * погоджувати власну зміну. Правило рішення: будь-яка відмова → «Відхилено»; звичайна/стандартна зміна потребує згоди
 * ВСІХ погоджувачів, термінова — достатньо ОДНІЄЇ згоди.
 */
class ChangeRequest
{
    public const TYPES = ['standard' => 'Стандартна', 'normal' => 'Звичайна', 'emergency' => 'Термінова'];
    public const RISKS = ['low' => 'Низький', 'medium' => 'Середній', 'high' => 'Високий'];
    public const STATUSES = [
        'draft' => 'Чернетка', 'pending' => 'На погодженні', 'approved' => 'Погоджено', 'rejected' => 'Відхилено',
        'in_progress' => 'Виконується', 'implemented' => 'Виконано', 'rolled_back' => 'Відкочено', 'cancelled' => 'Скасовано',
    ];
    public const BADGES = [
        'draft' => 'secondary', 'pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger',
        'in_progress' => 'primary', 'implemented' => 'success', 'rolled_back' => 'dark', 'cancelled' => 'secondary',
    ];
    /** Статуси, у яких зміну ще можна скасувати. */
    public const CANCELLABLE = ['draft', 'pending', 'approved', 'rejected'];
    /** Статуси, що займають вікно робіт (для виявлення накладень). */
    public const ACTIVE_WINDOW = ['pending', 'approved', 'in_progress'];

    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM change_requests LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    // ------------------------------------------------------------------ читання

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, u.full_name AS requester_name FROM change_requests c LEFT JOIN users u ON u.id = c.requester_id WHERE c.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function approvals(int $id): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.*, u.full_name FROM change_approvals a JOIN users u ON u.id = a.approver_id WHERE a.change_id = :id ORDER BY a.id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll();
    }

    public static function comments(int $id): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.*, u.full_name FROM change_comments m LEFT JOIN users u ON u.id = m.user_id WHERE m.change_id = :id ORDER BY m.id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll();
    }

    /** Умова видимості в списку: адмін і Керівник підрозділу — усе; решта — власні, де вони погоджувач, і зміни своєї команди. */
    public static function visibilitySql(string $alias, int $userId, ?string $role): array
    {
        if (in_array($role, ['admin', 'it_manager'], true)) {
            return ['1 = 1', []];
        }
        $sql = "({$alias}.requester_id = :cv1 OR EXISTS (SELECT 1 FROM change_approvals ca WHERE ca.change_id = {$alias}.id AND ca.approver_id = :cv2)";
        $params = ['cv1' => $userId, 'cv2' => $userId];
        if (Access::isDeptHead($role) && ($team = Team::userCondition("{$alias}.requester_id", 'cvt', $userId)) !== null) {
            $sql .= ' OR ' . $team[0];
            $params += $team[1];
        }
        return [$sql . ')', $params];
    }

    public static function canView(array $change, int $userId, ?string $role): bool
    {
        if (!Access::canUseChanges($role)) {
            return false;
        }
        [$sql, $params] = self::visibilitySql('c', $userId, $role);
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM change_requests c WHERE c.id = :cid AND {$sql}");
        $stmt->execute(['cid' => (int) $change['id']] + $params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array{rows: array, total: int} */
    public static function search(int $userId, ?string $role, array $f, int $page = 1, int $perPage = 25): array
    {
        [$vis, $params] = self::visibilitySql('c', $userId, $role);
        $where = [$vis];
        if (!empty($f['status']) && isset(self::STATUSES[$f['status']])) {
            $where[] = 'c.status = :fs';
            $params['fs'] = $f['status'];
        }
        if (($f['scope'] ?? '') === 'mine') {
            $where[] = 'c.requester_id = :fm';
            $params['fm'] = $userId;
        } elseif (($f['scope'] ?? '') === 'approve') {
            $where[] = "c.status = 'pending' AND EXISTS (SELECT 1 FROM change_approvals ap WHERE ap.change_id = c.id AND ap.approver_id = :fa AND ap.decision = 'pending')";
            $params['fa'] = $userId;
        } elseif (($f['scope'] ?? '') === 'active') {
            $where[] = "c.status IN ('pending','approved','in_progress')";
        }
        if (!empty($f['q'])) {
            $where[] = '(c.title LIKE :fq1 OR c.description LIKE :fq2 OR c.id = :fq3)';
            $like = '%' . addcslashes((string) $f['q'], '\\%_') . '%';
            $params += ['fq1' => $like, 'fq2' => $like, 'fq3' => (int) ltrim((string) $f['q'], '#')];
        }
        $db = Database::connection();
        $w = implode(' AND ', $where);
        $cnt = $db->prepare("SELECT COUNT(*) FROM change_requests c WHERE {$w}");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();
        $stmt = $db->prepare(
            "SELECT c.*, u.full_name AS requester_name,
                    (SELECT COUNT(*) FROM change_approvals a WHERE a.change_id = c.id AND a.decision = 'approved') AS approved_count,
                    (SELECT COUNT(*) FROM change_approvals a WHERE a.change_id = c.id) AS approvers_count
             FROM change_requests c LEFT JOIN users u ON u.id = c.requester_id
             WHERE {$w} ORDER BY FIELD(c.status,'pending','approved','in_progress','draft','rejected','implemented','rolled_back','cancelled'), COALESCE(c.window_start, c.created_at) DESC, c.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage)
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Скільки змін чекає рішення цього користувача (бейдж у меню). */
    public static function pendingFor(int $userId): int
    {
        if (!self::ready()) {
            return 0;
        }
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM change_approvals a JOIN change_requests c ON c.id = a.change_id WHERE a.approver_id = :u AND a.decision = 'pending' AND c.status = 'pending'"
        );
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** Інші зміни з вікном робіт, що перетинається (погоджені, на погодженні, виконувані). */
    public static function overlapping(array $change): array
    {
        if (empty($change['window_start']) || empty($change['window_end'])) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            "SELECT id, title, status, window_start, window_end FROM change_requests
             WHERE id <> :id AND status IN ('pending','approved','in_progress') AND window_start < :e AND window_end > :s ORDER BY window_start LIMIT 10"
        );
        $stmt->execute(['id' => (int) ($change['id'] ?? 0), 's' => $change['window_start'], 'e' => $change['window_end']]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------ погоджувачі

    /** Кого можна призначити погоджувачем: активні адміністратори, Керівники підрозділу й відділів. */
    public static function eligibleApprovers(): array
    {
        return Database::connection()->query(
            "SELECT u.id, u.full_name, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1 AND r.code IN ('admin','it_manager','dept_head') ORDER BY u.full_name"
        )->fetchAll();
    }

    /** Погоджувачі за замовчуванням (id) для автора й ризику; автор у списку не буває. */
    public static function defaultApprovers(int $requesterId, string $risk): array
    {
        $ids = [];
        foreach (Team::nearestHeads($requesterId) as $head) {   // кілька відділів — усі найближчі керівники
            $ids[] = $head;
        }
        $db = Database::connection();
        $managers = array_map('intval', $db->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code = 'it_manager' ORDER BY u.id")->fetchAll(\PDO::FETCH_COLUMN));
        if ($risk === 'high' || !$ids) {
            $ids = array_merge($ids, $managers);
        }
        if (!$ids) {
            $admin = $db->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code = 'admin' ORDER BY u.id LIMIT 1")->fetchColumn();
            if ($admin !== false) {
                $ids[] = (int) $admin;
            }
        }
        return array_values(array_diff(array_unique($ids), [$requesterId]));
    }

    // ------------------------------------------------------------------ права на дії

    public static function isOwnerOrAdmin(array $c, int $userId, ?string $role): bool
    {
        return (int) $c['requester_id'] === $userId || $role === 'admin';
    }

    public static function canEdit(array $c, int $userId, ?string $role): bool
    {
        return in_array($c['status'], ['draft', 'rejected'], true) && self::isOwnerOrAdmin($c, $userId, $role);
    }

    public static function canRun(array $c, int $userId, ?string $role): bool
    {
        return self::isOwnerOrAdmin($c, $userId, $role) || $role === 'it_manager';
    }

    public static function canCancel(array $c, int $userId, ?string $role): bool
    {
        return in_array($c['status'], self::CANCELLABLE, true) && self::canRun($c, $userId, $role);
    }

    /** Рядок погодження користувача, якщо він має право ухвалити рішення зараз; інакше null. */
    public static function myPendingApproval(array $c, int $userId): ?array
    {
        if ($c['status'] !== 'pending') {
            return null;
        }
        $stmt = Database::connection()->prepare("SELECT * FROM change_approvals WHERE change_id = :c AND approver_id = :u AND decision = 'pending'");
        $stmt->execute(['c' => (int) $c['id'], 'u' => $userId]);
        return $stmt->fetch() ?: null;
    }

    // ------------------------------------------------------------------ запис

    /** Створює чернетку або оновлює змінювану. @return int id */
    public static function save(?int $id, array $d, int $requesterId): int
    {
        $db = Database::connection();
        $cols = ['title' => $d['title'], 'change_type' => $d['change_type'], 'risk' => $d['risk'], 'description' => $d['description'],
            'reason' => $d['reason'] ?: null, 'impact' => $d['impact'] ?: null, 'rollback_plan' => $d['rollback_plan'] ?: null,
            'window_start' => $d['window_start'], 'window_end' => $d['window_end'], 'ticket_id' => $d['ticket_id']];
        if ($id === null) {
            $db->prepare('INSERT INTO change_requests (title, change_type, risk, description, reason, impact, rollback_plan, window_start, window_end, ticket_id, requester_id)
                          VALUES (:title, :change_type, :risk, :description, :reason, :impact, :rollback_plan, :window_start, :window_end, :ticket_id, :req)')
               ->execute($cols + ['req' => $requesterId]);
            $id = (int) $db->lastInsertId();
        } else {
            $db->prepare('UPDATE change_requests SET title = :title, change_type = :change_type, risk = :risk, description = :description, reason = :reason,
                          impact = :impact, rollback_plan = :rollback_plan, window_start = :window_start, window_end = :window_end, ticket_id = :ticket_id WHERE id = :id')
               ->execute($cols + ['id' => $id]);
        }
        return $id;
    }

    /** Що не вистачає, щоб надіслати на погодження (список повідомлень; порожній — усе гаразд). */
    public static function submitProblems(array $c, array $approverIds): array
    {
        $p = [];
        if (trim((string) $c['rollback_plan']) === '') {
            $p[] = 'вкажіть план відкату';
        }
        if (empty($c['window_start']) || empty($c['window_end'])) {
            $p[] = 'вкажіть вікно робіт (початок і завершення)';
        } elseif (strtotime((string) $c['window_end']) <= strtotime((string) $c['window_start'])) {
            $p[] = 'завершення вікна робіт має бути пізніше за початок';
        }
        if (!$approverIds) {
            $p[] = 'оберіть хоча б одного погоджувача';
        } elseif (in_array((int) $c['requester_id'], array_map('intval', $approverIds), true)) {
            $p[] = 'автор не може погоджувати власну зміну';
        }
        return $p;
    }

    /** Надсилає на погодження: фіксує погоджувачів (старі рішення скидаються). */
    public static function submit(int $id, array $approverIds): void
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM change_approvals WHERE change_id = :id')->execute(['id' => $id]);
            $ins = $db->prepare('INSERT INTO change_approvals (change_id, approver_id) VALUES (:c, :u)');
            foreach (array_unique($approverIds) as $uid) {
                $ins->execute(['c' => $id, 'u' => (int) $uid]);
            }
            $db->prepare("UPDATE change_requests SET status = 'pending', submitted_at = NOW(), decided_at = NULL WHERE id = :id")->execute(['id' => $id]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Записує рішення погоджувача й, якщо рішення настало, переводить зміну в «Погоджено» / «Відхилено».
     * @return string статус після рішення
     */
    public static function decide(int $id, int $approverId, bool $approve, string $comment): string
    {
        $db = Database::connection();
        $db->prepare("UPDATE change_approvals SET decision = :d, comment = :c, decided_at = NOW() WHERE change_id = :id AND approver_id = :u AND decision = 'pending'")
           ->execute(['d' => $approve ? 'approved' : 'rejected', 'c' => $comment !== '' ? mb_substr($comment, 0, 500) : null, 'id' => $id, 'u' => $approverId]);
        $c = self::find($id);
        $rows = self::approvals($id);
        $decisions = array_column($rows, 'decision');
        $new = 'pending';
        if (in_array('rejected', $decisions, true)) {
            $new = 'rejected';
        } elseif ($c['change_type'] === 'emergency' ? in_array('approved', $decisions, true) : !in_array('pending', $decisions, true)) {
            $new = 'approved';
        }
        if ($new !== 'pending') {
            $db->prepare('UPDATE change_requests SET status = :s, decided_at = NOW() WHERE id = :id AND status = \'pending\'')->execute(['s' => $new, 'id' => $id]);
        }
        return $new;
    }

    /** Перехід виконання: start (approved → in_progress), implemented / rolled_back (in_progress → кінцевий), cancelled. */
    public static function transition(int $id, string $to): bool
    {
        $map = [
            'in_progress' => [['approved'], 'started_at'],
            'implemented' => [['in_progress'], 'finished_at'],
            'rolled_back' => [['in_progress'], 'finished_at'],
            'cancelled' => [self::CANCELLABLE, null],
        ];
        if (!isset($map[$to])) {
            return false;
        }
        [$from, $col] = $map[$to];
        $in = "'" . implode("','", $from) . "'";
        $stmt = Database::connection()->prepare("UPDATE change_requests SET status = :to" . ($col ? ", {$col} = NOW()" : '') . " WHERE id = :id AND status IN ({$in})");
        $stmt->execute(['to' => $to, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function addComment(int $id, int $userId, string $body): void
    {
        Database::connection()->prepare('INSERT INTO change_comments (change_id, user_id, body) VALUES (:c, :u, :b)')
            ->execute(['c' => $id, 'u' => $userId, 'b' => $body]);
    }
}
