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
    case HolidaysManage = 'holidays.manage';
    case RequestsSubmit = 'requests.submit';
    case RequestsDecide = 'requests.decide';
    case RequestsViewAll = 'requests.view-all';
}
