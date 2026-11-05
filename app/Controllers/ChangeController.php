<?php

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\View;
use App\Models\Audit;
use App\Models\ChangeRequest;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\MailerService;

/**
 * Запити на зміни (Change) із погодженням: список, створення, надсилання на погодження, рішення погоджувачів, виконання.
 * Права — у ChangeRequest (видимість, canEdit/canRun/canCancel, myPendingApproval); кожна дія пишеться в журнал аудиту.
 */
class ChangeController
{
    public function __construct()
    {
        Auth::requireLogin();
        if (!Access::canUseChanges(Auth::role())) {
            http_response_code(403);
            echo 'Запити на зміни доступні персоналу й керівникам.';
            exit;
        }
    }

    private function uid(): int
    {
        return (int) Auth::id();
    }

    private function load(int $id): array
    {
        if (!ChangeRequest::ready()) {
            http_response_code(503);
            echo 'Розділ ще не налаштовано: виконайте sudo bash update.sh (міграція 069).';
            exit;
        }
        $c = ChangeRequest::find($id);
        if (!$c || !ChangeRequest::canView($c, $this->uid(), Auth::role())) {
            http_response_code(404);
            echo 'Запит на зміну не знайдено.';
            exit;
        }
        return $c;
    }

    private function back(int $id, string $key, string $msg): never
    {
        header('Location: /changes/' . $id . '?' . $key . '=' . rawurlencode($msg));
        exit;
    }

    // ------------------------------------------------------------------ список й перегляд

    public function index(): void
    {
        $ready = ChangeRequest::ready();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $f = ['status' => (string) ($_GET['status'] ?? ''), 'scope' => (string) ($_GET['scope'] ?? ''), 'q' => trim((string) ($_GET['q'] ?? ''))];
        $res = $ready ? ChangeRequest::search($this->uid(), Auth::role(), $f, $page) : ['rows' => [], 'total' => 0];
        View::render('changes/index', [
            'ready' => $ready, 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => 25, 'f' => $f,
            'pending' => $ready ? ChangeRequest::pendingFor($this->uid()) : 0,
            'error' => $_GET['error'] ?? null, 'success' => $_GET['success'] ?? null,
        ]);
    }

    public function show(array $params): void
    {
        $c = $this->load((int) $params['id']);
        $uid = $this->uid();
        $role = Auth::role();
        View::render('changes/show', [
            'c' => $c, 'approvals' => ChangeRequest::approvals((int) $c['id']), 'comments' => ChangeRequest::comments((int) $c['id']),
            'overlaps' => ChangeRequest::overlapping($c),
            'canEdit' => ChangeRequest::canEdit($c, $uid, $role), 'canRun' => ChangeRequest::canRun($c, $uid, $role),
            'canCancel' => ChangeRequest::canCancel($c, $uid, $role), 'myApproval' => ChangeRequest::myPendingApproval($c, $uid),
            'eligible' => ChangeRequest::eligibleApprovers(),
            'defaults' => ChangeRequest::defaultApprovers((int) $c['requester_id'], (string) $c['risk']),
            'error' => $_GET['error'] ?? null, 'success' => $_GET['success'] ?? null,
        ]);
    }

    // ------------------------------------------------------------------ створення й зміна

    public function create(): void
    {
        View::render('changes/form', ['c' => ['change_type' => 'normal', 'risk' => 'medium', 'ticket_id' => (int) ($_GET['ticket'] ?? 0) ?: null], 'isNew' => true, 'error' => $_GET['error'] ?? null]);
    }

    public function edit(array $params): void
    {
        $c = $this->load((int) $params['id']);
        if (!ChangeRequest::canEdit($c, $this->uid(), Auth::role())) {
            $this->back((int) $c['id'], 'error', 'Змінювати можна лише чернетку чи відхилений запит, і лише автору.');
        }
        View::render('changes/form', ['c' => $c, 'isNew' => false, 'error' => $_GET['error'] ?? null]);
    }

