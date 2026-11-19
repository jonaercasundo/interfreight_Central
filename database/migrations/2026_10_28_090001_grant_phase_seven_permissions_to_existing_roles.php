<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'Administrator' => Permission::query()->where('name', 'like', 'additional-charges.%')->orWhere('name', 'like', 'commercial-changes.%')->orWhere('name', 'like', 'expense-recoverability.%')->orWhere('name', 'profit-impact.view')->get(),
            'Operations' => ['additional-charges.view', 'additional-charges.create', 'additional-charges.update', 'additional-charges.submit', 'additional-charges.history.view'],
            'Transport' => ['additional-charges.view', 'additional-charges.create', 'additional-charges.update', 'additional-charges.submit', 'additional-charges.history.view'],
            'Forwarding' => ['additional-charges.view', 'additional-charges.create', 'additional-charges.update', 'additional-charges.submit', 'additional-charges.history.view'],
            'Sales' => ['additional-charges.view', 'additional-charges.internal-review', 'additional-charges.client-request.prepare', 'additional-charges.client-response.record', 'additional-charges.history.view', 'commercial-changes.view', 'commercial-changes.authorize'],
            'Accounting' => ['additional-charges.view', 'additional-charges.internal-review', 'additional-charges.client-response.verify', 'additional-charges.history.view', 'expense-recoverability.view', 'expense-recoverability.classify', 'profit-impact.view'],
            'Finance' => ['additional-charges.view', 'additional-charges.internal-review', 'additional-charges.client-response.verify', 'additional-charges.history.view', 'commercial-changes.view', 'expense-recoverability.view', 'expense-recoverability.classify', 'profit-impact.view'],
            'Billing' => ['commercial-changes.billing-eligibility.view'],
            'Executive' => ['additional-charges.view', 'commercial-changes.view', 'expense-recoverability.view', 'profit-impact.view'],
        ];

        foreach ($grants as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo(is_array($permissions) ? Permission::query()->whereIn('name', $permissions)->where('guard_name', 'web')->get() : $permissions);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep additive role grants intact to avoid revoking access already assigned to users.
    }
};
