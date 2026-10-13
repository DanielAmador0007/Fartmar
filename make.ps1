# FARTMAR - equivalente del Makefile para Windows PowerShell (sin make).
# Uso: .\make.ps1 <objetivo> [argumento]     p.ej.  .\make.ps1 up   |   .\make.ps1 test
#      .\make.ps1 restore backups\fartmar-XXXX.dump
# Si la politica de ejecucion lo bloquea:
#   powershell -ExecutionPolicy Bypass -File .\make.ps1 up
param(
    [Parameter(Position = 0)]
    [string]$Target = 'help',
    [Parameter(Position = 1)]
    [string]$Argument = ''
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$DevFiles = @('-f', 'docker-compose.yml', '-f', 'docker-compose.dev.yml')
$DevImage = 'fartmar-api-dev:local'
$Frontend = Join-Path $PSScriptRoot 'frontend'

function Invoke-Step {
    param([string]$Command, [string[]]$Arguments, [string]$WorkDir = $PSScriptRoot)
    Write-Host "> $Command $($Arguments -join ' ')" -ForegroundColor Cyan
    Push-Location $WorkDir
    try {
        & $Command @Arguments
        if ($LASTEXITCODE -ne 0) { throw "Fallo: $Command $($Arguments -join ' ') (codigo $LASTEXITCODE)" }
    }
    finally { Pop-Location }
}

function Invoke-Compose { param([string[]]$Arguments) Invoke-Step 'docker' (@('compose') + $Arguments) }
function Invoke-ComposeDev { param([string[]]$Arguments) Invoke-Step 'docker' (@('compose') + $DevFiles + $Arguments) }
function Invoke-Api { param([string[]]$Arguments) Invoke-ComposeDev (@('exec', '-T', 'api') + $Arguments) }

function Ensure-Env {
    if (-not (Test-Path '.env')) { Copy-Item '.env.example' '.env'; Write-Host 'Creado .env desde .env.example' }
}

# Si la API que corre no es la de desarrollo (p. ej. tras "up"), levanta el
# entorno de desarrollo antes de ejecutar Pest/Larastan/eval.
function Ensure-Dev {
    Ensure-Env
    $id = docker compose ps -q api 2>$null
    $image = if ($id) { docker inspect -f '{{.Config.Image}}' $id 2>$null } else { '' }
    if ($image -ne $DevImage) {
        Write-Host 'La API en ejecucion no es la de desarrollo: levantando "dev"...' -ForegroundColor Yellow
        Invoke-ComposeDev @('up', '-d', '--build', '--wait')
    }
}

function Invoke-Backup {
    $outDir = Join-Path $PSScriptRoot 'backups'
    New-Item -ItemType Directory -Force -Path $outDir | Out-Null
    $stamp = (Get-Date).ToUniversalTime().ToString("yyyyMMdd'T'HHmmss'Z'")
    $file = Join-Path $outDir "fartmar-$stamp.dump"
    $remote = '/tmp/fartmar-backup.dump'
    # pg_dump dentro del contenedor y copia con "docker compose cp": PowerShell
    # 5.1 corrompe binarios redirigidos con ">". Los comandos de sh van sin
    # comillas dobles internas (PowerShell 5.1 no las escapa al pasarlas a
    # programas nativos); las variables de Postgres no tienen espacios.
    Invoke-Compose @('exec', '-T', 'db', 'sh', '-c', ('pg_dump -U $POSTGRES_USER -d $POSTGRES_DB -Fc -Z 6 -f ' + $remote + ' && pg_restore --list ' + $remote + ' > /dev/null'))
    Invoke-Compose @('cp', "db:$remote", $file)
    Invoke-Compose @('exec', '-T', 'db', 'rm', '-f', $remote)
    $hash = (Get-FileHash -Algorithm SHA256 $file).Hash.ToLower()
    Set-Content -Path "$file.sha256" -Value "$hash  $(Split-Path $file -Leaf)" -Encoding Ascii -NoNewline
    $days = if ($env:RETENTION_DAYS) { [int]$env:RETENTION_DAYS } else { 14 }
    Get-ChildItem $outDir -Filter 'fartmar-*.dump*' | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$days) } | Remove-Item
    Write-Host "Respaldo: $file (+ .sha256); retencion $days dias" -ForegroundColor Green
}

