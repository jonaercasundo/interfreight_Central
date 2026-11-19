<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class AuditEventCatalog
{
    public const CATEGORIES = [
        'authentication' => 'Authentication',
        'users' => 'User Management',
        'roles' => 'Roles & Permissions',
        'sales' => 'Sales & Forecasting',
        'system' => 'System & Integrations',
        'security' => 'Security',
        'other' => 'Other',
    ];

    private const LABELS = [
        'auth.login_succeeded' => 'Successful Login',
        'auth.login_failed' => 'Failed Login',
        'auth.logout' => 'Signed Out',
        'auth.password_reset_requested' => 'Password Reset Requested',
        'auth.password_reset' => 'Password Reset Completed',
        'users.bootstrap_administrator_created' => 'Administrator Account Created',
        'users.created' => 'User Account Created',
        'users.updated' => 'User Account Updated',
        'users.roles_changed' => 'User Roles Changed',
        'users.deactivated' => 'User Account Deactivated',
        'users.reactivated' => 'User Account Reactivated',
        'users.password_reset_requested' => 'User Password Reset Requested',
        'users.temporary_password_issued' => 'Temporary Password Issued',
        'users.temporary_password_reset' => 'Temporary Password Reset by Administrator',
        'users.first_login_password_changed' => 'Temporary Password Changed at First Login',
        'roles.permissions_changed' => 'Role Permissions Changed',
        'permissions.changed' => 'Permissions Changed',
        'profile.updated' => 'Profile Updated',
        'profile.password_changed' => 'Profile Password Changed',
        'integrations.authentication_failed' => 'Integration Authentication Failed',
        'clients.created' => 'Client Created',
        'clients.updated' => 'Client Updated',
        'inquiries.created' => 'Client Inquiry Created',
        'inquiries.updated' => 'Client Inquiry Updated',
        'inquiries.submitted' => 'Client Inquiry Submitted',
        'inquiries.status_changed' => 'Client Inquiry Status Changed',
        'forecasts.created' => 'Shipment Forecast Created',
        'forecasts.updated' => 'Shipment Forecast Revised',
        'forecasts.withdrawn' => 'Shipment Forecast Withdrawn',
    ];

    public static function label(string $action): string
    {
        if (isset(self::LABELS[$action])) {
            return self::LABELS[$action];
        }

        return mb_convert_case(str_replace(['.', '_', '-'], ' ', $action), MB_CASE_TITLE, 'UTF-8');
    }

    public static function matchingLabels(string $search): array
    {
        $needle = mb_strtolower($search);

        return array_keys(array_filter(self::LABELS, static fn (string $label) => str_contains(mb_strtolower($label), $needle)));
    }

    public static function category(string $action): string
    {
        if ($action === 'auth.login_failed' || str_starts_with($action, 'security.')) {
            return 'security';
        }
        if (str_starts_with($action, 'auth.')) {
            return 'authentication';
        }
        if (str_starts_with($action, 'users.') || str_starts_with($action, 'profile.')) {
            return 'users';
        }
        if (str_starts_with($action, 'roles.') || str_starts_with($action, 'permissions.')) {
            return 'roles';
        }
        if (str_starts_with($action, 'integrations.') || str_starts_with($action, 'system.')) {
            return 'system';
        }
        if (str_starts_with($action, 'clients.') || str_starts_with($action, 'inquiries.') || str_starts_with($action, 'forecasts.')) {
            return 'sales';
        }

        return 'other';
    }

    public static function applyCategory(Builder $query, string $category): Builder
    {
        return match ($category) {
            'authentication' => $query->where('action', 'like', 'auth.%')->where('action', '!=', 'auth.login_failed'),
            'users' => $query->where(fn (Builder $q) => $q->where('action', 'like', 'users.%')->orWhere('action', 'like', 'profile.%')),
            'roles' => $query->where(fn (Builder $q) => $q->where('action', 'like', 'roles.%')->orWhere('action', 'like', 'permissions.%')),
            'sales' => $query->where(fn (Builder $q) => $q->where('action', 'like', 'clients.%')->orWhere('action', 'like', 'inquiries.%')->orWhere('action', 'like', 'forecasts.%')),
            'system' => $query->where(fn (Builder $q) => $q->where('action', 'like', 'integrations.%')->orWhere('action', 'like', 'system.%')),
            'security' => $query->where(fn (Builder $q) => $q->where('action', 'like', 'security.%')->orWhere('action', '=', 'auth.login_failed')),
            'other' => $query->where('action', 'not like', 'auth.%')->where('action', 'not like', 'users.%')->where('action', 'not like', 'profile.%')
                ->where('action', 'not like', 'roles.%')->where('action', 'not like', 'permissions.%')->where('action', 'not like', 'integrations.%')
                ->where('action', 'not like', 'system.%')->where('action', 'not like', 'security.%'),
            default => $query,
        };
    }
}
