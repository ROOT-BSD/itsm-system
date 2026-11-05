<div class="mb-3">
    <a href="/" class="text-decoration-none">&larr; Дашборд</a>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0"><?= !empty($mine) ? 'Мої відкриті задачі' : 'Відкриті задачі' ?></h3>
    <div class="d-flex gap-2">
        <?php if (\App\Core\Auth::hasRole(['admin', 'unit_admin', 'it_manager'])): ?><a href="<?= \App\Core\Auth::hasRole(['admin']) ? '/admin/task-rules' : '/task-rules' ?>" class="btn btn-outline-secondary">Правила для задач</a><?php endif; ?>
        <?php $exportUrl = '/tasks/export'; require __DIR__ . '/../_export_button.php'; ?>
    </div>
</div>

<?php if (!empty($_GET['bulk_ok'])): ?><div class="alert alert-success py-2"><?= \App\Core\View::e((string) $_GET['bulk_ok']) ?></div><?php endif; ?>
<?php if (!empty($_GET['bulk_err'])): ?><div class="alert alert-danger py-2"><?= \App\Core\View::e((string) $_GET['bulk_err']) ?></div><?php endif; ?>
<?php
// Закриті задачі від керівництва в цьому списку (їх бачать лише ті, хто може архівувати) — масове відправлення в «Архів».
$bulk = \App\Models\Task::archiveReady() && \App\Models\Task::canArchive(\App\Core\Auth::role())
    && (bool) array_filter($tasks ?? [], static fn($x) => !empty($x['is_closed']));
$cols = 8 + ($bulk ? 1 : 0);
?>
<?php if ($bulk): ?>
<form method="post" action="/tasks/archive-bulk" id="bulk-archive-form" data-confirm="Відправити вибрані закриті задачі в архів?">
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-2 d-flex align-items-center gap-2">
        <button type="submit" class="btn btn-sm btn-outline-secondary" id="bulk-archive-btn" disabled>📦 В архів вибрані (<span id="bulk-archive-count">0</span>)</button>
        <span class="text-muted small">Позначте закриті задачі (закреслені) й відправте їх в архів одним рухом.</span>
    </div>
<?php endif; ?>
<table class="table table-bordered table-sm table-compact-rows bg-white align-middle">
    <thead>
        <tr><?php if ($bulk): ?><th style="width:2rem"><input type="checkbox" class="form-check-input" id="bulk-archive-all" title="Вибрати всі закриті"></th><?php endif; ?><th>#</th><th>Назва</th><th>Проєкт</th><th>Виконавець</th><th>Пріоритет</th><th>Статус</th><th>Термін</th><th>Створено</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tasks)): ?>
        <tr><td colspan="<?= $cols ?>" class="text-center text-muted">Відкритих задач немає</td></tr>
    <?php else:
        // Групи за проєктом: кожен проєкт — свій стійкий пастельний колір (як на дашборді).
        $prevProject = null;
        foreach ($tasks as $t):
            $pid = (int) $t['project_id'];
            $hue = ($pid * 47) % 360;
            if ($prevProject !== $pid): $prevProject = $pid; ?>
        <tr style="--bs-table-bg: hsl(<?= $hue ?>, 45%, 86%);">
            <td colspan="<?= $cols ?>" class="fw-semibold"><a href="/projects/<?= $pid ?>" class="text-decoration-none text-body"><?= \App\Core\View::e($t['project_name']) ?></a></td>
        </tr>
        <?php endif; ?>
        <tr class="<?= !empty($t['is_closed']) ? 'task-struck' : '' ?>" style="--bs-table-bg: hsl(<?= $hue ?>, 55%, 94%);">
            <?php if ($bulk): ?><td><?php if (!empty($t['is_closed'])): ?><input type="checkbox" class="form-check-input bulk-archive-item" name="ids[]" value="<?= (int)$t['id'] ?>"><?php endif; ?></td><?php endif; ?>
            <td><a href="/tasks/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?php if (!empty($t['parent_task_id'])): ?><span class="text-muted" title="Підзадача задачі #<?= (int)$t['parent_task_id'] ?>">↳ </span><?php endif; ?><?= \App\Core\View::e($t['title']) ?>
                <?= \App\Core\View::progress($t) ?>
                <?php foreach (($taskLabels[(int) $t['id']] ?? []) as $lb): ?><span class="badge text-bg-<?= \App\Core\View::e($lb['color']) ?>"><?= \App\Core\View::e($lb['name']) ?></span> <?php endforeach; ?></td>
            <td><a href="/projects/<?= (int)$t['project_id'] ?>"><?= \App\Core\View::e($t['project_name']) ?></a></td>
            <td><?= \App\Core\View::e($t['assignee_name'] ?? '—') ?></td>
            <td><span class="badge prio-<?= \App\Core\View::e($t['priority']) ?>"><?= \App\Core\View::e($t['priority']) ?></span></td>
            <td><span class="badge bg-secondary"><?= \App\Core\View::e($t['status_name']) ?></span></td>
            <td class="<?= ($u = \App\Core\View::urgencyClass($t['due_date'] ?? null)) !== '' ? 'dash-due-' . substr($u, -1) : '' ?>">
                <?php if (!empty($t['due_date']) && \App\Core\View::urgencyLevel($t['due_date']) === 4): ?>
                    <span class="badge bg-danger">⚠️ <?= \App\Core\View::date($t['due_date'], '') ?></span>
                <?php elseif (!empty($t['due_date'])): ?>
                    <?= \App\Core\View::date($t['due_date'], '') ?>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td><?= \App\Core\View::e($t['created_at_formatted']) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
<?php if ($bulk): ?></form><script src="/assets/js/bulk-archive.js"></script><?php endif; ?>
