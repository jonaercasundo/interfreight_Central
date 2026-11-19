<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $administrator = Role::where('name', 'Administrator')->where('guard_name', 'web')->first();
        if ($administrator) {
            $administrator->givePermissionTo(Permission::whereIn('name', [
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.verify', 'expenses.adjust',
                'expenses.receipts.upload', 'expenses.receipts.verify', 'expenses.client-approval.record',
                'cash-advances.view', 'cash-advances.request', 'cash-advances.review', 'cash-advances.approve',
                'cash-advances.disbursement.record', 'cash-advances.disbursement.verify', 'cash-advances.liquidate',
                'cash-advances.liquidation.verify', 'cash-advances.returns.verify', 'budgets.utilization.view',
                'expenses.variance.view', 'expenses.exceptions.view', 'expenses.exceptions.approve',
            ])->get());
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep permission assignments intact on rollback; existing administrator access is protected.
    }
};
