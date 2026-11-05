<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * Оновлення системи зі сторінки адміністратора: перевірка нової версії на GitHub, розбір CHANGELOG, приймання архіву з
 * комп'ютера (перевірка, розпакування в тимчасовий каталог) і застосування його поверх поточних файлів із відкатом.
 *
 * Безпека (заміна коду — найризикованіша дія в системі):
 *  - застосування й приймання архіву працюють ЛИШЕ коли в .env задано UPDATE_FROM_WEB=true (тобто це рішення людини з доступом
 *    до сервера, а не будь-якого адміністратора в браузері); перевірка нової версії доступна завжди;
 *  - архів перевіряється до розпакування: структура (VERSION, app/, update.sh), шляхи без «..»/абсолютних/символьних посилань,
 *    ліміти кількості файлів і розміру (захист від ZIP-бомб); .env, storage/ і .git ніколи не зачіпаються;
 *  - застосування: страхувальна резервна копія (за замовчуванням), копії замінюваних файлів для відкату, атомарна заміна
 *    кожного файлу (запис у тимчасовий файл → rename), міграції окремим процесом PHP; збій → автоматичний відкат файлів.
 */
final class UpdateService
{
    public const REPO = 'ROOT-BSD/itsm-system';
    public const MAX_FILES = 20000;
    public const MAX_UNPACKED = 240 * 1048576;
    /** Що можна ставити з архіву (верхній рівень). Решта (storage, .env, .git, сторонні файли) ігнорується. */
    public const ALLOWED_TOP = ['app', 'bin', 'config', 'database', 'docs', 'public', 'scripts', 'install.sh', 'update.sh', 'README.md', 'INSTALL.md', 'CHANGELOG.md', 'VERSION', '.htaccess'];

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function currentVersion(): string
    {
        return trim((string) @file_get_contents(self::root() . '/VERSION')) ?: '0';
    }

    public static function webEnabled(): bool
    {
        return in_array(strtolower((string) \App\Core\Config::get('update.web', '')), ['1', 'true', 'yes', 'on'], true);
    }

    public static function updatesDir(): string
    {
        return self::root() . '/storage/updates';
    }

    public static function validVersion(string $v): bool
    {
        return (bool) preg_match('/^\d+\.\d+(\.\d+)?([-+][0-9A-Za-z.\-]+)?$/', $v);
    }

    // ------------------------------------------------------------------ CHANGELOG

