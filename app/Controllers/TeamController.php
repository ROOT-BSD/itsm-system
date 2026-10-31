<?php

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Team;
use App\Core\View;
use App\Models\Audit;

/**
 * «Команда» Керівника відділу (роль dept_head): список підлеглих (users.manager_id), додавання й зняття.
 * Керівники відділів підпорядковані Керівнику підрозділу. Складають команди адміністратор і Керівник підрозділу
 * (будь-якого відділу); Керівник відділу — лише власну (додає співробітників, у яких ще немає керівника).
 */
class TeamController
{
    /** Ролі, яких не можна підпорядкувати Керівнику відділу. */
    private const NOT_SUBORDINATE = ['admin', 'it_manager', 'unit_admin'];

    public function index(): void
    {
        Auth::requireLogin();
        $role = Auth::role();
        if (Access::isDeptHead($role)) {
            header('Location: /team/' . (int) Auth::id());
            exit;
        }
        if (!Access::canManageTeams($role)) {
            $this->deny();
        }
        $heads = Team::ready() ? Database::connection()->query(
            "SELECT u.id, u.full_name, u.email, u.is_active, m.full_name AS superior_name,
                    (SELECT COUNT(*) FROM users s WHERE s.manager_id = u.id AND s.is_active = 1) AS direct_count
               FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN users m ON m.id = u.manager_id
              WHERE r.code = 'dept_head' ORDER BY u.is_active DESC, u.full_name"
        )->fetchAll() : [];
        foreach ($heads as &$h) {
            $h['total_count'] = count(Team::subordinateIds((int) $h['id']));
        }
        unset($h);
        View::render('team/index', ['heads' => $heads, 'tree' => Team::ready() ? $this->forest() : [], 'ready' => Team::ready(), 'error' => $_GET['error'] ?? null, 'success' => $_GET['success'] ?? null]);
    }

    /** Усі активні користувачі, що беруть участь в ієрархії, з лічильником відкритих задач: [id => рядок]. */
    private function hierarchyUsers(): array
    {
        $rows = Database::connection()->query(
            "SELECT u.id, u.full_name, u.email, u.manager_id, r.code AS role_code, r.name AS role_name,
                    (SELECT COUNT(*) FROM tasks t JOIN task_statuses s ON s.id = t.status_id WHERE t.assignee_id = u.id AND s.is_closed = 0) AS open_tasks
               FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 ORDER BY u.full_name"
        )->fetchAll();
        $users = [];
        foreach ($rows as $r) {
            $users[(int) $r['id']] = $r;
        }
        return $users;
    }

    /** Вузол дерева з вкладеними підлеглими (захист від циклів і надмірної глибини). */
    private function node(array $users, array $children, int $id, array $seen, int $depth): array
    {
        $u = $users[$id];
        $kids = [];
        if ($depth < Team::MAX_DEPTH + 2) {
            foreach ($children[$id] ?? [] as $cid) {
                if (!isset($seen[$cid])) {
                    $kids[] = $this->node($users, $children, $cid, $seen + [$cid => true], $depth + 1);
                }
            }
        }
        return $u + ['children' => $kids, 'total' => array_sum(array_map(static fn($k) => 1 + $k['total'], $kids))];
    }

    private function childrenMap(array $users): array
    {
        $children = [];
        foreach ($users as $id => $u) {
            if ($u['manager_id'] !== null && isset($users[(int) $u['manager_id']])) {
                $children[(int) $u['manager_id']][] = $id;
            }
        }
        return $children;
    }

    /** Дерево всієї ієрархії: Керівники підрозділу → Керівники відділів → їхні підлеглі (вкладені відділи). */
    private function forest(): array
    {
        $users = $this->hierarchyUsers();
        $children = $this->childrenMap($users);
        $roots = [];
        foreach ($users as $id => $u) {
            $topHead = $u['role_code'] === 'it_manager';
            // корінь: Керівник підрозділу, або керівник відділу без активного керівника
            if ($topHead || ($u['role_code'] === 'dept_head' && ($u['manager_id'] === null || !isset($users[(int) $u['manager_id']])))) {
                $roots[] = $this->node($users, $children, $id, [$id => true], 0);
            }
        }
        usort($roots, static fn($a, $b) => [$a['role_code'] === 'it_manager' ? 0 : 1, $a['full_name']] <=> [$b['role_code'] === 'it_manager' ? 0 : 1, $b['full_name']]);
        return $roots;
    }

    /** Дерево команди одного Керівника відділу (корінь — він сам). */
    private function subtree(int $headId): array
    {
        $users = $this->hierarchyUsers();
        if (!isset($users[$headId])) {
            return [];
        }
        return [$this->node($users, $this->childrenMap($users), $headId, [$headId => true], 0)];
    }

