<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Робочий календар для SLA: робочі дні тижня, години роботи, свята (щороку «ДД.ММ» або конкретна дата) і робочі дні-перенесення
 * (наприклад, субота замість святкового п'ятничного дня). Налаштування `work_calendar` (JSON); за вимкненого календаря нормативи
 * рахуються календарним часом (24/7), як і раніше.
 */
class WorkCalendar
{
    public const DAY_NAMES = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Нд'];
    /** Типові державні свята України з фіксованою датою (без рухомих — Великдень, Трійця; за воєнного стану вихідними можуть не бути). */
    public const UA_DEFAULT_HOLIDAYS = ['01.01', '08.03', '01.05', '08.05', '28.06', '15.07', '24.08', '01.10', '25.12'];

    private static ?array $cfg = null;

    /** @return array{enabled: bool, days: int[], start: string, end: string, holidays: string[], workdays: string[]} */
    public static function config(): array
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }
        $c = ['enabled' => false, 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '18:00', 'holidays' => [], 'workdays' => []];
        try {
            $raw = json_decode((string) Setting::get('work_calendar', ''), true);
            if (is_array($raw)) {
                $c['enabled'] = !empty($raw['enabled']);
                if (!empty($raw['days']) && is_array($raw['days'])) {
                    $c['days'] = array_values(array_unique(array_filter(array_map('intval', $raw['days']), static fn($d) => $d >= 1 && $d <= 7)));
                }
                foreach (['start', 'end'] as $k) {
                    if (isset($raw[$k]) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $raw[$k])) {
                        $c[$k] = (string) $raw[$k];
                    }
                }
                foreach (['holidays', 'workdays'] as $k) {
                    $c[$k] = isset($raw[$k]) && is_array($raw[$k]) ? array_values(array_filter(array_map('strval', $raw[$k]))) : [];
                }
            }
        } catch (\Throwable) {
        }
        if ($c['end'] <= $c['start'] || !$c['days']) {
            $c['enabled'] = false; // некоректний розклад не повинен «зупиняти» SLA
        }
        return self::$cfg = $c;
    }

    public static function reset(): void
    {
        self::$cfg = null;
    }

    public static function enabled(): bool
    {
        return self::config()['enabled'];
    }

    public static function isWorkingDay(string $ymd): bool
    {
        $c = self::config();
        if (in_array($ymd, $c['workdays'], true)) {
            return true;
        }
        if (in_array($ymd, $c['holidays'], true) || in_array(substr($ymd, 5), $c['holidays'], true)) {
            return false;
        }
        return in_array((int) date('N', strtotime($ymd . ' 12:00:00')), $c['days'], true);
    }

    /**
     * Момент, коли спливе $minutes робочих хвилин, починаючи з $from. Поза робочим часом відлік стартує з найближчого відкриття.
     * Вимкнений календар — просто $from + хвилини.
     */
    public static function addMinutes(int $from, int $minutes): int
    {
        $c = self::config();
        $minutes = max(0, $minutes);
        if (!$c['enabled']) {
            return $from + $minutes * 60;
        }
        $left = $minutes * 60;
        $ts = $from;
        for ($i = 0; $i < 4000; $i++) {
            $day = date('Y-m-d', $ts);
            if (self::isWorkingDay($day)) {
                $open = strtotime($day . ' ' . $c['start'] . ':00');
                $close = strtotime($day . ' ' . $c['end'] . ':00');
                $cur = max($ts, $open);
                if ($cur < $close) {
                    $avail = $close - $cur;
                    if ($left <= $avail) {
                        return $cur + $left;
                    }
                    $left -= $avail;
                }
            }
            $ts = strtotime($day . ' 00:00:00 +1 day');
        }
        return $from + $minutes * 60; // календар без жодного робочого дня на ~11 років вперед — не зациклюємось
    }

    /**
     * Розбір списку дат з форми: рядки/коми, формати ДД.ММ (щороку), ДД.ММ.РРРР, РРРР-ММ-ДД.
     * Повертає [список у внутрішньому форматі «MM-DD»/«YYYY-MM-DD», перший некоректний фрагмент або null].
     *
     * @return array{0: string[], 1: ?string}
     */
    public static function parseDates(string $text, bool $allowYearly = true): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
            if (preg_match('/^(\d{1,2})\.(\d{1,2})$/', $t, $m) && $allowYearly && checkdate((int) $m[2], (int) $m[1], 2024)) {
                $out[] = sprintf('%02d-%02d', $m[2], $m[1]);
            } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $t, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                $out[] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $out[] = $t;
            } else {
                return [[], $t];
            }
        }
        return [array_values(array_unique($out)), null];
    }

    /** Для показу у формі: «MM-DD» → «ДД.ММ», «YYYY-MM-DD» → «ДД.ММ.РРРР». @param string[] $dates */
    public static function formatDates(array $dates): string
    {
        $o = [];
        foreach ($dates as $d) {
            $o[] = strlen($d) === 5 ? substr($d, 3, 2) . '.' . substr($d, 0, 2) : substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.' . substr($d, 0, 4);
        }
        return implode("\n", $o);
    }
}
