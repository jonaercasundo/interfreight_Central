<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'billing.dashboard.view', 'billing.ready.view', 'billing.invoices.view', 'billing.invoices.create',
        'billing.invoices.update', 'billing.invoices.submit', 'billing.invoices.review', 'billing.invoices.approve',
        'billing.invoices.issue', 'billing.invoices.download', 'billing.invoices.adjust', 'billing.invoices.history.view',
        'billing.documents.manage', 'billing.exceptions.view', 'billing.exceptions.resolve', 'billing.register.export',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $grants = [
            'Billing' => ['billing.dashboard.view', 'billing.ready.view', 'billing.invoices.view', 'billing.invoices.create', 'billing.invoices.update', 'billing.invoices.submit', 'billing.invoices.issue', 'billing.invoices.download', 'billing.invoices.history.view', 'billing.documents.manage', 'billing.exceptions.view', 'billing.exceptions.resolve'],
            'Accounting' => ['billing.dashboard.view', 'billing.invoices.view', 'billing.invoices.review', 'billing.invoices.approve', 'billing.invoices.download', 'billing.invoices.history.view', 'billing.exceptions.view'],
            'Finance' => ['billing.dashboard.view', 'billing.invoices.view', 'billing.invoices.review', 'billing.invoices.approve', 'billing.invoices.download', 'billing.invoices.history.view', 'billing.exceptions.view'],
            'Sales' => ['billing.invoices.view'],
            'Operations' => ['billing.ready.view'],
            'Transport' => ['billing.ready.view'],
            'Forwarding' => ['billing.ready.view'],
            'Executive' => ['billing.dashboard.view', 'billing.invoices.view', 'billing.exceptions.view'],
        ];
        foreach ($grants as $roleName => $names) {
            $role = Role::query()->where(['name' => $roleName, 'guard_name' => 'web'])->first();
            if ($role) {
                $role->givePermissionTo(Permission::query()->whereIn('name', $names)->where('guard_name', 'web')->get());
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve assigned access during rollback; this migration only adds permission records and grants.
    }
};
