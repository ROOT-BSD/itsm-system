<div class="mb-3">
    <?php require __DIR__ . '/_back.php'; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Черги тікетів</h3>
    <a href="/admin/queues/create" class="btn btn-primary">+ Нова черга</a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<?php $wc = \App\Services\WorkCalendar::config(); $e = static fn($v) => \App\Core\View::e($v); ?>
<div class="card shadow-sm mb-3 form-card-md">
    <div class="card-header">Робочий календар для SLA</div>
    <div class="card-body">
        <p class="small text-muted mb-2">Якщо ввімкнути, час SLA (перша відповідь і вирішення) рахується лише в робочі години робочих днів: за межами графіка та у свята відлік не йде, а тікет, створений вночі чи у вихідний, починає відлік з наступного відкриття. Вимкнено — нормативи рахуються цілодобово. Календар спільний для всіх черг.</p>
        <form method="post" action="/admin/queues/work-calendar" class="row g-2">
            <?= \App\Core\Csrf::field() ?>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="enabled" id="wc_enabled" value="1" <?= $wc['enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="wc_enabled">Враховувати робочий календар у SLA</label></div></div>
            <div class="col-12 d-flex flex-wrap gap-3">
                <?php foreach (\App\Services\WorkCalendar::DAY_NAMES as $n => $l): ?>
                    <label class="form-check-label small"><input class="form-check-input" type="checkbox" name="days[]" value="<?= $n ?>" <?= in_array($n, $wc['days'], true) ? 'checked' : '' ?>> <?= $l ?></label>
                <?php endforeach; ?>
            </div>
            <div class="col-auto"><label class="form-label small mb-0">Початок</label><input type="time" name="start" class="form-control form-control-sm" value="<?= $e($wc['start']) ?>" required></div>
            <div class="col-auto"><label class="form-label small mb-0">Кінець</label><input type="time" name="end" class="form-control form-control-sm" value="<?= $e($wc['end']) ?>" required></div>
            <div class="col-md-6"><label class="form-label small mb-0">Свята (по одному в рядок або через кому)</label>
                <textarea name="holidays" id="wc_holidays" rows="5" class="form-control form-control-sm" placeholder="01.01&#10;24.08&#10;05.04.2027"><?= $e(\App\Services\WorkCalendar::formatDates($wc['holidays'])) ?></textarea>
                <div class="form-text">«ДД.ММ» — щороку; «ДД.ММ.РРРР» — одноразово. <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="wc_fill_ua">Додати типові свята України</button> (без рухомих — Великдень, Трійця; перевірте за чинним законодавством і режимом воєнного стану).</div></div>
            <div class="col-md-6"><label class="form-label small mb-0">Робочі дні-перенесення (напр., субота замість святкового дня)</label>
                <textarea name="workdays" rows="5" class="form-control form-control-sm" placeholder="12.01.2027"><?= $e(\App\Services\WorkCalendar::formatDates($wc['workdays'])) ?></textarea>
                <div class="form-text">Формат «ДД.ММ.РРРР»; такий день вважається робочим навіть у неробочий день тижня.</div></div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary" type="submit">Зберегти календар</button></div>
        </form>
        <script src="<?= $e(\App\Core\View::asset('/assets/js/work-calendar.js')) ?>" defer data-ua="<?= $e(implode(',', \App\Services\WorkCalendar::UA_DEFAULT_HOLIDAYS)) ?>"></script>
    </div>
</div>

<div class="card shadow-sm mb-3 form-card-md">
    <div class="card-header">SLA за пріоритетом тікета</div>
    <div class="card-body">
        <p class="small text-muted mb-2">Норматив черги задає час для «звичайного» пріоритету. Для інших пріоритетів він множиться на відсоток: 50 % — вдвічі швидше, 150 % — повільніше.</p>
        <?php $pc = \App\Models\Ticket::slaPriorityPercents(); ?>
        <form method="post" action="/admin/queues/sla-priority" class="row g-2 align-items-end">
            <?= \App\Core\Csrf::field() ?>
            <?php foreach (['low' => 'Низький', 'high' => 'Високий', 'critical' => 'Критичний'] as $k => $l): ?>
                <div class="col-auto"><label class="form-label small mb-0"><?= $l ?>, %</label>
                    <input type="number" name="pct_<?= $k ?>" min="10" max="1000" class="form-control form-control-sm" style="width:6rem" value="<?= (int) $pc[$k] ?>" required></div>
            <?php endforeach; ?>
            <div class="col-auto"><button class="btn btn-sm btn-outline-primary" type="submit">Зберегти</button></div>
        </form>
    </div>
</div>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>Назва</th><th>Опис</th><th>Тікетів у черзі</th><th>SLA: перша відповідь / вирішення (хв)</th><th>Автопризначення оператора</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (empty($queues)): ?>
        <tr><td colspan="6" class="text-center text-muted">Черг ще немає</td></tr>
    <?php else: foreach ($queues as $q): ?>
        <tr>
            <td><?= \App\Core\View::e($q['name']) ?></td>
            <td><?= \App\Core\View::e($q['description'] ?? '—') ?></td>
            <td><?= (int)$q['tickets_count'] ?></td>
            <td>
                <form method="post" action="/admin/queues/<?= (int)$q['id'] ?>/sla" class="d-flex gap-1 align-items-center">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="number" name="first_response_minutes" class="form-control form-control-sm sla-minutes-input"
                           min="1" value="<?= $q['first_response_minutes'] !== null ? (int)$q['first_response_minutes'] : '' ?>" required>
                    <span class="text-muted">/</span>
                    <input type="number" name="resolution_minutes" class="form-control form-control-sm sla-minutes-input"
                           min="1" value="<?= $q['resolution_minutes'] !== null ? (int)$q['resolution_minutes'] : '' ?>" required>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Зберегти</button>
                </form>
                <?php if ($q['first_response_minutes'] === null): ?>
                    <div class="form-text text-warning">Норматив ще не налаштовано — прострочення для цієї черги не рахується.</div>
                <?php endif; ?>
            </td>
            <td>
                <form method="post" action="/admin/queues/<?= (int)$q['id'] ?>/default-operator">
                    <?= \App\Core\Csrf::field() ?>
                    <select name="default_operator_id" class="form-select form-select-sm auto-submit-select user-select">
                        <option value="">— без автопризначення —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (int)($q['default_operator_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= \App\Core\View::e($u['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if (!empty($q['default_operator_id'])): ?>
                    <div class="form-text">Новий тікет у цій черзі одразу отримає: <?= \App\Core\View::e($q['default_operator_name']) ?></div>
                <?php endif; ?>
            </td>
            <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="/admin/queues/<?= (int)$q['id'] ?>/edit">Змінити</a>
                <?php if ((int)$q['tickets_count'] === 0 && count($queues) > 1): ?>
                <form method="post" action="/admin/queues/<?= (int)$q['id'] ?>/delete" class="d-inline" data-confirm="Видалити порожню чергу «<?= \App\Core\View::e($q['name']) ?>»? Правила черги з умовою «лише в цій черзі» буде видалено разом із нею.">
                    <?= \App\Core\Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-danger" type="submit">Видалити</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
