<?php
$e = static fn($v) => \App\Core\View::e((string) $v);
$id = (int) $c['id'];
$st = $c['status'];
$field = static fn(string $label, ?string $val) => $val !== null && trim($val) !== '' ? '<h6 class="mt-3 mb-1">' . $label . '</h6><div style="white-space:pre-line">' . \App\Core\View::e($val) . '</div>' : '';
?>
<div class="mb-3"><a href="/changes" class="text-decoration-none">&larr; Запити на зміни</a></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
    <h3 class="mb-0">#<?= $id ?> <?= $e($c['title']) ?></h3>
    <span class="badge fs-6 text-bg-<?= $e(\App\Models\ChangeRequest::BADGES[$st]) ?>"><?= $e(\App\Models\ChangeRequest::STATUSES[$st]) ?></span>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<?php if ($overlaps): ?>
    <div class="alert alert-warning small"><strong>Вікно робіт перетинається з іншими змінами:</strong>
        <?php foreach ($overlaps as $o): ?><br><a href="/changes/<?= (int) $o['id'] ?>">#<?= (int) $o['id'] ?> <?= $e($o['title']) ?></a> — <?= $e(\App\Models\ChangeRequest::STATUSES[$o['status']]) ?>, <?= $e(\App\Core\TimeFormat::dateTime((string) $o['window_start'])) ?> – <?= $e(\App\Core\TimeFormat::dateTime((string) $o['window_end'])) ?><?php endforeach; ?></div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-8">
    <div class="card shadow-sm mb-3"><div class="card-body">
        <div class="row small text-muted mb-2">
            <div class="col-sm-4">Тип: <strong class="text-body"><?= $e(\App\Models\ChangeRequest::TYPES[$c['change_type']]) ?></strong></div>
            <div class="col-sm-4">Ризик: <strong class="text-body"><?= $e(\App\Models\ChangeRequest::RISKS[$c['risk']]) ?></strong></div>
            <div class="col-sm-4">Автор: <strong class="text-body"><?= $e($c['requester_name'] ?? '—') ?></strong></div>
            <div class="col-12 mt-1">Вікно робіт: <strong class="text-body"><?= $c['window_start'] ? $e(\App\Core\TimeFormat::dateTime((string) $c['window_start'])) . ' – ' . $e(\App\Core\TimeFormat::dateTime((string) $c['window_end'])) : 'не вказано' ?></strong>
                <?php if ($c['ticket_id']): ?> · тікет <a href="/tickets/<?= (int) $c['ticket_id'] ?>">#<?= (int) $c['ticket_id'] ?></a><?php endif; ?></div>
        </div>
        <?= $field('Що змінюємо', $c['description']) ?>
        <?= $field('Обґрунтування', $c['reason']) ?>
        <?= $field('Вплив', $c['impact']) ?>
        <?= $field('План відкату', $c['rollback_plan']) ?>
        <div class="small text-muted mt-3">Створено <?= $e(\App\Core\TimeFormat::dateTime((string) $c['created_at'])) ?>
            <?php foreach (['submitted_at' => 'надіслано', 'decided_at' => 'рішення', 'started_at' => 'розпочато', 'finished_at' => 'завершено'] as $k => $l): ?>
                <?= $c[$k] ? ' · ' . $l . ' ' . $e(\App\Core\TimeFormat::dateTime((string) $c[$k])) : '' ?><?php endforeach; ?></div>
    </div></div>

    <div class="card shadow-sm" id="comments"><div class="card-body">
        <h5 class="card-title">Коментарі</h5>
        <?php if (!$comments): ?><p class="text-muted small">Коментарів ще немає.</p><?php endif; ?>
        <?php foreach ($comments as $m): ?>
            <div class="border-bottom pb-2 mb-2"><div class="small text-muted"><strong class="text-body"><?= $e($m['full_name'] ?? 'користувача видалено') ?></strong>, <?= $e(\App\Core\TimeFormat::dateTime((string) $m['created_at'])) ?></div>
                <div style="white-space:pre-line"><?= $e($m['body']) ?></div></div>
        <?php endforeach; ?>
        <form method="post" action="/changes/<?= $id ?>/comment"><?= \App\Core\Csrf::field() ?>
            <textarea name="body" class="form-control mb-2" rows="3" required maxlength="5000" placeholder="Коментар до запиту"></textarea>
            <button class="btn btn-outline-primary btn-sm" type="submit">Додати коментар</button></form>
    </div></div>
</div>

