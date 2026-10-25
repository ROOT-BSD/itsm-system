<div class="row justify-content-center">
    <div class="col-lg-8">
        <p class="small"><a href="/support/kb">&larr; Уся база знань</a> · <a href="/support">Подати звернення</a></p>
        <h2 class="mb-1"><?= \App\Core\View::e($page['title']) ?></h2>
        <div class="text-muted small mb-3">База знань · оновлено <?= \App\Core\View::e(date('d.m.Y', strtotime((string) $page['updated_at']))) ?></div>
        <div class="card"><div class="card-body wiki-content"><?= $html /* HTML від App\Core\Markdown: увесь текст екранований, посилання проходять білий список */ ?></div></div>
        <?php if (!empty($files)): ?>
            <div class="card mt-3"><div class="card-header">Файли до статті</div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($files as $f): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <a href="/support/kb/files/<?= (int) $f['id'] ?>"><?= \App\Core\View::e($f['original_name']) ?></a>
                            <span class="small text-muted"><?= \App\Core\View::e(\App\Core\UploadLimits::human((int) $f['size_bytes'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($feedbackReady)): ?>
            <div class="card mt-3"><div class="card-body">
                <?php if (!empty($thanks) || $voted): ?>
                    <p class="mb-0"><?= ($thanks ?? '') === 'no' || (($_COOKIE['kbf_' . (int) $page['id']] ?? '') === 'n' && empty($thanks)) ? 'Дякуємо за відгук. Шкода, що не допомогло — ' : 'Дякуємо за відгук! ' ?><?= ($thanks ?? '') === 'no' || (($_COOKIE['kbf_' . (int) $page['id']] ?? '') === 'n' && empty($thanks)) ? '<a href="/support">подайте звернення</a>, і ми розберемося.' : '' ?></p>
                <?php else: ?>
                    <form method="post" action="/support/kb/<?= \App\Core\View::e($page['slug']) ?>/feedback" class="d-flex flex-wrap gap-2 align-items-center">
                        <?= \App\Core\Csrf::field() ?>
                        <input type="hidden" name="from" value="<?= \App\Core\View::e($from) ?>">
                        <span class="me-2">Ця стаття допомогла?</span>
                        <button class="btn btn-sm btn-outline-success" type="submit" name="helpful" value="1">👍 Допомогла</button>
                        <button class="btn btn-sm btn-outline-secondary" type="submit" name="helpful" value="0">👎 Не допомогла</button>
                    </form>
                <?php endif; ?>
            </div></div>
        <?php endif; ?>
        <p class="mt-3 text-muted">Не допомогло? <a href="/support">Подайте звернення</a> — ми розберемося.</p>
    </div>
</div>
