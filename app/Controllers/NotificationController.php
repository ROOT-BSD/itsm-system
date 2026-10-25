<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\UserNotification;

/** Центр сповіщень: сповіщення «в системі» поточного користувача (дзвіночок у верхньому меню). */
class NotificationController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('notifications/index', [
            'items' => UserNotification::forUser((int) Auth::id()),
            'unread' => UserNotification::unreadCount((int) Auth::id()),
            'ready' => UserNotification::ready(),
            'categories' => UserNotification::CATEGORIES,
            'disabled' => UserNotification::disabledCategories((int) Auth::id()),
            'prefsReady' => UserNotification::prefsReady(),
            'saved' => !empty($_GET['saved']),
        ]);
    }

    /** Позначає прочитаним і переходить за адресою сповіщення (лише внутрішня адреса). */
    public function open(array $params): void
    {
        Auth::requireLogin();
        $n = UserNotification::find((int) ($params['id'] ?? 0), (int) Auth::id());
        $to = '/notifications';
        if ($n) {
            UserNotification::markRead((int) $n['id'], (int) Auth::id());
            if (!empty($n['url']) && preg_match('#^/[^/\\\\]#', (string) $n['url'])) {
                $to = (string) $n['url'];
            }
        }
        header('Location: ' . $to);
        exit;
    }

    /** Особисті налаштування: які категорії подій надходять у центр (відмічені — отримувати). */
    public function savePrefs(): void
    {
        Auth::requireLogin();
        $on = array_values(array_intersect(array_keys(UserNotification::CATEGORIES), array_map('strval', (array) ($_POST['categories'] ?? []))));
        UserNotification::saveEnabledCategories((int) Auth::id(), $on);
        header('Location: /notifications?saved=1');
        exit;
    }

    public function readAll(): void
    {
        Auth::requireLogin();
        UserNotification::markAllRead((int) Auth::id());
        header('Location: /notifications');
        exit;
    }

    public function clearRead(): void
    {
        Auth::requireLogin();
        UserNotification::deleteRead((int) Auth::id());
        header('Location: /notifications');
        exit;
    }
}
