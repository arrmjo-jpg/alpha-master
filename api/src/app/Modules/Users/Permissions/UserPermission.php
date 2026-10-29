<?php

namespace App\Modules\Users\Permissions;

use App\Modules\Core\Permissions\DefinesPermissions;
use App\Modules\Core\Permissions\PermissionGroupDefinition;

enum UserPermission: string implements DefinesPermissions
{
    case View = 'users.view';
    case Create = 'users.create';
    case Update = 'users.update';
    case Activate = 'users.activate';
    case Deactivate = 'users.deactivate';
    case Ban = 'users.ban';
    case Unban = 'users.unban';
    case ChangeAccountType = 'users.change-account-type';
    case RevokeSessions = 'users.revoke-sessions';

    public static function groupDefinition(): PermissionGroupDefinition
    {
        return new PermissionGroupDefinition(
            key: 'users',
            name: ['ar' => 'المستخدمون', 'en' => 'Users'],
            description: ['ar' => 'إدارة حسابات المستخدمين وحالاتها وأنواعها', 'en' => 'Manage user accounts, their status and account types'],
            sortOrder: 10,
        );
    }

    public function group(): string
    {
        return 'users';
    }

    public function label(): array
    {
        return match ($this) {
            self::View => ['ar' => 'عرض المستخدمين', 'en' => 'View users'],
            self::Create => ['ar' => 'إنشاء مستخدمين', 'en' => 'Create users'],
            self::Update => ['ar' => 'تعديل بيانات المستخدمين', 'en' => 'Update users'],
            self::Activate => ['ar' => 'تفعيل الحسابات', 'en' => 'Activate accounts'],
            self::Deactivate => ['ar' => 'تعطيل الحسابات', 'en' => 'Deactivate accounts'],
            self::Ban => ['ar' => 'حظر الحسابات', 'en' => 'Ban accounts'],
            self::Unban => ['ar' => 'رفع الحظر عن الحسابات', 'en' => 'Unban accounts'],
            self::ChangeAccountType => ['ar' => 'تغيير نوع الحساب', 'en' => 'Change account type'],
            self::RevokeSessions => ['ar' => 'إنهاء جلسات المستخدمين', 'en' => 'Revoke user sessions'],
        };
    }

    public function description(): array
    {
        return match ($this) {
            self::View => ['ar' => 'السماح بعرض قائمة المستخدمين وتفاصيلهم', 'en' => 'Allows viewing the user list and user details'],
            self::Create => ['ar' => 'السماح بإنشاء حسابات مستخدمين جديدة', 'en' => 'Allows creating new user accounts'],
            self::Update => ['ar' => 'السماح بتعديل الاسم واسم المستخدم والبريد واللغة', 'en' => 'Allows editing name, username, email and language'],
            self::Activate => ['ar' => 'السماح بإعادة تفعيل الحسابات المعطلة', 'en' => 'Allows reactivating deactivated accounts'],
            self::Deactivate => ['ar' => 'السماح بتعطيل الحسابات وإنهاء جلساتها', 'en' => 'Allows deactivating accounts and ending their sessions'],
            self::Ban => ['ar' => 'السماح بحظر الحسابات وإنهاء جلساتها', 'en' => 'Allows banning accounts and ending their sessions'],
            self::Unban => ['ar' => 'السماح برفع الحظر عن الحسابات', 'en' => 'Allows lifting account bans'],
            self::ChangeAccountType => ['ar' => 'السماح بتغيير نوع الحساب بين مدير وكاتب ومستخدم', 'en' => 'Allows changing an account between admin, writer and user'],
            self::RevokeSessions => ['ar' => 'السماح بإنهاء جميع جلسات ورموز دخول مستخدم', 'en' => 'Allows ending all sessions and tokens of a user'],
        };
    }

    public function isSensitive(): bool
    {
        return $this === self::ChangeAccountType;
    }
}
