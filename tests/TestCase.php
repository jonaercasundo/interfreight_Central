<?php

namespace Tests;

use App\Services\MalwareScanner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $filePath): array
            {
                return ['status' => 'clean', 'result' => 'Synthetic test scanner approved this fixture.'];
            }
        });
    }

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
