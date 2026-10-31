<?php

namespace App\Services;

use App\Models\Ticket;

/**
 * «Звіт по тікетах»: облік часу роботи з тікетами за період створення — скільки створено/вирішено, середній і медіанний
 * час першої реакції та вирішення, у розрізі статусів, черг і операторів. Рахується в PHP по рядках Ticket::workReportRows().
 */
class TicketWorkReport
{
    /** Мінімальні хвилини → «2 д 3 г 15 хв»; null → «—». */
    public static function duration(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $m = (int) round($minutes);
        if ($m < 1) {
            return '< 1 хв';
        }
        $d = intdiv($m, 1440);
        $h = intdiv($m % 1440, 60);
        $min = $m % 60;
        $parts = [];
        if ($d) {
            $parts[] = $d . ' д';
        }
        if ($h) {
            $parts[] = $h . ' г';
        }
        if ($min && $d === 0) {
            $parts[] = $min . ' хв';
        }
        return implode(' ', $parts) ?: '< 1 хв';
    }

    /** @param float[] $vals @return array{avg: ?float, median: ?float, max: ?float, n: int} */
    public static function stats(array $vals): array
    {
        $vals = array_values(array_filter($vals, static fn($v) => $v !== null && $v >= 0));
        $n = count($vals);
        if ($n === 0) {
            return ['avg' => null, 'median' => null, 'max' => null, 'n' => 0];
        }
        sort($vals);
        $median = $n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
        return ['avg' => array_sum($vals) / $n, 'median' => (float) $median, 'max' => (float) max($vals), 'n' => $n];
    }

