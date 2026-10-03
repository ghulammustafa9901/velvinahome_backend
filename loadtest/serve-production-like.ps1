# Local only: serve the API the way production runs it — config, routes and events cached,
# opcache on without timestamp checks, debug off — on its own port, without touching the
# development server or bootstrap/cache (the caches go to a temporary folder).
#
#   . $env:USERPROFILE\devtools\env.ps1
#   .\loadtest\serve-production-like.ps1            # http://127.0.0.1:8010, Ctrl+C to stop
#
# Code changes need a restart (opcache does not re-check files).
param(
    [int] $Port = 8010,
    [string] $CacheDir = (Join-Path $env:TEMP 'platform-loadtest-cache')
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
New-Item -ItemType Directory -Force $CacheDir | Out-Null

$env:APP_DEBUG = 'false'
$env:LOG_LEVEL = 'warning'
$env:APP_CONFIG_CACHE = Join-Path $CacheDir 'config.php'
$env:APP_ROUTES_CACHE = Join-Path $CacheDir 'routes.php'
$env:APP_EVENTS_CACHE = Join-Path $CacheDir 'events.php'

Push-Location $root
try {
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache

    # Like `artisan serve`: run from public/, with Laravel's router script.
    Set-Location (Join-Path $root 'public')
    Write-Host "Serving production-like API on http://127.0.0.1:$Port (caches in $CacheDir)"
    php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d opcache.memory_consumption=256 `
        -d opcache.max_accelerated_files=50000 -d memory_limit=512M `
        -S "127.0.0.1:$Port" (Join-Path $root 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')
}
finally {
    Pop-Location
}
