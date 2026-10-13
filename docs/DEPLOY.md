# Despliegue y operación

**Arranque:** `cp .env.example .env && docker compose up -d --build --wait` (`make up`) → http://localhost:8080. Orden: `db` → `migrate` (one-shot: migraciones, rol de la app, semilla demo) → `api` (PHP-FPM) y `scheduler` → `web` (Nginx: SPA + FastCGI de `/api`, `/health`, `/ready`). Migrar en un servicio aparte evita que varias réplicas compitan y que la API arranque con el esquema a medias. Desarrollo: `make dev` (S-99). Imágenes sin root (`www-data`, `nginx`), `cap_drop: ALL`, sin `tests/` ni dependencias dev, `APP_DEBUG=false` fijo.

**Secretos** (gestor de secretos, nunca el repo): `APP_KEY`, `PATIENT_HASH_KEY`, `POSTGRES_PASSWORD`, `DB_APP_PASSWORD`, `LLM_API_KEY`. Generar: `docker run --rm php:8.4-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'`. Demo: `ALLOW_GENERATED_KEYS=true` genera las llaves una vez en el volumen `app_keys`; producción: `false` y `SEED_DEMO_DATA=false`. Cambiar una llave deja ilegibles los documentos cifrados y los hashes de búsqueda.

**BD: dueño ≠ aplicación.** `migrate` usa el dueño; `api`/`scheduler` usan `fartmar_app` (`db:provision-app-role`): sin `TRUNCATE` ni DDL, y kardex y bitácoras solo `SELECT`/`INSERT`. Los triggers de inmutabilidad no frenan `TRUNCATE`; los permisos sí. En producción el dueño tampoco debe ser superusuario.

## Estrategia (`.github/workflows/ci.yml`)
Lint → pruebas (Pest con PostgreSQL 16 y concurrencia, Vitest, `assistant:eval` sobre BD recién sembrada) → **imágenes inmutables por SHA** (GHCR en `main`: `sha` + `latest`) → **staging** (mismas imágenes en el runner: smoke test, no-root, `TRUNCATE` denegado, respaldo + restauración) → **producción con aprobación manual**: *Settings → Environments → production → Required reviewers*. En repos privados de cuentas gratuitas no existe esa regla: usar repo público o `workflow_dispatch` en `main` con `confirm_production=DESPLEGAR`. Los despliegues son simulados (`scripts/deploy.sh`, `DRY_RUN=1`): respaldo → pull del SHA → `migrate` → `up --wait` → smoke test. Un solo host = recreación con segundos de corte; en un orquestador, *rolling update* (o blue/green) con `/ready` como readiness y `/health` como liveness.

## Migraciones y rollback
**Expand/contract:** cada versión solo agrega (columnas nulas o con default, tablas, índices `CONCURRENTLY`) y el código nuevo convive con el esquema viejo; borrar o renombrar va después (*contract*), cuando ninguna versión desplegada lo usa. **Rollback** = desplegar el SHA anterior (`DRY_RUN=0 scripts/deploy.sh production <api:sha> <web:sha>`), nunca `migrate:rollback`; si el daño es de datos, restaurar el respaldo previo. Interruptores por configuración (p. ej. `LLM_PROVIDER=mock` apaga el modelo externo sin redeploy).

## Respaldo y restauración
- `scripts/backup.sh` (`.\make.ps1 backup`): `pg_dump -Fc` en el contenedor, control con `pg_restore --list`, `.sha256`, retención `RETENTION_DAYS=14`. Cron cada 6 h (`0 */6 * * * cd /srv/fartmar && scripts/backup.sh /backups`) y copia fuera del host (bucket cifrado y versionado).
- **Prueba de restauración** (semanal y en CI): `scripts/restore.sh <archivo>` (`.\make.ps1 restore <archivo>`) restaura en una BD temporal, verifica migraciones, conteos y que cada existencia cuadre con su kardex, y la borra.
- **Restauración real:** `scripts/restore.sh <archivo> --into-live` (pide el nombre de la BD): detiene la app, reemplaza la BD y levanta el stack (`migrate` reaplica permisos).
- **RPO ≤ 6 h** con dumps (≤ 5 min con archivado de WAL/PITR, p. ej. pgBackRest); **RTO ≤ 1 h**.

## HTTPS, HSTS y red
La demo publica HTTP en 8080. En producción TLS termina en Nginx o en un balanceador: activar `Strict-Transport-Security` (comentado en `docker/nginx/fartmar/security-headers.conf`, empezar con `max-age` corto) y, con balanceador, `set_real_ip_from` (comentado en `docker/nginx/default.conf`). Laravel no confía en `X-Forwarded-For` (`TRUSTED_PROXIES` vacío, S-100). Postgres solo en `127.0.0.1`.

## Observabilidad
`/health` (sin BD) y `/ready` (BD + migraciones), sin autenticación. Logs JSON: Laravel (`correlation_id`, PII redactada) y Nginx (sin query string ni cuerpos), cruzables por `request_id` = `client_correlation_id`. Rotación `json-file` 10 MB × 3.
