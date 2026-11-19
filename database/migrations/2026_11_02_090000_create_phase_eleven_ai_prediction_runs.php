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
        Schema::create('ai_prediction_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->string('target', 64)->index();
            $table->string('model_version', 80);
            $table->date('training_cutoff')->nullable();
            $table->date('input_start')->nullable();
            $table->date('input_end')->nullable();
            $table->unsignedTinyInteger('horizon_months');
            $table->string('status', 24)->index();
            $table->json('source_aggregate');
            $table->json('forecasts');
            $table->json('evaluation')->nullable();
            $table->text('limitation')->nullable();
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->index(['target', 'generated_at']);
        });

        foreach (['ai.forecasts.view', 'ai.forecasts.generate'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $executive = Role::query()->where(['name' => 'Executive', 'guard_name' => 'web'])->first();
        $executive?->givePermissionTo(Permission::where('guard_name', 'web')->whereIn('name', ['ai.forecasts.view', 'ai.forecasts.generate'])->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prediction_runs');
        // Keep authorization registry rows and grants intact, consistent with previous IFC migrations.
    }
};
