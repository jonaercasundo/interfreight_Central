param(
    [Parameter(Mandatory = $true)][string]$Archive,
    [switch]$RestoreToDisposableDatabase
)
$ErrorActionPreference = 'Stop'
foreach ($tool in @('age', 'tar')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "Required verification tool '$tool' is unavailable." }
}
$resolvedArchive = (Resolve-Path -LiteralPath $Archive).Path
$manifestPath = "$resolvedArchive.manifest.json"
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) { throw 'Backup manifest is missing.' }
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$actualHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $resolvedArchive).Hash.ToLowerInvariant()
if ($actualHash -ne $manifest.sha256) { throw 'Encrypted archive SHA-256 does not match its manifest.' }
if ([string]::IsNullOrWhiteSpace($env:IFC_BACKUP_AGE_IDENTITY)) { throw 'Set IFC_BACKUP_AGE_IDENTITY to the authorized decryption identity file.' }
$work = Join-Path ([IO.Path]::GetTempPath()) "ifc-restore-drill-$([guid]::NewGuid().ToString('N'))"
$plain = Join-Path $work 'backup.tar.gz'
New-Item -ItemType Directory -Path $work | Out-Null
try {
    & age --decrypt --identity $env:IFC_BACKUP_AGE_IDENTITY --output $plain $resolvedArchive
    if ($LASTEXITCODE -ne 0) { throw 'Decryption failed.' }
    $entries = tar -tzf $plain
    if ($LASTEXITCODE -ne 0) { throw 'Archive integrity check failed.' }
    foreach ($entry in $entries) {
        $normalized = $entry.Replace('\\', '/')
        if ($normalized.StartsWith('/') -or $normalized -match '^[A-Za-z]:' -or ($normalized.Split('/') -contains '..')) { throw 'Archive contains an unsafe path.' }
    }
    tar -xzf $plain -C $work
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath (Join-Path $work 'database.sql'))) { throw 'Database dump is missing or invalid.' }
    $documentsRoot = Join-Path $work 'private-documents'
    $dump = Join-Path $work 'database.sql'
    if ((Get-Item -LiteralPath $dump).Length -eq 0) { throw 'Database dump is empty.' }
    $documentCount = (Get-ChildItem -LiteralPath $documentsRoot -File -Recurse | Measure-Object).Count
    if ($documentCount -ne [int]$manifest.private_document_file_count) { throw 'Private document count does not match the backup manifest.' }
    Write-Output "Archive decrypt and integrity verification succeeded. Database dump bytes: $((Get-Item $dump).Length); private files: $documentCount."

    if ($RestoreToDisposableDatabase) {
        if ($env:IFC_RESTORE_DB -notmatch '^[A-Za-z0-9_]+_restore_drill$') { throw 'IFC_RESTORE_DB must end with _restore_drill.' }
        foreach ($name in @('DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD')) {
            if ([string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($name))) { throw "Required setting '$name' is missing." }
        }
        $oldMysqlPwd = $env:MYSQL_PWD
        try {
            $env:MYSQL_PWD = $env:DB_PASSWORD
            $exists = & mysql --host=$env:DB_HOST --port=$env:DB_PORT --user=$env:DB_USERNAME --batch --skip-column-names --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$($env:IFC_RESTORE_DB)'"
            if ($LASTEXITCODE -ne 0) { throw 'Unable to inspect the explicitly named restore-drill database.' }
            if ([int]$exists -gt 0) { throw 'Restore target already contains tables; refusing to overwrite it.' }
            & mysql --host=$env:DB_HOST --port=$env:DB_PORT --user=$env:DB_USERNAME --execute="CREATE DATABASE IF NOT EXISTS $($env:IFC_RESTORE_DB) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            if ($LASTEXITCODE -ne 0) { throw 'Could not create isolated restore target.' }
            Get-Content -LiteralPath $dump -Raw | & mysql --host=$env:DB_HOST --port=$env:DB_PORT --user=$env:DB_USERNAME $env:IFC_RESTORE_DB
            if ($LASTEXITCODE -ne 0) { throw 'Restore into disposable database failed.' }
            Write-Output "Database restore completed to empty isolated target '$($env:IFC_RESTORE_DB)'. Run schema and representative-row checks before recording an RTO/RPO result."
        }
        finally {
            if ($null -eq $oldMysqlPwd) { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue } else { $env:MYSQL_PWD = $oldMysqlPwd }
        }
    }
}
finally {
    Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
}
