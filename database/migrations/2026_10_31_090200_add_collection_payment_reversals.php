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
        Schema::create('collection_payment_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->unique()->constrained('collection_payment_receipts')->restrictOnDelete();
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->text('reason');
            $table->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at');
            $table->timestamps();
        });

        Permission::findOrCreate('collections.reverse', 'web');
        $finance = Role::query()->where(['name' => 'Finance', 'guard_name' => 'web'])->first();
        $finance?->givePermissionTo(Permission::findByName('collections.reverse', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_payment_reversals');
        // Permission and role grants are retained to protect existing access data.
    }
};