    /** Розбір полів форми; повертає [дані, помилка]. */
    private function parse(): array
    {
        $dt = static function (string $v): ?string {
            $v = trim($v);
            return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v) ? str_replace('T', ' ', $v) . ':00' : null;
        };
        $txt = static fn(string $k, int $max): string => mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST[$k] ?? ''))), 0, $max);
        $d = [
            'title' => mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200),
            'change_type' => array_key_exists((string) ($_POST['change_type'] ?? ''), ChangeRequest::TYPES) ? $_POST['change_type'] : 'normal',
            'risk' => array_key_exists((string) ($_POST['risk'] ?? ''), ChangeRequest::RISKS) ? $_POST['risk'] : 'medium',
            'description' => $txt('description', 10000), 'reason' => $txt('reason', 5000), 'impact' => $txt('impact', 5000), 'rollback_plan' => $txt('rollback_plan', 5000),
            'window_start' => $dt((string) ($_POST['window_start'] ?? '')), 'window_end' => $dt((string) ($_POST['window_end'] ?? '')),
            'ticket_id' => null,
        ];
        $ticket = (int) ltrim(trim((string) ($_POST['ticket_id'] ?? '')), '#');
        if ($ticket > 0) {
            $st = \App\Core\Database::connection()->prepare('SELECT id FROM tickets WHERE id = :id');
            $st->execute(['id' => $ticket]);
            if ($st->fetchColumn() === false) {
                return [$d, 'Тікет №' . $ticket . ' не знайдено.'];
            }
            $d['ticket_id'] = $ticket;
        }
        if ($d['title'] === '' || $d['description'] === '') {
            return [$d, 'Вкажіть назву й опис зміни.'];
        }
        return [$d, null];
    }

    public function store(): void
    {
        if (!ChangeRequest::ready()) {
            $this->load(0);
        }
        [$d, $err] = $this->parse();
        if ($err !== null) {
            View::render('changes/form', ['c' => $d, 'isNew' => true, 'error' => $err]);
            return;
        }
        $id = ChangeRequest::save(null, $d, $this->uid());
        Audit::log('change', $id, 'created', $this->uid(), ['title' => $d['title'], 'type' => $d['change_type'], 'risk' => $d['risk']]);
        header('Location: /changes/' . $id . '?success=' . rawurlencode('Чернетку збережено. Перевірте дані й надішліть на погодження.'));
        exit;
    }

    public function update(array $params): void
    {
        $c = $this->load((int) $params['id']);
        if (!ChangeRequest::canEdit($c, $this->uid(), Auth::role())) {
            $this->back((int) $c['id'], 'error', 'Змінювати можна лише чернетку чи відхилений запит, і лише автору.');
        }
        [$d, $err] = $this->parse();
        if ($err !== null) {
            View::render('changes/form', ['c' => $d + ['id' => $c['id']], 'isNew' => false, 'error' => $err]);
            return;
        }
        ChangeRequest::save((int) $c['id'], $d, $this->uid());
        Audit::log('change', (int) $c['id'], 'updated', $this->uid(), ['title' => $d['title']]);
        $this->back((int) $c['id'], 'success', 'Збережено.');
    }

    // ------------------------------------------------------------------ погодження

    public function submit(array $params): void
    {
        $c = $this->load((int) $params['id']);
        if (!ChangeRequest::canEdit($c, $this->uid(), Auth::role())) {
            $this->back((int) $c['id'], 'error', 'Надіслати на погодження може лише автор чернетки чи відхиленого запиту.');
        }
        $eligible = array_map('intval', array_column(ChangeRequest::eligibleApprovers(), 'id'));
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['approvers'] ?? [])), fn($i) => in_array($i, $eligible, true) && $i !== (int) $c['requester_id'])));
        if (!$ids) {
            $ids = ChangeRequest::defaultApprovers((int) $c['requester_id'], (string) $c['risk']);
        }
        if ($problems = ChangeRequest::submitProblems($c, $ids)) {
            $this->back((int) $c['id'], 'error', 'Не можна надіслати: ' . implode('; ', $problems) . '.');
        }
        ChangeRequest::submit((int) $c['id'], $ids);
        Audit::log('change', (int) $c['id'], 'submitted', $this->uid(), ['approvers' => implode(',', $ids)]);
        foreach ($ids as $aid) {
            $this->notify($aid, 'Зміна №' . $c['id'] . ' чекає вашого погодження', $c['title'] . ' — вікно ' . $this->windowText($c) . ', ризик: ' . ChangeRequest::RISKS[$c['risk']], (int) $c['id'], true);
        }
        $this->back((int) $c['id'], 'success', 'Надіслано на погодження (' . count($ids) . ').');
    }

    public function decide(array $params): void
    {
        $c = $this->load((int) $params['id']);
        $mine = ChangeRequest::myPendingApproval($c, $this->uid());
        if ($mine === null) {
            $this->back((int) $c['id'], 'error', 'Ви не можете ухвалити рішення щодо цього запиту (вже ухвалено, запит не на погодженні чи ви не погоджувач).');
        }
        $approve = ($_POST['decision'] ?? '') === 'approve';
        $comment = mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 500);
        if (!$approve && $comment === '') {
            $this->back((int) $c['id'], 'error', 'Вкажіть причину відмови.');
        }
        $status = ChangeRequest::decide((int) $c['id'], $this->uid(), $approve, $comment);
        Audit::log('change', (int) $c['id'], $approve ? 'approved_by' : 'rejected_by', $this->uid(), ['status' => $status]);
        $who = Auth::name();
        $req = (int) $c['requester_id'];
        if ($status === 'pending') {
            $this->notify($req, 'Зміна №' . $c['id'] . ': ' . $who . ' погодив(ла)', $c['title'], (int) $c['id'], false);
            $msg = 'Ваше рішення збережено; чекаємо решту погоджувачів.';
        } else {
            $this->notify($req, 'Зміна №' . $c['id'] . ($status === 'approved' ? ' погоджена' : ' відхилена'),
                $c['title'] . ($approve ? '' : ' — причина: ' . $comment) . ' (' . $who . ')', (int) $c['id'], true);
            $msg = $status === 'approved' ? 'Запит погоджено.' : 'Запит відхилено.';
        }
        $this->back((int) $c['id'], 'success', $msg);
    }

    // ------------------------------------------------------------------ виконання

    public function transition(array $params): void
    {
        $c = $this->load((int) $params['id']);
        $to = (string) ($_POST['to'] ?? '');
        $labels = ['in_progress' => 'розпочато виконання', 'implemented' => 'виконано', 'rolled_back' => 'відкочено', 'cancelled' => 'скасовано'];
        if (!isset($labels[$to])) {
            $this->back((int) $c['id'], 'error', 'Невідома дія.');
        }
        $allowed = $to === 'cancelled' ? ChangeRequest::canCancel($c, $this->uid(), Auth::role()) : ChangeRequest::canRun($c, $this->uid(), Auth::role());
        if (!$allowed || !ChangeRequest::transition((int) $c['id'], $to)) {
            $this->back((int) $c['id'], 'error', 'Ця дія недоступна у поточному статусі чи для вашої ролі.');
        }
        Audit::log('change', (int) $c['id'], $to, $this->uid(), []);
        $targets = array_unique(array_merge([(int) $c['requester_id']], array_map(fn($a) => (int) $a['approver_id'], ChangeRequest::approvals((int) $c['id']))));
        foreach ($targets as $t) {
            if ($t > 0 && $t !== $this->uid()) {
                $this->notify($t, 'Зміна №' . $c['id'] . ': ' . $labels[$to], $c['title'], (int) $c['id'], false);
            }
        }
        $this->back((int) $c['id'], 'success', 'Статус: ' . ChangeRequest::STATUSES[$to] . '.');
    }

    public function comment(array $params): void
    {
        $c = $this->load((int) $params['id']);
        $body = mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? ''))), 0, 5000);
        if ($body === '') {
            $this->back((int) $c['id'], 'error', 'Введіть текст коментаря.');
        }
        ChangeRequest::addComment((int) $c['id'], $this->uid(), $body);
        $targets = array_unique(array_merge([(int) $c['requester_id']], array_map(fn($a) => (int) $a['approver_id'], ChangeRequest::approvals((int) $c['id']))));
        foreach ($targets as $t) {
            if ($t > 0 && $t !== $this->uid()) {
                $this->notify($t, 'Зміна №' . $c['id'] . ': новий коментар', Auth::name() . ': ' . mb_substr($body, 0, 150), (int) $c['id'], false);
            }
        }
        header('Location: /changes/' . (int) $c['id'] . '?success=' . rawurlencode('Коментар додано.') . '#comments');
        exit;
    }

    // ------------------------------------------------------------------ допоміжне

    private function windowText(array $c): string
    {
        return \App\Core\TimeFormat::dateTime((string) $c['window_start']) . ' – ' . \App\Core\TimeFormat::dateTime((string) $c['window_end']);
    }

    /** Сповіщення в центрі (категорія «changes») і, за потреби, лист. Збій не ламає дію. */
    private function notify(int $userId, string $title, string $body, int $changeId, bool $mail): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $url = '/changes/' . $changeId;
            UserNotification::notify($userId, $title, $body, $url, 'changes');
            if ($mail) {
                $u = User::findById($userId);
                if ($u && $u['is_active'] && !empty($u['email'])) {
                    MailerService::send($u['email'], $u['full_name'], $title, "Доброго дня, {$u['full_name']}!\n\n{$title}.\n{$body}\n\nПереглянути:\n" . Setting::appUrl() . $url . "\n\nЦе автоматичне повідомлення.");
                }
            }
        } catch (\Throwable) {
        }
    }
}