    private static function group(array $rows, string $keyField, string $nameField, string $fallback): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $k = $r[$keyField] ?? 0;
            $groups[$k] ??= ['name' => $r[$nameField] ?? $fallback, 'rows' => []];
            $groups[$k]['rows'][] = $r;
        }
        $out = [];
        foreach ($groups as $g) {
            $out[] = ['name' => $g['name']] + self::summarize($g['rows']);
        }
        usort($out, static fn($a, $b) => $b['total'] <=> $a['total']);
        return $out;
    }

    /** @return array{total: int, resolved: int, open: int, hours: float, response: array, resolution: array} */
    public static function summarize(array $rows): array
    {
        $resolved = 0;
        foreach ($rows as $r) {
            if (in_array($r['status'], ['resolved', 'closed'], true)) {
                $resolved++;
            }
        }
        return [
            'total' => count($rows),
            'resolved' => $resolved,
            'open' => count($rows) - $resolved,
            'hours' => array_sum(array_map(static fn($r) => (float) ($r['spent_hours'] ?? 0), $rows)),
            'response' => self::stats(array_map(static fn($r) => $r['response_min'] !== null ? (float) $r['response_min'] : null, $rows)),
            'resolution' => self::stats(array_map(static fn($r) => $r['resolution_min'] !== null ? (float) $r['resolution_min'] : null, $rows)),
        ];
    }

    /** @return array{summary: array, byStatus: array, byQueue: array, byOperator: array} */
    public static function build(array $rows): array
    {
        $byStatus = [];
        foreach (Ticket::STATUS_LABELS as $code => $label) {
            $byStatus[$code] = ['label' => $label, 'count' => 0];
        }
        foreach ($rows as $r) {
            if (isset($byStatus[$r['status']])) {
                $byStatus[$r['status']]['count']++;
            }
        }
        return [
            'summary' => self::summarize($rows),
            'byStatus' => $byStatus,
            'byQueue' => self::group($rows, 'queue_id', 'queue_name', '—'),
            'byOperator' => self::group($rows, 'operator_id', 'operator_name', 'Не призначено'),
        ];
    }

    /** Заголовки таблиці XLSX/CSV «Звіту по тікетах». */
    public const HEADERS = [
        '№', 'Тема', 'Черга', 'Заявник', 'Оператор', 'Статус', 'Створено', 'Перша відповідь', 'Вирішено',
        'До першої відповіді', 'До вирішення', 'Витрачено часу',
    ];

    /** Рядки таблиці XLSX/CSV за рядками Ticket::workReportRows(). */
    public static function tableRows(array $src): array
    {
        $fmt = static fn(?float $m) => $m === null ? '' : \App\Core\TimeFormat::minutes((int) round($m));
        $rows = [];
        foreach ($src as $t) {
            $rows[] = [
                (int) $t['id'], $t['subject'], $t['queue_name'], $t['requester_name'], $t['operator_name'] ?? '',
                \App\Models\Ticket::statusLabel((string) $t['status']), \App\Core\TimeFormat::dateTime((string) $t['created_at']), \App\Core\TimeFormat::dateTime($t['first_response_at'] ?? null), \App\Core\TimeFormat::dateTime($t['resolved_at'] ?? null),
                $fmt($t['response_min'] !== null ? (float) $t['response_min'] : null), $fmt($t['resolution_min'] !== null ? (float) $t['resolution_min'] : null),
                \App\Core\TimeFormat::hm((float) $t['spent_hours']),
            ];
        }
        return $rows;
    }

    /** PDF «Звіту по тікетах»: підсумок, розбивки за чергами й операторами та список тікетів. */
    public static function pdf(array $f, array $src, bool $unit): \App\Core\PdfReport
    {
        $d = self::build($src);
        $dur = static fn(array $s) => $s['n'] > 0 ? self::duration($s['avg']) . ' / ' . self::duration($s['median']) : '—';
        $lines = ['Період створення: ' . \App\Core\TimeFormat::date($f['date_from']) . ' — ' . \App\Core\TimeFormat::date($f['date_to'])];
        if ($unit) {
            $lines[] = 'Лише тікети підрозділу';
        }
        $lines[] = 'Час календарний (без урахування вихідних і робочих годин). Сформовано: ' . date('d.m.Y H:i');
        $pdf = new \App\Core\PdfReport('Звіт по тікетах', $lines);
        $s = $d['summary'];
        $pdf->line('Створено: ' . $s['total'] . '; вирішено/закрито: ' . $s['resolved'] . '; відкрито: ' . $s['open']);
        $pdf->line('Витрачено часу (облік часу в тікетах): ' . \App\Core\TimeFormat::hm((float) $s['hours']));
        $pdf->line('До першої відповіді (середній / медіана): ' . $dur($s['response']));
        $pdf->line('До вирішення (середній / медіана): ' . $dur($s['resolution']));
        $cols = fn(string $first) => [
            ['header' => $first, 'width' => 38], ['header' => 'Створено', 'width' => 17], ['header' => 'Вирішено', 'width' => 17],
            ['header' => 'Відкрито', 'width' => 17], ['header' => 'Час', 'width' => 22], ['header' => 'Перша відповідь', 'width' => 34], ['header' => 'Вирішення', 'width' => 32],
        ];
        $map = static fn(array $g) => array_map(static fn($r) => [$r['name'], $r['total'], $r['resolved'], $r['open'], \App\Core\TimeFormat::hm((float) $r['hours']), $dur($r['response']), $dur($r['resolution'])], $g);
        $pdf->table('По чергах (середній / медіана)', $cols('Черга'), $map($d['byQueue']));
        $pdf->table('По операторах (середній / медіана)', $cols('Оператор'), $map($d['byOperator']));
        $rows = [];
        foreach ($src as $t) {
            $rows[] = ['#' . $t['id'], $t['subject'], $t['operator_name'] ?? '—', \App\Models\Ticket::statusLabel((string) $t['status']), \App\Core\TimeFormat::dateTime((string) $t['created_at']),
                self::duration($t['response_min'] !== null ? (float) $t['response_min'] : null),
                self::duration($t['resolution_min'] !== null ? (float) $t['resolution_min'] : null),
                \App\Core\TimeFormat::hm((float) $t['spent_hours'])];
        }
        $pdf->table('Тікети за період (' . count($src) . ')', [
            ['header' => '№', 'width' => 11], ['header' => 'Тема', 'width' => 28], ['header' => 'Оператор', 'width' => 26], ['header' => 'Статус', 'width' => 20],
            ['header' => 'Створено', 'width' => 30], ['header' => 'До відповіді', 'width' => 20], ['header' => 'До вирішення', 'width' => 22], ['header' => 'Час', 'width' => 23],
        ], $rows);
        return $pdf;
    }
}
