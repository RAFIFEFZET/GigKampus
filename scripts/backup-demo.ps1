[CmdletBinding()]
param(
    [ValidatePattern('^[a-z0-9][a-z0-9_-]*$')]
    [string] $ProjectName = 'gigkampus',
    [string[]] $ComposeFile = @(),
    [string] $OutputDirectory = (Join-Path $env:LOCALAPPDATA 'GigKampus\backups')
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

$config = Invoke-DemoDocker config --format json | Out-String | ConvertFrom-Json
$postgres = $config.services.postgres.environment
$running = @(Invoke-DemoDocker ps --status running --services)
if ($running -notcontains 'postgres') { throw "PostgreSQL proyek '$ProjectName' harus berjalan." }
$writers = @($running | Where-Object { $_ -in @('web', 'app', 'queue-ocr', 'queue-default', 'reverb') })

New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
$outputPath = (Resolve-Path -LiteralPath $OutputDirectory).Path
$id = [Guid]::NewGuid().ToString('N')
$stamp = [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')
$archive = Join-Path $outputPath "$ProjectName-$stamp-$($id.Substring(0, 8)).zip"
$partialArchive = "$archive.partial.zip"
$tempRoot = (Resolve-Path -LiteralPath ([IO.Path]::GetTempPath())).Path
$staging = Join-Path $tempRoot "gigkampus-backup-$id"
$containerDump = "/tmp/gigkampus-$id.dump"
$payload = @('database.dump', 'private.tar.gz', 'public.tar.gz')
$stoppedWriters = $false
$hasContainerDump = $false

try {
    New-Item -ItemType Directory -Path $staging | Out-Null
    $packStorage = @'
set -eu
tar -czf /backup/private.tar.gz -C /var/www/html/storage/app/private .
tar -czf /backup/public.tar.gz -C /var/www/html/storage/app/public .
tar -tzf /backup/private.tar.gz >/dev/null
tar -tzf /backup/public.tar.gz >/dev/null
'@
    [IO.File]::WriteAllText((Join-Path $staging 'pack.sh'), $packStorage.Replace([string][char]13, ''))

    if ($writers.Count -gt 0) {
        $stoppedWriters = $true
        Invoke-DemoDocker stop @writers | Out-Host
    }
    $hasContainerDump = $true
    $database = @("--username=$($postgres.POSTGRES_USER)", "--dbname=$($postgres.POSTGRES_DB)")
    Invoke-DemoDocker exec -T postgres pg_dump --format=custom --no-owner --no-acl @database "--file=$containerDump"
    Invoke-DemoDocker exec -T postgres pg_restore --list $containerDump | Out-Null
    Invoke-DemoDocker cp "postgres:$containerDump" (Join-Path $staging 'database.dump') | Out-Host
    Invoke-DemoDocker run --rm --no-deps -T --user 0 --volume "$($staging):/backup" --entrypoint sh app /backup/pack.sh | Out-Host

    $manifest = [ordered] @{
        format = 1
        project = $ProjectName
        database = $postgres.POSTGRES_DB
        created_utc = [DateTime]::UtcNow.ToString('o')
        sha256 = [ordered] @{}
    }
    foreach ($name in $payload) {
        $manifest.sha256[$name] = (Get-FileHash -LiteralPath (Join-Path $staging $name)).Hash
    }
    [IO.File]::WriteAllText((Join-Path $staging 'manifest.json'), ($manifest | ConvertTo-Json))

    & tar.exe -a -cf $partialArchive -C $staging 'manifest.json' @payload
    if ($LASTEXITCODE -ne 0) { throw 'Gagal membuat arsip ZIP backup.' }
    Move-Item -LiteralPath $partialArchive -Destination $archive
} finally {
    if ($hasContainerDump) {
        try { Invoke-DemoDocker exec -T postgres rm -f $containerDump }
        catch { Write-Warning $_ }
    }
    if ($stoppedWriters) {
        try { Invoke-DemoDocker start @writers | Out-Host }
        catch { Write-Warning "Gagal menjalankan ulang layanan proyek '$ProjectName'. $_" }
    }
    if ((Split-Path -Parent $staging) -eq $tempRoot -and (Test-Path -LiteralPath $staging)) {
        Remove-Item -LiteralPath $staging -Recurse -Force
    }
    if (Test-Path -LiteralPath $partialArchive) { Remove-Item -LiteralPath $partialArchive -Force }
}

Get-Item -LiteralPath $archive
