<?php

use App\Services\ClamAvMalwareScanner;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$cleanPath = tempnam(sys_get_temp_dir(), 'ifc17-clean-');
$infectedPath = tempnam(sys_get_temp_dir(), 'ifc17-eicar-');
if ($cleanPath === false || $infectedPath === false) {
    throw new RuntimeException('Could not create temporary scanner probe files.');
}

try {
    file_put_contents($cleanPath, 'Synthetic IFC clean-file scan.');
    file_put_contents($infectedPath, 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
    $scanner = app(ClamAvMalwareScanner::class);
    $clean = $scanner->scan($cleanPath);
    $infected = $scanner->scan($infectedPath);
    printf("clean=%s\n", $clean['status']);
    printf("eicar=%s\n", $infected['status']);
    if ($clean['status'] !== 'clean' || $infected['status'] !== 'infected') {
        exit(1);
    }
} finally {
    @unlink($cleanPath);
    @unlink($infectedPath);
}
