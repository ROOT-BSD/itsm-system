<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\UploadLimits;
use App\Core\View;
use App\Models\Audit;
use App\Services\BackupService;
use App\Services\UpdateService;

/**
 * Адмін-панель → Налаштування → Оновлення: перевірка нової версії (GitHub), команда оновлення зі змінами з CHANGELOG, оновлення
 * з ZIP-архіву, завантаженого з комп'ютера. Лише «Адміністратор системи». Приймання й застосування архіву працюють,
 * тільки якщо в .env задано UPDATE_FROM_WEB=true; кожну дію записано в журнал аудиту.
 */
class UpdateController
{
    public const CONFIRM_WORD = 'ОНОВИТИ';

    public function __construct()
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Оновлення доступні лише ролі «Адміністратор системи».';
            exit;
        }
    }

    public function index(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $preview = $token !== '' && UpdateService::webEnabled() ? UpdateService::preview($token) : null;
        View::render('admin/update', [
            'current' => UpdateService::currentVersion(),
            'check' => UpdateService::lastCheck(),
            'hint' => UpdateService::commandHint(),
            'webEnabled' => UpdateService::webEnabled(),
            'preview' => $preview,
            'uploadMax' => UploadLimits::human(UploadLimits::effectiveFileMax(PHP_INT_MAX)),
            'root' => UpdateService::root(),
            'writable' => is_writable(UpdateService::root() . '/VERSION') && is_writable(UpdateService::root() . '/app'),
            'confirmWord' => self::CONFIRM_WORD,
            'phpCli' => UpdateService::phpCli(),
            'success' => $_GET['success'] ?? null,
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function check(): void
    {
        $r = UpdateService::check();
        Audit::log('update', 0, 'checked', (int) Auth::id(), ['result' => $r['ok'] ? ($r['available'] ? 'є ' . $r['version'] : 'актуальна') : 'помилка']);
        $this->redirect($r['ok'] ? 'success' : 'error', $r['ok']
            ? ($r['available'] ? 'Доступна нова версія ' . $r['version'] . ' (у вас ' . $r['current'] . ').' : 'У вас остання версія (' . $r['current'] . ').')
            : (string) $r['error']);
    }

    public function upload(): void
    {
        if (!UpdateService::webEnabled()) {
            $this->redirect('error', 'Оновлення з веб-сторінки вимкнено: задайте UPDATE_FROM_WEB=true у .env.');
        }
        $f = $_FILES['archive'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
            $this->redirect('error', match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Архів завеликий для завантаження (ліміт сервера: ' . UploadLimits::human(UploadLimits::effectiveFileMax(PHP_INT_MAX)) . '). Збільште upload_max_filesize/post_max_size або оновіть командою.',
                UPLOAD_ERR_NO_FILE => 'Оберіть ZIP-архів на комп’ютері.',
                default => 'Файл не завантажився (код ' . $code . ').',
            });
        }
        if (!preg_match('/\.zip$/i', (string) ($f['name'] ?? ''))) {
            $this->redirect('error', 'Потрібен архів у форматі .zip.');
        }
        $r = UpdateService::stage($f['tmp_name']);
        Audit::log('update', 0, $r['ok'] ? 'archive_uploaded' : 'archive_rejected', (int) Auth::id(), [
            'file' => mb_substr(basename((string) $f['name']), 0, 100), 'version' => $r['version'] ?? null, 'error' => $r['error'] ?? null,
        ]);
        if (!$r['ok']) {
            $this->redirect('error', $r['error']);
        }
        header('Location: /admin/update?token=' . $r['token']);
        exit;
    }

    public function discard(): void
    {
        UpdateService::discard((string) ($_POST['token'] ?? ''));
        $this->redirect('success', 'Завантажений архів видалено, нічого не змінено.');
    }

    public function apply(): void
    {
        $token = (string) ($_POST['token'] ?? '');
        $back = '/admin/update?token=' . rawurlencode($token);
        if (trim((string) ($_POST['confirm'] ?? '')) !== self::CONFIRM_WORD) {
            $this->redirect('error', 'Оновлення не виконано: введіть слово «' . self::CONFIRM_WORD . '» для підтвердження.', $back);
        }
        $preview = UpdateService::preview($token);
        if ($preview === null) {
            $this->redirect('error', 'Розпакований архів не знайдено. Завантажте архів ще раз.');
        }
        if ($preview['relation'] < 0) {
            $this->redirect('error', 'Архів старіший за встановлену версію — оновлення відхилено.', $back);
        }
        $uid = (int) Auth::id();

        // Страхувальна копія — ДО заміни файлів (і до будь-яких змін).
        $backup = '';
        if (!empty($_POST['backup'])) {
            try {
                $r = BackupService::create();
                $backup = ' Резервна копія: ' . $r['file'] . '.';
            } catch (\Throwable $e) {
                $this->redirect('error', 'Оновлення не виконано: не вдалося створити резервну копію (' . $e->getMessage() . '). Файли не змінено.', $back);
            }
        }
        Audit::log('update', 0, 'apply_started', $uid, ['from' => $preview['current'], 'to' => $preview['version']]);

        // Після заміни файлів цей (старий) процес не повинен автозавантажувати нові класи — підвантажуємо все потрібне наперед.
        foreach ([\App\Models\Audit::class, \App\Models\Setting::class, \App\Core\Csrf::class, \App\Core\Database::class] as $cls) {
            class_exists($cls);
        }
        $result = UpdateService::apply($token);

        // Журнал і перенаправлення — лише завантаженими класами.
        try {
            Audit::log('update', 0, $result['ok'] ? 'applied' : 'apply_failed', $uid, [
                'from' => $preview['current'], 'to' => $preview['version'], 'message' => mb_substr($result['message'], 0, 200),
            ]);
        } catch (\Throwable) {
        }
        $text = $result['message'] . $backup . ' ' . implode(' ', $result['log'])
            . ($result['ok'] ? ' Заходити по SSH і запускати update.sh не потрібно: міграції та решту кроків виконано. Перевірте «Стан системи».' : '');
        $this->redirect($result['ok'] ? 'success' : 'error', mb_substr($text, 0, 1500), $result['ok'] ? '/admin/update' : $back);
    }

    private function redirect(string $key, string $message, string $to = '/admin/update'): never
    {
        header('Location: ' . $to . (str_contains($to, '?') ? '&' : '?') . $key . '=' . rawurlencode($message));
        exit;
    }
}
