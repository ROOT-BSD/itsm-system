<?php

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\View;
use App\Models\Audit;
use App\Models\ReplyTemplate;

/**
 * «Готові відповіді»: персонал підтримки керує особистими шаблонами; спільні (для всього персоналу) створюють і змінюють
 * адміністратор системи та керівник ІТ-підрозділу.
 */
class ReplyTemplateController
{
    public function __construct()
    {
        Auth::requireLogin();
        if (!Access::canAssignTicketOperator(Auth::role())) {
            http_response_code(403);
            echo 'Готові відповіді доступні лише персоналу підтримки.';
            exit;
        }
    }

    private function canShare(): bool
    {
        return Auth::hasRole(['admin', 'it_manager']);
    }

    private function canEdit(array $t): bool
    {
        return $t['owner_id'] === null ? $this->canShare() : (int) $t['owner_id'] === (int) Auth::id();
    }

    public function index(): void
    {
        $edit = !empty($_GET['edit']) ? ReplyTemplate::find((int) $_GET['edit']) : null;
        if ($edit && !$this->canEdit($edit)) {
            $edit = null;
        }
        View::render('reply_templates/index', [
            'ready' => ReplyTemplate::ready(), 'templates' => ReplyTemplate::visibleTo((int) Auth::id()),
            'edit' => $edit, 'canShare' => $this->canShare(), 'me' => (int) Auth::id(),
            'error' => $_GET['error'] ?? null, 'success' => $_GET['success'] ?? null,
        ]);
    }

    public function save(): void
    {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $existing = $id !== null ? ReplyTemplate::find($id) : null;
        if ($id !== null && (!$existing || !$this->canEdit($existing))) {
            $this->back('error', 'Шаблон не знайдено або немає прав.');
        }
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
        $body = trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')));
        if ($name === '' || $body === '') {
            $this->back('error', 'Вкажіть назву й текст відповіді.', $id);
        }
        if (mb_strlen($body) > 5000) {
            $this->back('error', 'Текст — до 5000 символів.', $id);
        }
        $owner = $existing ? ($existing['owner_id'] === null ? null : (int) $existing['owner_id'])
            : (($this->canShare() && !empty($_POST['shared'])) ? null : (int) Auth::id());
        $rid = ReplyTemplate::save($id, $owner, $name, $body);
        Audit::log('reply_template', $rid, $id === null ? 'created' : 'updated', (int) Auth::id(), ['name' => $name, 'shared' => $owner === null]);
        $this->back('success', 'Шаблон «' . $name . '» збережено.');
    }

    public function delete(array $params): void
    {
        $t = ReplyTemplate::find((int) $params['id']);
        if (!$t || !$this->canEdit($t)) {
            $this->back('error', 'Шаблон не знайдено або немає прав.');
        }
        ReplyTemplate::delete((int) $t['id']);
        Audit::log('reply_template', (int) $t['id'], 'deleted', (int) Auth::id(), ['name' => $t['name']]);
        $this->back('success', 'Шаблон «' . $t['name'] . '» видалено.');
    }

    private function back(string $key, string $message, ?int $edit = null): never
    {
        header('Location: /reply-templates?' . $key . '=' . urlencode($message) . ($key === 'error' && $edit ? '&edit=' . $edit : ''));
        exit;
    }
}
