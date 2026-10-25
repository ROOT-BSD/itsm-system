<?php
// Спільна форма створення/редагування розкладу. Очікує: $sch (масив або null), $action, $submitLabel, $projects, $users, $categories, $isAdmin.
$e = static fn($v) => \App\Core\View::e($v);
$wd = \App\Models\ReportSchedule::WEEKDAYS;
$fm = \App\Models\ReportSchedule::FORMATS;
$isMonthly = ($sch['frequency'] ?? '') === 'monthly';
$isTickets = ($sch['report_type'] ?? 'time') === 'tickets';
$types = \App\Models\ReportSchedule::TYPES;
?>
    <form method="post" action="<?= $e($action) ?>" class="row g-3" id="schedule-form">
        <?= \App\Core\Csrf::field() ?>
        <div class="col-md-3">
            <label class="form-label" for="sch-type">Звіт</label>
            <select name="report_type" id="sch-type" class="form-select">
                <?php foreach ($types as $k => $v): if ($k === 'tickets' && empty($canTicketsReport) && !$isTickets) { continue; } ?>
                    <option value="<?= $e($k) ?>" <?= ($sch['report_type'] ?? 'time') === $k ? 'selected' : '' ?>><?= $e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sch-name">Назва</label>
            <input type="text" name="name" id="sch-name" class="form-control" maxlength="150" required value="<?= $e($sch['name'] ?? '') ?>" placeholder="напр. Облік часу команди — щотижня">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sch-freq">Періодичність</label>
            <select name="frequency" id="sch-freq" class="form-select">
                <option value="weekly" <?= ($sch['frequency'] ?? 'weekly') === 'weekly' ? 'selected' : '' ?>>Щотижня</option>
                <option value="monthly" <?= ($sch['frequency'] ?? '') === 'monthly' ? 'selected' : '' ?>>Щомісяця</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sch-format">Формат файлу</label>
            <select name="format" id="sch-format" class="form-select">
                <?php foreach ($fm as $k => $v): ?><option value="<?= $e($k) ?>" <?= ($sch['format'] ?? 'pdf') === $k ? 'selected' : '' ?>><?= $e($v) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 <?= $isMonthly ? 'd-none' : '' ?>" id="sch-weekday-box">
            <label class="form-label" for="sch-weekday">День тижня</label>
            <select name="weekday" id="sch-weekday" class="form-select">
                <?php foreach ($wd as $k => $v): ?><option value="<?= (int) $k ?>" <?= (int) ($sch['weekday'] ?? 1) === (int) $k ? 'selected' : '' ?>><?= $e($v) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 <?= $isMonthly ? '' : 'd-none' ?>" id="sch-monthday-box">
            <label class="form-label" for="sch-monthday">Число місяця</label>
            <select name="month_day" id="sch-monthday" class="form-select">
                <?php for ($d = 1; $d <= 28; $d++): ?><option value="<?= $d ?>" <?= (int) ($sch['month_day'] ?? 1) === $d ? 'selected' : '' ?>><?= $d ?></option><?php endfor; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sch-time">Не раніше (час)</label>
            <input type="time" name="send_time" id="sch-time" class="form-control" required value="<?= sprintf('%02d:%02d', (int) ($sch['send_hour'] ?? 8), (int) ($sch['send_minute'] ?? 0)) ?>">
        </div>
        <div class="col-md-4 <?= $isTickets ? '' : 'd-none' ?>" data-sch-for="tickets">
            <label class="form-label" for="sch-queue">Черга</label>
            <select name="queue_id" id="sch-queue" class="form-select">
                <option value="">— усі черги —</option>
                <?php foreach ($queues ?? [] as $q): ?><option value="<?= (int) $q['id'] ?>" <?= (int) ($sch['queue_id'] ?? 0) === (int) $q['id'] ? 'selected' : '' ?>><?= $e($q['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 <?= $isTickets ? '' : 'd-none' ?>" data-sch-for="tickets">
            <label class="form-label" for="sch-op">Оператор</label>
            <select name="operator_id" id="sch-op" class="form-select user-select">
                <option value="">— усі оператори —</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) ($sch['operator_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= $e($u['full_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 small text-muted <?= $isTickets ? '' : 'd-none' ?>" data-sch-for="tickets">«Звіт по тікетах»: тікети, створені за попередній тиждень чи місяць. Адміністратор підрозділу отримує дані лише свого підрозділу.</div>
        <div class="col-md-4 <?= $isTickets ? 'd-none' : '' ?>" data-sch-for="time">
            <label class="form-label" for="sch-project">Проєкт</label>
            <select name="project_id" id="sch-project" class="form-select">
                <option value="">— усі доступні проєкти —</option>
                <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($sch['project_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= $e($p['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 <?= $isTickets ? 'd-none' : '' ?>" data-sch-for="time">
            <label class="form-label" for="sch-user">Користувач (чий облік часу)</label>
            <select name="user_id" id="sch-user" class="form-select user-select">
                <option value="">— усі користувачі —</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) ($sch['log_user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= $e($u['full_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 <?= $isTickets ? 'd-none' : '' ?>" data-sch-for="time">
            <label class="form-label" for="sch-cat">Категорія активності</label>
            <input type="text" name="category" id="sch-cat" class="form-control" list="sch-cats" maxlength="100" value="<?= $e($sch['category'] ?? '') ?>" placeholder="необов'язково">
            <datalist id="sch-cats"><?php foreach ($categories as $c): ?><option value="<?= $e($c) ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="col-12">
            <label class="form-label" for="sch-extra">Додаткові одержувачі (email через кому; ви отримуватимете звіт завжди)</label>
            <input type="text" name="extra_recipients" id="sch-extra" class="form-control" value="<?= $e($sch['extra_recipients'] ?? '') ?>" placeholder="<?= $isAdmin ? 'будь-які адреси' : 'лише адреси користувачів системи' ?>">
        </div>
        <div class="col-12"><button type="submit" class="btn btn-primary"><?= $e($submitLabel) ?></button><?php if ($sch): ?> <a href="/reports/schedules" class="btn btn-outline-secondary">Скасувати</a><?php endif; ?></div>
    </form>
<script nonce="<?= \App\Core\Csp::nonce() ?>">
document.getElementById('sch-type')?.addEventListener('change', function () {
    document.querySelectorAll('[data-sch-for]').forEach(function (el) { el.classList.toggle('d-none', el.getAttribute('data-sch-for') !== this.value); }, this);
});
document.getElementById('sch-freq')?.addEventListener('change', function () {
    const monthly = this.value === 'monthly';
    document.getElementById('sch-weekday-box').classList.toggle('d-none', monthly);
    document.getElementById('sch-monthday-box').classList.toggle('d-none', !monthly);
});
</script>
