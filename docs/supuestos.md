# Supuestos

Decisiones tomadas ante ambigüedades del enunciado (sección 8: "toma una decisión razonable y regístrala"). Se irán ampliando en cada fase.

## Entorno e infraestructura

| # | Supuesto | Motivo |
|---|---|---|
| S-01 | Base de datos **PostgreSQL 16**. | `SELECT … FOR UPDATE`, CHECK constraints, triggers e índices parciales defienden las reglas de negocio en la BD (RN-03, RN-06, RN-08). |
| S-02 | Las pruebas automáticas corren **contra PostgreSQL** (BD `fartmar_test`), nunca contra SQLite. | Las restricciones y el bloqueo de filas son justamente lo que se prueba; SQLite no los reproduce igual. |
| S-03 | PHP 8.4 y Laravel 13 (última estable al crear el proyecto). | El enunciado pide PHP ≥ 8.2 y la versión estable vigente. |
| S-04 | No se requiere PHP en el host: Composer, Artisan, Pint, Larastan y Pest se ejecutan dentro del contenedor `api`. | Reproducibilidad y un solo prerrequisito (Docker). Node se usa en el host para el frontend en desarrollo. |
| S-05 | En desarrollo, la API usa el servidor embebido de PHP con 4 workers; producción usará php-fpm + Nginx (fase DevOps). | Simplicidad en Fase 0; la imagen de producción es multi-stage y no root. |
| S-06 | Puertos del host: Postgres 55432, API 8080, frontend 5173. | Evitar conflictos con otros servicios locales (5432/5433 suelen estar ocupados). |
| S-07 | `APP_KEY` se genera en el entrypoint si falta, **solo para la demo**. En un entorno real se inyecta como secreto y nunca se regenera (invalidaría datos cifrados, p. ej. documentos de pacientes). | Levantar todo con un solo comando sin commitear secretos. |
| S-08 | La clave de Postgres en `.env.example` (`fartmar_local_demo`) es un valor de demo local, no un secreto. | Permite `cp .env.example .env && docker compose up` sin pasos manuales. |

## Observabilidad

| # | Supuesto | Motivo |
|---|---|---|
| S-09 | `GET /health` = liveness: responde 200 si el proceso PHP atiende; **no consulta la BD**. | Una caída de la BD no debe provocar reinicios en cascada de la API. |
| S-10 | `GET /ready` = readiness: 200 solo si la BD responde **y** no hay migraciones pendientes; si no, 503. | Evita enrutar tráfico a una instancia con esquema desactualizado. |
| S-11 | Ambas sondas son públicas (sin autenticación) y no exponen detalles internos (mensajes de error, hosts, versiones). | Las consumen orquestadores/balanceadores; no deben filtrar información. |
| S-12 | Las rutas de la API de negocio viven bajo `/api/*`; las sondas en la raíz (`/health`, `/ready`). | Convención habitual de orquestadores; el proxy del frontend solo reenvía `/api`. |

## Negocio (provisionales, se confirman en fases siguientes)

| # | Supuesto | Motivo |
|---|---|---|
| S-13 | "Hoy" para vencimientos se calcula en zona `America/Bogota`; las fechas se guardan en UTC. | La IPS opera en Colombia; evita que un lote "venza" a las 7 p. m. hora local. |
| S-14 | Un lote con `expires_at` igual a hoy **sí** se puede dispensar (vencido = `expires_at < hoy`). | Interpretación usual de "fecha de vencimiento" como último día válido. |
| S-15 | Umbral de alerta de vencimiento configurable con `ALERT_EXPIRY_DAYS` (90 por defecto). | RN-11 fija 90 días; se deja parametrizable. |
