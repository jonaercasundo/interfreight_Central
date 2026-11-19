param(
    [int] $Port = 8002,
    [string] $PhpExecutable = $env:IFC_PHP_EXECUTABLE
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$publicRoot = Join-Path $projectRoot 'public'
$frontController = Join-Path $publicRoot 'index.php'

if ([string]::IsNullOrWhiteSpace($PhpExecutable)) {
    $xamppPhp = 'C:\xampp\php\php.exe'
    $PhpExecutable = if (Test-Path -LiteralPath $xamppPhp) {
        $xamppPhp
    } else {
        (Get-Command php.exe -ErrorAction Stop).Source
    }
}

if (-not (Test-Path -LiteralPath $PhpExecutable)) {
    throw "PHP executable was not found: $PhpExecutable"
}
if (-not (Test-Path -LiteralPath $frontController)) {
    throw 'Laravel is not installed. Run this script from the project after Composer dependencies are installed.'
}

$listener = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
if ($listener) {
    throw "Port $Port is already in use. The script will not stop or replace that process."
}

$process = Start-Process -FilePath $PhpExecutable `
    -ArgumentList @('-S', "127.0.0.1:$Port", '-t', $publicRoot, $frontController) `
    -WorkingDirectory $projectRoot `
    -WindowStyle Hidden `
    -PassThru

$healthUrl = "http://127.0.0.1:$Port/up"
$deadline = (Get-Date).AddSeconds(15)
do {
    Start-Sleep -Milliseconds 300
    try {
        $health = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 2
        if ($health.StatusCode -eq 200) {
            Write-Output "IFC local signed-credential callback is listening on 127.0.0.1:$Port (PID $($process.Id))."
            Write-Output "Configure AI_LARAVEL_CREDENTIAL_URL=$("http://127.0.0.1:$Port/api/internal/ai-credential") in the ignored project .env, then restart FastAPI."
            exit 0
        }
    } catch {
        if ((Get-Date) -ge $deadline) {
            Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
            throw "Laravel callback health check did not pass at $healthUrl."
        }
    }
} while ((Get-Date) -lt $deadline)

Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
throw "Laravel callback health check did not pass at $healthUrl."
