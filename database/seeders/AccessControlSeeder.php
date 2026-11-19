<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlSeeder extends Seeder
{
    public const ROLES = ['Administrator', 'Executive', 'Sales', 'Sales Manager', 'Operations', 'Transport', 'Forwarding', 'Accounting', 'Billing', 'Finance'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $names = ['dashboard.view', 'executive.overview.view', 'users.view', 'users.create', 'users.update', 'users.password.reset', 'users.password.reset.privileged', 'users.deactivate', 'users.assign-role', 'roles.view', 'roles.manage', 'permissions.view', 'permissions.manage', 'audit.view', 'audit.export', 'integrations.status.view', 'profile.view', 'profile.update', 'assistant.use', 'ai.configuration.view', 'ai.configuration.manage', 'ai.configuration.test', 'admin.ai-config.view', 'admin.ai-config.manage', 'admin.ai-config.test', 'admin.ai-usage.view', 'admin.ai-pricing.manage', 'admin.ai-fx.manage', 'admin.ai-budget.manage', 'admin.ai-usage.export', 'shipments.view', 'shipments.manage', 'clients.view', 'clients.create', 'clients.update', 'inquiries.view', 'inquiries.create', 'inquiries.update', 'inquiries.submit', 'inquiries.review', 'inquiries.cancel', 'forecasts.view', 'forecasts.create', 'forecasts.update', 'forecasts.withdraw', 'forecasts.accounting.view', 'cost-estimates.view', 'cost-estimates.create', 'cost-estimates.update', 'cost-estimates.submit', 'cost-estimates.review', 'cost-estimates.history.view', 'cost-estimates.cash.view', 'quotations.view', 'quotations.create', 'quotations.update', 'quotations.submit', 'quotations.review', 'quotations.approve', 'quotations.issue', 'quotations.client-response.record', 'quotations.revise', 'quotations.history.view', 'quotations.export', 'margins.view', 'margins.policy.manage', 'financial-policies.view', 'financial-policies.manage', 'financial-policies.approve', 'billing.statutory.view', 'billing.statutory.manage', 'billing.statutory.approve', 'sales.dashboard.view', 'sales.calendar.view', 'sales.availability.view', 'sales.availability.manage', 'sales.inquiries.approve'];
        $names = [...$names, 'shipments.create', 'shipments.update', 'shipments.assign', 'shipments.cancel', 'shipments.timeline.view', 'shipments.documents.view', 'shipments.documents.upload', 'operations.jobs.view', 'operations.jobs.manage', 'operations.customs.manage', 'operations.documents.verify', 'transport.jobs.view', 'transport.assignments.manage', 'transport.dispatch.manage', 'transport.deliveries.manage', 'transport.pod.upload', 'transport.pod.verify', 'forwarding.jobs.view', 'forwarding.bookings.manage', 'forwarding.containers.manage', 'forwarding.milestones.manage', 'shipment.exceptions.view', 'shipment.exceptions.manage', 'shipment.reconciliation.view'];
        $phaseSixPermissions = ['expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.verify', 'expenses.adjust', 'expenses.receipts.upload', 'expenses.receipts.verify', 'cash-advances.view', 'cash-advances.request', 'cash-advances.review', 'cash-advances.approve', 'cash-advances.disbursement.record', 'cash-advances.disbursement.verify', 'cash-advances.liquidate', 'cash-advances.liquidation.verify', 'cash-advances.returns.verify', 'budgets.utilization.view', 'expenses.variance.view', 'expenses.exceptions.view', 'expenses.exceptions.approve', 'expenses.client-approval.record'];
        $names = [...$names, ...$phaseSixPermissions];
        $phaseSevenPermissions = ['additional-charges.view', 'additional-charges.create', 'additional-charges.update', 'additional-charges.submit', 'additional-charges.internal-review', 'additional-charges.client-request.prepare', 'additional-charges.client-response.record', 'additional-charges.client-response.verify', 'additional-charges.history.view', 'commercial-changes.view', 'commercial-changes.authorize', 'commercial-changes.billing-eligibility.view', 'expense-recoverability.view', 'expense-recoverability.classify', 'profit-impact.view'];
        $names = [...$names, ...$phaseSevenPermissions];
        foreach (['Sales', 'Operations', 'Transport', 'Forwarding', 'Accounting', 'Billing', 'Finance'] as $department) {
            $names[] = 'department.'.strtolower($department).'.view';
        }
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $newRoles = [];
        foreach (self::ROLES as $name) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $newRoles[$name] = $role->wasRecentlyCreated;
        }
        $all = Permission::all();
        Role::findByName('Administrator')->givePermissionTo($all);
        if ($newRoles['Executive']) {
            Role::findByName('Executive')->syncPermissions(Permission::whereIn('name', ['dashboard.view', 'executive.overview.view', 'audit.view', 'integrations.status.view', 'shipments.view', ...array_values(array_filter($names, fn ($name) => str_ends_with($name, '.view') && str_starts_with($name, 'department.')))])->get());
        }
        foreach (['Sales', 'Operations', 'Transport', 'Forwarding', 'Accounting', 'Billing', 'Finance'] as $department) {
            if ($newRoles[$department]) {
                $departmentPermissions = ['dashboard.view', 'profile.view', 'profile.update', 'shipments.view', 'department.'.strtolower($department).'.view', 'assistant.use'];
                if ($department === 'Sales') {
                    $departmentPermissions = [...$departmentPermissions, 'clients.view', 'clients.create', 'clients.update', 'inquiries.view', 'inquiries.create', 'inquiries.update', 'inquiries.submit', 'inquiries.review', 'inquiries.cancel', 'forecasts.view', 'forecasts.create', 'forecasts.update', 'forecasts.withdraw', 'cost-estimates.view', 'cost-estimates.create', 'cost-estimates.update', 'cost-estimates.submit', 'cost-estimates.history.view', 'quotations.view', 'quotations.create', 'quotations.update', 'quotations.submit', 'quotations.issue', 'quotations.client-response.record', 'quotations.revise', 'quotations.history.view', 'quotations.export', 'margins.view'];
                }
                if ($department === 'Accounting') {
                    $departmentPermissions = [...$departmentPermissions, 'forecasts.accounting.view', 'cost-estimates.view', 'cost-estimates.review', 'cost-estimates.cash.view', 'quotations.view', 'quotations.review'];
                }
                if ($department === 'Finance') {
                    $departmentPermissions = [...$departmentPermissions, 'cost-estimates.view', 'quotations.view', 'quotations.review', 'quotations.approve', 'quotations.history.view', 'margins.view', 'margins.policy.manage', 'financial-policies.view', 'financial-policies.manage', 'financial-policies.approve', 'billing.statutory.view', 'billing.statutory.manage', 'billing.statutory.approve'];
                }
                Role::findByName($department)->syncPermissions(Permission::whereIn('name', $departmentPermissions)->get());
            }
        }
        Role::findByName('Sales')->givePermissionTo(['sales.dashboard.view', 'sales.calendar.view', 'sales.availability.view']);
        Role::findByName('Sales Manager')->givePermissionTo([
            'dashboard.view', 'profile.view', 'profile.update', 'assistant.use', 'department.sales.view',
            'shipments.view', 'shipments.create', 'shipments.timeline.view',
            'clients.view', 'clients.create', 'clients.update',
            'inquiries.view', 'inquiries.create', 'inquiries.update', 'inquiries.submit', 'inquiries.review', 'inquiries.cancel',
            'forecasts.view', 'forecasts.create', 'forecasts.update', 'forecasts.withdraw',
            'cost-estimates.view', 'cost-estimates.create', 'cost-estimates.update', 'cost-estimates.submit', 'cost-estimates.history.view',
            'quotations.view', 'quotations.create', 'quotations.update', 'quotations.submit', 'quotations.issue',
            'quotations.client-response.record', 'quotations.revise', 'quotations.history.view', 'quotations.export', 'margins.view',
            'sales.dashboard.view', 'sales.calendar.view', 'sales.availability.view', 'sales.availability.manage', 'sales.inquiries.approve',
        ]);
        Role::findByName('Accounting')->givePermissionTo(Permission::whereIn('name', ['accounting.dashboard.view', 'funding-requirements.view', 'funding-requirements.recalculate', 'budgets.view', 'budgets.create', 'budgets.update', 'budgets.submit', 'budgets.allocate', 'budgets.revise', 'budgets.close', 'budgets.history.view', 'cash-forecasts.view', 'commitments.view', 'budget-actuals.view', 'budget-variances.view'])->get());
        Role::findByName('Finance')->givePermissionTo(Permission::whereIn('name', ['accounting.dashboard.view', 'funding-requirements.view', 'budgets.view', 'budgets.review', 'budgets.approve', 'budgets.revise', 'budgets.history.view', 'cash-forecasts.view', 'commitments.view', 'budget-actuals.view', 'budget-variances.view'])->get());
        Role::findByName('Executive')->givePermissionTo(Permission::whereIn('name', ['accounting.dashboard.view', 'funding-requirements.view', 'budgets.view', 'budgets.history.view', 'budget-variances.view'])->get());
        if ($newRoles['Executive']) {
            Role::findByName('Executive')->givePermissionTo(['profile.view', 'profile.update', 'quotations.view', 'quotations.approve', 'quotations.history.view', 'margins.view']);
        }
        $phaseFiveGrants = [
            'Administrator' => ['shipments.view', 'shipments.timeline.view', 'shipments.documents.view', 'shipment.reconciliation.view'],
            'Sales' => ['shipments.view', 'shipments.create', 'shipments.timeline.view'],
            'Operations' => ['shipments.view', 'shipments.create', 'shipments.update', 'shipments.assign', 'shipments.cancel', 'shipments.timeline.view', 'shipments.documents.view', 'shipments.documents.upload', 'operations.jobs.view', 'operations.jobs.manage', 'operations.customs.manage', 'operations.documents.verify', 'transport.pod.verify', 'shipment.exceptions.view', 'shipment.exceptions.manage', 'shipment.reconciliation.view'],
            'Transport' => ['shipments.view', 'shipments.create', 'shipments.update', 'shipments.timeline.view', 'shipments.documents.view', 'shipments.documents.upload', 'transport.jobs.view', 'transport.assignments.manage', 'transport.dispatch.manage', 'transport.deliveries.manage', 'transport.pod.upload', 'shipment.exceptions.view', 'shipment.exceptions.manage'],
            'Forwarding' => ['shipments.view', 'shipments.create', 'shipments.update', 'shipments.timeline.view', 'shipments.documents.view', 'shipments.documents.upload', 'forwarding.jobs.view', 'forwarding.bookings.manage', 'forwarding.containers.manage', 'forwarding.milestones.manage', 'shipment.exceptions.view', 'shipment.exceptions.manage'],
            'Accounting' => ['shipments.view', 'shipments.timeline.view'],
            'Finance' => ['shipments.view', 'shipments.timeline.view'],
            'Executive' => ['shipments.view', 'shipments.timeline.view', 'shipment.reconciliation.view', 'operations.jobs.view', 'transport.jobs.view', 'forwarding.jobs.view'],
        ];
        foreach ($phaseFiveGrants as $role => $permissions) {
            Role::findByName($role)->givePermissionTo($permissions);
        }
        $phaseSixGrants = [
            'Operations' => ['expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.receipts.upload', 'cash-advances.view', 'cash-advances.request', 'cash-advances.liquidate', 'expenses.variance.view', 'expenses.exceptions.view'],
            'Transport' => ['expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.receipts.upload', 'cash-advances.view', 'cash-advances.request', 'cash-advances.liquidate', 'expenses.variance.view', 'expenses.exceptions.view'],
            'Forwarding' => ['expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.receipts.upload', 'cash-advances.view', 'cash-advances.request', 'cash-advances.liquidate', 'expenses.variance.view', 'expenses.exceptions.view'],
            'Accounting' => ['expenses.view', 'expenses.verify', 'expenses.receipts.verify', 'cash-advances.view', 'cash-advances.review', 'cash-advances.disbursement.record', 'cash-advances.liquidation.verify', 'cash-advances.returns.verify', 'budgets.utilization.view', 'expenses.variance.view', 'expenses.exceptions.view'],
            'Finance' => ['expenses.view', 'expenses.client-approval.record', 'cash-advances.view', 'cash-advances.approve', 'cash-advances.disbursement.verify', 'expenses.exceptions.view', 'expenses.exceptions.approve', 'expenses.variance.view', 'budgets.utilization.view'],
        ];
        foreach ($phaseSixGrants as $role => $permissions) {
            Role::findByName($role)->givePermissionTo($permissions);
        }
        $phaseSevenGrants = [
            'Operations' => ['additional-charges.view', 'additional-charges.create', 'additional-charges.update', 'additional-charges.submit', 'additional-charges.history.view'],
            'Transport' => ['additional-charges.view', 'additional-charges.create', 'additional-charges.update', 'additional-charges.submit', 'additional-charges.history.view'],
            'Forwarding' => ['additional-charges.view', 'additional-charges.create', 'additional-charges.update', 'additional-charges.submit', 'additional-charges.history.view'],
            'Sales' => ['additional-charges.view', 'additional-charges.internal-review', 'additional-charges.client-request.prepare', 'additional-charges.client-response.record', 'additional-charges.history.view', 'commercial-changes.view', 'commercial-changes.authorize'],
            'Accounting' => ['additional-charges.view', 'additional-charges.internal-review', 'additional-charges.client-response.verify', 'additional-charges.history.view', 'expense-recoverability.view', 'expense-recoverability.classify', 'profit-impact.view'],
            'Finance' => ['additional-charges.view', 'additional-charges.internal-review', 'additional-charges.client-response.verify', 'additional-charges.history.view', 'commercial-changes.view', 'expense-recoverability.view', 'expense-recoverability.classify', 'profit-impact.view'],
            'Billing' => ['commercial-changes.billing-eligibility.view'],
            'Executive' => ['additional-charges.view', 'commercial-changes.view', 'expense-recoverability.view', 'profit-impact.view'],
        ];
        foreach ($phaseSevenGrants as $role => $permissions) {
            Role::findByName($role)->givePermissionTo($permissions);
        }
        $phaseEightGrants = [
            'Billing' => ['billing.dashboard.view', 'billing.ready.view', 'billing.invoices.view', 'billing.invoices.create', 'billing.invoices.update', 'billing.invoices.submit', 'billing.invoices.issue', 'billing.invoices.download', 'billing.invoices.history.view', 'billing.documents.manage', 'billing.exceptions.view', 'billing.exceptions.resolve'],
            'Accounting' => ['billing.dashboard.view', 'billing.invoices.view', 'billing.invoices.review', 'billing.invoices.approve', 'billing.invoices.download', 'billing.invoices.history.view', 'billing.exceptions.view'],
            'Finance' => ['billing.dashboard.view', 'billing.invoices.view', 'billing.invoices.review', 'billing.invoices.approve', 'billing.invoices.download', 'billing.invoices.history.view', 'billing.exceptions.view'],
            'Sales' => ['billing.invoices.view'],
            'Operations' => ['billing.ready.view'],
            'Transport' => ['billing.ready.view'],
            'Forwarding' => ['billing.ready.view'],
            'Executive' => ['billing.dashboard.view', 'billing.invoices.view', 'billing.exceptions.view'],
        ];
        foreach ($phaseEightGrants as $role => $permissions) {
            Role::findByName($role)->givePermissionTo(Permission::whereIn('name', $permissions)->get());
        }
        $phaseNineGrants = [
            'Accounting' => ['collections.view', 'collections.record', 'collections.history.view', 'collections.followups.view', 'collections.followups.manage'],
            'Finance' => ['collections.view', 'collections.reconcile', 'collections.history.view', 'collections.followups.view', 'collections.reverse'],
            'Billing' => ['collections.view', 'collections.history.view'],
            'Executive' => ['collections.view', 'collections.followups.view'],
        ];
        foreach ($phaseNineGrants as $role => $permissions) {
            Role::findByName($role)->givePermissionTo(Permission::whereIn('name', $permissions)->get());
        }
        $phaseTenGrants = [
            'Operations' => ['shipments.completion.view', 'shipments.completion.operations.verify', 'shipments.reconciliation.view'],
            'Transport' => ['shipments.completion.view', 'shipments.reconciliation.view'],
            'Forwarding' => ['shipments.completion.view', 'shipments.reconciliation.view'],
            'Billing' => ['shipments.completion.view', 'shipments.completion.billing.verify', 'shipments.reconciliation.view'],
            'Accounting' => ['shipments.completion.view', 'shipments.completion.collections.verify', 'shipments.reconciliation.view', 'shipments.reconciliation.manage', 'financial-closing.view', 'financial-closing.review', 'financial-closing.snapshots.view', 'financial-closing.history.view'],
            'Finance' => ['shipments.completion.view', 'shipments.reconciliation.view', 'shipments.reconciliation.manage', 'profitability.dashboard.view', 'profitability.shipments.view', 'profitability.reports.view', 'profitability.cost-details.view', 'financial-closing.view', 'financial-closing.prepare', 'financial-closing.submit', 'financial-closing.review', 'financial-closing.close', 'financial-closing.reopen', 'financial-closing.snapshots.view', 'financial-closing.history.view', 'financial-closing.reports.export'],
            'Executive' => ['shipments.completion.view', 'shipments.reconciliation.view', 'profitability.dashboard.view', 'profitability.reports.view', 'financial-closing.view', 'financial-closing.approve', 'financial-closing.snapshots.view', 'financial-closing.history.view'],
        ];
        foreach ($phaseTenGrants as $role => $permissions) {
            Role::findByName($role)->givePermissionTo(Permission::whereIn('name', $permissions)->get());
        }
        Role::findByName('Finance')->givePermissionTo(Permission::whereIn('name', ['financial-policies.view', 'financial-policies.manage', 'financial-policies.approve', 'billing.statutory.view', 'billing.statutory.manage', 'billing.statutory.approve'])->get());
        Role::findByName('Executive')->givePermissionTo(Permission::whereIn('name', ['ai.forecasts.view', 'ai.forecasts.generate'])->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
