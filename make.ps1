# FARTMAR - equivalente del Makefile para Windows PowerShell (sin make).
# Uso: .\make.ps1 <objetivo>     p.ej.  .\make.ps1 up   |   .\make.ps1 test
# Si la politica de ejecucion lo bloquea:
#   powershell -ExecutionPolicy Bypass -File .\make.ps1 up
param(
    [Parameter(Position = 0)]
    [string]$Target = 'help'
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

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

function Invoke-Api { param([string[]]$Arguments) Invoke-Step 'docker' (@('compose', 'exec', '-T', 'api') + $Arguments) }
$Frontend = Join-Path $PSScriptRoot 'frontend'

function Ensure-Env {
    if (-not (Test-Path '.env')) { Copy-Item '.env.example' '.env'; Write-Host 'Creado .env desde .env.example' }
}

switch ($Target) {
    'help' {
        @'
Objetivos:
  up                Construye y levanta db + api (espera a que esten saludables)
  down              Detiene los contenedores (conserva datos)
  reset             Borra los volumenes DE ESTE PROYECTO (fartmar) y levanta desde cero
  logs              Sigue los logs de la API
  shell             Shell en el contenedor de la API
  fresh             migrate:fresh --seed en la BD de desarrollo
  test              Pest + Vitest
  test-backend      Pest (BD fartmar_test; incluye el grupo concurrency)
  test-concurrency  Solo pruebas de concurrencia real (procesos en paralelo)
  test-frontend     Vitest
  lint              Pint, Larastan, ESLint y tsc
  fix               Aplica formato de Pint
  eval              Evaluacion del asistente IA (proveedor mock)
  frontend-install  npm ci del frontend
  frontend-dev      Servidor Vite en http://localhost:5173
'@ | Write-Host
    }
    'up' { Ensure-Env; Invoke-Step 'docker' @('compose', 'up', '-d', '--build', '--wait') }
    'down' { Invoke-Step 'docker' @('compose', 'down') }
    'reset' { Invoke-Step 'docker' @('compose', 'down', '-v'); Ensure-Env; Invoke-Step 'docker' @('compose', 'up', '-d', '--build', '--wait') }
    'logs' { Invoke-Step 'docker' @('compose', 'logs', '-f', 'api') }
    'shell' { docker compose exec api sh }
    'fresh' { Invoke-Api @('php', 'artisan', 'migrate:fresh', '--seed', '--force') }
    'test-backend' { Invoke-Api @('./vendor/bin/pest') }
    'test-concurrency' { Invoke-Api @('./vendor/bin/pest', '--group=concurrency') }
    'test-frontend' { Invoke-Step 'npm' @('run', 'test:run') $Frontend }
    'test' {
        Invoke-Api @('./vendor/bin/pest')
        Invoke-Step 'npm' @('run', 'test:run') $Frontend
    }
    'lint' {
        Invoke-Api @('./vendor/bin/pint', '--test')
        Invoke-Api @('./vendor/bin/phpstan', 'analyse', '--no-progress')
        Invoke-Step 'npm' @('run', 'lint') $Frontend
        Invoke-Step 'npm' @('run', 'typecheck') $Frontend
    }
    'fix' { Invoke-Api @('./vendor/bin/pint') }
    'eval' { Invoke-Api @('php', 'artisan', 'assistant:eval', '--provider=mock') }
    'frontend-install' { Invoke-Step 'npm' @('ci') $Frontend }
    'frontend-dev' { Invoke-Step 'npm' @('run', 'dev') $Frontend }
    default { Write-Error "Objetivo desconocido: $Target. Use .\make.ps1 help"; exit 1 }
}
