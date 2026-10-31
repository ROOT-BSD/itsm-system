<?php use App\Core\View; ?>
<h3 class="mb-1">Керівники відділів</h3>
<p class="text-muted">Керівник відділу підпорядкований Керівнику підрозділу, має власну команду співробітників і бачить та веде проєкти, задачі й тікети своїх підлеглих. Роль «Керівник відділу» призначається в картці користувача (Адмін-панель → Користувачі).</p>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= View::e($success) ?></div><?php endif; ?>
<?php if (!$ready): ?>
    <div class="alert alert-warning">Потрібна міграція 063 (<code>sudo bash update.sh</code>).</div>
<?php elseif (!$heads): ?>
    <div class="alert alert-light border">Керівників відділів ще немає. Призначте роль «Керівник відділу» користувачу в Адмін-панелі.</div>
<?php else: ?>
<h5 class="mt-3">Структура підпорядкування</h5>
<p class="text-muted small">Керівник підрозділу → Керівники відділів → співробітники (вкладені відділи показано вкладеними гілками). Гілки можна згортати; число поруч — відкриті задачі.</p>
<?php if (!$tree): ?>
    <div class="alert alert-light border">Структура порожня.</div>
<?php else: $nodes = $tree; require __DIR__ . '/_tree.php'; endif; ?>
<details class="mt-4">
<summary class="text-muted">Таблиця керівників відділів</summary>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>Керівник відділу</th><th>Підпорядкований</th><th>Прямих підлеглих</th><th>Усього в команді</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($heads as $h): ?>
        <tr<?= (int) $h['is_active'] === 1 ? '' : ' class="text-muted"' ?>>
            <td><?= View::e($h['full_name']) ?><div class="small text-muted"><?= View::e($h['email']) ?><?= (int) $h['is_active'] === 1 ? '' : ' · вимкнений' ?></div></td>
            <td><?= View::e($h['superior_name'] ?? '—') ?></td>
            <td><?= (int) $h['direct_count'] ?></td>
            <td><?= (int) $h['total_count'] ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/team/<?= (int) $h['id'] ?>">Команда</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
</details>
<?php endif; ?>
