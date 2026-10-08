# Registro de uso de IA

Materia prima para `AI_USAGE.md`. Cada entrada sigue el formato de CLAUDE.md §9.

### 2026-10-08 — Fase 0: andamiaje del proyecto (backend, frontend, Docker, CI)
- Agente: devops-docker-ci (Claude Code)
- Qué generó la IA:
  - `backend/`: proyecto Laravel 13.35 (PHP 8.4) creado con Composer dentro de un contenedor; Sanctum (`install:api`), Pest 5, Larastan 3 (nivel 6 sobre `app/`), Pint. Estructura `app/Domain/{Inventory,Dispensing,Transfers,Patients,Assistant}`.
  - `HealthController` con `GET /health` (liveness, no toca BD) y `GET /ready` (BD + migraciones aplicadas, 503 si falla), registrados en `routes/probes.php` fuera del grupo `web`. 5 pruebas Pest.
  - `phpunit.xml` contra PostgreSQL en `fartmar_test` y un guard en `tests/TestCase.php`.
  - `frontend/`: Vite 8 + React 19 + TS (strict), TanStack Query, React Router, Vitest + Testing Library + jsdom, ESLint (flat config).
  - `docker-compose.yml` (proyecto `fartmar`): `db` (postgres:16-alpine) y `api` de desarrollo (`docker/php/Dockerfile` + `entrypoint.dev.sh`), `docker/postgres/init.sql`.
  - `.env.example` raíz y de backend, `.gitignore`, `Makefile`, `make.ps1`, `.github/workflows/ci.yml`.
- Qué decisión tomó y por qué:
  - **Pruebas siempre contra PostgreSQL**, nunca SQLite: CHECK, triggers y `FOR UPDATE` son el núcleo de la evaluación y SQLite no los reproduce igual.
  - **`/health` no consulta la BD**: si la BD cae, la API no debe reiniciarse en cascada; para eso está `/ready`. `/ready` no expone mensajes de error (pueden contener host/usuario); solo registra la clase de la excepción.
  - **Las sondas no pasan por el middleware `web`**: la sesión por defecto usa la BD y haría que la liveness dependiera de ella.
  - **Servidor de desarrollo con `php -S` en vez de `php artisan serve`**: `artisan serve` elimina del proceso hijo las variables de entorno que también existen en `backend/.env`, así que la API ignoraba `DB_PASSWORD` de docker-compose y `/ready` devolvía 503. Se descubrió al verificar.
  - **ESLint en vez de oxlint**: la plantilla actual de Vite trae oxlint, pero el enunciado exige ESLint.
  - **Frontend en el host en Fase 0** (`npm run dev` con proxy `/api` → `localhost:8080`); el contenedor del frontend (nginx no root) se agrega en la fase de DevOps.
  - Se borraron `backend/CLAUDE.md` y `backend/AGENTS.md` que trae el skeleton (Laravel Boost): piden instalar PHP en el host, lo que contradice el entorno del proyecto y confundiría a otros agentes.
- Puntos que Daniel debe revisar/entender:
  - **Bug evitado (importante para la sustentación):** en la primera versión, `make test` ejecutaba `migrate:fresh` sobre la BD de **desarrollo**. Causa: dentro del contenedor `DB_DATABASE=fartmar` está en `$_SERVER`, Laravel lee `$_SERVER` antes que `$_ENV`, y `<env force="true">` de PHPUnit solo escribe `$_ENV`/`putenv`. Corrección: variables forzadas también como `<server>` en `phpunit.xml`, más un guard en `TestCase::createApplication()` que aborta si la BD no termina en `_test` (se ejecuta antes de `RefreshDatabase`). Se verificó con una tabla marcador en la BD de desarrollo.
  - El entrypoint de desarrollo es idempotente: `composer install` si falta `vendor/`, crea `backend/.env`, genera `APP_KEY` solo si falta (solo demo), espera la BD y migra.
  - `docker/postgres/init.sql` solo se ejecuta con el volumen vacío; si ya existía, `make reset` (borra únicamente volúmenes del proyecto `fartmar`).
- Riesgos o cosas que podrían estar mal:
  - El workflow de CI se validó con `actionlint` y simulando sus pasos desde un clon limpio en contenedor, pero **no se ha ejecutado en GitHub** (no hay push). Las versiones de acciones (`checkout@v5`, `setup-node@v5`, `cache@v4`) deben confirmarse en la primera ejecución.
  - `make` no está instalado en el host Windows: el `Makefile` solo se validó con `make -n` en un contenedor; en Windows se usa `make.ps1`, que sí se probó.
  - `make eval` llama a `assistant:eval`, que aún no existe (Fase IA).
  - El primer `docker compose up` desde cero tarda ~2 min por `composer install` sobre el bind mount de Windows.
