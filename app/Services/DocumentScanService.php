<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentScanService
{
    public function __construct(private readonly MalwareScanner $scanner) {}

    /** @return array{path: string, scan_status: string, scan_result: string, scanned_at: mixed} */
    public function store(UploadedFile $file, string $trustedDirectory): array
    {
        $scan = $this->scanWithRetry($file->getRealPath());
        $safeId = (string) Str::uuid();
        $directory = $scan['status'] === 'clean' ? $trustedDirectory : 'quarantine/'.$safeId;
        $path = $file->store($directory, 'local');

        return [
            'path' => $path,
            'scan_status' => $scan['status'],
            'scan_result' => Str::limit($scan['result'], 500, ''),
            'scanned_at' => $scan['status'] === 'not_configured' ? null : now(),
        ];
    }

    /** @return array{status: string, result: string} */
    private function scanWithRetry(string $filePath): array
    {
        $retries = max(0, min(3, (int) config('documents.clamav.retries', 1)));
        $delay = max(0, min(2000, (int) config('documents.clamav.retry_delay_ms', 100)));

        for ($attempt = 0; ; $attempt++) {
            $scan = $this->scanner->scan($filePath);
            if ($scan['status'] !== 'error' || $attempt >= $retries) {
                return $scan;
            }

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }
    }

    public function assertTrusted(string $status): void
    {
        abort_unless($status === 'clean', 423, 'The document is quarantined until a clean malware scan is recorded.');
    }

    public function isTrusted(string $path, string $status): bool
    {
        return $status === 'clean' && Storage::disk('local')->exists($path);
    }
}
