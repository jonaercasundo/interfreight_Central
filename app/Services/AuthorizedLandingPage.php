<?php

namespace App\Services;

use App\Models\User;

/**
 * Resolves a safe post-authentication destination from the user's effective
 * permissions. The order is also the mixed-role landing priority.
 */
class AuthorizedLandingPage
{
    /** @var array<string, list<array{permission: string, path: string}>> */
    private const ROLE_DESTINATIONS = [
        'Administrator' => [
            ['permission' => 'dashboard.view', 'path' => '/dashboard'],
            ['permission' => 'users.view', 'path' => '/admin/users'],
            ['permission' => 'roles.view', 'path' => '/admin/roles'],
            ['permission' => 'audit.view', 'path' => '/admin/audit'],
            ['permission' => 'integrations.status.view', 'path' => '/admin/integrations'],
        ],
        'Executive' => [
            ['permission' => 'executive.overview.view', 'path' => '/executive'],
        ],
        'Sales' => [
            ['permission' => 'sales.dashboard.view', 'path' => '/sales/dashboard'],
            ['permission' => 'department.sales.view', 'path' => '/sales'],
        ],
        'Sales Manager' => [
            ['permission' => 'sales.dashboard.view', 'path' => '/sales/dashboard'],
            ['permission' => 'department.sales.view', 'path' => '/sales'],
        ],
        'Operations' => [
            ['permission' => 'operations.jobs.view', 'path' => '/operations'],
            ['permission' => 'department.operations.view', 'path' => '/operations'],
        ],
        'Transport' => [
            ['permission' => 'transport.jobs.view', 'path' => '/transport'],
            ['permission' => 'department.transport.view', 'path' => '/transport'],
        ],
        'Forwarding' => [
            ['permission' => 'forwarding.jobs.view', 'path' => '/forwarding'],
            ['permission' => 'department.forwarding.view', 'path' => '/forwarding'],
        ],
        'Accounting' => [
            ['permission' => 'accounting.dashboard.view', 'path' => '/accounting'],
            ['permission' => 'budgets.view', 'path' => '/accounting'],
            ['permission' => 'department.accounting.view', 'path' => '/accounting'],
        ],
        'Billing' => [
            ['permission' => 'billing.dashboard.view', 'path' => '/billing'],
            ['permission' => 'billing.invoices.view', 'path' => '/billing/register'],
            ['permission' => 'department.billing.view', 'path' => '/billing'],
        ],
        'Finance' => [
            ['permission' => 'financial-closing.view', 'path' => '/finance/shipment-closing'],
            ['permission' => 'collections.view', 'path' => '/collections'],
            ['permission' => 'department.finance.view', 'path' => '/finance'],
        ],
    ];

    /** @var list<array{permission: string, path: string}> */
    private const GENERAL_DESTINATIONS = [
        ['permission' => 'dashboard.view', 'path' => '/dashboard'],
        ['permission' => 'sales.dashboard.view', 'path' => '/sales/dashboard'],
        ['permission' => 'sales.calendar.view', 'path' => '/sales/calendar'],
        ['permission' => 'department.sales.view', 'path' => '/sales'],
        ['permission' => 'inquiries.view', 'path' => '/sales/inquiries'],
        ['permission' => 'clients.view', 'path' => '/sales/clients'],
        ['permission' => 'operations.jobs.view', 'path' => '/operations'],
        ['permission' => 'transport.jobs.view', 'path' => '/transport'],
        ['permission' => 'forwarding.jobs.view', 'path' => '/forwarding'],
        ['permission' => 'accounting.dashboard.view', 'path' => '/accounting'],
        ['permission' => 'billing.dashboard.view', 'path' => '/billing'],
        ['permission' => 'collections.view', 'path' => '/collections'],
        ['permission' => 'users.view', 'path' => '/admin/users'],
        ['permission' => 'roles.view', 'path' => '/admin/roles'],
        ['permission' => 'audit.view', 'path' => '/admin/audit'],
        ['permission' => 'profile.view', 'path' => '/profile'],
    ];

    public function resolve(User $user): ?string
    {
        foreach (self::ROLE_DESTINATIONS as $role => $destinations) {
            if (! $user->hasRole($role)) {
                continue;
            }

            if ($destination = $this->firstAllowed($user, $destinations)) {
                return $destination;
            }
        }

        return $this->firstAllowed($user, self::GENERAL_DESTINATIONS);
    }

    /** @param list<array{permission: string, path: string}> $destinations */
    private function firstAllowed(User $user, array $destinations): ?string
    {
        foreach ($destinations as $destination) {
            if ($user->can($destination['permission'])) {
                return $destination['path'];
            }
        }

        return null;
    }
}
