<?php

namespace App\Core;

/**
 * Рольові правила, які мусять діяти ОДНАКОВО в усіх каналах — веб-інтерфейсі, REST API (і вебхуках): інакше API
 * став би «чорним ходом» з іншими правами. Тут лише чисті функції від коду ролі; видимість конкретних записів — у
 * моделях (Project::isVisibleTo, Ticket::isVisibleTo). Нове рольове правило додається тут, а не в окремому контролері.
 */
final class Access
{
    public static function isAdmin(?string $role): bool
    {
        return $role === 'admin';
    }

    /** Бачити в списку ще нікому не призначені тікети (черга, з якої беруть роботу). */
    public static function canSeeUnassignedTickets(?string $role): bool
    {
        return in_array($role, ['it_manager', 'support_operator'], true);
    }

    /** Запити на зміни (Change): створювати й бачити розділ — персонал та керівники (заявники й спостерігачі — ні). */
    public static function canUseChanges(?string $role): bool
    {
        return in_array($role, ['admin', 'it_manager', 'sysadmin', 'support_operator', 'dept_head'], true);
    }

    /** Призначати й знімати оператора тікета. */
    public static function canAssignTicketOperator(?string $role): bool
    {
        return in_array($role, ['admin', 'unit_admin', 'it_manager', 'dept_head', 'support_operator'], true);
    }

    /** Чий це коментар у тікеті: оператора чи заявника (так історично визначає й веб-форма). */
    public static function ticketCommentAuthorType(?string $role): string
    {
        return in_array($role, ['admin', 'unit_admin', 'dept_head', 'support_operator'], true) ? 'operator' : 'requester';
    }

    /** Облік часу по всіх доступних проєктах і тікетах (/manager/time): керівник підрозділу. */
    public static function canViewManagerTime(?string $role): bool
    {
        return in_array($role, ['it_manager', Team::ROLE], true);
    }

    /** Керівник відділу: команда підлеглих, їхні проєкти, задачі й тікети (App\Core\Team). */
    public static function isDeptHead(?string $role): bool
    {
        return $role === Team::ROLE;
    }

    /** Складати команду Керівників відділу (додавати/знімати підлеглих): адміністратор і Керівник підрозділу; Керівник відділу — лише свою. */
    public static function canManageTeams(?string $role): bool
    {
        return in_array($role, ['admin', 'it_manager'], true);
    }

    /** Створювати проєкти й підпроєкти, призначати відповідального. */
    public static function canManageProjects(?string $role): bool
    {
        return in_array($role, ['admin', 'unit_admin', 'it_manager', 'dept_head'], true);
    }

    /** Реєстр обладнання й ПЗ: бачити й редагувати картки активів (адміністратор підрозділу — лише свого підрозділу). */
    public static function canUseAssets(?string $role): bool
    {
        return in_array($role, ['admin', 'unit_admin', 'it_manager', 'support_operator'], true);
    }

    /** Видаляти картки активів (решта лише переводить їх у «Списано»). */
    public static function canDeleteAssets(?string $role): bool
    {
        return in_array($role, ['admin', 'it_manager'], true);
    }

    /** Адміністратор підрозділу: керує користувачами й бачить дані свого AD OU (App\Core\Unit). */
    public static function isUnitAdmin(?string $role): bool
    {
        return $role === Unit::ROLE;
    }
}
