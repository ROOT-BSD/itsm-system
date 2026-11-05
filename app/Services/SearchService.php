<?php

namespace App\Services;

use App\Core\Access;
use App\Core\Database;
use App\Core\FullText;
use App\Core\Markdown;
use App\Models\Forum;
use App\Models\LibraryDocument;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\WikiPage;

/**
 * Глобальний пошук: проєкти, задачі (з коментарями), тікети (з коментарями), вікі, документи, форум.
 *
 * Права НЕ дублюються й не послаблюються: кожен розділ користується тим самим механізмом видимості, що й власна сторінка
 * (Project::accessCondition для проєктів і задач, Ticket::visibilityCondition для тікетів, вікі / бібліотека / форум — власні методи
 * пошуку з урахуванням ролі). Невидиме користувачу не потрапляє ні в результати, ні в лічильники.
 */
class SearchService
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 100;
    public const PER_SECTION = 10;

    /**
     * @return array<int, array{key: string, title: string, total: int, more: bool, more_url: ?string, items: array<int, array{url: string, title: string, meta: string, snippet: string}>}>
     */
    public static function run(string $query, int $userId, ?string $role): array
    {
        $sections = [];
        foreach (['projects' => 'Проєкти', 'tasks' => 'Задачі', 'tickets' => 'Тікети', 'wiki' => 'Вікі', 'library' => 'Документи', 'forum' => 'Форум'] as $key => $title) {
            try {
                $section = self::$key($query, $userId, $role, true); // спершу повнотекстовий пошук із ранжуванням
                if ($section['total'] === 0 && in_array($key, ['projects', 'tasks', 'tickets'], true) && FullText::boolean($query) !== null) {
                    $section = self::$key($query, $userId, $role, false); // підрядок усередині слова — звичайний LIKE
                }
            } catch (\Throwable) {
                continue; // таблиці ще немає (не виконано update.sh) чи збій розділу — решта пошуку працює
            }
            $sections[] = ['key' => $key, 'title' => $title] + $section;
        }
        return $sections;
    }

    // ---------- розділи ----------

    private static function projects(string $q, int $userId, ?string $role, bool $useFt = false): array
    {
        $like = self::like($q);
        $ft = $useFt && FullText::ready('projects', 'ft_name_description') ? FullText::boolean($q) : null;
        $where = '(' . ($ft !== null ? 'MATCH(p.name, p.description) AGAINST (:f1 IN BOOLEAN MODE)' : 'p.name LIKE :l1 OR p.description LIKE :l2')
            . (($id = self::idOf($q)) ? ' OR p.id = :pid' : '') . ')';
        $params = ($ft !== null ? ['f1' => $ft] : ['l1' => $like, 'l2' => $like]) + ($id ? ['pid' => $id] : []);
        if (!Access::isAdmin($role)) {
            [$acc, $accParams] = Project::accessCondition('p', 'sa', $userId);
            $where .= ' AND ' . $acc;
            $params += $accParams;
        }
        $total = self::count("SELECT COUNT(*) FROM projects p WHERE {$where}", $params);
        $stmt = Database::connection()->prepare(
            "SELECT p.id, p.name, p.status, p.description, ru.full_name AS responsible
             FROM projects p LEFT JOIN users ru ON ru.id = p.responsible_user_id
             WHERE {$where}
             ORDER BY (p.name LIKE :ord) DESC" . ($ft !== null ? ', MATCH(p.name, p.description) AGAINST (:f2 IN BOOLEAN MODE) DESC' : '') . ", p.updated_at DESC LIMIT " . self::PER_SECTION
        );
        $stmt->execute($params + ['ord' => $like] + ($ft !== null ? ['f2' => $ft] : []));
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $items[] = [
                'url' => '/projects/' . (int) $r['id'] . self::hl($q),
                'title' => self::mark((string) $r['name'], $q),
                'meta' => 'Проєкт #' . (int) $r['id'] . ($r['status'] !== 'active' ? ' · ' . self::projectStatus((string) $r['status']) : '') . ($r['responsible'] ? ' · відповідальний: ' . $r['responsible'] : ''),
                'snippet' => !self::has((string) $r['name'], $q) ? self::snippet((string) $r['description'], $q) : '',
            ];
        }
        return self::result($total, $items, null);
    }

    private static function tasks(string $q, int $userId, ?string $role, bool $useFt = false): array
    {
        $like = self::like($q);
        $ft = $useFt && FullText::ready('tasks', 'ft_title_description') && FullText::ready('comments', 'ft_body') ? FullText::boolean($q) : null;
        $where = ($ft !== null
            ? '(MATCH(t.title, t.description) AGAINST (:f1 IN BOOLEAN MODE) OR EXISTS (SELECT 1 FROM comments c WHERE c.task_id = t.id AND MATCH(c.body) AGAINST (:f2 IN BOOLEAN MODE))'
            : '(t.title LIKE :l1 OR t.description LIKE :l2 OR EXISTS (SELECT 1 FROM comments c WHERE c.task_id = t.id AND c.body LIKE :l3)')
            . (($id = self::idOf($q)) ? ' OR t.id = :tid' : '') . ')';
        $params = ($ft !== null ? ['f1' => $ft, 'f2' => $ft] : ['l1' => $like, 'l2' => $like, 'l3' => $like]) + ($id ? ['tid' => $id] : []);
        if (!Access::isAdmin($role)) {
            [$acc, $accParams] = Project::accessCondition('p', 'sa', $userId);
            $where .= ' AND ' . $acc;
            $params += $accParams;
        }
        $from = 'FROM tasks t JOIN projects p ON p.id = t.project_id JOIN task_statuses ts ON ts.id = t.status_id';
        $total = self::count("SELECT COUNT(*) {$from} WHERE {$where}", $params);
        $stmt = Database::connection()->prepare(
            "SELECT t.id, t.title, t.description, p.name AS project_name, ts.name AS status_name, ts.is_closed,
                    (SELECT c2.body FROM comments c2 WHERE c2.task_id = t.id AND " . ($ft !== null ? 'MATCH(c2.body) AGAINST (:f3 IN BOOLEAN MODE)' : 'c2.body LIKE :lc') . " ORDER BY c2.id DESC LIMIT 1) AS comment_hit
             {$from} WHERE {$where}
             ORDER BY (t.title LIKE :ord) DESC" . ($ft !== null ? ', MATCH(t.title, t.description) AGAINST (:f4 IN BOOLEAN MODE) DESC' : '') . ", ts.is_closed ASC, t.updated_at DESC LIMIT " . self::PER_SECTION
        );
        $stmt->execute($params + ['ord' => $like] + ($ft !== null ? ['f3' => $ft, 'f4' => $ft] : ['lc' => $like]));
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $snippet = '';
            if (!self::has((string) $r['title'], $q)) {
                $snippet = self::has((string) $r['description'], $q)
                    ? self::snippet((string) $r['description'], $q)
                    : ($r['comment_hit'] !== null ? 'у коментарі: ' . self::snippet((string) $r['comment_hit'], $q) : '');
            }
            $items[] = [
                'url' => '/tasks/' . (int) $r['id'] . self::hl($q),
                'title' => self::mark((string) $r['title'], $q),
                'meta' => 'Задача #' . (int) $r['id'] . ' · ' . $r['project_name'] . ' · ' . $r['status_name'] . ((int) $r['is_closed'] ? ' (закрита)' : ''),
                'snippet' => $snippet,
            ];
        }
        return self::result($total, $items, null);
    }

    private static function tickets(string $q, int $userId, ?string $role, bool $useFt = false): array
    {
        $like = self::like($q);
        $ft = $useFt && FullText::ready('tickets', 'ft_subject_description') && FullText::ready('ticket_comments', 'ft_body') ? FullText::boolean($q) : null;
        $where = ($ft !== null
            ? '(MATCH(t.subject, t.description) AGAINST (:f1 IN BOOLEAN MODE) OR t.requester_name LIKE :l3 OR EXISTS (SELECT 1 FROM ticket_comments c WHERE c.ticket_id = t.id AND MATCH(c.body) AGAINST (:f2 IN BOOLEAN MODE))'
            : '(t.subject LIKE :l1 OR t.description LIKE :l2 OR t.requester_name LIKE :l3 OR EXISTS (SELECT 1 FROM ticket_comments c WHERE c.ticket_id = t.id AND c.body LIKE :l4)')
            . (($id = self::idOf($q)) ? ' OR t.id = :tid' : '') . ')';
        $params = ($ft !== null ? ['f1' => $ft, 'f2' => $ft, 'l3' => $like] : ['l1' => $like, 'l2' => $like, 'l3' => $like, 'l4' => $like]) + ($id ? ['tid' => $id] : []);
        if (!Access::isAdmin($role)) {
            [$vis, $visParams] = Ticket::visibilityCondition('t', $userId, Access::canSeeUnassignedTickets($role));
            $where .= ' AND ' . $vis;
            $params += $visParams;
        }
        $from = 'FROM tickets t JOIN ticket_queues qu ON qu.id = t.queue_id';
        $total = self::count("SELECT COUNT(*) {$from} WHERE {$where}", $params);
        $stmt = Database::connection()->prepare(
            "SELECT t.id, t.subject, t.description, t.status, t.requester_name, qu.name AS queue_name,
                    (SELECT c2.body FROM ticket_comments c2 WHERE c2.ticket_id = t.id AND " . ($ft !== null ? 'MATCH(c2.body) AGAINST (:f3 IN BOOLEAN MODE)' : 'c2.body LIKE :lc') . " ORDER BY c2.id DESC LIMIT 1) AS comment_hit
             {$from} WHERE {$where}
             ORDER BY (t.subject LIKE :ord) DESC" . ($ft !== null ? ', MATCH(t.subject, t.description) AGAINST (:f4 IN BOOLEAN MODE) DESC' : '') . ", (t.status = 'closed') ASC, t.created_at DESC LIMIT " . self::PER_SECTION
        );
        $stmt->execute($params + ['ord' => $like] + ($ft !== null ? ['f3' => $ft, 'f4' => $ft] : ['lc' => $like]));
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $snippet = '';
            if (!self::has((string) $r['subject'], $q)) {
                $snippet = self::has((string) $r['description'], $q)
                    ? self::snippet((string) $r['description'], $q)
                    : ($r['comment_hit'] !== null ? 'у коментарі: ' . self::snippet((string) $r['comment_hit'], $q) : '');
            }
            $items[] = [
                'url' => '/tickets/' . (int) $r['id'] . self::hl($q),
                'title' => self::mark((string) $r['subject'], $q),
                'meta' => 'Тікет #' . (int) $r['id'] . ' · ' . $r['queue_name'] . ' · ' . \App\Models\Ticket::statusLabel((string) $r['status']) . ' · заявник: ' . $r['requester_name'],
                'snippet' => $snippet,
            ];
        }
        return self::result($total, $items, null);
    }

    private static function wiki(string $q, int $userId, ?string $role, bool $useFt = false): array
    {
        $rows = WikiPage::search($q, $role, self::PER_SECTION + 1);
        $items = [];
        foreach (array_slice($rows, 0, self::PER_SECTION) as $r) {
            $items[] = [
                'url' => '/wiki/' . rawurlencode((string) $r['slug']) . self::hl($q),
                'title' => self::mark((string) $r['title'], $q),
                'meta' => 'Вікі',
                'snippet' => !empty($r['in_title']) ? '' : self::snippet((string) ($r['content'] ?? ''), $q),
            ];
        }
        return self::result(count($rows), $items, '/wiki?q=' . rawurlencode($q), count($rows) > self::PER_SECTION);
    }

    private static function library(string $q, int $userId, ?string $role, bool $useFt = false): array
    {
        $res = LibraryDocument::search($role, $userId, null, $q, 1, self::PER_SECTION, '');
        $items = [];
        foreach ($res['rows'] as $r) {
            $items[] = [
                'url' => '/library/' . (int) $r['id'] . self::hl($q),
                'title' => self::mark((string) $r['title'], $q),
                'meta' => 'Документ' . ($r['category_name'] ? ' · ' . $r['category_name'] : '') . ($r['original_name'] ? ' · ' . $r['original_name'] : ''),
                'snippet' => self::librarySnippet($r, $q),
            ];
        }
        return self::result((int) $res['total'], $items, '/library?q=' . rawurlencode($q));
    }

    private static function forum(string $q, int $userId, ?string $role, bool $useFt = false): array
    {
        $res = Forum::search($role, $q, 1, self::PER_SECTION, $userId);
        $items = [];
        foreach ($res['rows'] as $r) {
            $items[] = [
                'url' => '/forum/topics/' . (int) $r['id'] . self::hl($q),
                'title' => self::mark((string) $r['title'], $q),
                'meta' => 'Форум · ' . $r['board_name'] . ' · відповідей: ' . (int) $r['reply_count'],
                'snippet' => '',
            ];
        }
        return self::result((int) $res['total'], $items, '/forum/search?q=' . rawurlencode($q));
    }

    // ---------- допоміжне ----------

    private static function result(int $total, array $items, ?string $moreUrl, ?bool $more = null): array
    {
        return ['total' => $total, 'more' => $more ?? $total > count($items), 'more_url' => $moreUrl, 'items' => $items];
    }

    private static function count(string $sql, array $params): int
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Шаблон LIKE з екрануванням % _ \ — запит «50%» шукає саме «50%». */
    private static function like(string $q): string
    {
        return '%' . addcslashes($q, '\\%_') . '%';
    }

    /** «#42» чи «42» — ще й пошук за номером. */
    private static function idOf(string $q): int
    {
        return preg_match('/^#?(\d{1,9})$/', $q, $m) ? (int) $m[1] : 0;
    }

    private static function projectStatus(string $s): string
    {
        return ['archived' => 'в архіві', 'closed' => 'закритий'][$s] ?? $s;
    }

    /** Фрагмент для документа: з опису, а якщо там збігу немає — з тексту самого файлу («у файлі: …»). */
    public static function librarySnippet(array $row, string $q): string
    {
        if (self::has((string) $row['title'], $q)) {
            return '';
        }
        $fromDescription = self::snippet((string) ($row['description'] ?? ''), $q);
        if ($fromDescription !== '' || empty($row['version_id'])) {
            return $fromDescription;
        }
        $text = LibraryDocument::versionText((int) $row['version_id']);
        $hits = $text === null ? [] : self::contentSnippets($text, $q, 1);
        return $hits ? 'у файлі: ' . $hits[0] : '';
    }

    /**
     * Фрагменти з тексту файлу навколо збігів (готовий безпечний HTML, до $max штук, не перекриваються).
     * @return string[]
     */
    public static function contentSnippets(string $text, string $q, int $max = 5): array
    {
        $re = self::pattern($q);
        if ($re === null || !preg_match_all($re, $text, $all, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $out = [];
        $lastEnd = -1;
        foreach ($all[0] as [$hit, $byte]) {
            $pos = mb_strlen(substr($text, 0, $byte));
            if ($pos < $lastEnd) {
                continue;
            }
            $len = mb_strlen($hit);
            $start = max(0, $pos - 70);
            $end = $pos + $len + 100;
            $before = preg_replace('/\s+/u', ' ', mb_substr($text, $start, $pos - $start));
            $after = preg_replace('/\s+/u', ' ', mb_substr($text, $pos + $len, 100));
            $out[] = ($start > 0 ? '…' : '') . $esc($before) . '<mark>' . $esc($hit) . '</mark>' . $esc($after) . ($end < mb_strlen($text) ? '…' : '');
            $lastEnd = $end;
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /** Параметр для переходу до збігу на сторінці знайденого запису (див. public/assets/js/search-highlight.js). */
    private static function hl(string $q): string
    {
        return '?hl=' . rawurlencode($q);
    }

    /** Регулярний вираз збігу: спершу вся фраза, потім окремі слова (від 2 символів). */
    private static function pattern(string $q): ?string
    {
        $alts = [preg_quote($q, '/')];
        foreach (FullText::terms($q) as $t) {
            if (mb_strlen($t) >= 2 && mb_strtolower($t) !== mb_strtolower($q)) {
                $alts[] = preg_quote($t, '/');
            }
        }
        return $q === '' ? null : '/' . implode('|', $alts) . '/iu';
    }

    private static function has(string $text, string $q): bool
    {
        $re = self::pattern($q);
        return $re !== null && preg_match($re, $text) === 1;
    }

    /** HTML із виділеним збігом (фраза чи окремі слова); кожен шматок екранується окремо. */
    private static function mark(string $text, string $q): string
    {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $re = self::pattern($q);
        $parts = $re === null ? false : preg_split($re === null ? '//' : $re, $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
        if (!$parts || count($parts) < 2) {
            return $esc($text);
        }
        $out = '';
        $pos = 0;
        foreach ($parts as [$piece, $off]) {
            if ($off > $pos) {
                $out .= '<mark>' . $esc(substr($text, $pos, $off - $pos)) . '</mark>';
            }
            $out .= $esc($piece);
            $pos = $off + strlen($piece);
        }
        if ($pos < strlen($text)) {
            $out .= '<mark>' . $esc(substr($text, $pos)) . '</mark>';
        }
        return $out;
    }

    /** Фрагмент навколо першого збігу (готовий безпечний HTML); порожній, якщо збігу в тексті немає. */
    private static function snippet(string $text, string $q): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', Markdown::plainText($text)) ?? '');
        $re = self::pattern($q);
        if ($re === null || !preg_match($re, $plain, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $pos = mb_strlen(substr($plain, 0, $m[0][1]));
        $len = mb_strlen($m[0][0]);
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $start = max(0, $pos - 60);
        return ($start > 0 ? '…' : '') . $esc(mb_substr($plain, $start, $pos - $start))
            . '<mark>' . $esc(mb_substr($plain, $pos, $len)) . '</mark>'
            . $esc(mb_substr($plain, $pos + $len, 80))
            . (mb_strlen($plain) > $pos + $len + 80 ? '…' : '');
    }
}
