<?php

namespace App\Enums;

enum PermissionName: string
{
    case DashboardView = 'dashboard.view';
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersSuspend = 'users.suspend';
    case UsersInvite = 'users.invite';
    case DepartmentsManage = 'departments.manage';
    case SupervisorsManage = 'supervisors.manage';
    case LeaveBalanceView = 'leave-balance.view';
    case LeaveBalanceAdjust = 'leave-balance.adjust';
    case RequestsCreate = 'requests.create';
    case RequestsViewAny = 'requests.view-any';
    case HolidaysManage = 'holidays.manage';
}
