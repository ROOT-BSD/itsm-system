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
            "SELECT u.id, u.full_name, u.email, u.is_active,
                    NULL AS direct_count
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE r.code = 'dept_head' ORDER BY u.is_active DESC, u.full_name"
        )->fetchAll() : [];
        $names = $this->managerNames();
        foreach ($heads as &$h) {
            $h['superior_name'] = $names[(int) $h['id']] ?? null;
            $sub = Team::subordinateIds((int) $h['id']);
            $h['total_count'] = count($sub);
            $h['direct_count'] = count(array_filter($sub, static fn($sid) => in_array((int) $h['id'], Team::managerIds($sid), true)));
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

    /** Імена керівників: [user_id => «Іван, Петро»] (для всіх, у кого вони є). */
    private function managerNames(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT id, full_name FROM users')->fetchAll() as $r) {
            $names[(int) $r['id']] = $r['full_name'];
        }
        foreach (array_keys($names ?? []) as $uid) {
            $mg = array_map(static fn($m) => $names[$m] ?? null, Team::managerIds($uid));
            $mg = array_filter($mg);
            if ($mg) {
                $out[$uid] = implode(', ', $mg);
            }
        }
        return $out;
    }

    /** Дочірні вузли дерева; користувач із кількома керівниками з'являється під кожним із них. */
    private function childrenMap(array $users): array
    {
        $children = [];
        foreach ($users as $id => $u) {
            foreach (Team::managerIds((int) $id) as $mid) {
                if (isset($users[$mid])) {
                    $children[$mid][] = $id;
                }
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
            $hasActiveManager = (bool) array_filter(Team::managerIds((int) $id), static fn($m) => isset($users[$m]));
            if ($topHead || ($u['role_code'] === 'dept_head' && !$hasActiveManager)) {
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

    /**
     * Id співробітників підрозділу (OU) поточного користувача, яких він може додавати до команди; null — обмеження немає
     * (глобальний адміністратор або підрозділ користувача не задано). Входять той самий, вкладені й батьківські OU.
     * @return int[]|null
     */
    private function unitScopeIds(): ?array
    {
        if (Access::isAdmin(Auth::role())) {
            return null;
        }
        $ou = \App\Core\Unit::userOu((int) Auth::id());
        if ($ou === null) {
            return null;
        }
        // «Свій підрозділ» — той самий OU, вкладені в нього й батьківські (Керівник відділу може сидіти у вужчому OU, ніж його колеги).
        $lo = mb_strtolower($ou);
        $ids = [];
        foreach (Database::connection()->query('SELECT x.id, ' . \App\Core\Unit::ouExpr('x') . ' AS ou FROM users x')->fetchAll() as $r) {
            $o = mb_strtolower(trim((string) $r['ou']));
            if ($o === '') {
                continue;
            }
            if ($o === $lo || str_ends_with($o, ',' . $lo) || str_ends_with($lo, ',' . $o)) {
                $ids[] = (int) $r['id'];
            }
        }
        return $ids;
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
                "SELECT u.id, u.full_name, u.email, r.name AS role_name,
                        (SELECT COUNT(*) FROM tasks t JOIN task_statuses s ON s.id = t.status_id WHERE t.assignee_id = u.id AND s.is_closed = 0) AS open_tasks,
                        (SELECT COUNT(*) FROM tickets k WHERE (k.requester_user_id = u.id OR k.assigned_operator_id = u.id) AND k.status NOT IN ('resolved','closed')) AS open_tickets,
                        (SELECT COALESCE(SUM(l.hours), 0) FROM time_logs l WHERE l.user_id = u.id AND l.log_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month_hours
                   FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE u.id IN ({$in}) ORDER BY u.full_name"
            )->fetchAll();
            $names = [];
            foreach ($pdo->query('SELECT id, full_name FROM users')->fetchAll() as $n) {
                $names[(int) $n['id']] = $n['full_name'];
            }
            foreach ($rows as &$r) {
                $mids = Team::managerIds((int) $r['id']);
                $r['direct'] = in_array($id, $mids, true);
                $r['others'] = implode(', ', array_filter(array_map(static fn($m) => $m !== $id ? ($names[$m] ?? null) : null, $mids)));
            }
            unset($r);
        }
        // Кого можна додати: активні, не адміністратори/керівники ІТ, не сам керівник, не його керівники (без циклів).
        // Користувач може належати кільком відділам. Якщо в того, хто складає команду, задано підрозділ, він бачить усіх
        // співробітників свого підрозділу (і тих, у кого вже є інший керівник); без підрозділу Керівник відділу бачить
        // лише тих, у кого керівника ще немає.
        $canMove = Access::canManageTeams(Auth::role());
        $exclude = array_merge([$id], $ids, $this->ancestors($id));
        $cands = [];
        $unitIds = $this->unitScopeIds();
        $diag = ['outside' => 0, 'busy' => 0, 'inTeam' => 0];
        $allNames = [];
        foreach ($pdo->query('SELECT id, full_name FROM users')->fetchAll() as $n) {
            $allNames[(int) $n['id']] = $n['full_name'];
        }
        foreach ($pdo->query(
            "SELECT u.id, u.full_name, u.email, r.code AS role_code, r.name AS role_name
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.is_active = 1 ORDER BY u.full_name"
        )->fetchAll() as $u) {
            if (in_array((int) $u['id'], $exclude, true) || in_array($u['role_code'], self::NOT_SUBORDINATE, true)) {
                continue;
            }
            if ($unitIds !== null && !in_array((int) $u['id'], $unitIds, true)) {
                $diag['outside']++;
                continue;
            }
            $mids = Team::managerIds((int) $u['id']);
            if (in_array($id, $mids, true) || ($mids && !$canMove && $unitIds === null)) {
                $diag[in_array($id, $mids, true) ? 'inTeam' : 'busy']++;
                continue;
            }
            $u['manager_name'] = $mids ? implode(', ', array_filter(array_map(static fn($m) => $allNames[$m] ?? null, $mids))) : null;
            $cands[] = $u;
        }
        $superiors = $canMove ? $pdo->query("SELECT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'it_manager' AND u.is_active = 1 ORDER BY u.full_name")->fetchAll() : [];
        $superiorIds = array_values(array_intersect(Team::managerIds($id), array_map(static fn($x) => (int) $x['id'], $superiors)));
        View::render('team/show', [
            'diag' => $diag, 'unitLabel' => $unitIds !== null ? \App\Core\LdapDn::ouLabel((string) \App\Core\Unit::userOu((int) Auth::id())) : null,
            'superiorIds' => $superiorIds, 'managerNames' => array_map(static fn($m) => $allNames[$m] ?? '', Team::managerIds($id)),
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
        $stmt = $pdo->prepare('SELECT u.id, u.full_name, u.is_active, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id');
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
        $unitIds = $this->unitScopeIds();
        if ($unitIds !== null && !in_array($uid, $unitIds, true)) {
            $this->back($hid, 'error', 'Додавати можна лише співробітників вашого підрозділу.');
        }
        $existing = Team::managerIds($uid);
        if (in_array($hid, $existing, true)) {
            $this->back($hid, 'error', 'Користувач уже в цій команді.');
        }
        if ($existing && !Access::canManageTeams(Auth::role()) && $unitIds === null) {
            $this->back($hid, 'error', 'У цього користувача вже є керівник — до другого відділу його додає Керівник підрозділу або адміністратор.');
        }
        Team::link($uid, $hid);
        Audit::log('user', $uid, 'manager_added', (int) Auth::id(), ['manager_id' => $hid, 'other_managers' => implode(',', $existing)]);
        $this->back($hid, 'success', $u['full_name'] . ' додано до команди' . ($existing ? ' (також залишається в інших відділах)' : '') . '.');
    }

    public function remove(array $params): void
    {
        Auth::requireLogin();
        $head = $this->headOrFail((int) $params['id']);
        $hid = (int) $head['id'];
        $uid = (int) ($_POST['user_id'] ?? 0);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT full_name FROM users WHERE id = :id');
        $stmt->execute(['id' => $uid]);
        $name = $stmt->fetchColumn();
        // Знімати можна лише прямого підлеглого цього керівника (вкладених знімає їхній безпосередній керівник);
        // зв'язки з іншими відділами користувача лишаються.
        if ($name === false || !in_array($hid, Team::managerIds($uid), true)) {
            $this->back($hid, 'error', 'Користувач не є прямим підлеглим цього керівника.');
        }
        Team::unlink($uid, $hid);
        Audit::log('user', $uid, 'manager_removed', (int) Auth::id(), ['manager_id' => $hid]);
        $this->back($hid, 'success', $name . ' знято з команди.');
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
        $want = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['superior_ids'] ?? [])), static fn($i) => $i > 0)));
        $pdo = Database::connection();
        $okIds = $want ? array_map('intval', $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'it_manager' AND u.is_active = 1 AND u.id IN (" . implode(',', $want) . ')')->fetchAll(\PDO::FETCH_COLUMN)) : [];
        if (count($okIds) !== count($want)) {
            $this->back($hid, 'error', 'Керівником Керівника відділу може бути лише активний Керівник підрозділу.');
        }
        // змінюємо лише зв'язки з Керівниками підрозділу; підпорядкування іншим Керівникам відділів не чіпаємо
        $allIt = array_map('intval', $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'it_manager'")->fetchAll(\PDO::FETCH_COLUMN));
        $before = array_values(array_intersect(Team::managerIds($hid), $allIt));
        foreach (array_diff($before, $okIds) as $m) {
            Team::unlink($hid, $m);
        }
        foreach (array_diff($okIds, $before) as $m) {
            Team::link($hid, $m);
        }
        Audit::log('user', $hid, 'managers_set', (int) Auth::id(), ['superiors' => implode(',', $okIds), 'previous' => implode(',', $before)]);
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
        $stmt = Database::connection()->prepare("SELECT u.* FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND r.code = 'dept_head'");
        $stmt->execute(['id' => $id]);
        $head = $stmt->fetch();
        if (!$head) {
            http_response_code(404);
            echo 'Керівника відділу не знайдено.';
            exit;
        }
        $names = array_filter(array_map(static function ($m) {
            $s = Database::connection()->prepare('SELECT full_name FROM users WHERE id = :id');
            $s->execute(['id' => $m]);
            return $s->fetchColumn() ?: null;
        }, Team::managerIds($id)));
        $head['superior_name'] = $names ? implode(', ', $names) : null;
        return $head;
    }

    /** Усі керівники вгору по всіх гілках підпорядкування (для заборони циклів). @return int[] */
    private function ancestors(int $userId): array
    {
        $out = [];
        $level = [$userId];
        for ($i = 0; $i < Team::MAX_DEPTH + 2 && $level; $i++) {
            $next = [];
            foreach ($level as $uid) {
                foreach (Team::managerIds($uid) as $m) {
                    if (!in_array($m, $out, true) && $m !== $userId) {
                        $out[] = $m;
                        $next[] = $m;
                    }
                }
            }
            $level = $next;
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
