# Estado del proyecto y traspaso entre sesiones

> Documento vivo. Léelo al iniciar una sesión nueva de Claude Code (junto con `CLAUDE.md`).
> Última actualización: 2026-10-08, al cerrar la Fase 2.
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
| 2 Dispensación (FEFO, concurrencia, idempotencia) | ✅ Hecha (PR `feat/fase-2-dispensacion`) | FefoAllocator, StockService, KardexService, DispenseService, API `/api/v1`; 253 tests (871 aserciones) con 9 de concurrencia real; revisor-senior sin Críticos/Altos, Medios corregidos |
| 3 Traslados, ajustes, alertas | ⏭️ **Siguiente** | backend-dominio → qa-tester; reutilizar `StockService` (ya admite salida/entrada de traslado y `AJUSTE`) y `ConcurrentDispenser` |
| 4 Seguridad y privacidad | Pendiente | |
| 5 Frontend | Pendiente | |
| 6 Asistente IA | Pendiente | |
| 7 DevOps completo | Pendiente | |
| 8 Documentación y cierre | Pendiente | |

## Entorno (importante para cualquier agente)

- Windows 11, PowerShell 5.1. **No hay PHP ni Composer en el host**: todo lo de PHP corre en el contenedor
  (`docker compose exec -T api php artisan …`, `./vendor/bin/pest`, `pint --test`, `phpstan analyse`).
- Atajos: `.\make.ps1 up | test | lint | fresh | reset | eval` (Windows) o `make …`.
- En PowerShell 5.1 no uses `2>&1` con comandos nativos (convierte stderr en error); usa `2>$null`.
- Proyecto compose `fartmar`. Puertos host: Postgres **55432**, API **8080**, frontend **5173**.
- **No tocar contenedores Docker ajenos** del usuario (`trigra-dev-*`, `sonarqube-biotrust`, `cea-postgres`,
  `busy_moore`). Prohibido `prune`, `docker rm/rmi/stop/kill` de cosas ajenas (bloqueado en permisos).
- Las pruebas usan la BD `fartmar_test`; `tests/TestCase.php` aborta si la BD no termina en `_test`.
- Usuarios demo (contraseña `password`): ver `docs/modelo-datos.md` (incluye `regente2@fartmar.test` para RN-05/RN-08).
- Repo: https://github.com/DanielAmador0007/Fartmar (rama `main`).

## Bloqueos externos

- **GitHub Actions no corre**: la cuenta tiene un bloqueo de facturación ("account is locked due to a billing
  issue"). Daniel debe resolverlo en GitHub → Settings → Billing. Mientras tanto, validar en local.

## Lo que la Fase 3 debe saber de la Fase 2

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
  la BD **no** valida que `balance_after` = saldo anterior ± cantidad → lo garantiza `KardexService`.
- Traslados: los lotes se fijan al despachar en `transfer_item_lots` (FEFO multi-lote); una discrepancia
  por lote despachado.
- La BD no valida: FEFO, vencimiento, vigencia de prescripción, transiciones de estado (valida la fila
  final, no el paso). Todo eso va en `app/Domain` con pruebas.
- El seeder de inventario ya usa `StockService` (hecho en la Fase 2).

## Riesgos conocidos / deuda abierta

- Triggers de solo inserción no bloquean `TRUNCATE` (necesario para pruebas). En producción: usuario de
  BD no dueño de las tablas (documentar en DEPLOY.md, Fase 7).
- Cambiar `APP_KEY` o `PATIENT_HASH_KEY` invalida documentos cifrados/hashes (sin rutina de re-cifrado).
- La BD no exige que quien resuelve una discrepancia sea regente ni que autorizadores estén activos
  (`is_active`): cubrir en Policies (Fase 3/4) o con trigger.
- Falta `README.md` (Fase 8): pasar ahí las credenciales demo y los comandos.

### Pendientes de seguridad detectados en la revisión de la Fase 2 (para Fase 4 / 7)

- **[Alto · Fase 4] PII y secretos en logs de excepciones:** `QueryException` incluye el SQL **con los valores**
  (se vio en pruebas: un INSERT de `users` con correo y hash de contraseña). Laravel lo registra al reportar un 500.
  Falta el procesador Monolog que redacte claves sensibles y los valores de SQL, y logs JSON a stdout
  (hoy `LOG_CHANNEL=stderr` en texto plano).
- **[Alto · Fase 7] `APP_DEBUG=true` por defecto** en `docker-compose.yml` y `.env.example` (correcto para demo
  local). La imagen/compose de producción debe forzar `APP_ENV=production` y `APP_DEBUG=false`; con debug activo
  cualquier 500 no capturado expone SQL y host. El renderizador ya responde `ERROR_INTERNO` si debug está apagado (S-44).
- **[Medio · Fase 4] Middleware `X-Correlation-Id`:** `correlation_id` de dispensaciones, kardex y `audit_logs`
  sigue en `null`; `audit_logs.ip` también (nadie llena `Context::get('ip')`).
- **[Medio · Fase 4] Intentos de login fallidos no se auditan** (solo los exitosos y el logout). Agregar
  `auth.login_failed` en `audit_logs` sin el correo en claro (p. ej. HMAC del correo) para detectar fuerza bruta.
- **[Medio · Fase 4] `patient_access_logs`, endpoint de pacientes y enmascarado para auditor (`PatientMasker`):**
  aún no existen. Hoy ningún Resource expone datos del paciente (solo `patient_id`), verificado en pruebas.
- **[Medio · Fase 4] Al desactivar un usuario (CRUD de admin), revocar sus tokens** además de la verificación de
  `is_active` en cada petición (S-41). Prueba de matriz `AuthorizationMatrixTest` debe ampliarse con cada endpoint nuevo.
- **[Bajo] Enumeración de ids por rol sin permiso:** un médico recibe 404 por una dispensación inexistente y 403 por
  una existente (el binding resuelve antes de la Policy). Solo revela que el id existe, sin datos.
- **[Bajo] Alcance por bodega** de `GET /dispensations/{id}` (S-42): cualquier personal de farmacia ve cualquier bodega.
- **[Bajo · Fase 7] Headers de seguridad** (CSP, HSTS, `X-Content-Type-Options`, `X-Frame-Options`) en Nginx; hoy la
  API corre con el servidor embebido de PHP.
- **[Bajo] Primer login de cada worker** tarda más (se genera el hash ficticio para igualar tiempos); diferencia
  medible solo en la primera petición del proceso.
- Restos de Docker de la Fase 0 que Daniel puede borrar a mano si quiere:
  `docker volume rm fartmar_composer_cache` · `docker rmi fartmar-php-dev:local alpine:3 rhysd/actionlint:latest`.

## Documentos de referencia

- `docs/enunciado.md` — enunciado original.
- `docs/modelo-datos.md` — esquema, ER y credenciales demo.
- `docs/supuestos.md` — supuestos S-01…
- `docs/ai-log.md` — registro de uso de IA por tarea (materia prima de `AI_USAGE.md`).