    /** «Звіт по тікетах» для Керівника відділу: тікети, де заявник або оператор — він сам чи його підлеглий. */
    public function ticketsReport(): void
    {
        Auth::requireLogin();
        if (!Access::isDeptHead(Auth::role())) {
            $this->deny();
        }
        $scope = (int) Auth::id();
        $f = AdminController::ticketsReportFilters();
        $rows = \App\Models\Ticket::workReportRows($f, $scope);
        View::render('admin/tickets_report', [
            'unitMode' => true,
            'teamMode' => true,
            'f' => $f,
            'report' => \App\Services\TicketWorkReport::build($rows),
            'rows' => array_slice($rows, 0, 200),
            'rowCount' => count($rows),
            'queues' => \App\Models\Ticket::queues(),
            'operators' => \App\Models\Ticket::workReportOperators($scope),
            'exportBase' => '/team/tickets-report/export',
        ]);
    }

    public function show(array $params): void
    {
        Auth::requireLogin();
        $head = $this->headOrFail((int) $params['id']);
        $id = (int) $head['id'];
        $pdo = Database::connection();
        $ids = Team::subordinateIds($id);
        $rows = [];
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $rows = $pdo->query(
                "SELECT u.id, u.full_name, u.email, u.manager_id, mg.full_name AS manager_name, r.name AS role_name,
                        (SELECT COUNT(*) FROM tasks t JOIN task_statuses s ON s.id = t.status_id WHERE t.assignee_id = u.id AND s.is_closed = 0) AS open_tasks,
                        (SELECT COUNT(*) FROM tickets k WHERE (k.requester_user_id = u.id OR k.assigned_operator_id = u.id) AND k.status NOT IN ('resolved','closed')) AS open_tickets,
                        (SELECT COALESCE(SUM(l.hours), 0) FROM time_logs l WHERE l.user_id = u.id AND l.log_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month_hours
                   FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN users mg ON mg.id = u.manager_id
                  WHERE u.id IN ({$in}) ORDER BY u.full_name"
            )->fetchAll();
        }
        // Кого можна додати: активні, не адміністратори/керівники ІТ, не сам керівник, не його керівники (без циклів); без чужого керівника
        // (адміністратор і Керівник підрозділу можуть переводити й тих, у кого керівник уже є).
        $canMove = Access::canManageTeams(Auth::role());
        $exclude = array_merge([$id], $ids, $this->ancestors($id));
        $cands = [];
        foreach ($pdo->query(
            "SELECT u.id, u.full_name, u.email, u.manager_id, r.code AS role_code, r.name AS role_name, mg.full_name AS manager_name
               FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN users mg ON mg.id = u.manager_id
              WHERE u.is_active = 1 ORDER BY u.full_name"
        )->fetchAll() as $u) {
            if (in_array((int) $u['id'], $exclude, true) || in_array($u['role_code'], self::NOT_SUBORDINATE, true)) {
                continue;
            }
            if ($u['manager_id'] !== null && !$canMove) {
                continue;
            }
            $cands[] = $u;
        }
        $superiors = $canMove ? $pdo->query("SELECT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'it_manager' AND u.is_active = 1 ORDER BY u.full_name")->fetchAll() : [];
        View::render('team/show', [
            'tree' => $this->subtree($id),
            'head' => $head, 'rows' => $rows, 'candidates' => $cands, 'canMove' => $canMove, 'superiors' => $superiors,
            'error' => $_GET['error'] ?? null, 'success' => $_GET['success'] ?? null,
        ]);
    }

    public function add(array $params): void
    {
        Auth::requireLogin();
        $head = $this->headOrFail((int) $params['id']);
        $hid = (int) $head['id'];
        $uid = (int) ($_POST['user_id'] ?? 0);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT u.id, u.full_name, u.manager_id, u.is_active, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id');
        $stmt->execute(['id' => $uid]);
        $u = $stmt->fetch();
        if (!$u || (int) $u['is_active'] !== 1) {
            $this->back($hid, 'error', 'Оберіть активного користувача.');
        }
        if ($uid === $hid || in_array($u['role_code'], self::NOT_SUBORDINATE, true)) {
            $this->back($hid, 'error', 'Цього користувача не можна підпорядкувати Керівнику відділу (роль або сам керівник).');
        }
        if (in_array($uid, $this->ancestors($hid), true)) {
            $this->back($hid, 'error', 'Це створило б замкнене підпорядкування: користувач уже вище в ієрархії.');
        }
        if ($u['manager_id'] !== null && (int) $u['manager_id'] !== $hid && !Access::canManageTeams(Auth::role())) {
            $this->back($hid, 'error', 'У цього користувача вже є керівник — переведення виконує Керівник підрозділу або адміністратор.');
        }
        $pdo->prepare('UPDATE users SET manager_id = :m WHERE id = :id')->execute(['m' => $hid, 'id' => $uid]);
        Team::reset();
        Audit::log('user', $uid, 'manager_set', (int) Auth::id(), ['manager_id' => $hid, 'previous' => $u['manager_id']]);
        $this->back($hid, 'success', $u['full_name'] . ' додано до команди.');
    }

