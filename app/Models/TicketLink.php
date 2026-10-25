<?php

namespace App\Models;

use App\Core\Database;

/**
 * Пов'язані та дубльовані тікети (міграція 041). 'related' — симетричний зв'язок (один рядок, менший id першим);
 * 'duplicate' — спрямований: ticket_id є дублем linked_id (оригіналу). Тікет — дубль щонайбільше одного оригіналу,
 * оригінал сам не може бути дублем, тож групи завжди «оригінал + його дублі» без ланцюжків і циклів.
 */
class TicketLink
{
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = (int) Database::connection()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_links'"
            )->fetchColumn() > 0;
        }
        return $ready;
    }

    /**
     * Зв'язки тікета, згруповані: оригінал (якщо цей тікет — дубль), дублі цього тікета, пов'язані.
     *
     * @return array{original: ?array<string, mixed>, duplicates: array<int, array<string, mixed>>, related: array<int, array<string, mixed>>}
     */
    public static function forTicket(int $ticketId): array
    {
        $out = ['original' => null, 'duplicates' => [], 'related' => []];
        if (!self::ready()) {
            return $out;
        }
        $stmt = Database::connection()->prepare(
            "SELECT l.id AS link_id, l.link_type, l.ticket_id, l.linked_id, t.id, t.subject, t.status, t.requester_user_id, t.assigned_operator_id
             FROM ticket_links l
             JOIN tickets t ON t.id = IF(l.ticket_id = :a, l.linked_id, l.ticket_id)
             WHERE l.ticket_id = :b OR l.linked_id = :c
             ORDER BY t.id"
        );
        $stmt->execute(['a' => $ticketId, 'b' => $ticketId, 'c' => $ticketId]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['link_type'] === 'related') {
                $out['related'][] = $row;
            } elseif ((int) $row['ticket_id'] === $ticketId) {
                $out['original'] = $row;
            } else {
                $out['duplicates'][] = $row;
            }
        }
        return $out;
    }

    /**
     * Тікети, схожі на цей за темою (можливі дублі): за останні 180 днів, крім самого тікета й уже пов'язаних.
     * Слова теми ≥ 4 літер (і 3-літерні на кшталт VPN) зводяться до основи, збіг у темі важить 3, в описі — 1; потрібен збіг щонайменше двох балів.
     * Доступ до тікетів перевіряє викликач.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function similar(array $ticket, int $limit = 5): array
    {
        $self = (int) $ticket['id'];
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $ticket['subject']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $len = mb_strlen($w);
            if ($len >= 4 || ($len === 3 && !in_array($w, ['для', 'що', 'як', 'або', 'але', 'при', 'над', 'під', 'про', 'між', 'без', 'чи', 'нам', 'вам'], true))) {
                $words[mb_substr($w, 0, $len >= 7 ? $len - 2 : ($len >= 5 ? $len - 1 : $len))] = true;
            }
        }
        $words = array_slice(array_keys($words), 0, 8);
        if (!$words) {
            return [];
        }
        $like = [];
        $args = [];
        foreach ($words as $w) {
            $like[] = '(t.subject LIKE ? OR t.description LIKE ?)';
            $pat = '%' . addcslashes($w, '%_\\') . '%';
            array_push($args, $pat, $pat);
        }
        $linked = [$self];
        if (self::ready()) {
            $st = Database::connection()->prepare('SELECT IF(ticket_id = ?, linked_id, ticket_id) FROM ticket_links WHERE ticket_id = ? OR linked_id = ?');
            $st->execute([$self, $self, $self]);
            $linked = array_merge($linked, array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)));
        }
        $in = implode(',', array_map('intval', $linked));
        $stmt = Database::connection()->prepare(
            'SELECT t.id, t.subject, t.description, t.status, t.created_at, t.requester_user_id, t.assigned_operator_id FROM tickets t
             WHERE t.id NOT IN (' . $in . ') AND t.created_at >= (NOW() - INTERVAL 180 DAY) AND (' . implode(' OR ', $like) . ')
             ORDER BY t.id DESC LIMIT 200'
        );
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $subject = mb_strtolower((string) $row['subject']);
            $desc = mb_strtolower(mb_substr((string) $row['description'], 0, 3000));
            $score = 0;
            foreach ($words as $w) {
                $score += (mb_strpos($subject, $w) !== false ? 3 : 0) + (mb_strpos($desc, $w) !== false ? 1 : 0);
            }
            if ($score >= 2) {
                unset($row['description']);
                $row['score'] = $score;
                $out[] = $row;
            }
        }
        usort($out, static fn(array $a, array $b): int => [$b['score'], $b['id']] <=> [$a['score'], $a['id']]);
        return $out;
    }

    /**
     * Зв'язки для заявника на порталі. Чужі дані не розкриваються: про оригінал-дубль — лише статус, про приєднані дублі — кількість,
     * про пов'язані — тема й статус тільки тих звернень, що подані з тією самою адресою.
     *
     * @return array{original_status: ?string, duplicates: int, related: array<int, array{id: int, subject: string, status: string}>}
     */
    public static function forRequester(array $ticket): array
    {
        $out = ['original_status' => null, 'duplicates' => 0, 'related' => []];
        if (!self::ready()) {
            return $out;
        }
        $links = self::forTicket((int) $ticket['id']);
        if ($links['original']) {
            $out['original_status'] = (string) $links['original']['status'];
        }
        $out['duplicates'] = count($links['duplicates']);
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $links['related']);
        if ($ids) {
            $stmt = Database::connection()->prepare('SELECT id, subject, status FROM tickets WHERE id IN (' . implode(',', $ids) . ') AND requester_email = :e ORDER BY id');
            $stmt->execute(['e' => $ticket['requester_email']]);
            foreach ($stmt->fetchAll() as $r) {
                $out['related'][] = ['id' => (int) $r['id'], 'subject' => (string) $r['subject'], 'status' => (string) $r['status']];
            }
        }
        return $out;
    }

    /** id дублів оригіналу. @return int[] */
    public static function duplicateIds(int $originalId): array
    {
        if (!self::ready()) {
            return [];
        }
        $stmt = Database::connection()->prepare("SELECT ticket_id FROM ticket_links WHERE linked_id = :id AND link_type = 'duplicate' ORDER BY ticket_id");
        $stmt->execute(['id' => $originalId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** id оригіналу, якщо тікет — дубль. */
    public static function originalOf(int $ticketId): ?int
    {
        if (!self::ready()) {
            return null;
        }
        $stmt = Database::connection()->prepare("SELECT linked_id FROM ticket_links WHERE ticket_id = :id AND link_type = 'duplicate'");
        $stmt->execute(['id' => $ticketId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** Оригінали для списку тікетів-дублів: [id дубля => id оригіналу]. @param int[] $ids @return array<int, int> */
    public static function originalsFor(array $ids): array
    {
        if (!self::ready() || !$ids) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = Database::connection()->query("SELECT ticket_id, linked_id FROM ticket_links WHERE link_type = 'duplicate' AND ticket_id IN ({$in})")->fetchAll();
        return array_map('intval', array_column($rows, 'linked_id', 'ticket_id'));
    }

    /** Скільки дублів у кожного з наведених тікетів-оригіналів. @param int[] $ids @return array<int, int> */
    public static function duplicateCounts(array $ids): array
    {
        if (!self::ready() || !$ids) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = Database::connection()->query(
            "SELECT linked_id, COUNT(*) AS n FROM ticket_links WHERE link_type = 'duplicate' AND linked_id IN ({$in}) GROUP BY linked_id"
        )->fetchAll();
        return array_column($rows, 'n', 'linked_id');
    }

    /**
     * Додає зв'язок. $kind: 'related' | 'duplicate_of' (тікет $a — дубль $b) | 'has_duplicate' ($b — дубль $a).
     * Повертає текст помилки або null.
     */
    public static function add(int $a, int $b, string $kind, int $userId): ?string
    {
        if (!self::ready()) {
            return 'Потрібна міграція 041 (update.sh)';
        }
        if ($a === $b) {
            return 'Не можна зв\'язати тікет із самим собою';
        }
        $pdo = Database::connection();
        $pair = $pdo->prepare('SELECT COUNT(*) FROM ticket_links WHERE (ticket_id = :a AND linked_id = :b) OR (ticket_id = :b2 AND linked_id = :a2)');
        $pair->execute(['a' => $a, 'b' => $b, 'b2' => $b, 'a2' => $a]);
        if ((int) $pair->fetchColumn() > 0) {
            return 'Ці тікети вже пов\'язані — спершу приберіть наявний зв\'язок';
        }

        if ($kind === 'related') {
            [$lo, $hi] = $a < $b ? [$a, $b] : [$b, $a];
            self::insert($lo, $hi, 'related', $userId);
            Audit::log('ticket', $a, 'link_related_added', $userId, ['ticket' => $b]);
            Audit::log('ticket', $b, 'link_related_added', $userId, ['ticket' => $a]);
            return null;
        }

        [$dup, $orig] = $kind === 'duplicate_of' ? [$a, $b] : [$b, $a];
        if (self::originalOf($orig) !== null) {
            return 'Тікет №' . $orig . ' сам є дублем тікета №' . self::originalOf($orig) . ' — вкажіть як оригінал №' . self::originalOf($orig);
        }
        if (self::originalOf($dup) !== null) {
            return 'Тікет №' . $dup . ' уже є дублем тікета №' . self::originalOf($dup) . ' — спершу приберіть цей зв\'язок';
        }

        $pdo->beginTransaction();
        try {
            // Якщо у «дубля» були власні дублі — вони переходять до нового оригіналу (без ланцюжків).
            $moved = self::duplicateIds($dup);
            if ($moved) {
                $pdo->prepare("UPDATE ticket_links SET linked_id = :o WHERE linked_id = :d AND link_type = 'duplicate'")->execute(['o' => $orig, 'd' => $dup]);
            }
            self::insert($dup, $orig, 'duplicate', $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Audit::log('ticket', $dup, 'link_duplicate_of_added', $userId, ['original' => $orig]);
        Audit::log('ticket', $orig, 'link_has_duplicate_added', $userId, ['duplicate' => $dup]);
        return null;
    }

    public static function find(int $linkId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ticket_links WHERE id = :id');
        $stmt->execute(['id' => $linkId]);
        return $stmt->fetch() ?: null;
    }

    public static function remove(array $link, int $userId): void
    {
        Database::connection()->prepare('DELETE FROM ticket_links WHERE id = :id')->execute(['id' => $link['id']]);
        Audit::log('ticket', (int) $link['ticket_id'], 'link_removed', $userId, ['ticket' => (int) $link['linked_id'], 'type' => $link['link_type']]);
        Audit::log('ticket', (int) $link['linked_id'], 'link_removed', $userId, ['ticket' => (int) $link['ticket_id'], 'type' => $link['link_type']]);
    }

    private static function insert(int $ticketId, int $linkedId, string $type, int $userId): void
    {
        Database::connection()->prepare('INSERT INTO ticket_links (ticket_id, linked_id, link_type, created_by) VALUES (:a, :b, :t, :u)')
            ->execute(['a' => $ticketId, 'b' => $linkedId, 't' => $type, 'u' => $userId]);
    }
}
