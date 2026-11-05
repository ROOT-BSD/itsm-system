<?php use App\Core\View; use App\Core\Access; use App\Core\Auth; use App\Core\TimeFormat;
$self = (int) Auth::id() === (int) $head['id']; ?>
<?php if (!$self): ?><a href="/team" class="text-decoration-none">&larr; Керівники відділів</a><?php endif; ?>
<h3 class="mb-1 mt-1"><?= $self ? 'Моя команда' : 'Команда: ' . View::e($head['full_name']) ?></h3>
<p class="text-muted">
    Керівник відділу <strong><?= View::e($head['full_name']) ?></strong>, підпорядкований: <strong><?= View::e($head['superior_name'] ?? 'не вказано') ?></strong>.
    Проєкти, задачі й тікети підлеглих (де вони автор, відповідальний, учасник, виконавець, заявник чи оператор) доступні керівнику відділу для перегляду й ведення; облік часу — у пункті меню «Час».
</p>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= View::e($success) ?></div><?php endif; ?>

<?php if ($self && \App\Core\Access::isDeptHead(Auth::role())): ?>
    <p><a class="btn btn-sm btn-outline-primary" href="/team/tickets-report">📊 Звіт по тікетах команди</a></p>
<?php endif; ?>
<?php if (!empty($tree) && !empty($tree[0]['children'])): ?>
    <h5 class="mt-3">Структура команди</h5>
    <?php $nodes = $tree; require __DIR__ . '/_tree.php'; ?>
<?php endif; ?>

<?php if ($canMove): ?>
<form method="post" action="/team/<?= (int) $head['id'] ?>/superior" class="row g-2 align-items-end mb-4">
    <?= \App\Core\Csrf::field() ?>
    <div class="col-md-5">
        <label class="form-label small mb-1">Підпорядкований Керівникам підрозділу (можна кільком — Ctrl/Cmd + клік)</label>
        <select name="superior_ids[]" class="form-select form-select-sm" multiple size="<?= min(5, max(2, count($superiors))) ?>">
            <?php foreach ($superiors as $s): ?><option value="<?= (int) $s['id'] ?>"<?= in_array((int) $s['id'], $superiorIds, true) ? ' selected' : '' ?>><?= View::e($s['full_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-primary" type="submit">Зберегти</button></div>
</form>
<?php endif; ?>

<form method="post" action="/team/<?= (int) $head['id'] ?>/add" class="row g-2 align-items-end mb-4">
    <?= \App\Core\Csrf::field() ?>
    <div class="col-md-6">
        <label class="form-label small mb-1" for="team-add">Додати співробітника до команди</label>
        <select name="user_id" id="team-add" class="form-select form-select-sm user-select" aria-label="Почніть вводити ім'я або e-mail">
            <option value="">— почніть вводити ім'я або e-mail —</option>
            <?php foreach ($candidates as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= View::e($c['full_name']) ?> (<?= View::e($c['email']) ?>) — <?= View::e($c['role_name']) ?><?= $c['manager_name'] ? ' (вже у відділі: ' . View::e($c['manager_name']) . ')' : '' ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary" type="submit">Додати</button></div>
    <?php if ($unitLabel !== null): ?>
        <div class="col-12 form-text">Показано співробітників вашого підрозділу «<?= View::e($unitLabel) ?>».
            <?php if (!$candidates): ?>Додати нікого: <?= (int) $diag['inTeam'] ?> уже в цій команді, <?= (int) $diag['busy'] ?> мають іншого керівника, <?= (int) $diag['outside'] ?> — поза вашим підрозділом.<?php endif; ?></div>
    <?php endif; ?>
</form>

<?php if (!$rows): ?>
    <div class="alert alert-light border">У команді ще немає співробітників.</div>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>Співробітник</th><th>Роль</th><th>Також у відділах</th><th>Відкриті задачі</th><th>Відкриті тікети</th><th>Час за місяць</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= View::e($r['full_name']) ?><div class="small text-muted"><?= View::e($r['email']) ?></div></td>
            <td><?= View::e($r['role_name']) ?></td>
            <td><?= $r['others'] !== '' ? View::e($r['others']) : '<span class="text-muted">—</span>' ?><?= !$r['direct'] ? '<div class="small text-muted">(через вкладений відділ)</div>' : '' ?></td>
            <td><?= (int) $r['open_tasks'] ?></td>
            <td><?= (int) $r['open_tickets'] ?></td>
            <td><?= View::e(TimeFormat::hm((float) $r['month_hours'])) ?></td>
            <td class="text-end">
                <?php if ($r['direct']): ?>
                <form method="post" action="/team/<?= (int) $head['id'] ?>/remove" data-confirm="Зняти зі складу команди?" class="d-inline">
                    <?= \App\Core\Csrf::field() ?><input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0" type="submit">Зняти</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
