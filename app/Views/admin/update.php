<?php
$e = static fn($v) => \App\Core\View::e($v);
/** Простий показ розділу CHANGELOG: заголовок, пункти списку, **жирне** і `код` (екранування ДО підстановок). */
$notes = static function (string $md) use ($e): string {
    $out = '';
    foreach (preg_split('/\R/', $md) ?: [] as $i => $line) {
        $line = rtrim($line);
        if ($line === '') {
            continue;
        }
        $t = $e(preg_replace('/^\s*[-*]\s+/', '', $line));
        $t = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $t);
        $t = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $t);
        $out .= $i === 0 ? '<h6 class="mt-2">' . $t . '</h6>' : (preg_match('/^\s*[-*]\s+/', $line) ? '<div class="small ms-3">• ' . $t . '</div>' : '<div class="small">' . $t . '</div>');
    }
    return $out;
};
?>
<div class="mb-3"><?php require __DIR__ . '/_back.php'; ?></div>

<h3 class="mb-3">Оновлення системи</h3>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4"><div class="card-body">
    <h5 class="card-title">Перевірка нової версії</h5>
    <p class="mb-2">Встановлена версія: <strong><?= $e($current) ?></strong>
        <?php if ($check): ?>
            · остання перевірка: <?= $e(date('d.m.Y H:i', strtotime((string) $check['at']))) ?> —
            <?php if (empty($check['ok'])): ?><span class="text-danger"><?= $e($check['error'] ?? 'помилка') ?></span>
            <?php elseif ($check['available']): ?><span class="badge text-bg-warning">доступна версія <?= $e($check['version']) ?></span>
            <?php else: ?><span class="text-success">встановлена остання версія</span><?php endif; ?>
        <?php endif; ?>
    </p>
    <form method="post" action="/admin/update/check" class="mb-2">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit" class="btn btn-outline-primary btn-sm">Перевірити зараз</button>
        <span class="small text-muted ms-2">Запит іде до github.com/<?= $e(\App\Services\UpdateService::REPO) ?> (лише номер версії й CHANGELOG).</span>
    </form>

    <?php if ($check && !empty($check['available'])): ?>
        <h6 class="mt-3">Як оновитись</h6>
        <pre class="bg-body-tertiary border rounded p-2 small mb-2"><?= $e(implode("\n", $hint)) ?></pre>
        <p class="small text-muted mb-3"><code>update.sh</code> застосує міграції БД, оновить гайди у вікі та права на файли. Перед оновленням зробіть <a href="/admin/backup">резервну копію</a>.</p>
        <h6>Що нового (з CHANGELOG)</h6>
        <?php if (!empty($check['notes'])): ?>
            <div class="border rounded p-2" style="max-height: 24rem; overflow:auto">
                <?php foreach ($check['notes'] as $text): ?><?= $notes((string) $text) ?><?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="small text-muted">У CHANGELOG немає розділів для версій новіших за встановлену.</p>
        <?php endif; ?>
    <?php endif; ?>
</div></div>

