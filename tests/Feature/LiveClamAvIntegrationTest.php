<?php

namespace Tests\Feature;

use App\Services\ClamAvMalwareScanner;
use App\Services\DocumentScanService;
use App\Services\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LiveClamAvIntegrationTest extends TestCase
{
    public function test_real_clamav_accepts_clean_file_and_quarantines_eicar_signature(): void
    {
        $host = getenv('IFC_CLAMAV_HOST');
        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('Set IFC_CLAMAV_HOST to run the isolated live ClamAV test.');
        }

        config([
            'documents.clamav.host' => $host,
            'documents.clamav.port' => (int) (getenv('IFC_CLAMAV_PORT') ?: 3310),
            'documents.clamav.timeout' => 10,
            'documents.clamav.retries' => 1,
            'documents.clamav.retry_delay_ms' => 100,
        ]);
        $this->app->instance(MalwareScanner::class, app(ClamAvMalwareScanner::class));
        Storage::fake('local');

        $clean = app(DocumentScanService::class)->store(
            UploadedFile::fake()->createWithContent('clean.txt', 'Synthetic IFC clean-file verification.')->mimeType('text/plain'),
            'documents',
        );
        $this->assertSame('clean', $clean['scan_status']);
        $this->assertStringStartsWith('documents/', $clean['path']);

        $signature = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $infected = app(DocumentScanService::class)->store(
            UploadedFile::fake()->createWithContent('eicar-test.txt', $signature)->mimeType('text/plain'),
            'documents',
        );
        $this->assertSame('infected', $infected['scan_status']);
        $this->assertStringStartsWith('quarantine/', $infected['path']);

        try {
            app(DocumentScanService::class)->assertTrusted($infected['scan_status']);
            $this->fail('The EICAR test signature must remain quarantined.');
        } catch (HttpException $exception) {
            $this->assertSame(423, $exception->getStatusCode());
        }
    }
}
