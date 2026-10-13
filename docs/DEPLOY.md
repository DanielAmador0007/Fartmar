# Despliegue y operación

**Arranque:** `cp .env.example .env && docker compose up -d --build --wait` (`make up`) → http://localhost:8080. Orden: `db` → `migrate` (one-shot: migraciones, rol de la app, semilla demo) → `api` (PHP-FPM) y `scheduler` → `web` (Nginx: SPA + `/api`). Imágenes sin root, `APP_DEBUG=false` fijo.

**Secretos** (gestor de secretos, nunca el repo): `APP_KEY`, `PATIENT_HASH_KEY`, `POSTGRES_PASSWORD`, `DB_APP_PASSWORD`, `LLM_API_KEY` (solo `api`). Generar: `openssl rand -base64 32` (prefijo `base64:`). Producción: `ALLOW_GENERATED_KEYS=false` y `SEED_DEMO_DATA=false` (`deploy.sh production` aborta si no). **Respalde `APP_KEY` y `PATIENT_HASH_KEY` aparte de la BD**: sin ellas un respaldo no permite leer ni buscar documentos de pacientes.

**BD:** `migrate` usa el dueño; `api`/`scheduler`, el rol `fartmar_app` sin DDL ni `TRUNCATE` (S-101); con PostgreSQL gestionado el DBA crea el rol y se usa `DB_APP_ROLE_MANAGED_EXTERNALLY=true` (S-112).

## Estrategia (`.github/workflows/ci.yml`)
Lint + auditorías → pruebas (Pest con PostgreSQL 16 y concurrencia, Vitest, `assistant:eval`) → **imágenes inmutables** + Trivy → **staging** en el runner (smoke, no-root, `TRUNCATE` denegado, respaldo + restauración) → **publicación en GHCR** (`sha` + `latest`, solo `main`) → **producción** con aprobación manual. La aprobación exige el environment `production` en GitHub: [pasos en el README](../README.md#cicd-configurar-la-aprobación-de-producción). Los despliegues son simulados (`scripts/deploy.sh`, `DRY_RUN=1`): respaldo → pull del SHA → `migrate` → `up --wait` → smoke. Un solo host = segundos de corte; en un orquestador, *rolling update* o blue/green con `/ready` (readiness) y `/health` (liveness).

## Migraciones y rollback
**Expand/contract:** cada versión solo agrega (columnas nulas o con default, tablas, índices `CONCURRENTLY`) y convive con el esquema anterior; borrar o renombrar va en una versión posterior. **Rollback** = desplegar el SHA anterior (`DRY_RUN=0 scripts/deploy.sh production <api:sha> <web:sha>`), nunca `migrate:rollback`; si el daño es de datos, restaurar el respaldo previo. Interruptores por configuración (p. ej. `LLM_PROVIDER=mock`).

## Respaldo y restauración
- `scripts/backup.sh` (`make backup`): `pg_dump -Fc`, control con `pg_restore --list`, `.sha256`, `umask 077`, retención 14 días. Cron cada 6 h y copia cifrada fuera del host.
- **Prueba de restauración** (semanal y en CI): `scripts/restore.sh <archivo>`: BD temporal, verifica migraciones, conteos y kardex = existencias, y la borra.
- **Restauración real:** `scripts/restore.sh <archivo> --into-live`: detiene la app, reemplaza la BD y levanta el stack.
- **RPO ≤ 6 h** con dumps (≤ 5 min con WAL/PITR); **RTO ≤ 1 h**.

## Red y observabilidad
HTTP en 8080 para la demo; en producción TLS en Nginx o en el balanceador, con HSTS y la IP real resuelta en Nginx (S-100). `/health` (sin BD) y `/ready` (BD + migraciones). Logs JSON de Laravel (`correlation_id`, PII redactada) y de Nginx (sin query string, cuerpos ni `Authorization`).
