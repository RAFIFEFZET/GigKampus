[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string] $Archive,
    [Parameter(Mandatory)]
    [ValidatePattern('^[a-z0-9][a-z0-9_-]*$')]
    [string] $ProjectName,
    [string[]] $ComposeFile = @()
)

$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
if ($ComposeFile.Count -eq 0) { $ComposeFile = @(Join-Path $repo 'compose.yaml') }
$compose = @('compose', '--project-directory', $repo, '--project-name', $ProjectName)
foreach ($file in $ComposeFile) {
    $compose += @('-f', (Resolve-Path -LiteralPath $file).Path)
}

function Invoke-DemoDocker {
    & docker @compose @args
    if ($LASTEXITCODE -ne 0) { throw "Docker Compose gagal ($LASTEXITCODE) pada perintah $($args[0])." }
}

$archivePath = (Resolve-Path -LiteralPath $Archive).Path
$payload = @('database.dump', 'private.tar.gz', 'public.tar.gz')
$expectedEntries = @('manifest.json') + $payload
$entries = @(& tar.exe -tf $archivePath)
if ($LASTEXITCODE -ne 0 -or $entries.Count -ne $expectedEntries.Count -or
    (Compare-Object -ReferenceObject $expectedEntries -DifferenceObject $entries -CaseSensitive)) {
    throw 'Arsip backup tidak dapat dibaca atau isinya tidak sesuai.'
}

$tempRoot = (Resolve-Path -LiteralPath ([IO.Path]::GetTempPath())).Path
$id = [Guid]::NewGuid().ToString('N')
$staging = Join-Path $tempRoot "gigkampus-restore-$id"
$containerDump = "/tmp/gigkampus-$id.dump"
$hasContainerDump = $false

try {
    New-Item -ItemType Directory -Path $staging | Out-Null
    & tar.exe -xf $archivePath -C $staging
    if ($LASTEXITCODE -ne 0) { throw 'Gagal mengekstrak arsip backup.' }
    $manifest = Get-Content -LiteralPath (Join-Path $staging 'manifest.json') -Raw | ConvertFrom-Json
    if ($manifest.format -ne 1) { throw 'Versi arsip backup tidak didukung.' }
    foreach ($name in $payload) {
        $actualHash = (Get-FileHash -LiteralPath (Join-Path $staging $name)).Hash
        if ($manifest.sha256.$name -ne $actualHash) { throw "Checksum $name tidak cocok." }
    }

    $config = Invoke-DemoDocker config --format json | Out-String | ConvertFrom-Json
    $postgres = $config.services.postgres.environment
    $running = @(Invoke-DemoDocker ps --status running --services)
    if (@($running | Where-Object { $_ -in @('web', 'app', 'queue-ocr', 'queue-default', 'reverb') }).Count -gt 0) {
        throw "Proyek '$ProjectName' masih menjalankan aplikasi atau worker. Restore memerlukan target baru dengan hanya PostgreSQL berjalan."
    }

    Invoke-DemoDocker up -d --wait postgres | Out-Host
    $database = @("--username=$($postgres.POSTGRES_USER)", "--dbname=$($postgres.POSTGRES_DB)")
    $countSql = @'
SELECT (
    SELECT count(*) FROM pg_class WHERE relnamespace = 'public'::regnamespace
) + (
    SELECT count(*) FROM pg_proc WHERE pronamespace = 'public'::regnamespace
) + (
    SELECT count(*) FROM pg_namespace WHERE nspname NOT LIKE 'pg_%'
    AND nspname NOT IN ('public', 'information_schema')
);
'@
    $objectCount = Invoke-DemoDocker exec -T postgres psql -X -tA --set=ON_ERROR_STOP=1 @database "--command=$countSql"
    if ($objectCount -ne '0') { throw "Database target '$($postgres.POSTGRES_DB)' tidak kosong. Gunakan proyek Compose baru." }

    $restoreStorage = @'
set -eu
private=/var/www/html/storage/app/private
public=/var/www/html/storage/app/public
for disk in "$private" "$public"; do
    if [ -d "$disk" ] && [ -n "$(find "$disk" -mindepth 1 -maxdepth 1 ! \( -name .gitignore -type f \) -print -quit)" ]; then
        echo "Storage target tidak kosong: $disk" >&2
        exit 1
    fi
done
tar -tzf /backup/private.tar.gz >/dev/null
tar -tzf /backup/public.tar.gz >/dev/null
if [ "$1" = restore ]; then
    install -d -o www-data -g www-data -m 750 "$private"
    install -d -o www-data -g www-data -m 755 "$public"
    tar -xzf /backup/private.tar.gz --numeric-owner --same-owner --same-permissions -C "$private"
    tar -xzf /backup/public.tar.gz --numeric-owner --same-owner --same-permissions -C "$public"
fi
'@
    [IO.File]::WriteAllText((Join-Path $staging 'restore.sh'), $restoreStorage.Replace([string][char]13, ''))
    $storageCommand = @('run', '--rm', '--no-deps', '-T', '--user', '0', '--volume',
        "$($staging):/backup:ro", '--entrypoint', 'sh', 'app', '/backup/restore.sh')
    Invoke-DemoDocker @storageCommand check | Out-Host

    $hasContainerDump = $true
    Invoke-DemoDocker cp (Join-Path $staging 'database.dump') "postgres:$containerDump" | Out-Host
    Invoke-DemoDocker exec -T postgres pg_restore --list $containerDump | Out-Null
    Invoke-DemoDocker @storageCommand restore | Out-Host
    Invoke-DemoDocker exec -T postgres pg_restore --single-transaction --exit-on-error --no-owner --no-acl @database $containerDump

    Write-Output "Backup $($manifest.created_utc) dipulihkan ke proyek '$ProjectName'."
} finally {
    if ($hasContainerDump) {
        try { Invoke-DemoDocker exec -T postgres rm -f $containerDump }
        catch { Write-Warning $_ }
    }
    if ((Split-Path -Parent $staging) -eq $tempRoot -and (Test-Path -LiteralPath $staging)) {
        Remove-Item -LiteralPath $staging -Recurse -Force
    }
}