# Prueba de restauracion en una BD temporal (no toca la BD real). La
# restauracion real (--into-live) esta en scripts/restore.sh (bash).
function Invoke-RestoreCheck {
    param([string]$File)
    if (-not $File -or -not (Test-Path $File)) { throw 'Uso: .\make.ps1 restore backups\fartmar-XXXX.dump' }
    if (Test-Path "$File.sha256") {
        $expected = ((Get-Content "$File.sha256" -Raw).Trim() -split '\s+')[0]
        $actual = (Get-FileHash -Algorithm SHA256 $File).Hash.ToLower()
        if ($expected -ne $actual) { throw 'El checksum no coincide: archivo danado' }
        Write-Host 'checksum OK'
    }
    $remote = '/tmp/fartmar-restore.dump'
    $target = 'fartmar_restore_check_' + (Get-Date).ToUniversalTime().ToString('yyyyMMddHHmmss')
    Invoke-Compose @('cp', $File, "db:$remote")
    $psql = 'psql -U $POSTGRES_USER -d $TARGET -v ON_ERROR_STOP=1 -At'
    try {
        Invoke-Compose @('exec', '-T', '-e', "TARGET=$target", 'db', 'sh', '-c',
            ('createdb -U $POSTGRES_USER $TARGET && pg_restore -U $POSTGRES_USER -d $TARGET --no-owner --no-acl --exit-on-error ' + $remote))
        # El SQL entra por stdin: sin problemas de comillas.
        $counts = "select 'migraciones = ' || count(*) from migrations union all select 'usuarios = ' || count(*) from users union all select 'movimientos de kardex = ' || count(*) from kardex_movements;"
        $counts | docker compose exec -T -e "TARGET=$target" db sh -c $psql
        $sql = 'select count(*) from stocks s where s.quantity <> coalesce((select sum(k.direction * k.quantity) from kardex_movements k where k.warehouse_id = s.warehouse_id and k.lot_id = s.lot_id), 0);'
        $bad = $sql | docker compose exec -T -e "TARGET=$target" db sh -c $psql
        if ($LASTEXITCODE -ne 0 -or "$bad".Trim() -ne '0') { throw "Verificacion fallida: existencias que no cuadran con el kardex = $bad" }
        Write-Host 'Verificacion OK: el kardex cuadra con las existencias' -ForegroundColor Green
    }
    finally {
        docker compose exec -T -e "TARGET=$target" db sh -c ('dropdb -U $POSTGRES_USER --if-exists $TARGET; rm -f ' + $remote) | Out-Null
        Write-Host "BD temporal $target borrada"
    }
}

switch ($Target) {
    'help' {
        @'
Objetivos:
  up                Stack tipo produccion (http://localhost:8080), espera a que este sano
  dev               Entorno de desarrollo (override docker-compose.dev.yml)
  down              Detiene los contenedores (conserva datos)
  reset             Borra los volumenes DE ESTE PROYECTO (fartmar) y levanta desarrollo desde cero
  logs              Sigue los logs de api, web y scheduler
  shell             Shell en el contenedor de la API
  fresh             migrate:fresh --seed en la BD de desarrollo
  test              Pest + Vitest
  test-backend      Pest (BD fartmar_test; incluye el grupo concurrency)
  test-concurrency  Solo pruebas de concurrencia real (procesos en paralelo)
  test-frontend     Vitest
  lint              Pint, Larastan, ESLint y tsc
  fix               Aplica formato de Pint
  eval              Evaluacion del asistente IA (mock) sobre la BD semilla (fresh = datos limpios)
  backup            Respaldo pg_dump -Fc en backups\ (RETENTION_DAYS, por defecto 14)
  restore <archivo> Prueba de restauracion en una BD temporal (no toca la BD real)
  frontend-install  npm ci del frontend
  frontend-dev      Servidor Vite en http://localhost:5173
test, lint, eval y fresh levantan "dev" solos si la API en ejecucion no es la de desarrollo.
'@ | Write-Host
    }
    'up' { Ensure-Env; Invoke-Compose @('up', '-d', '--build', '--wait') }
    'dev' { Ensure-Env; Invoke-ComposeDev @('up', '-d', '--build', '--wait') }
    'down' { Invoke-ComposeDev @('down') }
    'reset' { Invoke-ComposeDev @('down', '-v'); Ensure-Env; Invoke-ComposeDev @('up', '-d', '--build', '--wait') }
    'logs' { Invoke-Compose @('logs', '-f', 'api', 'web', 'scheduler') }
    'shell' { docker compose exec api sh }
    'fresh' { Ensure-Dev; Invoke-Api @('php', 'artisan', 'migrate:fresh', '--seed', '--force') }
    'test-backend' { Ensure-Dev; Invoke-Api @('./vendor/bin/pest') }
    'test-concurrency' { Ensure-Dev; Invoke-Api @('./vendor/bin/pest', '--group=concurrency') }
    'test-frontend' { Invoke-Step 'npm' @('run', 'test:run') $Frontend }
    'test' {
        Ensure-Dev
        Invoke-Api @('./vendor/bin/pest')
        Invoke-Step 'npm' @('run', 'test:run') $Frontend
    }
    'lint' {
        Ensure-Dev
        Invoke-Api @('./vendor/bin/pint', '--test')
        Invoke-Api @('./vendor/bin/phpstan', 'analyse', '--no-progress')
        Invoke-Step 'npm' @('run', 'lint') $Frontend
        Invoke-Step 'npm' @('run', 'typecheck') $Frontend
    }
    'fix' { Ensure-Dev; Invoke-Api @('./vendor/bin/pint') }
    'eval' { Ensure-Dev; Invoke-Api @('php', 'artisan', 'assistant:eval', '--provider=mock') }
    'backup' { Invoke-Backup }
    'restore' { Invoke-RestoreCheck $Argument }
    'frontend-install' { Invoke-Step 'npm' @('ci') $Frontend }
    'frontend-dev' { Invoke-Step 'npm' @('run', 'dev') $Frontend }
    default { Write-Error "Objetivo desconocido: $Target. Use .\make.ps1 help"; exit 1 }
}