<div class="col-lg-4">
    <div class="card shadow-sm mb-3"><div class="card-body">
        <h5 class="card-title">Погодження</h5>
        <?php if ($approvals): ?>
            <ul class="list-unstyled mb-2">
            <?php foreach ($approvals as $a): ?>
                <li class="mb-2"><?= ['pending' => '⏳', 'approved' => '✅', 'rejected' => '❌'][$a['decision']] ?> <strong><?= $e($a['full_name']) ?></strong>
                    <?php if ($a['decided_at']): ?><span class="small text-muted"> · <?= $e(\App\Core\TimeFormat::dateTime((string) $a['decided_at'])) ?></span><?php endif; ?>
                    <?php if ($a['comment']): ?><div class="small ms-4" style="white-space:pre-line"><?= $e($a['comment']) ?></div><?php endif; ?></li>
            <?php endforeach; ?>
            </ul>
            <p class="small text-muted"><?= $c['change_type'] === 'emergency' ? 'Термінова зміна: достатньо однієї згоди.' : 'Потрібна згода всіх погоджувачів.' ?> Будь-яка відмова відхиляє запит.</p>
        <?php else: ?><p class="text-muted small">Ще не надсилалось на погодження.</p><?php endif; ?>

        <?php if ($myApproval): ?>
            <form method="post" action="/changes/<?= $id ?>/decide" class="border-top pt-2"><?= \App\Core\Csrf::field() ?>
                <label class="form-label small mb-1">Ваше рішення (причина обов’язкова при відмові)</label>
                <textarea name="comment" class="form-control mb-2" rows="2" maxlength="500"></textarea>
                <button name="decision" value="approve" class="btn btn-success btn-sm" type="submit">Погодити</button>
                <button name="decision" value="reject" class="btn btn-outline-danger btn-sm" type="submit" data-confirm="Відхилити запит №<?= $id ?>?">Відхилити</button>
            </form>
        <?php endif; ?>
    </div></div>

    <?php if ($canEdit): ?>
    <div class="card shadow-sm mb-3"><div class="card-body">
        <h5 class="card-title"><?= $st === 'rejected' ? 'Повторне надсилання' : 'Надсилання на погодження' ?></h5>
        <form method="post" action="/changes/<?= $id ?>/submit"><?= \App\Core\Csrf::field() ?>
            <label class="form-label small mb-1">Погоджувачі (за замовчуванням підставлено за правилами)</label>
            <select name="approvers[]" class="form-select mb-2" multiple size="<?= min(6, max(3, count($eligible))) ?>">
                <?php foreach ($eligible as $u): if ((int) $u['id'] === (int) $c['requester_id']) { continue; } ?>
                    <option value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $defaults, true) ? 'selected' : '' ?>><?= $e($u['full_name']) ?> (<?= $e(['admin' => 'адмін', 'it_manager' => 'керівник підрозділу', 'dept_head' => 'керівник відділу'][$u['role_code']] ?? $u['role_code']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <div class="form-text mb-2">Автор не може погоджувати власну зміну. Кілька — Ctrl/Cmd + клік.</div>
            <button class="btn btn-primary btn-sm" type="submit">Надіслати на погодження</button>
            <a class="btn btn-outline-secondary btn-sm" href="/changes/<?= $id ?>/edit">Редагувати</a>
        </form>
    </div></div>
    <?php endif; ?>

    <?php if ($canRun || $canCancel): ?>
    <div class="card shadow-sm"><div class="card-body">
        <h5 class="card-title">Виконання</h5>
        <?php foreach ([
            ['approved', 'in_progress', 'Розпочати виконання', 'btn-primary', ''],
            ['in_progress', 'implemented', 'Виконано', 'btn-success', ''],
            ['in_progress', 'rolled_back', 'Відкотили', 'btn-outline-dark', 'Позначити зміну як відкочену?'],
        ] as [$from, $to, $label, $cls, $confirm]): if ($canRun && $st === $from): ?>
            <form method="post" action="/changes/<?= $id ?>/transition" class="d-inline"><?= \App\Core\Csrf::field() ?><input type="hidden" name="to" value="<?= $e($to) ?>">
                <button class="btn btn-sm <?= $cls ?>" type="submit"<?= $confirm ? ' data-confirm="' . $e($confirm) . '"' : '' ?>><?= $e($label) ?></button></form>
        <?php endif; endforeach; ?>
        <?php if ($canCancel): ?>
            <form method="post" action="/changes/<?= $id ?>/transition" class="d-inline"><?= \App\Core\Csrf::field() ?><input type="hidden" name="to" value="cancelled">
                <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Скасувати запит №<?= $id ?>?">Скасувати</button></form>
        <?php endif; ?>
        <?php if (!in_array($st, ['approved', 'in_progress'], true) && !$canCancel): ?><p class="text-muted small mb-0">Дій немає.</p><?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
</div>
