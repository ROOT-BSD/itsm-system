<?php

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\View;
use App\Models\Task;
use App\Models\TicketTimeLog;

/** Сторінки керівника підрозділу й керівника відділу: облік часу по всіх доступних їм проєктах і тікетах (лише читання). */
class ManagerController
{
    public function time(): void
    {
        Auth::requireLogin();
        if (!Access::canViewManagerTime(Auth::role())) {
            http_response_code(403);
            echo 'Сторінка доступна ролям «Керівник підрозділу» та «Керівник відділу».';
            return;
        }
        $id = (int) Auth::id();
        $logs = Task::timeLogsAll($id);
        View::render('admin/time', [
            'managerMode' => true,
            'timeLogs' => $logs,
            'hoursByUser' => Task::hoursByUserAll($id),
            'hoursByProject' => Task::hoursByProjectAll($id),
            'hoursByDay' => Task::hoursByPeriodAll('day', $id),
            'hoursByWeek' => Task::hoursByPeriodAll('week', $id),
            'hoursByMonth' => Task::hoursByPeriodAll('month', $id),
            'hoursByDayUser' => Task::hoursByPeriodAndUserAll('day', $id),
            'hoursByWeekUser' => Task::hoursByPeriodAndUserAll('week', $id),
            'hoursByMonthUser' => Task::hoursByPeriodAndUserAll('month', $id),
            'hoursByDayProject' => Task::hoursByPeriodAndProjectAll('day', $id),
            'hoursByWeekProject' => Task::hoursByPeriodAndProjectAll('week', $id),
            'hoursByMonthProject' => Task::hoursByPeriodAndProjectAll('month', $id),
            'totalHours' => array_sum(array_column($logs, 'hours')),
            'ticketHours' => TicketTimeLog::hoursByTicket(null, 200, $id, Access::canSeeUnassignedTickets(Auth::role())),
        ]);
    }
}
