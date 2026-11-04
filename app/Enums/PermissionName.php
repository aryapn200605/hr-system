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
}
