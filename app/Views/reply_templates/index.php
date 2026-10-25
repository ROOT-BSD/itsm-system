<?php
$e = static fn($v) => \App\Core\View::e((string) $v);
$f = $edit ?? [];
?>
<div class="mb-3"><a href="/tickets" class="text-decoration-none">&larr; Тікети</a></div>
<h3 class="mb-1">Готові відповіді</h3>
<p class="text-muted">Типові тексти для відповідей у тікетах. На сторінці тікета оберіть «Готова відповідь…» над полем коментаря — текст вставиться з підстановкою даних тікета, його можна відредагувати перед надсиланням.
    Змінні: <?php foreach (\App\Models\ReplyTemplate::PLACEHOLDERS as $k => $d): ?><code><?= $e($k) ?></code> — <?= $e($d) ?>; <?php endforeach; ?></p>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
<?php if (empty($ready)): ?>
    <div class="alert alert-warning">Таблицю ще не створено — виконайте <code>sudo bash update.sh</code> (міграція 058).</div>
<?php else: ?>
<table class="table table-bordered bg-white align-middle table-sm">
    <thead><tr><th>Назва</th><th>Текст</th><th>Чий</th><th></th></tr></thead>
    <tbody>
    <?php if (!$templates): ?><tr><td colspan="4" class="text-center text-muted">Шаблонів ще немає</td></tr><?php endif; ?>
    <?php foreach ($templates as $t):
        $mine = $t['owner_id'] === null ? $canShare : (int) $t['owner_id'] === $me; ?>
        <tr>
            <td><strong><?= $e($t['name']) ?></strong></td>
            <td class="small" style="white-space:pre-line"><?= $e(mb_strimwidth((string) $t['body'], 0, 240, '…')) ?></td>
            <td class="small"><?= $t['owner_id'] === null ? '<span class="badge text-bg-info">спільний</span>' : 'мій' ?></td>
            <td class="text-nowrap">
                <?php if ($mine): ?>
                    <a class="btn btn-sm btn-outline-primary" href="/reply-templates?edit=<?= (int) $t['id'] ?>">Змінити</a>
                    <form method="post" action="/reply-templates/<?= (int) $t['id'] ?>/delete" class="d-inline" data-confirm="Видалити шаблон «<?= $e($t['name']) ?>»?"><?= \App\Core\Csrf::field() ?>
                        <button class="btn btn-sm btn-outline-danger" type="submit">Видалити</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<div class="card shadow-sm mt-3 form-card-md">
    <div class="card-header"><?= $edit ? 'Зміна шаблону' : 'Новий шаблон' ?></div>
    <div class="card-body">
        <form method="post" action="/reply-templates">
            <?= \App\Core\Csrf::field() ?>
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
            <div class="mb-3"><label class="form-label">Назва</label>
                <input type="text" name="name" maxlength="100" class="form-control" value="<?= $e($f['name'] ?? '') ?>" required></div>
            <div class="mb-3"><label class="form-label">Текст</label>
                <textarea name="body" rows="6" maxlength="5000" class="form-control" required placeholder="Доброго дня, {заявник}! Ваше звернення №{номер} прийнято в роботу…"><?= $e($f['body'] ?? '') ?></textarea></div>
            <?php if ($canShare && !$edit): ?>
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="shared" id="shared" value="1">
                <label class="form-check-label" for="shared">Спільний — доступний усьому персоналу підтримки</label></div>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit">Зберегти</button>
            <?php if ($edit): ?><a class="btn btn-outline-secondary" href="/reply-templates">Скасувати</a><?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>
