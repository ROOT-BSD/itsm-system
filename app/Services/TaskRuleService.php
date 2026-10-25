<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\Task;
use App\Models\TaskRule;
use App\Models\UserNotification;

/**
 * Автоматичні правила для задач. Події: created (створення задачі, будь-яким шляхом), status (зміна статусу, опційно на конкретний),
 * comment (новий запис у журналі робіт задачі) і due (зміна терміну вручну).
 * Для події правила перевіряються за порядком (sort_order, id); виконується перше, що збіглося.
 * Умови: ключові слова в назві/описі, проєкт, пріоритет, виконавець (призначений / не призначений).
 * Дії: призначити виконавця, встановити пріоритет, сповістити «в системі» (виконавця та/або вибраних працівників).
 * Дії правил не запускають інших правил (виконавець і пріоритет ставляться напряму).
 */
class TaskRuleService
{
    public const EVENTS = ['created' => 'створення задачі', 'status' => 'зміна статусу', 'comment' => 'новий запис у журналі робіт', 'due' => 'зміна терміну'];

    public static string $lastNote = '';
    /** Захист від зациклення: дія правила (запис у журнал) не запускає інших правил. */
    private static bool $running = false;

    /** @param array<string,mixed> $t задача (рядок з БД) */
    public static function matches(array $rule, array $t, string $event, ?int $statusId): bool
    {
        if ($rule['trigger_event'] !== $event) {
            return false;
        }
        if ($event === 'status' && !empty($rule['when_status_id']) && (int) $rule['when_status_id'] !== (int) $statusId) {
            return false;
        }
        if (!empty($rule['project_id']) && (int) $rule['project_id'] !== (int) $t['project_id']) {
            return false;
        }
        if (!empty($rule['cond_priority']) && $rule['cond_priority'] !== ($t['priority'] ?? 'normal')) {
            return false;
        }
        $has = !empty($t['assignee_id']);
        if (($rule['cond_assignee'] === 'assigned' && !$has) || ($rule['cond_assignee'] === 'unassigned' && $has)) {
            return false;
        }
        $kws = TicketRuleService::parseKeywords((string) $rule['keywords']);
        if (!$kws) {
            return true;
        }
        $hay = match ($rule['match_in']) {
            'title' => (string) $t['title'],
            'description' => (string) ($t['description'] ?? ''),
            default => $t['title'] . "\n" . ($t['description'] ?? ''),
        };
        $hay = (string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', str_replace("\u{00A0}", ' ', $hay)));
        foreach ($kws as $kw) {
            if (mb_stripos($hay, (string) preg_replace('/\s+/u', ' ', $kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Перше правило, що збіглося (для застосування та «перевірки на прикладі»). */
    public static function find(array $t, string $event, ?int $statusId): ?array
    {
        foreach (TaskRule::active($event) as $rule) {
            if (self::matches($rule, $t, $event, $statusId)) {
                return $rule;
            }
        }
        return null;
    }

    /** @return int[] */
    public static function systemUserIds(array $rule): array
    {
        return TicketRuleService::systemUserIds($rule);
    }

    /** Автор запису журналу від імені правила: той, хто створив правило, якщо він активний; інакше перший активний адміністратор. */
    private static function commentAuthor(array $rule): ?int
    {
        $db = \App\Core\Database::connection();
        if (!empty($rule['created_by'])) {
            $st = $db->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1');
            $st->execute(['id' => (int) $rule['created_by']]);
            if (($id = $st->fetchColumn()) !== false) {
                return (int) $id;
            }
        }
        $id = $db->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code = 'admin' ORDER BY u.id LIMIT 1")->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Застосовує правила до задачі. Помилки правил роботу не ламають. */
    public static function apply(int $taskId, string $event, ?int $statusId = null): void
    {
        self::$lastNote = '';
        if (self::$running) {
            return;
        }
        try {
            if (!TaskRule::ready()) {
                return;
            }
            $t = Task::find($taskId);
            if (!$t) {
                return;
            }
            $rule = self::find($t, $event, $statusId ?? (int) $t['status_id']);
            if (!$rule) {
                return;
            }
            $db = \App\Core\Database::connection();
            $assigneeChanged = !empty($rule['action_assignee_id']) && (int) $rule['action_assignee_id'] !== (int) ($t['assignee_id'] ?? 0);
            if ($assigneeChanged) {
                $db->prepare('UPDATE tasks SET assignee_id = :a WHERE id = :id')->execute(['a' => (int) $rule['action_assignee_id'], 'id' => $taskId]);
                $t['assignee_id'] = (int) $rule['action_assignee_id'];
            }
            $prioChanged = !empty($rule['action_priority']) && $rule['action_priority'] !== ($t['priority'] ?? 'normal');
            if ($prioChanged) {
                $db->prepare('UPDATE tasks SET priority = :p WHERE id = :id')->execute(['p' => $rule['action_priority'], 'id' => $taskId]);
            }
            $labelAdded = !empty($rule['action_label_id']) && \App\Models\TaskLabel::add($taskId, (int) $rule['action_label_id']);
            $commented = false;
            if (!empty($rule['action_comment'])) {
                $author = self::commentAuthor($rule);
                if ($author !== null) {
                    self::$running = true;
                    try {
                        Task::addEntry($taskId, $author, 'Правило «' . $rule['name'] . '»: ' . $rule['action_comment'], 0.0, date('Y-m-d'), null);
                        $commented = true;
                    } finally {
                        self::$running = false;
                    }
                }
            }
            TaskRule::markHit((int) $rule['id']);
            Audit::log('task', $taskId, 'rule_applied', null, [
                'rule_id' => (int) $rule['id'], 'rule' => $rule['name'], 'event' => $event,
                'assignee_id' => $assigneeChanged ? (int) $rule['action_assignee_id'] : null, 'priority' => $prioChanged ? $rule['action_priority'] : null,
                'label_id' => $labelAdded ? (int) $rule['action_label_id'] : null, 'comment' => $commented,
            ]);
            $ids = self::systemUserIds($rule);
            if (!empty($rule['notify_system_assignee']) && !empty($t['assignee_id'])) {
                $ids[] = (int) $t['assignee_id'];
            }
            foreach (array_unique($ids) as $uid) {
                UserNotification::notify($uid, 'Задача №' . $taskId . ': спрацювало правило «' . $rule['name'] . '»',
                    'Задача №' . $taskId . ' — ' . (['status' => 'змінено статус', 'comment' => 'новий запис у журналі робіт', 'due' => 'змінено термін'][$event] ?? 'нова задача') . '. ' . $t['title'], '/tasks/' . $taskId);
            }
            self::$lastNote = "правило «{$rule['name']}»";
        } catch (\Throwable $e) {
            error_log('Правила задач: ' . $e->getMessage());
        }
    }
}
