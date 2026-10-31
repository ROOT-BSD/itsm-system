<?php
/** Дерево підпорядкування. Очікує $nodes (вкладені вузли з children/total) і $level. Рендер — рекурсивно. */
use App\Core\View;
$renderTree = static function (array $nodes, int $level) use (&$renderTree): void {
    echo '<ul class="team-tree' . ($level === 0 ? ' team-tree-root' : '') . '">';
    foreach ($nodes as $n) {
        $isHead = in_array($n['role_code'], ['dept_head', 'it_manager'], true);
        $label = '<span class="team-node-name">' . View::e($n['full_name']) . '</span> '
            . '<span class="badge ' . ($n['role_code'] === 'it_manager' ? 'bg-dark' : ($n['role_code'] === 'dept_head' ? 'bg-primary' : 'bg-light text-dark border')) . '">' . View::e($n['role_name']) . '</span>'
            . ' <span class="text-muted small">' . View::e($n['email']) . '</span>'
            . ((int) $n['open_tasks'] > 0 ? ' <span class="badge text-bg-secondary" title="Відкритих задач">' . (int) $n['open_tasks'] . '</span>' : '')
            . ($n['role_code'] === 'dept_head' ? ' <a class="small" href="/team/' . (int) $n['id'] . '">команда</a>' : '');
        if ($n['children']) {
            echo '<li><details open><summary>' . $label . ' <span class="text-muted small">(підлеглих: ' . (int) $n['total'] . ')</span></summary>';
            $renderTree($n['children'], $level + 1);
            echo '</details></li>';
        } else {
            echo '<li class="team-leaf">' . $label . '</li>';
        }
    }
    echo '</ul>';
};
$renderTree($nodes, 0);