    public function remove(array $params): void
    {
        Auth::requireLogin();
        $head = $this->headOrFail((int) $params['id']);
        $hid = (int) $head['id'];
        $uid = (int) ($_POST['user_id'] ?? 0);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT manager_id, full_name FROM users WHERE id = :id');
        $stmt->execute(['id' => $uid]);
        $u = $stmt->fetch();
        // Знімати можна лише прямого підлеглого цього керівника (вкладених знімає їхній безпосередній керівник).
        if (!$u || (int) $u['manager_id'] !== $hid) {
            $this->back($hid, 'error', 'Користувач не є прямим підлеглим цього керівника.');
        }
        $pdo->prepare('UPDATE users SET manager_id = NULL WHERE id = :id')->execute(['id' => $uid]);
        Team::reset();
        Audit::log('user', $uid, 'manager_cleared', (int) Auth::id(), ['manager_id' => $hid]);
        $this->back($hid, 'success', $u['full_name'] . ' знято з команди.');
    }

    /** Підпорядкування самого Керівника відділу Керівнику підрозділу. */
    public function superior(array $params): void
    {
        Auth::requireLogin();
        if (!Access::canManageTeams(Auth::role())) {
            $this->deny();
        }
        $head = $this->headOrFail((int) $params['id']);
        $hid = (int) $head['id'];
        $sup = (int) ($_POST['superior_id'] ?? 0);
        $pdo = Database::connection();
        if ($sup > 0) {
            $stmt = $pdo->prepare("SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND r.code = 'it_manager' AND u.is_active = 1");
            $stmt->execute(['id' => $sup]);
            if (!$stmt->fetchColumn()) {
                $this->back($hid, 'error', 'Керівником Керівника відділу може бути лише активний Керівник підрозділу.');
            }
        }
        $pdo->prepare('UPDATE users SET manager_id = :m WHERE id = :id')->execute(['m' => $sup > 0 ? $sup : null, 'id' => $hid]);
        Team::reset();
        Audit::log('user', $hid, 'manager_set', (int) Auth::id(), ['manager_id' => $sup > 0 ? $sup : null]);
        $this->back($hid, 'success', 'Підпорядкування збережено.');
    }

    /** Керівник відділу з id або 403/404; доступ: адміністратор, Керівник підрозділу, сам керівник. */
    private function headOrFail(int $id): array
    {
        $role = Auth::role();
        if (!Access::canManageTeams($role) && !(Access::isDeptHead($role) && (int) Auth::id() === $id)) {
            $this->deny();
        }
        if (!Team::ready()) {
            http_response_code(503);
            echo 'Потрібна міграція 063 (запустіть update.sh).';
            exit;
        }
        $stmt = Database::connection()->prepare("SELECT u.*, m.full_name AS superior_name FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN users m ON m.id = u.manager_id WHERE u.id = :id AND r.code = 'dept_head'");
        $stmt->execute(['id' => $id]);
        $head = $stmt->fetch();
        if (!$head) {
            http_response_code(404);
            echo 'Керівника відділу не знайдено.';
            exit;
        }
        return $head;
    }

    /** Ланцюг керівників вгору від користувача (для заборони циклів). @return int[] */
    private function ancestors(int $userId): array
    {
        $out = [];
        $cur = $userId;
        $pdo = Database::connection();
        for ($i = 0; $i < Team::MAX_DEPTH + 2; $i++) {
            $stmt = $pdo->prepare('SELECT manager_id FROM users WHERE id = :id');
            $stmt->execute(['id' => $cur]);
            $m = $stmt->fetchColumn();
            if (!$m || in_array((int) $m, $out, true)) {
                break;
            }
            $out[] = (int) $m;
            $cur = (int) $m;
        }
        return $out;
    }

    private function back(int $headId, string $key, string $msg): void
    {
        header('Location: /team/' . $headId . '?' . $key . '=' . urlencode($msg));
        exit;
    }

    private function deny(): void
    {
        http_response_code(403);
        echo 'Розділ «Команда» доступний Керівнику відділу, Керівнику підрозділу й адміністратору.';
        exit;
    }
}