    /**
     * Розділи CHANGELOG для версій НОВІШИХ за $current: [версія => текст розділу]. Заголовки вигляду «## 1.2.0 (дата)».
     * @return array<string, string>
     */
    public static function changelogSince(string $changelog, string $current): array
    {
        $out = [];
        $parts = preg_split('/^## +/m', $changelog) ?: [];
        array_shift($parts); // текст до першого «## »
        foreach ($parts as $part) {
            $nl = strpos($part, "\n");
            $head = trim($nl === false ? $part : substr($part, 0, $nl));
            if (!preg_match('/^(\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.]+)?)/', $head, $m)) {
                continue;
            }
            if (version_compare($m[1], $current, '>')) {
                $out[$m[1]] = trim($head . "\n" . ($nl === false ? '' : substr($part, $nl + 1)));
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ перевірка нової версії

    /** Запит до GitHub: найновіша версія й CHANGELOG. @return array{ok: bool, error: ?string, version: ?string, changelog: string} */
    public static function fetchRemote(): array
    {
        $base = 'https://raw.githubusercontent.com/' . self::REPO . '/main/';
        $get = static function (string $file, int $max) use ($base): ?string {
            $ctx = stream_context_create([
                'http' => ['timeout' => 8, 'follow_location' => 0, 'ignore_errors' => true, 'header' => "User-Agent: itsm-system-update-check\r\n"],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($base . $file, false, $ctx, 0, $max);
            $status = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int) $m[1];
                }
            }
            return $body !== false && $status === 200 ? $body : null;
        };
        $version = $get('VERSION', 100);
        if ($version === null) {
            return ['ok' => false, 'error' => 'GitHub недоступний із сервера (немає виходу в Інтернет або заблоковано доступ до raw.githubusercontent.com).', 'version' => null, 'changelog' => ''];
        }
        $version = trim($version);
        if (!self::validVersion($version)) {
            return ['ok' => false, 'error' => 'Сервер повернув некоректний номер версії.', 'version' => null, 'changelog' => ''];
        }
        return ['ok' => true, 'error' => null, 'version' => $version, 'changelog' => (string) $get('CHANGELOG.md', 1048576)];
    }

    /** Виконує перевірку й запам'ятовує результат (Setting update_check). */
    public static function check(): array
    {
        $r = self::fetchRemote();
        $current = self::currentVersion();
        $r['current'] = $current;
        $r['available'] = $r['ok'] && version_compare((string) $r['version'], $current, '>');
        $r['notes'] = $r['available'] ? self::changelogSince($r['changelog'], $current) : [];
        $r['at'] = date('Y-m-d H:i:s');
        Setting::set('update_check', json_encode([
            'at' => $r['at'], 'ok' => $r['ok'], 'error' => $r['error'], 'version' => $r['version'], 'current' => $current,
            'available' => $r['available'], 'notes' => $r['notes'],
        ], JSON_UNESCAPED_UNICODE));
        return $r;
    }

    /** Останній збережений результат перевірки або null. */
    public static function lastCheck(): ?array
    {
        $raw = Setting::get('update_check');
        $d = $raw ? json_decode($raw, true) : null;
        if (!is_array($d)) {
            return null;
        }
        // Версія в системі могла змінитися після оновлення — «є нова» перераховуємо.
        $d['current'] = self::currentVersion();
        $d['available'] = !empty($d['ok']) && version_compare((string) ($d['version'] ?? '0'), $d['current'], '>');
        return $d;
    }

    /** Команда оновлення для підказки (залежить від того, чи встановлено через git). */
    public static function commandHint(): array
    {
        $root = self::root();
        $steps = [];
        if (is_dir($root . '/.git')) {
            $steps[] = 'cd ' . $root;
            $steps[] = 'sudo -u $(stat -c %U .) git pull   # або git fetch && git merge --ff-only';
        } else {
            $steps[] = '# завантажте архів: https://github.com/' . self::REPO . '/archive/refs/heads/main.zip';
            $steps[] = '# розпакуйте поверх ' . $root . ' (файл .env НЕ чіпайте)';
            $steps[] = 'cd ' . $root;
        }
        $steps[] = 'sudo bash update.sh';
        return $steps;
    }

    // ------------------------------------------------------------------ приймання архіву

    /** Підготовка каталогу staging і прибирання старого (> 1 доби). */
    private static function prepareDir(): string
    {
        $dir = self::updatesDir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не вдалося створити ' . $dir . ' — перевірте права на storage.');
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('Каталог ' . $dir . ' недоступний для запису веб-серверу.');
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $old) {
            if (preg_match('/^[a-f0-9]{16}$/', basename($old)) && filemtime($old) < time() - 86400) {
                self::removeTree($old);
            }
        }
        return $dir;
    }

    public static function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                self::removeTree($path . '/' . $f);
            }
        }
        @rmdir($path);
    }

    /**
     * Перевіряє архів і розпаковує його в storage/updates/<токен>/new/.
     * @return array{ok: bool, error?: string, token?: string, version?: string}
     */
    public static function stage(string $zipFile): array
    {
        // Читач ZIP без розширення PHP «zip» (його на багатьох серверах немає): центральний каталог + gzinflate.
        try {
            $zip = \App\Services\Office\OfficeZip::open($zipFile);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Файл не є коректним ZIP-архівом.'];
        }
        try {
            $entries = $zip->entries();
            $n = count($entries);
            if ($n < 1 || $n > self::MAX_FILES) {
                return ['ok' => false, 'error' => 'Архів порожній або містить забагато файлів (' . $n . ').'];
            }
            $names = array_map('strval', array_keys($entries));
            $total = 0;
            foreach ($entries as $name => $en) {
                $name = (string) $name;
                if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('~(^|/)\.\.(/|$)~', $name) || preg_match('~^[A-Za-z]:~', $name)) {
                    return ['ok' => false, 'error' => 'Архів містить небезпечний шлях: «' . mb_substr(preg_replace('/[^\P{C}]/u', '?', $name) ?? '', 0, 80) . '». Оновлення відхилено.'];
                }
                if ($zip->isSymlink($name)) {
                    return ['ok' => false, 'error' => 'Архів містить символьне посилання («' . mb_substr($name, 0, 80) . '») — відхилено.'];
                }
                $total += (int) $en['size'];
                if ($total > self::MAX_UNPACKED) {
                    return ['ok' => false, 'error' => 'Розпакований розмір архіву завеликий (> ' . (self::MAX_UNPACKED >> 20) . ' МБ).'];
                }
            }
            // Корінь проєкту в архіві: каталог (або сам корінь), де лежать VERSION, update.sh і app/Core/Config.php.
            $prefix = null;
            foreach ($names as $name) {
                if ($name === 'VERSION' || preg_match('~^[^/]+/VERSION$~', $name)) {
                    $p = substr($name, 0, -7);
                    if (in_array($p . 'update.sh', $names, true) && in_array($p . 'app/Core/Config.php', $names, true)) {
                        $prefix = $p;
                        break;
                    }
                }
            }
            if ($prefix === null) {
                return ['ok' => false, 'error' => 'Це не архів ITSM System: не знайдено VERSION, update.sh та app/Core/Config.php (архів із GitHub чи пакет релізу).'];
            }
            $version = trim((string) $zip->read($prefix . 'VERSION'));
            if (!self::validVersion($version)) {
                return ['ok' => false, 'error' => 'У файлі VERSION архіву некоректний номер версії.'];
            }

            $dir = self::prepareDir();
            $token = bin2hex(random_bytes(8));
            $base = $dir . '/' . $token;
            $newDir = $base . '/new';
            if (!@mkdir($newDir, 0770, true)) {
                return ['ok' => false, 'error' => 'Не вдалося створити ' . $newDir . '.'];
            }
            $files = 0;
            foreach ($names as $name) {
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }
                $rel = substr($name, strlen($prefix));
                if ($rel === '' || str_ends_with($rel, '/')) {
                    continue;
                }
                $top = explode('/', $rel, 2)[0];
                if (!in_array($top, self::ALLOWED_TOP, true) || str_contains($rel, '/.git/') || basename($rel) === '.env') {
                    continue;
                }
                $dest = $newDir . '/' . $rel;
                if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0770, true)) {
                    throw new \RuntimeException('Не вдалося створити каталог для ' . $rel);
                }
                $data = $zip->read($name);
                if ($data === null || @file_put_contents($dest, $data) === false) {
                    throw new \RuntimeException('Не вдалося розпакувати ' . $rel);
                }
                $files++;
            }
            if ($files === 0 || !is_file($newDir . '/VERSION')) {
                self::removeTree($base);
                return ['ok' => false, 'error' => 'В архіві немає файлів програми.'];
            }
            file_put_contents($base . '/meta.json', json_encode(['version' => $version, 'files' => $files, 'at' => date('c')], JSON_UNESCAPED_UNICODE));
            return ['ok' => true, 'token' => $token, 'version' => $version];
        } catch (\Throwable $e) {
            if (isset($base)) {
                self::removeTree($base);
            }
            return ['ok' => false, 'error' => 'Не вдалося розпакувати архів: ' . $e->getMessage()];
        }
    }

    /** Каталог розпакованого архіву за токеном або null. */
    public static function stagedDir(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            return null;
        }
        $d = self::updatesDir() . '/' . $token;
        return is_file($d . '/meta.json') && is_dir($d . '/new') ? $d : null;
    }

    /**
     * Огляд розпакованого архіву: версії, що зміниться, зауваження. @return array<string, mixed>|null
     */
    public static function preview(string $token): ?array
    {
        $d = self::stagedDir($token);
        if ($d === null) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($d . '/meta.json'), true) ?: [];
        $root = self::root();
        $new = $changed = $same = 0;
        $unwritable = [];
        $sample = [];
        foreach (self::listFiles($d . '/new') as $rel) {
            $target = $root . '/' . $rel;
            if (!file_exists($target)) {
                $new++;
                $sample[] = '+ ' . $rel;
                $dirOk = self::nearestExistingDirWritable(dirname($target));
                if (!$dirOk) {
                    $unwritable[] = $rel;
                }
            } elseif (md5_file($target) !== md5_file($d . '/new/' . $rel)) {
                $changed++;
                if (count($sample) < 40) {
                    $sample[] = '~ ' . $rel;
                }
                if (!is_writable($target) || !is_writable(dirname($target))) {
                    $unwritable[] = $rel;
                }
            } else {
                $same++;
            }
        }
        $current = self::currentVersion();
        $changelog = (string) @file_get_contents($d . '/new/CHANGELOG.md');
        return [
            'token' => $token, 'version' => (string) ($meta['version'] ?? '?'), 'current' => $current,
            'relation' => version_compare((string) ($meta['version'] ?? '0'), $current),
            'new' => $new, 'changed' => $changed, 'same' => $same, 'sample' => array_slice($sample, 0, 40),
            'unwritable' => array_slice($unwritable, 0, 10), 'unwritable_total' => count($unwritable),
            'notes' => self::changelogSince($changelog, $current),
        ];
    }

    private static function nearestExistingDirWritable(string $dir): bool
    {
        while (!is_dir($dir) && $dir !== dirname($dir)) {
            $dir = dirname($dir);
        }
        return is_writable($dir);
    }

    /** @return string[] відносні шляхи файлів у каталозі */
    public static function listFiles(string $base): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink()) {
                $out[] = ltrim(substr($f->getPathname(), strlen($base)), '/');
            }
        }
        sort($out);
        return $out;
    }

    public static function discard(string $token): void
    {
        if (($d = self::stagedDir($token)) !== null) {
            self::removeTree($d);
        }
    }

    // ------------------------------------------------------------------ застосування

    /** Шлях до PHP CLI (веб-процес працює під php-fpm/apache, його PHP_BINARY для скриптів не підходить). */
    public static function phpCli(): ?string
    {
        $cands = [];
        if (PHP_SAPI === 'cli') {
            $cands[] = PHP_BINARY;
        }
        $cands[] = PHP_BINDIR . '/php';
        array_push($cands, '/usr/bin/php', '/usr/local/bin/php', '/opt/homebrew/bin/php');
        foreach ($cands as $c) {
            if ($c && @is_executable($c) && !str_contains($c, 'fpm') && !str_contains($c, 'cgi')) {
                return $c;
            }
        }
        return null;
    }

    /** @return array{0: int, 1: string} код виходу й вивід */
    private static function runCli(string $php, string $script, int $timeout = 300): array
    {
        $proc = proc_open([$php, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root());
        if (!is_resource($proc)) {
            return [1, 'не вдалося запустити ' . basename($script)];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $end = microtime(true) + $timeout;
        while (microtime(true) < $end) {
            $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $code = $st['exitcode'];
                break;
            }
            usleep(100000);
        }
        if (!isset($code)) {
            proc_terminate($proc);
            $out .= "\n[перевищено ліміт часу]";
            $code = 124;
        } else {
            $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return [(int) $code, trim($out)];
    }

    /**
     * Кроки update.sh без права root: каталоги сховища, кеш метрик шрифту PDF, гайди у вікі, текст вікі у файлах,
     * обрізання історії вікі, індекс файлів бібліотеки. Помилки тут не критичні (повідомляються в журналі, оновлення не відкочується).
     * Чого веб-процес зробити не може (власник і права всього дерева, php.ini, пакети ОС) — потребує SSH, але не обов'язкове.
     * @return string[]
     */
    public static function postSteps(string $root, ?string $php): array
    {
        $log = [];
        $made = [];
        foreach (['uploads', 'logs', 'backups', 'wiki'] as $d) {
            $p = $root . '/storage/' . $d;
            if (!is_dir($p) && @mkdir($p, 0770, true)) {
                $made[] = $d;
            }
        }
        $log[] = 'Каталоги сховища на місці' . ($made ? ' (створено: ' . implode(', ', $made) . ')' : '') . '.';
        $cache = $root . '/app/Vendor/tfpdf/font/unifont';
        $removed = 0;
        foreach (['*.mtx.php', '*.cw.dat', '*.cw127.php'] as $pattern) {
            foreach (glob($cache . '/' . $pattern) ?: [] as $f) {
                $removed += @unlink($f) ? 1 : 0;
            }
        }
        $log[] = 'Кеш шрифтів PDF: ' . ($removed ? "очищено файлів — {$removed}" : 'очищати нічого') . '.';
        if ($php === null) {
            return $log;
        }
        foreach ([
            'bin/import-wiki-docs.php' => 'Гайди у вікі',
            'bin/migrate-wiki-to-files.php' => 'Текст вікі у файлах',
            'bin/prune-wiki-revisions.php' => 'Історія вікі',
            'bin/index-library.php' => 'Індекс файлів бібліотеки',
        ] as $script => $what) {
            if (!is_file($root . '/' . $script)) {
                continue;
            }
            [$code, $out] = self::runCli($php, $root . '/' . $script, 120);
            $log[] = $what . ($code === 0 ? ': готово' : ': не вдалося (' . mb_substr($out, -150) . ') — не критично; повторіть через SSH: php ' . $script);
        }
        return $log;
    }

    /**
     * Накладає розпакований архів на поточні файли. ВАЖЛИВО: клас викликається з веб-запиту, що виконується СТАРИМ кодом;
     * після заміни файлів не можна автозавантажувати нові класи — викликач має заздалегідь підвантажити все потрібне
     * (див. UpdateController::apply) і закінчити запит простим редіректом.
     *
     * @return array{ok: bool, message: string, log: string[]}
     */
    public static function apply(string $token, bool $runMigrations = true): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $log = [];
        $fail = static function (string $m) use (&$log): array {
            return ['ok' => false, 'message' => $m, 'log' => $log];
        };
        if (!self::webEnabled()) {
            return $fail('Оновлення з веб-сторінки вимкнено (UPDATE_FROM_WEB у .env).');
        }
        $d = self::stagedDir($token);
        if ($d === null) {
            return $fail('Розпакований архів не знайдено (можливо, його вже застосовано чи скасовано). Завантажте архів ще раз.');
        }
        $meta = json_decode((string) file_get_contents($d . '/meta.json'), true) ?: [];
        $version = (string) ($meta['version'] ?? '');
        if (version_compare($version, self::currentVersion(), '<')) {
            return $fail('Архів старіший за встановлену версію (' . $version . ' < ' . self::currentVersion() . ') — повернення назад не підтримується.');
        }
        $php = $runMigrations ? self::phpCli() : null;
        if ($runMigrations && $php === null) {
            return $fail('Не знайдено PHP CLI для запуску міграцій. Застосуйте оновлення вручну: sudo bash update.sh.');
        }
        $lockFile = self::updatesDir() . '/.lock';
        $lock = fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $fail('Інше оновлення вже виконується.');
        }

        $root = self::root();
        $files = self::listFiles($d . '/new');
        $rollback = $d . '/rollback';
        $created = [];
        $saved = [];
        try {
            foreach ($files as $rel) {
                $src = $d . '/new/' . $rel;
                $target = $root . '/' . $rel;
                if (is_file($target)) {
                    if (md5_file($target) === md5_file($src)) {
                        continue;
                    }
                    $copy = $rollback . '/' . $rel;
                    if (!is_dir(dirname($copy)) && !@mkdir(dirname($copy), 0770, true)) {
                        throw new \RuntimeException('не вдалося створити ' . dirname($copy));
                    }
                    if (!@copy($target, $copy)) {
                        throw new \RuntimeException('не вдалося зберегти копію ' . $rel);
                    }
                    $saved[] = $rel;
                }
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                        throw new \RuntimeException('не вдалося створити каталог ' . dirname($rel));
                    }
                }
                $isNew = !file_exists($target);
                $mode = $isNew ? (str_ends_with($rel, '.sh') ? 0750 : 0640) : (fileperms($target) & 0777);
                $tmp = $target . '.upd-' . bin2hex(random_bytes(3));
                if (!@copy($src, $tmp)) {
                    throw new \RuntimeException('не вдалося записати ' . $rel . ' (немає прав на запис?)');
                }
                @chmod($tmp, $mode);
                if (!@rename($tmp, $target)) {
                    @unlink($tmp);
                    throw new \RuntimeException('не вдалося замінити ' . $rel);
                }
                if ($isNew) {
                    $created[] = $rel;
                }
            }
            $log[] = 'Замінено файлів: ' . count($saved) . ', нових: ' . count($created) . '.';
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            if ($runMigrations) {
                [$code, $out] = self::runCli($php, $root . '/bin/migrate.php');
                $log[] = 'Міграції (код ' . $code . '): ' . mb_substr($out, -600);
                if ($code !== 0) {
                    throw new \RuntimeException('міграції БД завершились помилкою: ' . mb_substr($out, -300));
                }
            }
            // Решта кроків update.sh, які не потребують root (каталоги, кеш шрифтів, вікі, індекс) — щоб заходити по SSH не довелось.
            // Якщо в новій версії є bin/post-update.php — кроки виконує НОВИЙ код (так нові релізи можуть додавати свої кроки);
            // інакше (оновлення з версії без нього) — кроки поточної версії.
            if ($runMigrations && is_file($root . '/bin/post-update.php')) {
                [$pc, $po] = self::runCli($php, $root . '/bin/post-update.php', 600);
                foreach (array_filter(array_map('trim', explode("\n", $po))) as $line) {
                    $log[] = $line;
                }
                if ($pc !== 0) {
                    $log[] = 'Післякроки завершились з помилкою (код ' . $pc . ') — не критично; повторіть: php bin/post-update.php';
                }
            } else {
                foreach (self::postSteps($root, $runMigrations ? $php : null) as $line) {
                    $log[] = $line;
                }
            }
        } catch (\Throwable $e) {
            foreach ($saved as $rel) {
                @copy($rollback . '/' . $rel, $root . '/' . $rel);
            }
            foreach ($created as $rel) {
                @unlink($root . '/' . $rel);
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $log[] = 'Файли повернуто до попереднього стану.';
            flock($lock, LOCK_UN);
            return $fail('Оновлення не виконано: ' . $e->getMessage());
        }
        // Копії для відкату лишаємо (на випадок ручного відновлення), розпаковані нові файли прибираємо.
        self::removeTree($d . '/new');
        file_put_contents($d . '/meta.json', json_encode($meta + ['applied_at' => date('c'), 'log' => $log], JSON_UNESCAPED_UNICODE));
        flock($lock, LOCK_UN);
        return ['ok' => true, 'message' => 'Систему оновлено до версії ' . $version . '.', 'log' => $log];
    }
}
