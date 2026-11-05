<?php
$e = static fn($v) => \App\Core\View::e((string) $v);
$v = static fn(string $k) => $c[$k] ?? '';
$dt = static fn(?string $s) => $s ? str_replace(' ', 'T', substr($s, 0, 16)) : '';
$action = $isNew ? '/changes' : '/changes/' . (int) $c['id'];
?>
<div class="mb-3"><a href="<?= $isNew ? '/changes' : '/changes/' . (int) $c['id'] ?>" class="text-decoration-none">&larr; <?= $isNew ? 'Запити на зміни' : 'До запиту' ?></a></div>
<h3 class="mb-3"><?= $isNew ? 'Новий запит на зміну' : 'Зміна запиту №' . (int) $c['id'] ?></h3>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<form method="post" action="<?= $e($action) ?>" class="card shadow-sm"><div class="card-body row g-3">
    <?= \App\Core\Csrf::field() ?>
    <div class="col-12"><label class="form-label">Назва *</label><input type="text" name="title" class="form-control" maxlength="200" required value="<?= $e($v('title')) ?>"></div>
    <div class="col-md-4"><label class="form-label">Тип зміни</label><select name="change_type" class="form-select">
        <?php foreach (\App\Models\ChangeRequest::TYPES as $k => $label): ?><option value="<?= $e($k) ?>" <?= $v('change_type') === $k ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
        <div class="form-text">Термінова: достатньо згоди одного погоджувача. Звичайна й стандартна: потрібні всі.</div></div>
    <div class="col-md-4"><label class="form-label">Ризик</label><select name="risk" class="form-select">
        <?php foreach (\App\Models\ChangeRequest::RISKS as $k => $label): ?><option value="<?= $e($k) ?>" <?= $v('risk') === $k ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
        <div class="form-text">Високий ризик додає до погоджувачів Керівників підрозділу.</div></div>
    <div class="col-md-4"><label class="form-label">Пов’язаний тікет №</label><input type="text" name="ticket_id" class="form-control" inputmode="numeric" value="<?= $e($v('ticket_id')) ?>" placeholder="необов’язково"></div>
    <div class="col-12"><label class="form-label">Що змінюємо *</label><textarea name="description" class="form-control" rows="4" required><?= $e($v('description')) ?></textarea></div>
    <div class="col-md-6"><label class="form-label">Навіщо (обґрунтування)</label><textarea name="reason" class="form-control" rows="3"><?= $e($v('reason')) ?></textarea></div>
    <div class="col-md-6"><label class="form-label">На що вплине (системи, користувачі, простій)</label><textarea name="impact" class="form-control" rows="3"><?= $e($v('impact')) ?></textarea></div>
    <div class="col-12"><label class="form-label">План відкату <span class="text-muted">(обов’язковий для надсилання на погодження)</span></label><textarea name="rollback_plan" class="form-control" rows="3"><?= $e($v('rollback_plan')) ?></textarea></div>
    <div class="col-md-6"><label class="form-label">Вікно робіт: початок</label><input type="datetime-local" name="window_start" class="form-control" value="<?= $e($dt($v('window_start') ?: null)) ?>"></div>
    <div class="col-md-6"><label class="form-label">Вікно робіт: завершення</label><input type="datetime-local" name="window_end" class="form-control" value="<?= $e($dt($v('window_end') ?: null)) ?>"></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Зберегти</button> <span class="text-muted small ms-2">Погоджувачів ви оберете на сторінці запиту перед надсиланням.</span></div>
</div></form>
