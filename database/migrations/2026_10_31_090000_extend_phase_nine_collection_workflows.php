<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_payment_allocations', function (Blueprint $table): void {
            // MySQL requires an index beginning with receipt_id for its foreign key.
            // The old composite unique index supplied that implicitly, so create
            // a non-unique replacement before removing the uniqueness constraint.
            $table->index('receipt_id', 'collection_payment_alloc_receipt_idx');
            $table->dropUnique(['receipt_id', 'invoice_id']);
        });

        Schema::table('collection_payment_receipts', function (Blueprint $table): void {
            $table->string('provider_name', 120)->nullable();
            $table->string('bank_transaction_reference', 180)->nullable()->index();
            $table->string('deposit_reference', 180)->nullable();
            $table->date('clearing_date')->nullable();
            $table->text('payment_notes')->nullable();
        });

        Schema::create('collection_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->date('follow_up_date');
            $table->string('contact_method', 40);
            $table->text('notes');
            $table->date('promised_payment_date')->nullable();
            $table->decimal('promised_amount', 16, 2)->nullable();
            $table->text('next_action')->nullable();
            $table->string('status', 24)->default('scheduled')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'follow_up_date']);
            $table->index(['invoice_id', 'status']);
        });

        foreach (['collections.followups.view', 'collections.followups.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $grants = [
            'Accounting' => ['collections.followups.view', 'collections.followups.manage'],
            'Finance' => ['collections.followups.view'],
            'Executive' => ['collections.followups.view'],
        ];

        foreach ($grants as $roleName => $permissions) {
            $role = Role::query()->where(['name' => $roleName, 'guard_name' => 'web'])->first();
            if ($role) {
                $role->givePermissionTo(Permission::query()->whereIn('name', $permissions)->where('guard_name', 'web')->get());
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_follow_ups');
        Schema::table('collection_payment_receipts', function (Blueprint $table): void {
            $table->dropIndex(['bank_transaction_reference']);
            $table->dropColumn(['provider_name', 'bank_transaction_reference', 'deposit_reference', 'clearing_date', 'payment_notes']);
        });
        // Allocation history can contain multiple entries for an invoice/receipt pair; do not restore the old uniqueness constraint.
    }
};
