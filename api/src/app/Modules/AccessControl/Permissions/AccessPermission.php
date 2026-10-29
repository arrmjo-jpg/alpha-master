<?php

namespace App\Modules\AccessControl\Permissions;

use App\Modules\Core\Permissions\DefinesPermissions;
use App\Modules\Core\Permissions\PermissionGroupDefinition;

enum AccessPermission: string implements DefinesPermissions
{
    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';
    case RolesAssign = 'roles.assign';
    case RolesAssignPermissions = 'roles.assign-permissions';
    case PermissionsView = 'permissions.view';
    case AuditView = 'audit.view';

    /** @return list<PermissionGroupDefinition> */
    public static function groupDefinitions(): array
    {
        return [
            new PermissionGroupDefinition(
                key: 'roles',
                name: ['ar' => 'الأدوار', 'en' => 'Roles'],
                description: ['ar' => 'إدارة الأدوار وإسنادها وصلاحياتها', 'en' => 'Manage roles, their assignment and their permissions'],
                sortOrder: 20,
            ),
            new PermissionGroupDefinition(
                key: 'permissions',
                name: ['ar' => 'الصلاحيات', 'en' => 'Permissions'],
                description: ['ar' => 'عرض كتالوج الصلاحيات', 'en' => 'View the permission catalog'],
                sortOrder: 30,
            ),
            new PermissionGroupDefinition(
                key: 'audit',
                name: ['ar' => 'سجل التدقيق', 'en' => 'Audit log'],
                description: ['ar' => 'مراجعة سجل العمليات الحساسة', 'en' => 'Review the log of sensitive operations'],
                sortOrder: 90,
            ),
        ];
    }

    public function group(): string
    {
        return match ($this) {
            self::PermissionsView => 'permissions',
            self::AuditView => 'audit',
            default => 'roles',
        };
    }

    public function label(): array
    {
        return match ($this) {
            self::RolesView => ['ar' => 'عرض الأدوار', 'en' => 'View roles'],
            self::RolesCreate => ['ar' => 'إنشاء أدوار', 'en' => 'Create roles'],
            self::RolesUpdate => ['ar' => 'تعديل الأدوار', 'en' => 'Update roles'],
            self::RolesDelete => ['ar' => 'حذف الأدوار', 'en' => 'Delete roles'],
            self::RolesAssign => ['ar' => 'إسناد الأدوار للمستخدمين', 'en' => 'Assign roles to users'],
            self::RolesAssignPermissions => ['ar' => 'تعديل صلاحيات الأدوار', 'en' => 'Assign permissions to roles'],
            self::PermissionsView => ['ar' => 'عرض الصلاحيات', 'en' => 'View permissions'],
            self::AuditView => ['ar' => 'عرض سجل التدقيق', 'en' => 'View audit log'],
        };
    }

    public function description(): array
    {
        return match ($this) {
            self::RolesView => ['ar' => 'السماح بعرض الأدوار وصلاحياتها', 'en' => 'Allows viewing roles and their permissions'],
            self::RolesCreate => ['ar' => 'السماح بإنشاء أدوار جديدة', 'en' => 'Allows creating new roles'],
            self::RolesUpdate => ['ar' => 'السماح بتعديل أسماء الأدوار وأوصافها', 'en' => 'Allows editing role names and descriptions'],
            self::RolesDelete => ['ar' => 'السماح بحذف الأدوار غير المحمية', 'en' => 'Allows deleting unprotected roles'],
            self::RolesAssign => ['ar' => 'السماح بإسناد الأدوار للحسابات الإدارية وإزالتها', 'en' => 'Allows assigning roles to and removing them from admin accounts'],
            self::RolesAssignPermissions => ['ar' => 'السماح بإضافة الصلاحيات إلى الأدوار وإزالتها', 'en' => 'Allows adding permissions to and removing them from roles'],
            self::PermissionsView => ['ar' => 'السماح بعرض كتالوج الصلاحيات ومجموعاتها', 'en' => 'Allows viewing the permission catalog and its groups'],
            self::AuditView => ['ar' => 'السماح بعرض سجل العمليات الحساسة', 'en' => 'Allows viewing the log of sensitive operations'],
        };
    }

    public function isSensitive(): bool
    {
        return in_array($this, [self::RolesAssign, self::RolesAssignPermissions], true);
    }
}
