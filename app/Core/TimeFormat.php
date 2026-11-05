<?php
declare(strict_types=1);

namespace App\Core;

/** Показ тривалості: години (дробові) → «2 год 5 хв», «45 хв», «0 хв». Скрізь у звітах і на сторінках обліку часу. */
final class TimeFormat
{
    public static function hm(float $hours): string
    {
        return self::minutes((int) round($hours * 60));
    }

    /** Години (дробові) → ціла кількість хвилин для експорту й API. */
    public static function toMinutes(float $hours): int
    {
        return (int) round($hours * 60);
    }

    public static function minutes(int $min): string
    {
        if ($min <= 0) {
            return '0 хв';
        }
        $h = intdiv($min, 60);
        $m = $min % 60;
        return trim(($h > 0 ? $h . ' год' : '') . ($m > 0 ? ' ' . $m . ' хв' : ''));
    }

    /** Дата без екранування (PDF, XLSX, CSV, листи): «30.10.2026»; порожнє → $empty. */
    public static function date(?string $v, string $empty = ''): string
    {
        $v = trim((string) $v);
        if ($v === '' || str_starts_with($v, '0000') || ($ts = strtotime($v)) === false) {
            return $v === '' || str_starts_with($v, '0000') ? $empty : $v;
        }
        return date('d.m.Y', $ts);
    }

    /** Дата й час без екранування: «30.10.2026 14:05». */
    public static function dateTime(?string $v, string $empty = ''): string
    {
        $v = trim((string) $v);
        if ($v === '' || str_starts_with($v, '0000') || ($ts = strtotime($v)) === false) {
            return $v === '' || str_starts_with($v, '0000') ? $empty : $v;
        }
        return date('d.m.Y H:i', $ts);
    }
}
