<?php

return [
    // Fail closed when ClamAV is missing: uploads are retained in private quarantine.
    'clamav' => [
        'host' => env('IFC_CLAMAV_HOST'),
        'port' => (int) env('IFC_CLAMAV_PORT', 3310),
        'timeout' => (int) env('IFC_CLAMAV_TIMEOUT', 3),
        // Retry only transient scanner errors. Infected results are final and
        // are quarantined immediately; an unavailable scanner always fails closed.
        'retries' => (int) env('IFC_CLAMAV_RETRIES', 1),
        'retry_delay_ms' => (int) env('IFC_CLAMAV_RETRY_DELAY_MS', 100),
    ],
];
