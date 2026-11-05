<?php
$e = static fn($v) => \App\Core\View::e((string) $v);
$q = static fn(array $over) => '/changes?' . http_build_query(array_filter($over + $f, static fn($v) => $v !== '' && $v !== null));
$pages = (int) ceil($total / max(1, $perPage));
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h3 class="mb-0">Запити на зміни <span class="text-muted fs-6">(<?= (int) $total ?>)</span></h3>
    <?php if ($ready): ?><a href="/changes/new" class="btn btn-primary">+ Новий запит</a><?php endif; ?>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
<?php if (!$ready): ?>
    <div class="alert alert-warning">Розділ ще не налаштовано — виконайте <code>sudo bash update.sh</code> (міграція 069).</div>
<?php else: ?>
<?php if ($pending > 0): ?>
    <div class="alert alert-warning py-2">Чекають вашого рішення: <strong><?= (int) $pending ?></strong> — <a href="<?= $e($q(['scope' => 'approve', 'page' => null])) ?>">показати</a>.</div>
<?php endif; ?>
<form method="get" action="/changes" class="row g-2 mb-3 align-items-end">
    <div class="col-md-3"><input type="search" name="q" value="<?= $e($f['q']) ?>" class="form-control" placeholder="Назва, опис або №" maxlength="100"></div>
    <div class="col-md-3"><select name="status" class="form-select"><option value="">Усі статуси</option>
        <?php foreach (\App\Models\ChangeRequest::STATUSES as $k => $label): ?><option value="<?= $e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><select name="scope" class="form-select">
        <?php foreach (['' => 'Усі, що я бачу', 'mine' => 'Мої запити', 'approve' => 'Чекають мого рішення', 'active' => 'Активні (погодження, вікно, виконання)'] as $k => $label): ?><option value="<?= $e($k) ?>" <?= $f['scope'] === $k ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Показати</button> <a href="/changes" class="btn btn-link">Скинути</a></div>
</form>
<table class="table table-bordered bg-white align-middle table-sm">
    <thead><tr><th>№</th><th>Назва</th><th>Тип / ризик</th><th>Вікно робіт</th><th>Автор</th><th>Статус</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">Запитів немає</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="text-nowrap"><a href="/changes/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
            <td><a href="/changes/<?= (int) $r['id'] ?>" class="fw-semibold"><?= $e($r['title']) ?></a></td>
            <td class="small text-nowrap"><?= $e(\App\Models\ChangeRequest::TYPES[$r['change_type']]) ?><br>
                <span class="badge text-bg-<?= ['low' => 'success', 'medium' => 'warning', 'high' => 'danger'][$r['risk']] ?>"><?= $e(\App\Models\ChangeRequest::RISKS[$r['risk']]) ?></span></td>
            <td class="small text-nowrap"><?= $r['window_start'] ? $e(\App\Core\TimeFormat::dateTime((string) $r['window_start'])) . '<br>' . $e(\App\Core\TimeFormat::dateTime((string) $r['window_end'])) : '<span class="text-muted">—</span>' ?></td>
            <td class="small"><?= $e($r['requester_name'] ?? '—') ?></td>
            <td class="text-nowrap"><span class="badge text-bg-<?= $e(\App\Models\ChangeRequest::BADGES[$r['status']]) ?>"><?= $e(\App\Models\ChangeRequest::STATUSES[$r['status']]) ?></span>
                <?php if ($r['status'] === 'pending'): ?><div class="small text-muted"><?= (int) $r['approved_count'] ?>/<?= (int) $r['approvers_count'] ?> погоджено</div><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($pages > 1): ?>
<nav><ul class="pagination pagination-sm">
    <?php for ($p = 1; $p <= $pages; $p++): ?><li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $e($q(['page' => $p > 1 ? $p : null])) ?>"><?= $p ?></a></li><?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php endif; ?>
