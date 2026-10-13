# Estado del proyecto y traspaso entre sesiones

> Documento vivo. Léelo al iniciar una sesión nueva de Claude Code (junto con `CLAUDE.md`).
> Última actualización: 2026-10-13, al cerrar la Fase 7.
>
> Flujo de ramas: cada fase en su rama `feat/fase-N-<tema>` → push → PR a `main` → merge. Empezar la
> siguiente fase desde `main` actualizado (`git checkout main; git pull`).

## Cómo retomar en un chat nuevo

Pega esto al iniciar:

```
Eres el líder técnico de mi prueba técnica FARTMAR. Lee CLAUDE.md, PROMPT_ORQUESTADOR.md
y docs/PROGRESO.md (estado actual y pendientes). Continúa con la siguiente fase indicada
en PROGRESO.md delegando en los subagentes de .claude/agents. Al cerrar la fase: tests y
linters en verde (muestra salida), ai-log, commits pequeños, actualiza docs/PROGRESO.md y haz push.
```

## Estado por fase

| Fase | Estado | Notas |
|---|---|---|
| 0 Andamiaje | ✅ Hecha | Laravel 13.35 / PHP 8.4, Vite 8 + React 19, compose `fartmar`, CI, `/health` `/ready` |
| 1 Modelo de datos | ✅ Hecha | 10 migraciones, 19 modelos, enums, seeders, 80 tests (214 aserciones) |
| 2 Dispensación (FEFO, concurrencia, idempotencia) | ✅ Hecha (PR #1, mergeado) | FefoAllocator, StockService, KardexService, DispenseService, API `/api/v1`; 253 tests (871 aserciones) con 9 de concurrencia real; revisor-senior sin Críticos/Altos, Medios corregidos |
| 3 Traslados, ajustes, alertas | ✅ Hecha (PR #2, mergeado) | TransferStateMachine, TransferService, AdjustmentService, AlertService, KardexQuery, stock en tránsito; 540 tests (2350 aserciones) con 20 de concurrencia real; revisor-senior sin Críticos, Altos y Medios corregidos; supuestos S-48…S-67 |
| 4 Seguridad y privacidad | ✅ Hecha (mergeada con el PR #3) | Logs JSON redactados, correlation id, pacientes con enmascarado + bitácora, prescripciones, admin de usuarios con clave temporal, login sin enumeración; 717 tests (3160 aserciones); revisor-senior sin Críticos, Altos y Medios corregidos; supuestos S-68…S-79 |
| 5 Frontend | ✅ Hecha (PR #3, mergeado) | Login + cambio de clave, navegación por rol, dispensación con vista previa FEFO e Idempotency-Key, bandeja de autorizaciones, prescripción del médico, traslados por `allowed_actions`, inventario/alertas/ajustes, kardex, usuarios; endpoints `GET /warehouses`, `/products`, `/dispensations` y `allowed_actions` en dispensaciones; 104 tests Vitest, 758 Pest (3320 aserciones); revisor-senior sin Críticos, Altos y Medios corregidos; supuestos S-80…S-88 |
| 6 Asistente IA | ✅ Hecha (PR `feat/fase-6-asistente-ia`) | `LlmProvider` mock/ollama/anthropic por `LLM_PROVIDER`, 7 herramientas de solo lectura con Policies, filtros de entrada/salida, notas de traslados nunca enviadas al modelo, `POST /assistant/query`, `assistant:eval` 18/18 con mock, pantalla del asistente; 917 Pest (3875 aserciones), 121 Vitest; revisor-senior sin Críticos, Alto y Medios corregidos; supuestos S-89…S-98; OpenAPI 0.6.1 |
| 7 DevOps completo | ✅ Hecha (rama `feat/fase-7-devops`, sin push) | Imágenes multi-stage sin root (PHP-FPM + Nginx con la SPA), compose tipo producción con `migrate` one-shot y `scheduler`, override de desarrollo, rol de BD sin `TRUNCATE`, `TRUSTED_PROXIES`, headers de seguridad, logs JSON de Nginx, respaldo/restauración probados, CI completo (actionlint limpio), `docs/DEPLOY.md`; 927 Pest (3922 aserciones), 121 Vitest, eval 18/18; supuestos S-99…S-110. Sin revisión del revisor-senior todavía |
| 8 Documentación y cierre | ⏭️ **Siguiente** | Ver "Lo que las fases siguientes deben saber de la Fase 7" |

## Entorno (importante para cualquier agente)

- Windows 11, PowerShell 5.1. **No hay PHP ni Composer en el host**: todo lo de PHP corre en el contenedor
  (`docker compose exec -T api php artisan …`, `./vendor/bin/pest`, `pint --test`, `phpstan analyse`).
- Atajos: `.\make.ps1 up | dev | test | lint | fresh | reset | eval | backup | restore <archivo>` (Windows) o `make …`.
  Desde la Fase 7: `up` = stack tipo producción (lo del evaluador); `dev` = desarrollo (`docker-compose.dev.yml`);
  `test`, `lint`, `eval` y `fresh` levantan `dev` solos si la API en ejecución no es la de desarrollo; `reset` borra
  los volúmenes del proyecto y levanta `dev`.
- En PowerShell 5.1 no uses `2>&1` con comandos nativos (convierte stderr en error); usa `2>$null`.
- Proyecto compose `fartmar`. Puertos host: Nginx (SPA + API) **8080**, Postgres **127.0.0.1:55432**, Vite **5173**.
  La API (PHP-FPM) ya no publica puerto; el proxy de Vite apunta a Nginx en 8080.
- **No tocar contenedores Docker ajenos** del usuario (`trigra-dev-*`, `sonarqube-biotrust`, `cea-postgres`,
  `busy_moore`). Prohibido `prune`, `docker rm/rmi/stop/kill` de cosas ajenas (bloqueado en permisos).
- Las pruebas usan la BD `fartmar_test`; `tests/TestCase.php` aborta si la BD no termina en `_test`.
- Usuarios demo (contraseña `password`): ver `docs/modelo-datos.md` (incluye `regente2@fartmar.test` para RN-05/RN-08).
- Repo: https://github.com/DanielAmador0007/Fartmar (rama `main`).

## Bloqueos externos

- **GitHub Actions no corre**: la cuenta tiene un bloqueo de facturación ("account is locked due to a billing
  issue"). Daniel debe resolverlo en GitHub → Settings → Billing. Mientras tanto, validar en local.

## Lo que las fases siguientes deben saber de la Fase 7

- Operación en `docs/DEPLOY.md` (≤ 1 página) y decisiones en S-99…S-110. Archivos: `backend/Dockerfile`,
  `frontend/Dockerfile`, `docker/php/*`, `docker/nginx/*`, `docker-compose.yml`, `docker-compose.dev.yml`, `scripts/*`.
- **Para el README (Fase 8):** comando único `cp .env.example .env && docker compose up -d --build --wait` →
  http://localhost:8080 con usuarios demo (`password`); `make dev` para desarrollo; tabla de servicios; nota de que en
  repos privados de cuentas gratuitas no hay revisores en environments (alternativa `workflow_dispatch` con
  `confirm_production=DESPLEGAR`, ya en DEPLOY.md); que `ALLOW_GENERATED_KEYS` y `SEED_DEMO_DATA` son solo demo.
- `fartmar_app` (rol de la API en el stack de producción) no tiene `TRUNCATE`, DDL ni `UPDATE/DELETE` en kardex y
  bitácoras. Si una función nueva necesita otro permiso, actualizar `db:provision-app-role`: Pest corre como dueño y no
  lo detecta.
- Dev y demo usan volúmenes de BD distintos (`db_data_dev` / `db_data`); las llaves de dev viven en `backend/.env`, las
  de la demo en el volumen `app_keys`.
- No se pudo verificar: ejecución real en GitHub Actions (bloqueo de facturación), push a GHCR, `deploy.sh` con
  `DRY_RUN=0`, la CSP en un navegador real. La fase no pasó todavía por el revisor-senior.

## Lo que las fases siguientes deben saber de la Fase 6

- Código en `backend/app/Domain/Assistant` (servicio, guardas, proveedores, herramientas); contrato y trade-offs en
  `docs/asistente-ia.md` (texto base para la sección del README en la Fase 8).
- **DevOps (Fase 7):**
  - Variables nuevas en `.env.example` y compose: `LLM_PROVIDER` (default `mock`), `OLLAMA_URL`, `OLLAMA_MODEL`,
    `LLM_API_KEY` (nunca en el repo), `ANTHROPIC_MODEL`, `LLM_TIMEOUT`, `LLM_MAX_TOKENS`. El compose tiene `extra_hosts` para
    `host.docker.internal` (Ollama en el host).
  - El eval lee `backend/tests/Assistant/eval.json`: si la imagen de producción excluye `tests/`, mover el archivo o
    correr el eval desde la imagen de CI. Agregar `php artisan assistant:eval` al pipeline (sobre BD recién sembrada;
    el comando avisa si la semilla fue modificada).
- Proveedores reales sin probar en vivo: Ollama no está instalado en el host y Anthropic solo se probó con
  `Http::fake`. Un nombre propio escrito en la pregunta no se detecta (S-92): con proveedor externo saldría de la IPS.
- Bajos abiertos: nombres de bodega/producto (editables por admin) no van marcados como no confiables (L1); el mock no
  entiende sinónimos ("paracetamol"); el filtro de entrada rechaza lotes numéricos de ≥ 7 dígitos.
- En la BD de desarrollo el eval da 17/18 porque la demo de la Fase 5 dispensó L-ACE-2402; `make fresh` lo restablece.

## Lo que las fases siguientes deben saber de la Fase 5

- Frontend en `frontend/src`: `api/` (cliente único, `errors.ts` mapea códigos a español y muestra el correlation id),
  `auth/` (sesión, `permissions.ts` solo para menú/rutas), `features/<módulo>/`. `api/types.ts` se mantiene a mano
  contra `docs/openapi.yaml` (0.6.1 desde la Fase 6).
- Token en `sessionStorage` (S-82); datos de paciente solo en memoria. Idempotency-Key por intento (S-83), con
  respaldo si no hay `crypto.randomUUID`.
- **Asistente IA (Fase 6):** falta la pantalla del asistente en el frontend; agregarla en `features/assistant/` y en
  el menú (`auth/permissions.ts`).
- **DevOps (Fase 7):** el frontend corre en el host con el proxy de Vite (`/api` → 8080). Para la imagen de
  producción: build estático servido por Nginx con el proxy `/api`; `crypto.randomUUID` requiere HTTPS o localhost
  (hay respaldo, pero conviene servir por HTTPS).
- Bajos abiertos de la revisión: catálogo cortado en 1000 filas (L3), ajustes solo sobre la página visible (L4),
  discrepancias sin paginación (L6). Los commits iniciales de la Fase 5 (`aa28ea7`…`e698d5c`) no compilan por sí solos
  (importan `auth/` antes de crearlo); los posteriores sí.
- Cambio de comportamiento: el creador que intenta autorizar/rechazar recibe 403 `SEGREGACION_FUNCIONES` (Policy)
  antes que 409.

## Lo que las fases siguientes deben saber de la Fase 4

- **Frontend (Fase 5):**
  - Login y `GET /auth/me` devuelven `must_change_password`. Si es true, toda la API responde 403
    `CAMBIO_CLAVE_REQUERIDO` salvo `auth/password`, `auth/me` y `auth/logout`: hay que mostrar la pantalla de cambio
    de clave (`POST /auth/password`: actual + nueva confirmada, 12 caracteres a 72 bytes). Los usuarios demo tienen
    la marca en false.
  - Búsqueda de pacientes por **POST** `/patients/search` (`document_type`, `document_number`; S-69), con límite
    de 30 por minuto. Después `GET /patients/{id}/prescriptions` (por línea: prescrito, entregado, reservado,
    disponible) para armar la dispensación.
  - Para el auditor, `PatientResource.masked=true`, y `instructions`/`rejection_reason` salen `null`: la UI debe
    tolerar esos nulos.
  - Cada respuesta trae `X-Correlation-Id`; mostrarlo en los mensajes de error ayuda a soportar incidentes.
  - Endpoints de admin: `GET/POST /users`, `PATCH /users/{id}` (rol, is_active, nombre, password temporal).
- **Asistente IA (Fase 6):** las herramientas no deben devolver datos de pacientes; reutilizar el procesador
  `app/Logging/RedactSensitiveData` y no loguear prompts con PII.
- **DevOps (Fase 7):** el canal de log predeterminado es `json` (stderr). El canal `emergency` no admite tap de
  redacción (S-73). Falta configurar `TrustProxies` detrás de Nginx (IP real en bitácoras y limitadores), el formato
  JSON del access log de Nginx y los headers de seguridad.
- `docs/openapi.yaml` está en 0.4.0 con todos los endpoints de `route:list`.

## Lo que las fases siguientes deben saber de la Fase 3

- Traslados: `TransferService` bloquea primero la fila del traslado (`lockForTransition`), valida con
  `TransferStateMachine` y luego mueve stock vía `StockService`. Despacho todo o nada (S-54); recepción única con
  todos los lotes (S-55); faltantes → `transfer_discrepancies` que resuelve un regente distinto del receptor
  (`REINTEGRAR_ORIGEN` = AJUSTE + en origen, `PERDIDA` = sin movimiento).
- Defensa en BD agregada en esta fase (migraciones `2026_10_08_211000` y `2026_10_11_1000*`…`100400`): trigger de
  transición de estado (mismos pasos que la máquina, incluido rechazar "mismo estado"), líneas solo editables en
  BORRADOR, discrepancia RESUELTA congelada, RN-01 en `transfer_item_lots`, recepción única, `quantity_missing`
  coherente, `balance_after` del kardex = existencia actual, `assert_user_role` exige `is_active`, resolutor ≠ receptor.
- Los errores lanzados por esos triggers responden 500 genérico (el servicio valida antes con su error específico);
  si se quiere, mapearlos en `ApiErrorRenderer`.
- `TransferResource.allowed_actions` (S-67) dice al frontend qué botones mostrar; `BusinessDenial` permite que una
  Policy niegue con el JSON de error de dominio.
- Endpoints nuevos: `transfers` (+ submit/approve/dispatch/receive/cancel), `transfer-discrepancies` (+ resolve),
  `inventory-adjustments`, `kardex` (filtros incl. `reference_type`/`reference_id`), `alerts` (`limit`),
  `stocks/in-transit`. Matriz de permisos en `AuthorizationMatrixTest`.
- Pendiente menor: `TransferDiscrepancyPolicy::resolve` solo mira rol (la regla receptor≠resolutor vive en servicio
  y BD); INSERT directo de un traslado en estado avanzado no se bloquea (S-64); zona `America/Bogota` duplicada en
  un trigger (S-65); ajustes no idempotentes (S-61, el frontend debe bloquear doble clic); `GET /transfers/{id}`
  sin alcance por bodega.

## Lo que la Fase 3 debía saber de la Fase 2

- `StockService` es el **único** punto que escribe `stocks` (UPDATE relativo + `RETURNING` para `balance_after`);
  exige transacción abierta y bloquea con `FOR UPDATE OF stocks` ordenado por `(product_id, expires_at, lot_id)`.
- Orden de bloqueo global: cabecera de negocio (prescripción/traslado) → líneas por id → existencias por producto.
  En dispensación se bloquea la prescripción **antes** de insertar la dispensación (evita deadlock por la FK).
- Errores de BD mapeados en `ApiErrorRenderer` vía `App\Database\PostgresError` (CHECK, deadlock, serialización).
- Pruebas de concurrencia: `tests/Concurrency` (worker en procesos reales + barrera con advisory lock); grupo
  Pest `concurrency`, `.\make.ps1 test-concurrency`.
- Pendiente menor de la revisión: `sanctum:prune-expired` está programado pero no hay scheduler en compose (Fase 7);
  `correlation_id`/`ip` en `null` hasta el middleware de la Fase 4.

## Lo que la Fase 2 debía saber del esquema

- Dispensación en 3 niveles: `dispensations` → `dispensation_items` (por línea de prescripción) →
  `dispensation_item_lots` (lotes asignados por FEFO). Un controlado se crea `PENDIENTE_AUTORIZACION`
  con líneas pero **sin lotes**; los lotes y la salida de stock se hacen al autorizar.
- Insertar la cabecera con `requires_authorization` ya calculado **antes** de las líneas (un trigger
  rechaza líneas de productos controlados si la cabecera no exige autorización).
- Triggers exigen que quien autoriza/rechaza una dispensación y quien aprueba un traslado tenga rol
  `regente_farmacia`, y que sea distinto del creador/solicitante.
- `stocks` tiene `product_id` denormalizado con FK compuesta `(lot_id, product_id)`; índice parcial
  `WHERE quantity > 0` para FEFO.
- Kardex: `quantity > 0`, `direction ±1`, `balance_after >= 0`, FK a `(warehouse_id, lot_id)` de `stocks`;
  desde la Fase 3 un trigger exige `balance_after` = existencia actual de la fila.
- Traslados: los lotes se fijan al despachar en `transfer_item_lots` (FEFO multi-lote); una discrepancia
  por lote despachado.
- La BD no valida: FEFO, vencimiento en dispensación, vigencia de prescripción, transiciones de dispensación.
  Todo eso va en `app/Domain` con pruebas. (Transiciones de traslado y RN-01 en despacho sí, desde la Fase 3.)
- El seeder de inventario ya usa `StockService` (hecho en la Fase 2).

## Riesgos conocidos / deuda abierta

- ~~Triggers de solo inserción no bloquean `TRUNCATE`~~ **Resuelto en la Fase 7** para el stack de producción: la
  API corre con `fartmar_app` (sin `TRUNCATE`, S-101). En desarrollo y pruebas se sigue usando el dueño.
- Cambiar `APP_KEY` o `PATIENT_HASH_KEY` invalida documentos cifrados/hashes (sin rutina de re-cifrado).
- Falta `README.md` (Fase 8): pasar ahí las credenciales demo y los comandos.

### Pendientes de seguridad abiertos (revisiones de las Fases 2 y 4)

Resueltos en la Fase 4: PII en logs de excepciones, correlation id, login fallido auditado, pacientes con
enmascarado y bitácora, revocación de tokens, enumeración de correos por tiempo y primer login lento.

- ~~**[Alto] `APP_DEBUG=true` por defecto**~~ **Resuelto en la Fase 7:** la imagen de producción y
  `docker-compose.yml` fijan `APP_ENV=production` y `APP_DEBUG=false` (no se leen del `.env`); solo el override de
  desarrollo usa debug.
- **[Medio] Riesgo residual del admin (S-77):** puede crear un médico o un regente, entrar una vez con la clave
  temporal y cambiarla. Queda auditado (`user.created` con el rol, `user.password_changed`), pero no se impide.
- **[Bajo] Redacción de strings sin clave sensible** (`RedactSensitiveData`): solo enmascara correos dentro de textos
  libres; un documento bajo una clave como `input` o `value` pasaría tal cual.
- **[Bajo] Dos admins pueden desactivarse entre sí a la vez** y dejar el sistema sin administradores activos.
- **[Bajo] Decisión de auditar la búsqueda sin resultado en `PatientController`**: debería vivir en un servicio de dominio.
- **[Bajo] Sin retención de `audit_logs`**; cada login fallido agrega una fila. Prescripciones sin edición/anulación
  por API y lista por paciente limitada a 50.
- **[Bajo] Enumeración de ids por rol sin permiso:** un médico recibe 404 por una dispensación inexistente y 403 por
  una existente (el binding resuelve antes de la Policy). Solo revela que el id existe, sin datos.
- **[Bajo] Alcance por bodega** de `GET /dispensations/{id}` (S-42): cualquier personal de farmacia ve cualquier bodega.
- ~~**[Bajo] Headers de seguridad**~~ **Resuelto en la Fase 7** (S-104): CSP, `nosniff`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy` en Nginx; HSTS documentado y comentado hasta que haya TLS.
- Restos de Docker que Daniel puede borrar a mano si quiere (los permisos del agente bloquean `rmi`):
  `docker volume rm fartmar_composer_cache` · `docker rmi fartmar-php-dev:local alpine:3 rhysd/actionlint:latest`
  · de la Fase 7: `docker rmi fartmar-api:ci fartmar-web:ci` (etiquetas extra de las mismas imágenes).

## Documentos de referencia

- `docs/enunciado.md` — enunciado original.
- `docs/modelo-datos.md` — esquema, ER y credenciales demo.
- `docs/supuestos.md` — supuestos S-01…
- `docs/ai-log.md` — registro de uso de IA por tarea (materia prima de `AI_USAGE.md`).
