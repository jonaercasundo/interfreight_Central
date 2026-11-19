<?php

namespace Tests\Feature;

use App\Services\DocumentScanService;
use App\Services\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DocumentScanSecurityTest extends TestCase
{
    public function test_transient_scanner_errors_are_retried_but_infected_results_are_not(): void
    {
        Storage::fake('local');
        Config::set('documents.clamav.retries', 2);
        Config::set('documents.clamav.retry_delay_ms', 0);
        $scanner = new class implements MalwareScanner
        {
            public int $calls = 0;

            public function scan(string $filePath): array
            {
                $this->calls++;

                return ['status' => $this->calls < 3 ? 'error' : 'clean', 'result' => 'Synthetic result.'];
            }
        };
        $this->app->instance(MalwareScanner::class, $scanner);

        $stored = app(DocumentScanService::class)->store(UploadedFile::fake()->create('retry.pdf', 1), 'trusted');

        $this->assertSame(3, $scanner->calls);
        $this->assertSame('clean', $stored['scan_status']);
        $this->assertStringStartsWith('trusted/', $stored['path']);

        $scanner = new class implements MalwareScanner
        {
            public int $calls = 0;

            public function scan(string $filePath): array
            {
                $this->calls++;

                return ['status' => 'infected', 'result' => 'Synthetic threat.'];
            }
        };
        $this->app->instance(MalwareScanner::class, $scanner);

        $stored = app(DocumentScanService::class)->store(UploadedFile::fake()->create('infected.pdf', 1), 'trusted');
        $this->assertSame(1, $scanner->calls);
        $this->assertSame('infected', $stored['scan_status']);
        $this->assertStringStartsWith('quarantine/', $stored['path']);
    }

    public function test_infected_or_unavailable_scan_is_quarantined_and_cannot_be_trusted(): void
    {
        foreach (['infected', 'error', 'not_configured'] as $status) {
            Storage::fake('local');
            $this->app->instance(MalwareScanner::class, new class($status) implements MalwareScanner
            {
                public function __construct(private readonly string $status) {}

                public function scan(string $filePath): array
                {
                    return ['status' => $this->status, 'result' => 'Synthetic scanner result.'];
                }
            });

            $stored = app(DocumentScanService::class)->store(UploadedFile::fake()->create('proof.pdf', 1), 'shipment-documents');

            $this->assertSame($status, $stored['scan_status']);
            $this->assertStringStartsWith('quarantine/', $stored['path']);
            $this->assertFalse(app(DocumentScanService::class)->isTrusted($stored['path'], $stored['scan_status']));
            try {
                app(DocumentScanService::class)->assertTrusted($stored['scan_status']);
                $this->fail('A non-clean file must never be trusted.');
            } catch (HttpException $exception) {
                $this->assertSame(423, $exception->getStatusCode());
            }
        }
    }

    public function test_only_clean_scan_is_stored_in_trusted_directory(): void
    {
        Storage::fake('local');
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $filePath): array
            {
                return ['status' => 'clean', 'result' => 'Synthetic clean result.'];
            }
        });

        $stored = app(DocumentScanService::class)->store(UploadedFile::fake()->create('proof.pdf', 1), 'shipment-documents');

        $this->assertSame('clean', $stored['scan_status']);
        $this->assertStringStartsWith('shipment-documents/', $stored['path']);
        $this->assertTrue(app(DocumentScanService::class)->isTrusted($stored['path'], $stored['scan_status']));
    }
}
