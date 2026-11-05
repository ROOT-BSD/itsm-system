<?php
// Автоматичні регресійні тести без Composer та PHPUnit.
//
//   php bin/selftest.php                 # швидкі перевірки без БД (парсери, права за ролями, пошук, витяг тексту, міграції як файли)
//   php bin/selftest.php --db            # + сценарії на БД: схема, доступ у пошуку, ранжування, право закриття, правила задач, пошук у файлах
//   php bin/selftest.php --db --force    # дозволити БД, назва якої не містить «test»/«copy»/«staging»/«dev»
//   php bin/selftest.php --filter=search # лише групи, у назві яких є «search»
//
// Сценарії на БД створюють власні записи з унікальною міткою SELFTEST-… і прибирають їх наприкінці, але ЗАПУСКАТИ їх слід на
// копії бази (звідси захист за назвою БД). Код виходу: 0 — усе гаразд, 1 — є провали.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

$root = dirname(__DIR__);
$withDb = in_array('--db', $argv, true);
$force = in_array('--force', $argv, true);
$filter = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--filter=')) {
        $filter = substr($a, 9);
    }
}

// ------------------------------------------------------------------ мінімальний каркас

$GLOBALS['st'] = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'group' => '', 'run' => true];

function group(string $name, callable $fn): void
{
    global $filter;
    $GLOBALS['st']['group'] = $name;
    if ($filter !== '' && stripos($name, $filter) === false) {
        return;
    }
    echo "\n▸ {$name}\n";
    try {
        $fn();
    } catch (\Throwable $e) {
        check(false, 'виняток у групі: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

function check(bool $ok, string $what): void
{
    $GLOBALS['st'][$ok ? 'pass' : 'fail']++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $what . "\n";
}

function same(mixed $expected, mixed $actual, string $what): void
{
    $ok = $expected === $actual;
    check($ok, $what . ($ok ? '' : ' — очікувалось ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', отримано ' . json_encode($actual, JSON_UNESCAPED_UNICODE)));
}

function call(string $class, string $method, mixed ...$args): mixed
{
    $m = new \ReflectionMethod($class, $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}


/** Мінімальний ZIP без розширення zip (метод «stored»): items = [ім'я => вміст]; $symlink = ім'я запису-посилання. */
function mkzip(string $path, array $items, ?string $symlink = null): string
{
    $data = '';
    $cd = '';
    $n = 0;
    foreach ($items as $name => $content) {
        $name = (string) $name;
        $content = (string) $content;
        $crc = crc32($content);
        $off = strlen($data);
        $data .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, 0, 0, 0, $crc, strlen($content), strlen($content), strlen($name), 0) . $name . $content;
        $attr = $symlink === $name ? (0120777 << 16) : (0100644 << 16);
        $cd .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', (3 << 8) | 20, 20, 0, 0, 0, 0, $crc, strlen($content), strlen($content), strlen($name), 0, 0, 0, 0, $attr, $off) . $name;
        $n++;
    }
    file_put_contents($path, $data . $cd . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($cd), strlen($data), 0));
    return $path;
}

// ------------------------------------------------------------------ 1. без БД

group('Файли: синтаксис PHP, CSP, версія', function () use ($root) {
    $bad = [];
    $inline = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (!str_ends_with($p, '.php') || str_contains($p, '/storage/')) {
            continue;
        }
        $out = [];
        exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $bad[] = substr($p, strlen($root) + 1);
        }
        if (str_contains($p, '/app/Views/') && preg_match('/<[a-z][^>]*\son(click|change|submit|load|input|keyup|keydown)\s*=/i', (string) file_get_contents($p))) {
            $inline[] = substr($p, strlen($root) + 1);
        }
    }
    same([], $bad, 'усі .php-файли проходять php -l');
    same([], $inline, 'у шаблонах немає inline-обробників (CSP дозволяє лише зовнішній JS)');
    $config = require $root . '/config/config.php';
    same(trim((string) file_get_contents($root . '/VERSION')), (string) ($config['app']['version'] ?? $config['version'] ?? ''), 'VERSION збігається з config.php');
});

group('Міграції: файли й схема', function () use ($root) {
    $files = glob($root . '/database/migrations/*.sql') ?: [];
    $schema = (string) file_get_contents($root . '/database/schema.sql');
    $missing = [];
    $nums = [];
    foreach ($files as $f) {
        if (!preg_match('/^(\d+)_/', basename($f), $m)) {
            $missing[] = basename($f) . ' (ім\'я)';
            continue;
        }
        $nums[] = (int) $m[1];
        if (!str_contains($schema, "('" . $m[1] . "'")) {
            $missing[] = basename($f);
        }
    }
    same([], $missing, 'кожна міграція має базовий рядок у schema.sql');
    sort($nums);
    same(count($nums), count(array_unique($nums)), 'номери міграцій не повторюються');
    $gaps = [];
    for ($i = 1, $n = count($nums); $i < $n; $i++) {
        if ($nums[$i] !== $nums[$i - 1] + 1) {
            $gaps[] = $nums[$i - 1] . '→' . $nums[$i];
        }
    }
    same([], $gaps, 'нумерація міграцій без пропусків');
    foreach ($files as $f) {
        foreach (\App\Services\Migrator::splitStatements((string) file_get_contents($f)) as $stmt) {
            if (preg_match('/^\s*(DROP\s+(TABLE|DATABASE)|TRUNCATE)\b/i', $stmt)) {
                check(false, basename($f) . ': небезпечна команда ' . strtok(trim($stmt), "\n"));
            }
        }
    }
    check(true, 'у міграціях немає DROP/TRUNCATE');
    same(['A', 'B'], \App\Services\Migrator::splitStatements("A;\n-- c;\nB;"), 'splitStatements ігнорує коментарі');
});

group('Ролі й права (Access, Team)', function () {
    check(\App\Core\Access::isAdmin('admin') && !\App\Core\Access::isAdmin('it_manager'), 'isAdmin лише для admin');
    foreach (['it_manager', 'support_operator'] as $r) {
        check(\App\Core\Access::canSeeUnassignedTickets($r), "{$r} бачить нерозподілені тікети");
    }
    foreach (['requester', 'observer', 'dept_head', null] as $r) {
        check(!\App\Core\Access::canSeeUnassignedTickets($r), 'не бачить нерозподілені тікети: ' . var_export($r, true));
    }
    check(\App\Core\Access::isDeptHead('dept_head') && !\App\Core\Access::isDeptHead('requester'), 'isDeptHead');
    check(!\App\Core\Access::canManageTeams('requester'), 'заявник не керує командами');
    check(!\App\Core\Access::canAssignTicketOperator('requester'), 'заявник не призначає оператора');
    same('requester', \App\Core\Access::ticketCommentAuthorType('requester'), 'коментар заявника — requester');
    same('operator', \App\Core\Access::ticketCommentAuthorType('support_operator'), 'коментар оператора — operator');
});

group('Утиліти: час, URL, DN, ліміти, Markdown', function () {
    same('2 год 5 хв', \App\Core\TimeFormat::minutes(125), 'TimeFormat::minutes');
    same('0 хв', \App\Core\TimeFormat::minutes(0), 'TimeFormat 0');
    same('45 хв', \App\Core\TimeFormat::hm(0.75), 'TimeFormat::hm');
    same(65, \App\Core\TimeFormat::toMinutes(1.08), 'toMinutes: 1,08 год = 65 хв (точність DECIMAL(6,2) не ламає хвилини)');
    same(1, \App\Core\TimeFormat::toMinutes(0.02), 'toMinutes: 1 хв, збережена як 0,02 год');
    $c = \App\Services\ApiSerializer::taskComment(['id' => 1, 'author_id' => null, 'body' => 'x', 'hours' => 1.5, 'created_at' => null]);
    check($c['hours'] === 1.5 && $c['minutes'] === 90 && $c['duration'] === '1 год 30 хв', 'API: коментар задачі має hours, minutes і duration');
    $zero = \App\Services\ApiSerializer::taskComment(['id' => 1, 'author_id' => null, 'body' => 'x', 'hours' => 0, 'created_at' => null]);
    check($zero['minutes'] === 0 && $zero['duration'] === '0 хв', 'API: запис без часу — 0 хв');
    $row = ['id' => 1, 'project_id' => 1, 'title' => 't', 'type_id' => 1, 'status_id' => 1, 'priority' => 'normal', 'author_id' => 1, 'estimated_hours' => '2.50', 'actual_hours' => '1.08'];
    $t = \App\Services\ApiSerializer::task($row);
    check($t['estimated_minutes'] === 150 && $t['actual_minutes'] === 65 && $t['actual_duration'] === '1 год 5 хв', 'API: задача має estimated/actual у годинах, хвилинах і текстом');
    $q = \App\Services\ApiSerializer::task(['estimated_hours' => null] + $row);
    check($q['estimated_hours'] === null && $q['estimated_minutes'] === null, 'API: порожня оцінка → null');
    $src = [['id' => 1, 'subject' => 's', 'queue_name' => 'q', 'requester_name' => 'r', 'operator_name' => null, 'status' => 'new', 'created_at' => '2026-01-01 10:00:00',
        'first_response_at' => null, 'resolved_at' => null, 'response_min' => null, 'resolution_min' => null, 'spent_hours' => 1.5]];
    $tr = \App\Services\TicketWorkReport::tableRows($src);
    same(count(\App\Services\TicketWorkReport::HEADERS), count($tr[0]), 'звіт по тікетах: стовпців у заголовку й рядку порівну');
    same([ '1 год 30 хв', 90], array_slice($tr[0], -2), 'звіт по тікетах: «Витрачено» текстом і в хвилинах');
    same(2097152, \App\Core\UploadLimits::iniBytes('2M'), 'UploadLimits::iniBytes');
    same('OU=IT,OU=Kyiv', \App\Core\LdapDn::ouPath('CN=Ivan,OU=IT,OU=Kyiv,DC=x,DC=y'), 'LdapDn::ouPath');
    same(null, \App\Core\Markdown::safeUrl('javascript:alert(1)'), 'Markdown: javascript:-посилання відхиляється');
    same('https://a.b/c', \App\Core\Markdown::safeUrl('https://a.b/c'), 'Markdown: https-посилання дозволене');
    $md = new \App\Core\Markdown();
    $html = $md->toHtml("<script>alert(1)</script> **x** [a](javascript:alert(1))");
    check(!str_contains($html, '<script>') && !str_contains($html, 'href="javascript'), 'Markdown екранує <script> і не робить javascript:-посилань');
    check(str_contains($html, '<strong>x</strong>'), 'Markdown: жирний текст');
});

group('Пошук: FullText, виділення, фрагменти', function () {
    same(['підключення', 'VPN'], \App\Core\FullText::terms('підключення, VPN!'), 'FullText::terms ділить за розділовими знаками');
    same('+підключ* +VPN*', \App\Core\FullText::boolean('підключ VPN'), 'FullText::boolean: префікси, усі слова обов\'язкові');
    same(null, \App\Core\FullText::boolean('vp'), 'коротке слово → null (запасний LIKE)');
    same(null, \App\Core\FullText::boolean('   '), 'порожній запит → null');
    $S = \App\Services\SearchService::class;
    same('<mark>VPN</mark> не працює', call($S, 'mark', 'VPN не працює', 'vpn'), 'mark: регістр не важливий');
    same('&lt;b&gt; <mark>x</mark>', call($S, 'mark', '<b> x', 'x'), 'mark: HTML екранується');
    $m = call($S, 'mark', 'резервне копіювання серверів', 'копіювання резервне');
    check(substr_count($m, '<mark>') === 2, 'mark: окремі слова запиту виділяються: ' . $m);
    check(call($S, 'has', 'Принтер друкує', 'принтер') === true && call($S, 'has', 'Принтер', 'сканер') === false, 'has');
    $snip = call($S, 'snippet', str_repeat('слово ', 30) . 'ТОКЕН ' . str_repeat('кінець ', 30), 'токен');
    check(str_contains($snip, '<mark>ТОКЕН</mark>') && str_starts_with($snip, '…'), 'snippet: фрагмент навколо збігу з «…»');
    same('', call($S, 'snippet', 'нічого', 'xyz'), 'snippet: без збігу порожній');
    $hits = \App\Services\SearchService::contentSnippets('a <b>token</b> z ' . str_repeat('. ', 200) . ' token again', 'token', 5);
    check(count($hits) === 2 && str_contains($hits[0], '&lt;b&gt;'), 'contentSnippets: окремі фрагменти, HTML екранується');
    same('?hl=' . rawurlencode('а б'), call($S, 'hl', 'а б'), 'hl-параметр кодується');
});

group('Файли бібліотеки: витяг тексту', function () {
    $dir = sys_get_temp_dir() . '/selftest-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $make = static fn(string $name, array $parts): string => mkzip("$dir/$name", $parts);
    $E = \App\Services\LibraryTextExtractor::class;
    file_put_contents("$dir/a.txt", "Перший рядок\nПароль у сейфі");
    same("Перший рядок\nПароль у сейфі", $E::extract("$dir/a.txt", 'a.txt'), 'txt (UTF-8)');
    file_put_contents("$dir/w.csv", mb_convert_encoding("назва;значення\nсейф;1", 'Windows-1251', 'UTF-8'));
    check(str_contains((string) $E::extract("$dir/w.csv", 'w.csv'), 'сейф'), 'csv у Windows-1251 перекодовується');
    $docx = $make('b.docx', ['word/document.xml' => '<w:document xmlns:w="w"><w:body><w:p><w:r><w:t>Крипто</w:t></w:r><w:r><w:t>графія</w:t></w:r></w:p><w:p><w:r><w:t>Другий &amp; абзац</w:t></w:r></w:p></w:body></w:document>']);
    $t = (string) $E::extract($docx, 'b.docx');
    check(str_contains($t, 'Криптографія') && str_contains($t, "\nДругий & абзац"), 'docx: runs склеюються, абзаци розділені, сутності розкодовані');
    $xlsx = $make('c.xlsx', ['xl/sharedStrings.xml' => '<sst><si><t>Сервер А</t></si><si><t>Сервер Б</t></si></sst>']);
    check(str_contains((string) $E::extract($xlsx, 'c.xlsx'), 'Сервер Б'), 'xlsx: спільні рядки');
    $pptx = $make('d.pptx', ['ppt/slides/slide1.xml' => '<p:sld><a:p><a:r><a:t>Слайд один</a:t></a:r></a:p></p:sld>']);
    check(str_contains((string) $E::extract($pptx, 'd.pptx'), 'Слайд один'), 'pptx: текст слайдів');
    $odt = $make('e.odt', ['content.xml' => '<office:document-content><text:p>Текст ODT</text:p></office:document-content>']);
    check(str_contains((string) $E::extract($odt, 'e.odt'), 'Текст ODT'), 'odt');
    $evil = $make('f.docx', ['word/document.xml' => '<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><w:document><w:p>&a;</w:p></w:document>']);
    $r = $E::extract($evil, 'f.docx');
    check($r === null || !str_contains($r, 'root:'), 'docx із зовнішньою сутністю не читає файли');
    file_put_contents("$dir/bad.docx", 'не zip');
    same(null, $E::extract("$dir/bad.docx", 'bad.docx'), 'пошкоджений docx → null, без винятку');
    same(null, $E::extract("$dir/a.txt", 'a.png'), 'зображення → null');
    file_put_contents("$dir/big.txt", str_repeat('слово ', 200000));
    check(mb_strlen((string) $E::extract("$dir/big.txt", 'big.txt')) <= $E::MAX_CHARS, 'ліміт довжини тексту');
    array_map('unlink', glob("$dir/*") ?: []);
    rmdir($dir);
});

group('Оновлення: CHANGELOG, версії, перевірка архівів', function () {
    $U = \App\Services\UpdateService::class;
    $log = "# Історія\n\n## 1.2.0 (реліз)\n\n- нове\n\n## 1.1.0\n\n- середнє\n\n## 1.0.0 (реліз)\n\n- старе\n";
    same(['1.2.0', '1.1.0'], array_keys($U::changelogSince($log, '1.0.0')), 'changelogSince: лише новіші версії');
    same([], $U::changelogSince($log, '1.2.0'), 'changelogSince: нічого, коли версія актуальна');
    check(str_contains($U::changelogSince($log, '1.1.0')['1.2.0'], '- нове'), 'changelogSince: текст розділу');
    check($U::validVersion('1.0.0') && $U::validVersion('2.1') && $U::validVersion('1.0.0-rc1'), 'validVersion: допустимі');
    check(!$U::validVersion('../../x') && !$U::validVersion('1.0.0; rm') && !$U::validVersion(''), 'validVersion: недопустимі');
    check(!$U::webEnabled() || getenv('UPDATE_FROM_WEB') !== false, 'оновлення з веб-сторінки за замовчуванням вимкнено');
    check($U::phpCli() === null || is_executable($U::phpCli()), 'phpCli: знайдено виконуваний файл або null');
    $dir = sys_get_temp_dir() . '/selftest-upd-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $zip = static fn(string $name, array $items): string => mkzip("$dir/$name", $items);
    $base = ['x/VERSION' => '9.9.9', 'x/update.sh' => '#', 'x/app/Core/Config.php' => '<?php'];
    foreach ([
        'шлях з ..' => $base + ['x/../../evil.php' => 'x'],
        'абсолютний шлях' => $base + ['/etc/evil' => 'x'],
        'шлях з зворотною скісною' => $base + ['x\\evil.php' => 'x'],
        'без VERSION' => ['a.txt' => 'x'],
        'символьне посилання' => $base + ['x/app/link' => '/etc/passwd'],
        'некоректна версія' => ['x/VERSION' => '../../x'] + $base,
    ] as $label => $items) {
        $r = $U::stage(mkzip("$dir/t" . md5($label) . '.zip', $items, $label === 'символьне посилання' ? 'x/app/link' : null));
        check($r['ok'] === false, "stage відхиляє архів: {$label}");
        if (!empty($r['token'])) {
            $U::discard($r['token']);
        }
    }
    // коректний архів приймається, у т. ч. без розширення PHP zip
    $good = $U::stage(mkzip("$dir/good.zip", $base + ['x/CHANGELOG.md' => "## 9.9.9\n- ok\n", 'x/.env' => 'SECRET=1', 'x/storage/x.txt' => 'no']));
    check($good['ok'] === true && ($good['version'] ?? '') === '9.9.9', 'stage приймає коректний архів (без ext-zip)');
    if (!empty($good['token'])) {
        $p = $U::preview($good['token']);
        check($p !== null && $p['relation'] > 0 && !is_file($U::updatesDir() . '/' . $good['token'] . '/new/.env') && !is_file($U::updatesDir() . '/' . $good['token'] . '/new/storage/x.txt'), 'preview: версія новіша; .env і storage/ з архіву не беруться');
        $U::discard($good['token']);
    }
    file_put_contents("$dir/notzip.zip", 'hello');
    check($U::stage("$dir/notzip.zip")['ok'] === false, 'stage відхиляє не-ZIP');
    array_map('unlink', glob("$dir/*") ?: []);
    rmdir($dir);
    $hint = implode("\n", $U::commandHint());
    check(str_contains($hint, 'sudo bash update.sh'), 'підказка містить команду update.sh');
});

// ------------------------------------------------------------------ 2. на БД

if (!$withDb) {
    echo "\n(сценарії на БД пропущено — запустіть з --db на копії бази)\n";
} else {
    $db = \App\Core\Database::connection();
    $dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    if (!$force && !preg_match('/test|copy|staging|dev/i', $dbName)) {
        fwrite(STDERR, "\nВідмова: БД «{$dbName}» не схожа на копію (у назві немає test/copy/staging/dev). Запустіть на копії або додайте --force.\n");
        exit(1);
    }
    echo "\nБД: {$dbName}\n";
    $tag = 'SELFTEST' . substr(bin2hex(random_bytes(4)), 0, 6);
    $made = ['users' => [], 'projects' => [], 'tasks' => [], 'tickets' => [], 'docs' => []];
    $role = static fn(string $code): int => (int) $db->query("SELECT id FROM roles WHERE code = " . $db->quote($code))->fetchColumn();
    $mkUser = static function (string $code, ?int $manager = null) use ($db, $tag, $role, &$made): int {
        static $n = 0;
        $n++;
        $db->prepare('INSERT INTO users (email, full_name, password_hash, role_id, is_active, manager_id) VALUES (?, ?, ?, ?, 1, ?)')
            ->execute([strtolower($tag) . $n . '@selftest.local', "{$tag} {$code} {$n}", 'x', $role($code), $manager]);
        $uid = (int) $db->lastInsertId();
        if ($manager !== null) {
            \App\Core\Team::link($uid, $manager);   // user_managers (міграція 070) разом із users.manager_id
        }
        return $made['users'][] = $uid;
    };

    try {
        $adminId = $mkUser('admin');
        $manager = $mkUser('it_manager');
        $head = $mkUser('dept_head', $manager);
        $op = $mkUser('support_operator', $head);
        $stranger = $mkUser('requester');
        \App\Core\Team::reset();

        group('БД: схема й міграції', function () use ($db) {
            $m = new \App\Services\Migrator($db);
            same([], array_map(static fn($p) => is_array($p) ? ($p['version'] ?? $p[0] ?? '?') : (string) $p, $m->pending()), 'усі міграції застосовано (немає pending)');
            foreach ([['tickets', 'ft_subject_description'], ['comments', 'ft_body'], ['projects', 'ft_name_description'], ['tasks', 'ft_title_description'], ['library_versions', 'ft_content']] as [$t, $i]) {
                check(\App\Core\FullText::ready($t, $i), "FULLTEXT {$t}.{$i} існує");
            }
            $bad = [];
            foreach (\App\Services\SystemStatus::collect() as $s) {
                if ($s['title'] !== 'Схема БД (міграції)') {
                    continue;
                }
                foreach ($s['rows'] as $r) {
                    if ($r['level'] === 'fail') {
                        $bad[] = $r['label'] ?? json_encode($r, JSON_UNESCAPED_UNICODE);
                    }
                }
            }
            same([], $bad, 'сторінка «Стан системи»: у схемі немає провалів');
        });

        group('БД: команди й право закриття', function () use ($db, $tag, $manager, $head, $op, $stranger, $adminId, &$made) {
            same($head, \App\Core\Team::nearestHead($op), 'nearestHead(оператор) = його керівник відділу');
            check(in_array($op, \App\Core\Team::subordinateIds($head), true), 'subordinateIds містить підлеглого');
            check(!in_array($stranger, \App\Core\Team::subordinateIds($head), true), 'subordinateIds не містить чужого');
            $pid = mk_project($db, $tag . ' право', $manager, $made);
            $tid = mk_task($db, $pid, $tag . ' задача керівника', 'опис', $manager, $op, $made);
            $task = \App\Models\Task::find($tid);
            check(\App\Models\Task::isManagerial($task), 'задача керівника з підлеглим виконавцем — «від керівництва»');
            same($head, \App\Models\Task::reviewerId($task), 'перевіряючий — керівник відділу виконавця');
            check(!\App\Models\Task::canSetClosed($task, $op, 'support_operator'), 'виконавець НЕ може закрити');
            check(\App\Models\Task::canSetClosed($task, $head, 'dept_head'), 'перевіряючий може закрити');
            check(\App\Models\Task::canSetClosed($task, $adminId, 'admin'), 'адмін може закрити');
            check(\App\Models\Task::managerialStatusProblem($tid, 5, $op, 'support_operator') !== null, 'зміна на «закрита» виконавцем відхиляється');
            same(null, \App\Models\Task::managerialStatusProblem($tid, 4, $op, 'support_operator'), 'статус «Вирішена» виконавець ставить');
            same(null, \App\Models\Task::managerialStatusProblem($tid, 5, $head, 'dept_head'), 'перевіряючий закриває без зауважень');
            $own = mk_task($db, $pid, $tag . ' власна', 'опис', $op, $op, $made);
            check(!\App\Models\Task::isManagerial(\App\Models\Task::find($own)), 'власна задача — не від керівництва');
            // правила задач
            check(\App\Services\TaskRuleService::assigneeProblem($task, $manager) !== null, 'правило не може призначити автора виконавцем задачі від керівництва');
            same(null, \App\Services\TaskRuleService::assigneeProblem($task, $op), 'правило без зміни статусу «керівництва» дозволене');
            $db->prepare('UPDATE tasks SET status_id = 5 WHERE id = ?')->execute([$tid]);
            $closed = \App\Models\Task::find($tid);
            check(\App\Services\TaskRuleService::assigneeProblem($closed, $stranger) !== null, 'правило не міняє перевіряючого в закритій задачі');
        });

        group('БД: доступ у глобальному пошуку', function () use ($db, $tag, $manager, $stranger, $adminId, &$made) {
            $word = strtolower($tag) . 'секрет';
            $pid = mk_project($db, "Проєкт {$word}", $manager, $made);
            mk_task($db, $pid, "Задача {$word}", "опис {$word}", $manager, $manager, $made);
            $titles = static function (int $uid, string $role) use ($word): array {
                $out = [];
                foreach (\App\Services\SearchService::run($word, $uid, $role) as $s) {
                    foreach ($s['items'] as $it) {
                        $out[$s['key']][] = $it['url'];
                    }
                }
                return $out;
            };
            $a = $titles($adminId, 'admin');
            check(!empty($a['projects']) && !empty($a['tasks']), 'адмін знаходить проєкт і задачу');
            $m = $titles($manager, 'it_manager');
            check(!empty($m['projects']) && !empty($m['tasks']), 'автор знаходить свій проєкт і задачу');
            $s = $titles($stranger, 'requester');
            same([], $s, 'стороння людина нічого не знаходить (ні проєкт, ні задачу)');
            check(str_contains(($a['projects'][0] ?? ''), '?hl='), 'посилання з результатів містять ?hl=');
        });

        group('БД: ранжування й запасний LIKE', function () use ($db, $tag, $manager, &$made) {
            $word = strtolower($tag) . 'ранг';
            $pid = mk_project($db, "Ранжування {$tag}", $manager, $made);
            $descOnly = mk_task($db, $pid, 'Інша назва без слова', "тут {$word} лише в описі", $manager, $manager, $made);
            $titleHit = mk_task($db, $pid, "Назва {$word}", 'порожній опис', $manager, $manager, $made);
            $res = \App\Services\SearchService::run($word, $manager, 'it_manager');
            $tasks = [];
            foreach ($res as $s) {
                if ($s['key'] === 'tasks') {
                    $tasks = array_map(static fn($i) => $i['url'], $s['items']);
                }
            }
            $first = explode('?', $tasks[0] ?? '')[0];
            same("/tasks/{$titleHit}", $first, 'збіг у заголовку вище за збіг лише в описі');
            check(in_array("/tasks/{$descOnly}", array_map(static fn($u) => explode('?', $u)[0], $tasks), true), 'збіг в описі теж знайдено');
            $mid = mb_substr($word, 3, 8); // підрядок усередині слова — лише через LIKE
            $found = false;
            foreach (\App\Services\SearchService::run($mid, $manager, 'it_manager') as $s) {
                $found = $found || ($s['key'] === 'tasks' && $s['total'] > 0);
            }
            check($found, 'підрядок усередині слова знаходиться запасним LIKE');
        });

        group('БД: пошук усередині файлів бібліотеки', function () use ($db, $tag, $manager, $stranger, &$made) {
            $word = strtolower($tag) . 'вміст';
            $db->prepare("INSERT INTO library_documents (title, visibility, created_by) VALUES (?, 'all', ?)")->execute(["Док {$tag}", $manager]);
            $doc = (int) $db->lastInsertId();
            $made['docs'][] = $doc;
            $db->prepare('INSERT INTO library_versions (document_id, version_no, original_name, stored_name, mime_type, size_bytes, content_text, content_indexed, uploaded_by) VALUES (?, 1, ?, ?, ?, 1, ?, 1, ?)')
                ->execute([$doc, 'x.txt', substr(md5($tag), 0, 32), 'text/plain', "Початок. Усередині файлу є слово {$word} у тексті.", $manager]);
            $r = \App\Models\LibraryDocument::search('it_manager', $manager, null, $word, 1, 10);
            same(1, (int) $r['total'], 'документ знайдено за словом із вмісту файлу');
            $r = \App\Models\LibraryDocument::search('it_manager', $manager, null, mb_substr($word, 2, 8), 1, 10);
            same(1, (int) $r['total'], 'короткий/підрядковий запит знаходить вміст запасним LIKE');
            same(0, (int) \App\Models\LibraryDocument::search('it_manager', $manager, null, strtolower($tag) . 'нема', 1, 10)['total'], 'відсутнє слово — нічого');
            $row = $r['rows'][0] ?? [];
            check(str_contains(\App\Services\SearchService::librarySnippet($row, $word), 'у файлі:'), 'фрагмент «у файлі: …» у результатах');
            $db->prepare("UPDATE library_documents SET visibility = 'admin' WHERE id = ?")->execute([$doc]);
            same(0, (int) \App\Models\LibraryDocument::search('requester', $stranger, null, $word, 1, 10)['total'], 'вміст закритого документа не видно стороннім');
        });

        group('БД: кілька керівників відділів у користувача', function () use ($db, $manager, $head, $op, $stranger, &$made) {
            $T = \App\Core\Team::class;
            check($T::linksReady(), 'таблиця user_managers існує (міграція 070)');
            $role = static fn(string $c): int => (int) $db->query('SELECT id FROM roles WHERE code = ' . $db->quote($c))->fetchColumn();
            $db->prepare('INSERT INTO users (email, full_name, password_hash, role_id, is_active) VALUES (?, ?, ?, ?, 1)')
               ->execute([strtolower(bin2hex(random_bytes(3))) . '@selftest.local', 'selftest head2', 'x', $role('dept_head')]);
            $head2 = (int) $db->lastInsertId();
            $made['users'][] = $head2;
            $T::reset();
            same([$head], $T::managerIds($op), 'у оператора один керівник');
            check(!in_array($op, $T::subordinateIds($head2), true), 'до другого відділу ще не належить');
            $T::link($op, $head2);
            same([$head, $head2], $T::managerIds($op), 'тепер у оператора два керівники');
            check(in_array($op, $T::subordinateIds($head), true) && in_array($op, $T::subordinateIds($head2), true), 'підлеглий в обох командах');
            check($T::containsUser($head2, $op) && $T::scopeContains($head2, $op), 'другий керівник бачить підлеглого');
            same([$head, $head2], $T::nearestHeads($op), 'nearestHeads повертає обох керівників');
            same($head, $T::nearestHead($op), 'nearestHead — з меншим id');
            same($head, (int) $db->query("SELECT manager_id FROM users WHERE id = {$op}")->fetchColumn(), 'основний керівник не змінився');
            $T::unlink($op, $head);
            check(!in_array($op, $T::subordinateIds($head), true) && in_array($op, $T::subordinateIds($head2), true), 'зняття з одного відділу лишає інший');
            same($head2, (int) $db->query("SELECT manager_id FROM users WHERE id = {$op}")->fetchColumn(), 'основним став інший керівник');
            $T::link($op, $head);
            $T::unlink($op, $head2);
            $T::unlink($op, $head);
            $T::reset();
            same([], $T::managerIds($op), 'після зняття з усіх керівників немає');
            $T::link($op, $head);   // як було
            $T::reset();
        });

        group('БД: запити на зміни (Change)', function () use ($db, $manager, $head, $op, $stranger, $adminId, &$made) {
            $M = \App\Models\ChangeRequest::class;
            check($M::ready(), 'таблиці Change існують (міграція 069)');
            check(\App\Core\Access::canUseChanges('support_operator') && !\App\Core\Access::canUseChanges('requester'), 'доступ до Change: оператор так, заявник ні');
            $d = ['title' => 'Тест зміни', 'change_type' => 'normal', 'risk' => 'low', 'description' => 'опис', 'reason' => '', 'impact' => '', 'rollback_plan' => '',
                'window_start' => '2030-01-01 22:00:00', 'window_end' => '2030-01-02 02:00:00', 'ticket_id' => null];
            $id = $M::save(null, $d, $op);
            $made['changes'][] = $id;
            $c = $M::find($id);
            same('draft', $c['status'], 'нова зміна — чернетка');
            check($M::submitProblems($c, [$manager]) !== [], 'без плану відкату надсилати не можна');
            $d['rollback_plan'] = 'відкат';
            $M::save($id, $d, $op);
            $c = $M::find($id);
            check($M::submitProblems($c, []) !== [], 'без погоджувачів надсилати не можна');
            check($M::submitProblems($c, [$op]) !== [], 'автор не може бути погоджувачем');
            same([], $M::submitProblems($c, [$manager, $head]), 'повна зміна проходить перевірку');
            $M::submit($id, [$manager, $head]);
            same('pending', $M::find($id)['status'], 'після надсилання — на погодженні');
            check(!$M::canEdit($M::find($id), $op, 'support_operator'), 'на погодженні редагувати не можна');
            check($M::canView($M::find($id), $head, 'dept_head') && !$M::canView($M::find($id), $stranger, 'requester'), 'бачать погоджувач і не бачить стороннього');
            same('pending', $M::decide($id, $manager, true, ''), 'одна згода з двох — ще очікування');
            same('approved', $M::decide($id, $head, true, ''), 'усі погодили — погоджено');
            check(!$M::transition($id, 'implemented'), 'з «погоджено» одразу «виконано» не можна');
            check($M::transition($id, 'in_progress') && $M::transition($id, 'implemented'), 'погоджено → виконується → виконано');

            $d['change_type'] = 'emergency';
            $e = $M::save(null, $d, $op);
            $made['changes'][] = $e;
            $M::submit($e, [$manager, $head]);
            same('approved', $M::decide($e, $head, true, ''), 'термінова зміна: достатньо однієї згоди');

            $r = $M::save(null, $d + [], $op);
            $made['changes'][] = $r;
            $M::submit($r, [$manager, $head]);
            same('rejected', $M::decide($r, $manager, false, 'ні'), 'будь-яка відмова відхиляє запит');
            check($M::canEdit($M::find($r), $op, 'support_operator'), 'відхилений можна виправити й надіслати знову');
        });
    } finally {
        // прибирання: усе, що створили; порядок з урахуванням зовнішніх ключів
        $in = static fn(array $ids): string => $ids ? implode(',', array_map('intval', $ids)) : '0';
        $tryExec = static function (string $sql) use ($db): void {
            try {
                $db->exec($sql);
            } catch (\Throwable) {
            }
        };
        $tryExec('DELETE FROM change_requests WHERE id IN (' . $in($made['changes'] ?? []) . ')');
        $tryExec('DELETE FROM library_documents WHERE id IN (' . $in($made['docs']) . ')');
        $tryExec('DELETE FROM time_logs WHERE task_id IN (' . $in($made['tasks']) . ')');
        $tryExec('DELETE FROM tasks WHERE id IN (' . $in($made['tasks']) . ')');
        $tryExec('DELETE FROM projects WHERE id IN (' . $in($made['projects']) . ')');
        $tryExec('DELETE FROM users WHERE manager_id IN (' . $in($made['users']) . ') AND id IN (' . $in($made['users']) . ')');
        $tryExec('DELETE FROM users WHERE id IN (' . $in($made['users']) . ')');
        $left = (int) $db->query("SELECT COUNT(*) FROM users WHERE email LIKE '" . strtolower($tag) . "%@selftest.local'")->fetchColumn();
        echo "\n" . ($left === 0 ? "Тестові записи прибрано.\n" : "УВАГА: лишилось тестових користувачів: {$left} (мітка {$tag}).\n");
    }
}

function mk_project(\PDO $db, string $name, int $createdBy, array &$made): int
{
    $db->prepare('INSERT INTO projects (name, created_by) VALUES (?, ?)')->execute([$name, $createdBy]);
    return $made['projects'][] = (int) $db->lastInsertId();
}

function mk_task(\PDO $db, int $projectId, string $title, string $desc, int $author, int $assignee, array &$made): int
{
    $type = (int) $db->query('SELECT id FROM task_types ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO tasks (project_id, title, description, status_id, author_id, assignee_id, type_id) VALUES (?, ?, ?, 2, ?, ?, ?)')
        ->execute([$projectId, $title, $desc, $author, $assignee, $type]);
    return $made['tasks'][] = (int) $db->lastInsertId();
}

$s = $GLOBALS['st'];
echo "\nПідсумок: пройдено {$s['pass']}, провалено {$s['fail']}\n";
exit($s['fail'] === 0 ? 0 : 1);