<div class="card shadow-sm mb-4"><div class="card-body">
    <h5 class="card-title">Оновлення з архіву, завантаженого на комп’ютер</h5>
    <?php if (!$webEnabled): ?>
        <div class="alert alert-secondary small mb-0">
            Оновлення з браузера <strong>вимкнено</strong>: це заміна програмного коду, тому її вмикає лише той, хто має доступ до сервера.
            Додайте в <code>.env</code> рядок <code>UPDATE_FROM_WEB=true</code> (і прибирайте його, коли функція не потрібна). Поки вимкнено — оновлюйтесь командою вище:
            розпакуйте архів поверх каталогу системи (без <code>.env</code>) і виконайте <code>sudo bash update.sh</code>.
        </div>
    <?php else: ?>
        <?php if (!$writable): ?>
            <div class="alert alert-danger small">Каталог <code><?= $e($root) ?></code> недоступний для запису веб-серверу, тож застосувати оновлення звідси не вийде. Виконайте <code>sudo bash update.sh</code> (він виставить права), або оновіть вручну.</div>
        <?php endif; ?>
        <?php if ($phpCli === null): ?>
            <div class="alert alert-warning small">Не знайдено PHP CLI для запуску міграцій — застосування з браузера недоступне; використайте <code>sudo bash update.sh</code>.</div>
        <?php endif; ?>

        <?php if ($preview): ?>
            <div class="border rounded p-3 mb-3">
                <h6 class="mb-2">Архів завантажено: версія <?= $e($preview['version']) ?>
                    <small class="text-muted">(у вас <?= $e($preview['current']) ?>)</small></h6>
                <?php if ($preview['relation'] < 0): ?>
                    <div class="alert alert-danger small">Архів <strong>старіший</strong> за встановлену версію — повернення назад не підтримується (схема БД уже оновлена).</div>
                <?php elseif ($preview['relation'] === 0): ?>
                    <div class="alert alert-info small">Та сама версія: буде перезаписано лише змінені файли (корисно для виправлень).</div>
                <?php endif; ?>
                <p class="small mb-2">Нових файлів: <strong><?= (int) $preview['new'] ?></strong>, змінених: <strong><?= (int) $preview['changed'] ?></strong>, без змін: <?= (int) $preview['same'] ?>.
                    <code>.env</code>, <code>storage/</code> і дані не зачіпаються; файли, яких немає в архіві, не видаляються.</p>
                <?php if ($preview['unwritable_total'] > 0): ?>
                    <div class="alert alert-danger small">Немає прав на запис для <?= (int) $preview['unwritable_total'] ?> файл(ів), напр.: <code><?= $e(implode(', ', $preview['unwritable'])) ?></code>. Виправте права (<code>sudo bash update.sh</code> чи <code>chown</code>) або оновіть вручну.</div>
                <?php endif; ?>
                <?php if ($preview['sample']): ?>
                    <details class="mb-2"><summary class="small">Що зміниться (перші <?= count($preview['sample']) ?>)</summary>
                        <pre class="small mb-0"><?= $e(implode("\n", $preview['sample'])) ?></pre></details>
                <?php endif; ?>
                <h6>Що нового (з CHANGELOG архіву)</h6>
                <?php if ($preview['notes']): ?>
                    <div class="border rounded p-2 mb-3" style="max-height: 20rem; overflow:auto">
                        <?php foreach ($preview['notes'] as $text): ?><?= $notes((string) $text) ?><?php endforeach; ?>
                    </div>
                <?php else: ?><p class="small text-muted">У CHANGELOG архіву немає розділів новіших за встановлену версію.</p><?php endif; ?>

                <?php if ($preview['relation'] >= 0): ?>
                <form method="post" action="/admin/update/apply" class="row g-2 align-items-end">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="token" value="<?= $e($preview['token']) ?>">
                    <div class="col-12">
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="backup" value="1" id="upd-backup" checked>
                            <label class="form-check-label" for="upd-backup">Спершу створити резервну копію (БД і файли) — рекомендовано</label></div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small mb-1">Для підтвердження введіть «<?= $e($confirmWord) ?>»</label>
                        <input type="text" name="confirm" class="form-control" autocomplete="off" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-danger" <?= $preview['unwritable_total'] > 0 || !$writable || $phpCli === null ? 'disabled' : '' ?> data-confirm="Застосувати оновлення до версії <?= $e($preview['version']) ?>? Файли програми буде замінено, міграції БД виконано. У разі збою файли повернуться автоматично.">Застосувати оновлення</button>
                    </div>
                </form>
                <?php endif; ?>
                <form method="post" action="/admin/update/discard" class="mt-2">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="token" value="<?= $e($preview['token']) ?>">
                    <button type="submit" class="btn btn-link btn-sm p-0">Скасувати й видалити завантажений архів</button>
                </form>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/update/upload" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= \App\Core\Csrf::field() ?>
            <div class="col-md-7">
                <label class="form-label small mb-1">ZIP-архів системи (пакет релізу або архів із GitHub)</label>
                <input type="file" name="archive" class="form-control" accept=".zip,application/zip" required>
            </div>
            <div class="col-auto"><button type="submit" class="btn btn-primary">Завантажити й перевірити</button></div>
            <div class="col-12 small text-muted">Після застосування SSH і <code>update.sh</code> <strong>не потрібні</strong>: система сама виконує міграції БД, створює каталоги сховища, очищає кеш шрифтів PDF, оновлює гайди у вікі, переносить текст вікі у файли й індексує файли бібліотеки. Лише root зможе (необов’язково) вирівняти власника й права всього дерева — <code>sudo bash update.sh</code>.</div>
            <div class="col-12 small text-muted">Максимальний розмір, який приймає сервер: <?= $e($uploadMax) ?>. Спершу архів лише перевіряється й розпаковується окремо — нічого не змінюється, доки ви не підтвердите.</div>
        </form>
    <?php endif; ?>
</div></div>
