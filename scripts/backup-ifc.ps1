param(
    [Parameter(Mandatory = $true)][string]$BackupRoot,
    [string]$PrivateDocuments = (Join-Path $PSScriptRoot '..\storage\app\private'),
    [ValidateRange(0, 3650)][int]$RetentionDays = 0,
    [switch]$ApplyRetention
)
$ErrorActionPreference = 'Stop'

foreach ($tool in @('mysqldump', 'age', 'tar')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "Required backup tool '$tool' is unavailable." }
}
foreach ($name in @('DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'IFC_BACKUP_AGE_RECIPIENT')) {
    if ([string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($name))) { throw "Required setting '$name' is missing." }
}
if (-not (Test-Path -LiteralPath $PrivateDocuments -PathType Container)) { throw "Private document directory not found: $PrivateDocuments" }

$resolvedRoot = [IO.Path]::GetFullPath($BackupRoot)
New-Item -ItemType Directory -Force -Path $resolvedRoot | Out-Null
$stamp = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')
$id = [guid]::NewGuid().ToString('N')
$work = Join-Path ([IO.Path]::GetTempPath()) "ifc-backup-$id"
$stage = Join-Path $work 'payload'
$plain = Join-Path $work 'backup.tar.gz'
$dump = Join-Path $stage 'database.sql'
$documents = Join-Path $stage 'private-documents'
$archive = Join-Path $resolvedRoot "ifc-$stamp-$id.tar.gz.age"
$manifestPath = "$archive.manifest.json"
New-Item -ItemType Directory -Path $work | Out-Null
New-Item -ItemType Directory -Path $documents | Out-Null
$oldMysqlPwd = $env:MYSQL_PWD
try {
    $env:MYSQL_PWD = $env:DB_PASSWORD
    & mysqldump --single-transaction --quick --routines --triggers --host=$env:DB_HOST --port=$env:DB_PORT --user=$env:DB_USERNAME $env:DB_DATABASE --result-file=$dump
    if ($LASTEXITCODE -ne 0) { throw 'mysqldump failed.' }
    $sourceDocuments = @(Get-ChildItem -LiteralPath $PrivateDocuments -Force)
    if ($sourceDocuments.Count -gt 0) {
        Copy-Item -Path (Join-Path ([IO.Path]::GetFullPath($PrivateDocuments)) '*') -Destination $documents -Recurse -Force
    }
    tar -czf $plain -C $stage .
    if ($LASTEXITCODE -ne 0) { throw 'Archive creation failed.' }
    & age --recipient $env:IFC_BACKUP_AGE_RECIPIENT --output $archive $plain
    if ($LASTEXITCODE -ne 0) { throw 'Backup encryption failed.' }
    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant()
    $fileCount = (Get-ChildItem -LiteralPath $PrivateDocuments -File -Recurse | Measure-Object).Count
    $expires = if ($RetentionDays -gt 0) { [DateTime]::UtcNow.AddDays($RetentionDays).ToString('o') } else { $null }
    @{ backup_tool = 'ifc-phase14'; created_at_utc = [DateTime]::UtcNow.ToString('o'); retention_days = $RetentionDays; expires_at_utc = $expires; database = $env:DB_DATABASE; encrypted_archive = [IO.Path]::GetFileName($archive); sha256 = $hash; private_document_file_count = $fileCount; encryption = 'age'; dump_mode = 'single-transaction' } |
        ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding utf8
    Write-Output "Encrypted backup created: $archive"
    Write-Output "SHA-256: $hash"
    if ($ApplyRetention -and $RetentionDays -gt 0) {
        $cutoff = [DateTime]::UtcNow
        foreach ($oldManifest in Get-ChildItem -LiteralPath $resolvedRoot -Filter 'ifc-*.tar.gz.age.manifest.json' -File) {
            try { $old = Get-Content -LiteralPath $oldManifest.FullName -Raw | ConvertFrom-Json } catch { continue }
            if ($old.backup_tool -ne 'ifc-phase14' -or -not $old.expires_at_utc) { continue }
            if ([DateTime]::Parse($old.expires_at_utc).ToUniversalTime() -gt $cutoff) { continue }
            $oldArchive = Join-Path $resolvedRoot ([string]$old.encrypted_archive)
            if ((Split-Path -Leaf $oldArchive) -ne $old.encrypted_archive -or -not (Test-Path -LiteralPath $oldArchive -PathType Leaf)) { continue }
            Remove-Item -LiteralPath $oldArchive -Force
            Remove-Item -LiteralPath $oldManifest.FullName -Force
        }
    }
}
finally {
    if ($null -eq $oldMysqlPwd) { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue } else { $env:MYSQL_PWD = $oldMysqlPwd }
    Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
}
