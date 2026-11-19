<?php

namespace App\Providers;

use App\Services\ClamAvMalwareScanner;
use App\Services\MalwareScanner;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MalwareScanner::class, ClamAvMalwareScanner::class);
    }

    public function boot(): void
    {
        Gate::define('use-ai-assistant', fn ($user): bool => (bool) ($user->can_use_ai ?? false));

        // Browser tests must use the production manifest without reading or
        // changing the developer's possibly active public/hot file.
        if (app()->environment('testing') && env('IFC_E2E', false)) {
            Vite::useHotFile(storage_path('framework/testing/phase17-vite.hot'));
        }
    }
}
