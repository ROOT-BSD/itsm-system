<?php $e = static fn($v) => \App\Core\View::e((string) $v); ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h3 class="mb-0">Сповіщення <?php if ($unread > 0): ?><span class="badge text-bg-danger"><?= (int) $unread ?></span><?php endif; ?></h3>
    <?php if (!empty($items)): ?>
    <div class="d-flex gap-2">
        <form method="post" action="/notifications/read-all"><?= \App\Core\Csrf::field() ?><button class="btn btn-outline-secondary btn-sm" type="submit">Позначити всі прочитаними</button></form>
        <form method="post" action="/notifications/clear" data-confirm="Видалити всі прочитані сповіщення?"><?= \App\Core\Csrf::field() ?><button class="btn btn-outline-danger btn-sm" type="submit">Прибрати прочитані</button></form>
    </div>
    <?php endif; ?>
</div>
<?php if (!empty($saved)): ?><div class="alert alert-success py-2">Налаштування збережено.</div><?php endif; ?>
<?php if (!empty($prefsReady)): ?>
<details class="mb-3">
    <summary class="small text-muted">⚙️ Які сповіщення отримувати</summary>
    <form method="post" action="/notifications/prefs" class="card card-body mt-2">
        <?= \App\Core\Csrf::field() ?>
        <?php foreach ($categories as $k => $label): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="categories[]" id="nc_<?= $e($k) ?>" value="<?= $e($k) ?>" <?= in_array($k, $disabled, true) ? '' : 'checked' ?>>
                <label class="form-check-label" for="nc_<?= $e($k) ?>"><?= $e($label) ?></label></div>
        <?php endforeach; ?>
        <div class="form-text">Повідомлення від автоматичних правил (налаштованих адміністратором) надходять завжди. Листи на пошту налаштовує адміністратор окремо.</div>
        <div class="mt-2"><button class="btn btn-sm btn-outline-primary" type="submit">Зберегти</button></div>
    </form>
</details>
<?php endif; ?>
<?php if (empty($ready)): ?>
    <div class="alert alert-warning">Центр сповіщень ще не налаштовано — виконайте <code>sudo bash update.sh</code> (міграція 054).</div>
<?php elseif (empty($items)): ?>
    <div class="alert alert-light border">Сповіщень немає. Тут з'являтимуться повідомлення від автоматичних правил черги тікетів та інших подій.</div>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($items as $n): ?>
            <a href="/notifications/<?= (int) $n['id'] ?>/open" class="list-group-item list-group-item-action<?= $n['read_at'] === null ? ' list-group-item-primary' : '' ?>">
                <div class="d-flex justify-content-between gap-2">
                    <strong><?= $n['read_at'] === null ? '● ' : '' ?><?= $e($n['title']) ?></strong>
                    <small class="text-muted text-nowrap"><?= $e(date('d.m.Y H:i', strtotime((string) $n['created_at']))) ?></small>
                </div>
                <?php if (!empty($n['body'])): ?><div class="small"><?= $e($n['body']) ?></div><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
