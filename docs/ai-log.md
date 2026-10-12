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

### 2026-10-08 — Fase 1: modelo de datos, modelos, seeders y pruebas de constraints
- Agente: arquitecto-datos (Claude Code)
- Qué generó la IA:
  - 9 migraciones (`backend/database/migrations/2026_10_08_2100*` a `2108*`): rol en `users`, catálogo (`warehouses`, `products`, `lots`), `stocks` y `stock_minimums`, funciones PL/pgSQL compartidas, `kardex_movements`, `patients` + `prescriptions` + `prescription_items`, `patient_access_logs` + `audit_logs`, dispensaciones (3 tablas) y traslados (4 tablas).
  - 19 modelos Eloquent con relaciones tipadas, `$fillable` explícito y casts; enums `Role`, `KardexType`, `DispensationStatus`, `TransferStatus`, `DiscrepancyStatus`, `PrescriptionStatus`; `App\Domain\Patients\DocumentHasher` (HMAC); `config/fartmar.php`; morph map.
  - Factories, 4 seeders idempotentes y 47 pruebas Pest (42 nuevas en `tests/Feature/Database`) que intentan violar cada constraint por SQL directo. `docs/modelo-datos.md` con diagrama ER.
- Qué decisión tomó y por qué (resumen para la sustentación):
  - **Stock nunca negativo:** `CHECK (quantity >= 0)` en `stocks`. Aunque el servicio falle o dos transacciones compitan, PostgreSQL rechaza el UPDATE que deja saldo negativo. Es la última línea de defensa de RN-03 (la primera es `FOR UPDATE`, Fase 2).
  - **Kardex inmutable:** trigger `BEFORE UPDATE OR DELETE` que lanza excepción (RN-06), con `quantity > 0`, `direction ∈ {-1, 1}` coherente con el tipo (las entradas no restan, las salidas no suman), `balance_after >= 0` y motivo obligatorio para `AJUSTE`. Un movimiento solo puede referirse a una existencia real: FK `(warehouse_id, lot_id) → stocks`.
  - **No dispensar más de lo prescrito:** `CHECK (quantity_dispensed <= quantity_prescribed)` en `prescription_items`; las parciales acumuladas se suman sobre esa columna (RN-04).
  - **Segregación de funciones:** `CHECK (approved_by <> requested_by)` en traslados (RN-08) y `CHECK (authorized_by <> created_by)` en dispensaciones (RN-05); además un trigger exige que quien aprueba/autoriza tenga rol `regente_farmacia`, y un controlado no puede quedar `COMPLETADA` sin autorizador.
  - **Idempotencia:** `idempotency_key` UNIQUE + `request_hash` (RN-09): dos reintentos simultáneos no pueden crear dos dispensaciones; el segundo choca con el UNIQUE.
  - **FK compuestas `(lot_id, product_id) → lots(id, product_id)`:** el `product_id` denormalizado en stocks/kardex/dispensaciones/traslados siempre coincide con el lote; imposible dispensar un lote de otro producto. `(prescription_id, patient_id)` impide usar la prescripción de otro paciente.
  - **Traslados:** CHECK de los 7 estados, origen ≠ destino, cada estado exige sus actores (p. ej. `EN_TRANSITO` ⇒ `dispatched_by`) y `ANULADO` solo sin `dispatched_at` (RN-07). `0 <= quantity_received <= quantity_dispatched`.
  - **Privacidad:** documento cifrado + HMAC para búsqueda; bitácoras append-only con el mismo trigger del kardex.
  - Los CHECK se escribieron como implicaciones (`estado NOT IN (...) OR condición`): con la primera versión, un estado inválido violaba varios constraints a la vez y el error reportado era confuso; se detectó con las pruebas.
  - Desviaciones respecto al plan inicial: `transfer_items` sin `lot_id` (los lotes van en `transfer_item_lots`, multi-lote FEFO) y la dispensación en tres niveles para poder dejar un controlado pendiente sin lotes (supuestos S-19, S-20).
- Puntos que Daniel debe revisar/entender:
  - Por qué `stocks.product_id` está denormalizado y cómo la FK compuesta lo protege (S-16).
  - Que la BD **no** valida FEFO, vencimiento ni transiciones del traslado: eso es de `app/Domain` (Fase 2). Ver la sección "Lo que la BD NO garantiza" de `docs/modelo-datos.md`.
  - `Patient::booted()` recalcula `document_hash` al guardar si cambia el documento: es el único "hook" de modelo.
  - Helper `expectDbRejects()` en `tests/Pest.php`: usa `DB::transaction` (SAVEPOINT) para poder probar varias violaciones en una misma prueba con `RefreshDatabase`.
- Riesgos o cosas que podrían estar mal:
  - Los triggers append-only no bloquean `TRUNCATE`; en producción el usuario de la app no debería ser dueño de las tablas (S-23).
  - Si se rota `APP_KEY`/`PATIENT_HASH_KEY` se pierden los documentos cifrados y los hashes; no hay rutina de re-cifrado.
  - El seeder escribe stock y kardex directamente (aún no existe `KardexService`); en Fase 2 conviene que use el servicio.
  - `balance_after` coherente con el saldo depende del servicio (bloqueo + misma transacción); no hay constraint que lo garantice entre filas.

### 2026-10-08 — Fase 1 (cierre): revisión de cobertura de constraints y seeders
- Agente: qa-tester (Claude Code)
- Qué generó la IA:
  - Inventario de cada CHECK, UNIQUE, FK compuesta y trigger de las migraciones `2026_10_08_21*` contra las pruebas de `tests/Feature/Database`. Se encontraron ~30 constraints sin prueba que intentara violarlos (códigos vacíos/duplicados del catálogo, tipo de documento, acción vacía en bitácoras, `rejecter_differs`, `idempotency_key` vacía, UNIQUE de líneas, FK lote/producto en kardex y traslados, estado de discrepancias, etc.).
  - 33 pruebas nuevas (47 → 80 en total, 214 aserciones), incluidos casos límite que **deben aceptarse**: `quantity_dispensed == quantity_prescribed`, stock en 0, `balance_after = 0`, vigencia del mismo día, `quantity_received` 0 y = despachado, AJUSTE positivo, anular antes del despacho.
  - Pruebas de los triggers por **UPDATE** (así los usará el servicio en Fase 2): autorizar controlado con auxiliar, aprobar traslado con auxiliar, pasar a ANULADO un traslado EN_TRANSITO.
  - Pruebas del seeder: lote vencido y controlado con existencias, re-ejecución sin alterar existencias ni `quantity_dispensed`, datos sintéticos (`@fartmar.test`, documentos `99…`, teléfonos `300000…`, sin PII en claro en la tabla).
  - Migración `2026_10_08_210900_add_dispensation_items_integrity_triggers.php` (commit `fix(datos)`).
- Qué decisión tomó y por qué:
  - **Bug 1 (RN-05):** el CHECK `dispensations_controlled_needs_authorization` dependía de `requires_authorization`, que fija la aplicación. Si el servicio lo ponía en `false` para Morfina, la BD aceptaba una dispensación COMPLETADA sin regente. Ahora un trigger en `dispensation_items` deriva la exigencia de `products.is_controlled`, y otro en `dispensations` impide apagar la marca después.
  - **Bug 2 (RN-04):** `dispensation_items.prescription_item_id` podía apuntar a una línea de la prescripción de **otro paciente** (la FK compuesta de la cabecera no se propagaba a las líneas). Ahora un trigger exige que la línea pertenezca a `dispensations.prescription_id`.
  - Ambos se escribieron primero como pruebas que fallaban ("La base de datos aceptó una operación que debía rechazar") y luego se corrigieron. Se usaron triggers porque son reglas entre tablas (un CHECK solo ve la fila).
- Puntos que Daniel debe revisar/entender:
  - El orden de inserción que exige ahora la BD: cabecera con `requires_authorization` ya calculado **antes** de insertar las líneas.
  - Las pruebas que verifican "esto se acepta" son tan importantes como las de rechazo: evitan constraints demasiado estrictos que romperían el flujo normal.
- Riesgos o cosas que podrían estar mal:
  - La BD valida la fila resultante, no la transición: un UPDATE que borre `dispatched_at` y ponga `ANULADO` en la misma sentencia pasaría (documentado en `docs/modelo-datos.md`).
  - No se exige por BD que `resolved_by` de una discrepancia sea regente, ni que el usuario que autoriza/aprueba esté activo (`is_active`), ni que una dispensación PENDIENTE no tenga lotes. Candidatos a reforzar si se decide.
  - Si cambia `products.is_controlled` después de crear dispensaciones, los triggers no revisan las existentes.

### 2026-10-08 — Fase 2: núcleo de dispensación (FEFO, kardex, idempotencia, control especial) y API
- Agente: backend-dominio (Claude Code)
- Qué generó la IA:
  - `app/Domain/Inventory`: `FefoAllocator` (función pura), `StockService` (único punto que cambia `stocks.quantity`), `KardexService`, `BusinessDate` ("hoy" en Bogotá), `InventoryQuery`.
  - `app/Domain/Dispensing`: `DispenseService` (create / authorize / reject / preview), `IdempotencyGuard`, DTOs (`DispenseData`, `DispenseResult`, `FefoPreview`).
  - `app/Domain/Exceptions`: excepciones de negocio con código, estado HTTP y detalles; `app/Http/ApiErrorRenderer.php` las convierte a `{ error: { code, message, details } }` (registrado en `bootstrap/app.php`).
  - `app/Domain/Auth/LoginService`, `app/Domain/Audit/AuditLogger`.
  - API `/api/v1`: `auth/login|logout|me`, `dispensations` (POST con `Idempotency-Key`, GET, `preview`, `{id}/authorize`, `{id}/reject`), `stocks`. Form Requests, Policies (`DispensationPolicy`, `StockPolicy`) y Resources.
  - El `InventorySeeder` ahora usa `StockService::increase()` (antes escribía stock y kardex a mano).
  - Pruebas: 10 unitarias de FEFO, 8 de `StockService`, 23 de `DispenseService`, 29 de API. `docs/openapi.yaml` nuevo. Supuestos S-27…S-37.
- Qué decisión tomó y por qué:
  - **Flujo transaccional de una dispensación, en simple:** todo pasa dentro de una sola transacción; si algo falla en cualquier paso, la BD deshace todo (no queda stock descontado a medias ni kardex huérfano). Los pasos son:
    1. ¿Ya existe esa `Idempotency-Key`? Si es el mismo contenido, se devuelve la dispensación original y no se toca nada; si el contenido cambió, 409.
    2. Se inserta la cabecera de la dispensación **primero**, para "apartar" la clave. Si llega un segundo reintento idéntico al mismo tiempo, su INSERT queda esperando en el índice UNIQUE; cuando el primero confirma, el segundo falla con violación de UNIQUE, se deshace y el servicio devuelve la dispensación ganadora (carrera resuelta por la BD, sin locks extra).
    3. Se bloquean (`FOR UPDATE`) la prescripción y sus líneas y se valida vigencia y saldo: prescrito − entregado − reservado por pendientes.
    4. Si hay un controlado, se guarda como `PENDIENTE_AUTORIZACION` y termina (sin mover stock).
    5. Si no, por cada producto (en orden de `product_id`) se bloquean sus existencias no vencidas en orden `(expires_at, lot_id)`, `FefoAllocator` decide cuánto sale de cada lote, y `StockService::decrease()` descuenta y escribe el kardex con el saldo resultante.
  - **Orden de bloqueo fijo** (dispensación → prescripción → líneas por id → existencias por producto y FEFO) en todos los caminos para evitar deadlocks; `DB::transaction(fn, 3)` reintenta si aun así ocurre uno.
  - `FefoAllocator` es puro (recibe filas ya bloqueadas) para probarlo sin BD; el bloqueo vive en `StockService::lockFefoCandidates()` con `FOR UPDATE OF stocks` (no bloquea filas de `lots`).
  - `StockService::decrease()` vuelve a bloquear la fila, valida existencias y vencimiento y calcula `balance_after` desde la fila bloqueada: el saldo del kardex es correcto aunque otro llamador olvide bloquear antes. El CHECK `quantity >= 0` sigue siendo la última línea de defensa.
  - El rol de regente se valida en la Policy (403 `NO_AUTORIZADO`) **y** en el servicio, que además exige "distinto del creador" con un error propio (403 `SEGREGACION_FUNCIONES`); la BD lo exige otra vez con CHECK + trigger.
  - `IdempotencyGuard` no es `final` solo para poder simular la carrera con un mock parcial en la prueba.
- Puntos que Daniel debe revisar/entender:
  - Por qué la cabecera se inserta **antes** de validar cantidades (paso 2): si se validara primero, un reintento que espera al primero podría recibir `PRESCRIPCION_EXCEDIDA` en vez de la respuesta original.
  - Que `FOR UPDATE` en PostgreSQL (READ COMMITTED) espera a la otra transacción y luego **re-lee** la fila confirmada; por eso dos dispensaciones por la última unidad no pueden dejar stock negativo.
  - La prueba "carrera por la misma clave" en `tests/Feature/Dispensing/DispenseServiceTest.php`: simula el segundo reintento con un mock que la primera vez "no ve" la clave.
  - Supuestos S-30/S-31: una dispensación mixta queda entera pendiente; las pendientes reservan cantidad de la prescripción, no stock.
  - El orden de códigos HTTP: 409 conflictos de estado/stock/idempotencia, 422 reglas de prescripción y validación, 403 permisos y segregación.
- Riesgos o cosas que podrían estar mal:
  - La concurrencia real (procesos paralelos, sin `RefreshDatabase`) **todavía no está probada**: la hará qa-tester en `tests/Concurrency`. Las pruebas actuales corren en una sola conexión.
  - `isKeyCollision()` reconoce el UNIQUE por el nombre del índice (`dispensations_idempotency_key_unique`); si se renombra en una migración, la carrera devolvería 500.
  - Sin middleware de correlación aún: `correlation_id` del kardex y bitácoras queda `null` hasta la Fase 4 (se lee de `Context`).
  - La vista previa no bloquea: lo que muestra puede cambiar al confirmar (documentado en S-34).
  - Los tokens de Sanctum no expiran (S-36) y `Hash::check` contra un hash ficticio cuando el correo no existe es una mitigación básica de enumeración, no perfecta.

### 2026-10-08 — Fase 2: pruebas de concurrencia real y casos borde de dispensación
- Agente: qa-tester
- Qué generó la IA:
  - `backend/tests/Concurrency/bin/dispense-worker.php`: script que arranca Laravel en un **proceso PHP propio** (conexión propia a PostgreSQL), espera en una barrera y llama a `DispenseService::create` real. Imprime una línea JSON con el resultado.
  - `backend/tests/Support/ConcurrentDispenser.php`: lanza N workers con `Process::start`, los suelta a la vez con una barrera de `pg_advisory_lock` (la prueba tiene el lock exclusivo; cuando `pg_locks` muestra N esperas, lo suelta) y verifica el kardex (`balance_after` encadenado desde 0 y suma = `stocks.quantity`). También lee `pg_stat_database.deadlocks`.
  - `backend/tests/Concurrency/DispenseConcurrencyTest.php` (grupo `concurrency`, `DatabaseTruncation` + truncado al final; sin `RefreshDatabase`): última unidad con 8 procesos; stock 5 en 2 lotes con 10 procesos (FEFO bajo concurrencia); misma `Idempotency-Key` en 8 procesos; líneas en orden inverso sin deadlocks; prueba de control sin `FOR UPDATE`.
  - `tests/Feature/Dispensing/DispenseEdgeCasesTest.php` (13 casos) y 4 pruebas más en `DispensationApiTest.php`.
  - Objetivo `test-concurrency` en `Makefile` y `make.ps1`. Supuestos S-38 y S-39.
  - **Fix** en `StockService`: el saldo se actualiza con `UPDATE ... SET quantity = quantity ± n RETURNING quantity` en vez de `$stock->quantity -= n; save()`.
- Qué decisión tomó y por qué:
  - Procesos reales (no hilos ni mocks) para que cada solicitud tenga su conexión y los `FOR UPDATE` se bloqueen de verdad. Cada worker usa una prescripción distinta para que la única disputa sea el stock.
  - Barrera con advisory lock en vez de `sleep`: arranque de Laravel (lento) **antes** de la barrera; todos salen a la vez y la prueba es determinista (resultados exactos, no "a veces").
  - Prueba de control: el worker en modo `unsafe` cambia la gramática SQL de **su** conexión para quitar todos los `FOR UPDATE` y pausa tras cada lectura de `stocks`. No toca código de producción. Antes del fix, 5 procesos vendían la misma unidad y el stock quedaba en 0 (actualización perdida: cada uno escribía `quantity = 0`, el CHECK nunca veía un negativo). Con el UPDATE relativo, 1 sale y el resto lo frena el CHECK `stocks_quantity_non_negative`.
  - Mutaciones verificadas a mano (y revertidas): quitar los `FOR UPDATE` en `StockService` hace fallar las pruebas de última unidad y de 2 lotes; bloquear por id de línea en vez de por `product_id` hace que PostgreSQL registre 17–19 deadlocks (la prueba lo detecta aunque el reintento de `DB::transaction` los recupere); calcular "hoy" en UTC hace fallar las pruebas de vencimiento en Bogotá.
- Puntos que Daniel debe revisar/entender:
  - Cómo funciona la barrera (`pg_advisory_lock` exclusivo en la prueba / `pg_advisory_lock_shared` en cada worker) y por qué la prueba no puede usar `RefreshDatabase` (los workers no verían datos sin confirmar y no habría contención real).
  - Por qué el UPDATE relativo importa aunque exista `FOR UPDATE`: es lo que hace que el CHECK sea de verdad "la última línea de defensa".
  - La prueba de deadlocks mira el **contador** de PostgreSQL: con `DB::transaction(fn, 3)` un deadlock se reintenta y la prueba pasaría igual si solo se miraran los resultados.
  - Cobertura RN → archivo de prueba (para el README):
    | RN | Pruebas |
    |---|---|
    | RN-01 | `Unit/FefoAllocatorTest`, `Feature/Inventory/StockServiceTest`, `Feature/Dispensing/DispenseServiceTest`, `Feature/Dispensing/DispenseEdgeCasesTest` (borde de medianoche en Bogotá) |
    | RN-02 | `Unit/FefoAllocatorTest`, `Feature/Dispensing/DispenseServiceTest`, `Concurrency/DispenseConcurrencyTest` (FEFO con 10 procesos) |
    | RN-03 | `Concurrency/DispenseConcurrencyTest`, `Feature/Database/InventoryConstraintsTest`, `Feature/Inventory/StockServiceTest`, `Feature/Dispensing/DispenseEdgeCasesTest` (atomicidad multi-línea) |
    | RN-04 | `Feature/Dispensing/DispenseServiceTest`, `Feature/Dispensing/DispenseEdgeCasesTest`, `Feature/Database/DispensingConstraintsTest`, `Feature/Api/DispensationApiTest` |
    | RN-05 | `Feature/Dispensing/DispenseServiceTest`, `Feature/Dispensing/DispenseEdgeCasesTest`, `Feature/Database/DispensingConstraintsTest`, `Feature/Api/DispensationApiTest` |
    | RN-06 | `Feature/Database/InventoryConstraintsTest` (inmutabilidad), `Feature/Inventory/StockServiceTest`, `Concurrency/DispenseConcurrencyTest` (kardex = stock tras cada escenario) |
    | RN-09 | `Feature/Dispensing/DispenseServiceTest`, `Feature/Dispensing/DispenseEdgeCasesTest`, `Feature/Api/DispensationApiTest`, `Concurrency/DispenseConcurrencyTest` (misma clave en 8 procesos) |
- Riesgos o cosas que podrían estar mal:
  - Las pruebas de concurrencia tardan unos 30 s porque cada worker arranca Laravel sobre el volumen montado de Docker en Windows; en CI (Linux) debería ser más rápido.
  - El modo `unsafe` depende de que `Builder::lock()` pase por `compileLock()` de la gramática de PostgreSQL; si Laravel cambia eso, la prueba de control podría dejar de quitar los bloqueos (fallaría en la aserción de `CHECK_VIOLATION`, no pasaría en silencio).
  - `DatabaseTruncation` + `afterEach` truncan todas las tablas de `fartmar_test`; si alguien agrega datos fijos de referencia por migración, habría que excluirlos.
  - El replay de idempotencia devuelve la dispensación en su **estado actual** (p. ej. una pendiente que ya se autorizó vuelve como COMPLETADA), no una copia literal de la primera respuesta. Es razonable, pero conviene mencionarlo.

### 2026-10-08 — Fase 2: revisión de seguridad de autenticación, dispensación e inventario
- Agente: seguridad-privacidad
- Qué generó la IA:
  - Revisión de `/api/v1` (auth, dispensations, stocks): Policies, Resources, login, usuarios inactivos, IDOR, errores, validación, logs y tokens. Hallazgos verificados con pruebas exploratorias antes de corregir.
  - `app/Models/PersonalAccessToken.php`: rechaza tokens `id|secreto` con id que no cabe en `bigint`.
  - `AppServiceProvider`: `Sanctum::authenticateAccessTokensUsing` (usuario inactivo = token inválido) y limitador `login` (5/min por correo + IP, 20/min por IP).
  - `config/sanctum.php`: `expiration` = `SANCTUM_EXPIRATION` (480 min) y `guard = []` (solo Bearer). `LoginService` guarda `expires_at` en el token y el login lo devuelve. `sanctum:prune-expired` diario en `routes/console.php`.
  - `routes/api.php`: patrón de id `[1-9][0-9]{0,17}` en vez de `whereNumber`.
  - `ApiErrorRenderer`: `500 ERROR_INTERNO` sin detalles cuando `APP_DEBUG=false`; el 429 conserva `Retry-After`.
  - `StoreDispensationRequest`: `items` debe ser lista y cada línea exactamente `{prescription_item_id, quantity}`; ids `min:1`. `IndexStockRequest`: ids `min:1`.
  - `config/cors.php`: solo `CORS_ALLOWED_ORIGINS` (antes `*`).
  - Pruebas: `tests/Feature/Security/{AuthHardeningTest,ApiHardeningTest,AuthorizationMatrixTest}.php` (matriz rol × endpoint con dataset cartesiano de Pest: 5 roles × 7 endpoints) y `tests/Unit/ArchitectureTest.php` (controladores sin `Role`, sin `dd`/`dump`). Supuestos S-36 (actualizado) y S-40…S-45. Pendientes en `docs/PROGRESO.md`.
- Qué decisión tomó y por qué:
  - **Hallazgos y severidad:**
    - Alto (corregido): un token de un usuario desactivado seguía autenticando (`/auth/me` 200); las Policies frenaban la operación, pero no la autenticación.
    - Alto (corregido): tokens sin expiración.
    - Medio (corregido): `GET /dispensations/99999999999999999999` y el token `99999999999999999999|x` daban 500 con el SQL, host y BD en el cuerpo (con `APP_DEBUG=true`). El del token pasaba **antes** de autenticar.
    - Medio (corregido): 500 no uniforme y dependiente solo de `APP_DEBUG`; CORS `*` por defecto; login limitado solo por IP; el 429 perdía `Retry-After` (el renderizador creaba una respuesta nueva sin los headers).
    - Bajo (corregido): `items` aceptaba objetos y claves extra.
    - Sin hallazgos: no hay `if` de rol en controladores (todo pasa por Policies/Form Requests), ningún Resource expone nombre/documento del paciente, el login responde idéntico para correo inexistente, clave mala o usuario inactivo, el SQL crudo (`applyDelta`, `selectRaw`) usa bindings, no hay `dd`/`dump` ni secretos en el código, `Idempotency-Key` ya validaba formato y longitud.
  - El usuario inactivo se corta en el Guard de Sanctum (un solo lugar) y no con un middleware extra: así ninguna ruta nueva puede olvidarlo.
  - La expiración se cuenta desde el login (no deslizante) porque Sanctum no renueva tokens; 480 min = un turno.
  - Solo Bearer (S-40): elimina CSRF y simplifica; el riesgo de XSS se mitiga con expiración y CSP (Fase 7).
  - `ERROR_INTERNO` solo con debug apagado, para no perder el detalle en desarrollo.
- Puntos que Daniel debe revisar/entender:
  - Por qué un id enorme producía 500: `whereNumber` acepta cualquier cantidad de dígitos y PostgreSQL rechaza el valor al convertirlo a `bigint`. Lo mismo en `PersonalAccessToken::findToken` de Sanctum.
  - La diferencia entre **autenticación** (Guard de Sanctum: ¿quién es y está activo?) y **autorización** (Policies: ¿su rol puede hacer esto?). Antes, el inactivo solo se frenaba en la segunda.
  - Cómo leer `AuthorizationMatrixTest`: permitido = cualquier código distinto de 401/403 (el resultado de negocio se prueba en otros archivos). Hay que agregar cada endpoint nuevo ahí.
  - Que con un único origen CORS el paquete siempre responde ese origen (no `null`); el navegador bloquea porque no coincide.
- Riesgos o cosas que podrían estar mal:
  - **Las excepciones de BD se registran con el SQL y sus valores** (se vio un INSERT de `users` con correo y hash): la redacción Monolog de la Fase 4 es necesaria antes de exponer pacientes por API.
  - `APP_DEBUG=true` es el valor por defecto en compose; si alguien despliega así, los 500 vuelven a mostrar detalles. Se corrige en la imagen de producción (Fase 7).
  - El limitador de login usa la caché por defecto; con varias réplicas debe ser una caché compartida (Redis/BD), no `array`/`file`.
  - Detrás de un proxy (Nginx), `$request->ip()` será la del proxy si no se configuran `TrustProxies`: todo el tráfico compartiría el límite por IP (Fase 7).

### 2026-10-08 — Correcciones de la revisión de Fase 2 (dominio)
- Agente: backend-dominio (Claude Code)
- Qué generó la IA:
  - **M1** `app/Database/PostgresError.php` (lee SQLSTATE de `errorInfo[0]` y el nombre del constraint de la primera línea de `errorInfo[2]`) y `ApiErrorRenderer::databaseError`: CHECK `stocks_quantity_non_negative` → 409 `STOCK_INSUFICIENTE`; CHECK `prescription_items_dispensed_le_prescribed` → 422 `PRESCRIPCION_EXCEDIDA`; 40P01/40001 (tras los 3 intentos de `DB::transaction`) → 409 `CONFLICTO_CONCURRENCIA`. Aplica con o sin `APP_DEBUG`. Pruebas en `tests/Feature/Api/DatabaseErrorMappingTest.php`.
  - **B2** `DispenseService::executeOutflow`: `increment('quantity_dispensed', n)` en vez de `+=` y `save()`.
  - **B3** `IdempotencyGuard::isKeyCollision` usa `PostgresError` (23505 + `dispensations_idempotency_key_unique`), prueba con una violación real.
  - **B4** `Idempotency-Key`: 8–100 caracteres `[A-Za-z0-9_-]` y mensaje que lo dice; S-29 y openapi actualizados.
  - **B6** limitador `api` (120/min por usuario, `API_RATE_LIMIT_PER_MINUTE`) en el grupo `auth:sanctum`; S-47.
  - **B1** supuesto S-46 (semántica del replay) y prueba que lo fija.
  - **M2** modo `authorize` en `tests/Concurrency/bin/dispense-worker.php` y 4 pruebas de concurrencia real nuevas.
- Qué decisión tomó y por qué:
  - **M2 encontró un bug real:** 8 procesos pidiendo 1 sobre la MISMA prescripción daban OK=1 y 7 deadlocks (40P01). Flujo explicado simple: cada proceso (1) insertaba su dispensación, y ese INSERT, por la llave foránea, "marca" la prescripción en modo compartido (`FOR KEY SHARE`) para que nadie la borre; (2) luego pedía la prescripción en modo exclusivo (`FOR UPDATE`) para validar el saldo. El exclusivo espera a que los demás suelten su marca compartida, y los demás esperan lo mismo de él: nadie avanza. PostgreSQL mata a uno, Laravel reintenta 3 veces y al final falla. Las pruebas anteriores usaban una prescripción distinta por proceso y no lo veían.
  - **Corrección:** al crear, primero se bloquea la prescripción (la fila de espera es una sola) y después se inserta la dispensación. Al cambiar el orden apareció un segundo caso: dos reintentos con la misma clave pidiendo TODO el saldo; el segundo, al obtener el bloqueo, veía el saldo en 0 y respondía error en vez del replay. Por eso se vuelve a buscar la `Idempotency-Key` justo después de tomar el bloqueo. Hay prueba de concurrencia para cada caso y se comprobó que ambas fallan sin la corrección.
  - El revisor esperaba `PRESCRIPCION_EXCEDIDA=3` con una prescripción de una línea, pero al llegar a 5/5 la prescripción pasa a `COMPLETADA` (S-33) y los demás reciben `PRESCRIPCION_NO_VIGENTE` (S-39). Se dejaron dos pruebas: con una segunda línea pendiente (la prescripción sigue ACTIVA → `EXCEDIDA=3`) y con una sola línea (`NO_VIGENTE=3`).
  - El constraint se lee de `errorInfo[2]` y no de `getMessage()`, porque este último incluye el SQL y los valores; una prueba pone el nombre de otro constraint en un comentario SQL y verifica que no confunde.
  - B2: con `+=` y `save()` se escribe el valor calculado en PHP; si algo se saltara el bloqueo se perderían entregas sin que el CHECK lo note (la prueba lo demuestra: sin el cambio responde 201 y borra 5 unidades entregadas).
  - B4: se mantuvo el formato flexible (no solo UUID) para no romper clientes, pero sin `:` ni `.`.
- Puntos que Daniel debe revisar/entender:
  - Que un INSERT con FK bloquea (en modo compartido) la fila referenciada, y por qué eso + `FOR UPDATE` posterior sobre la misma fila produce deadlock. El nuevo orden está en el docblock de `DispenseService`.
  - Que autorizar bloquea primero la dispensación y luego la prescripción; no choca con "crear" porque crear nunca bloquea una dispensación existente.
  - La doble revisión de idempotencia en `createInTransaction` (paso 1 rápido sin bloqueo, paso 4 con bloqueo) y el `catch` del UNIQUE en `create()` (misma clave con otra prescripción).
  - S-46: el replay devuelve el estado ACTUAL, no el cuerpo original.
- Riesgos o cosas que podrían estar mal:
  - `PostgresError::constraint` depende de que `lc_messages` esté en inglés (por defecto en `postgres:16-alpine`); con otro idioma, el CHECK vuelve a ser 500 (nunca un código de negocio equivocado).
  - El bloqueo de la prescripción serializa TODAS las dispensaciones de una misma prescripción (correcto y barato: son pocas por paciente), pero también las de reintentos con la misma clave.
  - El límite de 120/min usa la caché por defecto; con varias réplicas debe ser compartida.


### 2026-10-08 — Fase 3: traslados, ajustes de inventario, alertas y consulta de kardex
- Agente: backend-dominio (Claude Code)
- Qué generó la IA:
  - `app/Domain/Transfers/TransferStateMachine.php`: mapa explícito de transiciones (RN-07) y `assertCanTransition(from, to)` → 409 `TRANSICION_INVALIDA`. Clase pura; `tests/Unit/TransferStateMachineTest.php` prueba las 49 parejas (desde, hacia) contra una lista escrita a mano.
  - `app/Domain/Transfers/TransferService.php`: `create`, `submit`, `approve`, `dispatch`, `receive`, `cancel`, `resolveDiscrepancy`; DTOs `TransferData`/`TransferItemData`; `TransferQuery` (listados). Excepciones nuevas `TransferNotValidException` (422 `TRASLADO_INVALIDO`) y `AdjustmentNotValidException` (422 `AJUSTE_INVALIDO`); `SegregationOfDutiesException::notRequester`.
  - `app/Domain/Inventory/AdjustmentService.php` (ajustes), `AlertService.php` + `ExpiringLotAlert`/`LowStockAlert` (RN-11), `KardexQuery.php` (consulta paginada).
  - Migración `2026_10_08_211000_add_transfer_resolution_guards.php` (justificación en su docblock): columna `transfer_discrepancies.resolution_action` con CHECK, trigger "quien resuelve es regente" (cierra un riesgo abierto en PROGRESO.md) y CHECK "anular exige motivo". Enum `DiscrepancyResolution`.
  - API `/api/v1`: `TransferController`, `TransferDiscrepancyController`, `InventoryAdjustmentController`, `KardexController`, `AlertController`; 9 Form Requests; `TransferPolicy`, `TransferDiscrepancyPolicy`, `KardexMovementPolicy`; Resources `TransferResource`, `TransferDiscrepancyResource`, `KardexMovementResource`, `AlertsResource`; `Route::pattern` para `transfer` y `discrepancy`. `ApiErrorRenderer` traduce el CHECK de RN-08 (`transfers_approver_differs`) a 403 `SEGREGACION_FUNCIONES` y el del motivo de AJUSTE a 422.
  - Pruebas: `tests/Feature/Transfers/TransferServiceTest.php`, `tests/Feature/Api/TransferApiTest.php`, `tests/Feature/Api/AdjustmentAndKardexApiTest.php`, `tests/Feature/Inventory/{AdjustmentServiceTest,AlertsTest}.php`, `tests/Concurrency/TransferConcurrencyTest.php` (5 pruebas con procesos reales), `tests/Support/TransferScenario.php`; `AuthorizationMatrixTest` ampliada a 20 endpoints × 5 roles; el worker de concurrencia admite `transfer-approve|dispatch|cancel`. Total: 446 pruebas (1651 aserciones), 14 de concurrencia.
  - Docs: `docs/openapi.yaml` (v0.3.0, 10 rutas nuevas), supuestos S-48…S-59, `docs/modelo-datos.md`.
- Qué decisión tomó y por qué:
  - **Flujo transaccional en lenguaje simple.** Todas las operaciones de traslado hacen lo mismo, dentro de una sola transacción que PostgreSQL deshace completa si algo falla:
    1. "Toman el turno" del traslado: `SELECT … FOR UPDATE` sobre la fila del traslado. Si dos personas despachan (o aprueban, o reciben) el mismo traslado al mismo tiempo, la segunda espera aquí.
    2. Ya con el turno, leen el estado y preguntan a la máquina de estados si el paso es válido. La segunda persona ve que el traslado ya cambió (p. ej. ya está `EN_TRANSITO`) y recibe 409; por eso nunca hay doble salida de stock.
    3. Mueven stock solo a través de `StockService` (el único que toca `stocks`), que además escribe el kardex con el saldo resultante. **Despachar**: por cada producto, en orden de `product_id`, bloquea las existencias NO vencidas del origen en orden FEFO, el asignador reparte entre lotes, sale `SALIDA_TRASLADO` por lote y se guarda qué lote salió (`transfer_item_lots`). Si un producto no alcanza, se lanza `STOCK_INSUFICIENTE` y se deshace TODO (nada sale de ningún producto). **Recibir**: por cada lote despachado se anota lo que llegó, entra `ENTRADA_TRASLADO` en el destino y, si faltó algo, se crea una discrepancia `PENDIENTE` de ese lote; el estado final es `RECIBIDO` o `RECIBIDO_PARCIAL`.
    4. Cambian el estado con quién y cuándo, y escriben la transición en `audit_logs` (solo IDs, estados y cantidades).
  - **Orden de bloqueo** igual al de la dispensación: cabecera (traslado) → lotes del traslado por id → existencias por `product_id` y luego `(expires_at, lot_id)`. Como un despacho y una dispensación en la misma bodega bloquean existencias en el mismo orden, no se esperan en círculo; la prueba "sin deadlocks" lo verifica con el contador de deadlocks de PostgreSQL.
  - **Verificación de que las pruebas detectan el problema:** se quitó a mano el `lockForUpdate()` de la cabecera y las pruebas de "4 procesos despachan el MISMO traslado" y "dos regentes aprueban a la vez" fallaron (varios OK a la vez); con el bloqueo pasan.
  - **Discrepancias (S-50):** dos salidas físicas posibles: `REINTEGRAR_ORIGEN` (las unidades nunca salieron: `AJUSTE` + en el origen cuyo motivo cita discrepancia y traslado) o `PERDIDA` (solo se registra; el stock ya había salido). Se agregó columna en vez de meter la acción en el texto para poder exigirla con CHECK y filtrarla.
  - **Lote vencido en tránsito (S-48):** se recibe, porque la recepción registra lo que físicamente llegó; RN-01 sigue impidiendo dispensarlo o trasladarlo de nuevo (hay prueba).
  - **Permisos (S-49, S-52, S-53):** crear, despachar y recibir: auxiliar o regente; solicitar: solo quien creó el borrador; aprobar: regente distinto del solicitante (403 `SEGREGACION_FUNCIONES`); anular: quien lo creó o un regente; resolver discrepancias y ajustar: regente; listados, kardex y alertas: también auditor. Todo en Policies; el servicio repite las reglas de negocio (RN-08, rol regente) como en la Fase 2.
  - **Despacho todo o nada (S-54) y recepción única con todos los lotes (S-55):** menos estados y menos casos raros que explicar.
  - **Ajustes (S-56):** sin tabla propia; el movimiento `AJUSTE` del kardex es el registro y `audit_logs` guarda IDs/cantidades sin el motivo (texto libre).
  - **Alertas (S-51):** incluyen lotes ya vencidos con unidades; el mínimo compara solo stock no vencido y es estricto (`<`).
- Puntos que Daniel debe revisar/entender:
  - Por qué `lockForTransition()` bloquea ANTES de leer el estado: si se leyera sin bloqueo, dos procesos verían `APROBADO` y ambos despacharían (es justo lo que demostró la prueba con el bloqueo quitado).
  - En `receive()` se valida primero contra `RECIBIDO` y al final contra el estado real (`RECIBIDO` o `RECIBIDO_PARCIAL`): ambos salen solo de `EN_TRANSITO`.
  - La matriz de `AuthorizationMatrixTest`: para `submit` y `cancel` el borrador se crea a nombre del usuario que prueba, porque la regla depende de quién lo creó.
  - Que la migración nueva obligó a ajustar datos de `tests/Feature/Database/TransferConstraintsTest.php` (Fase 1): las filas RESUELTA ahora llevan `resolution_action` y las ANULADO motivo.
- Riesgos o cosas que podrían estar mal:
  - No hay alcance por bodega (S-52): cualquier auxiliar puede despachar o recibir cualquier traslado. Si la IPS asigna personal a bodegas, hay que modelarlo y restringir en `TransferPolicy`.
  - `GET /alerts` no está paginado (devuelve todas las alertas); con muchos lotes podría ser pesado. Para la demo basta.
  - La resolución de una discrepancia no cambia el estado del traslado (`RECIBIDO_PARCIAL` es final); el frontend debe mirar el estado de cada discrepancia para saber si quedó algo pendiente.
  - La BD no impide que un traslado salte estados con un UPDATE manual (valida la fila final, no el paso); eso lo garantiza solo `TransferStateMachine` dentro del servicio.
  - El trigger de rol de quien resuelve no verifica `is_active` (igual que en dispensaciones y aprobaciones): lo cubren Policy y servicio.

### 2026-10-11 — Correcciones de la revisión de la Fase 3
- Agente: backend-dominio
- Qué generó la IA:
  - 4 migraciones nuevas (las aplicadas no se editaron):
    - `2026_10_11_100000_add_transfer_transition_guards.php` (A1): trigger `transfers_check_transition` con la tabla de pasos permitidos (la misma de `TransferStateMachine`); trigger que congela una discrepancia `RESUELTA`; trigger que solo deja crear/cambiar/borrar líneas de traslado en `BORRADOR`.
    - `2026_10_11_100100_add_transfer_lot_integrity_guards.php` (M2): RN-01 en BD (no se despacha un lote vencido, "hoy" de Bogotá), `quantity_received` se escribe una sola vez y lo despachado no cambia, `quantity_missing = despachado - recibido`, y `assert_user_role` exige `is_active`.
    - `2026_10_11_100200_add_kardex_balance_guard.php` (M3): un movimiento de kardex cuyo `balance_after` no es la existencia actual se rechaza.
    - `2026_10_11_100300_add_discrepancy_segregation_guard.php` (M5): quien recibió no resuelve la discrepancia.
  - Código: `SegregationOfDutiesException::receiverCannotResolve()` y la validación en `TransferService::resolveDiscrepancy` (M5); `App\Policies\BusinessDenial` + `TransferPolicy::approve` que niega al solicitante con `SEGREGACION_FUNCIONES`, y `ApiErrorRenderer` que lo traduce al JSON uniforme (M6); `App\Domain\Transfers\TransferActions` y `allowed_actions` en `TransferResource` (M6); `GET /api/v1/stocks/in-transit` con `TransferQuery::inTransit`, `IndexInTransitStockRequest`, `InTransitStockResource` (M1); `WarehouseSummaryResource` compartido (L2); actores con la misma forma en listado y detalle con `TransferQuery::LIST_RELATIONS` (L6); filtro `reference_type` + `reference_id` en `GET /kardex` (L5); `limit` y totales en `GET /alerts` (L3).
  - Pruebas: `tests/Feature/Database/{TransferTransitionGuardsTest,TransferLotIntegrityGuardsTest}.php`, `tests/Feature/Api/{InTransitStockApiTest,WarehouseSummaryShapeTest}.php` y casos nuevos en `InventoryConstraintsTest`, `TransferServiceTest`, `TransferApiTest`, `AdjustmentAndKardexApiTest`, `AlertsTest`, `AdjustmentServiceTest` y la matriz de autorización. Total: 506 pruebas (1970 aserciones), 14 de concurrencia.
  - Docs: `docs/openapi.yaml` (stock en tránsito, `allowed_actions`, filtros de kardex, `limit` de alertas, 403 de discrepancias), supuestos S-60…S-67 y ajustes a S-50, S-56, S-57.
- Qué decisión tomó y por qué:
  - **Flujo en lenguaje simple: dos cerrojos para lo mismo.** La aplicación ya validaba todo (máquina de estados con la fila bloqueada, FEFO sin vencidos, segregación). Ahora la base de datos repite las reglas importantes con triggers, como un segundo guardia en la puerta: si alguien hace un `UPDATE` a mano, o un bug se salta el servicio, PostgreSQL rechaza el cambio y deshace toda la transacción. Ejemplos: no se puede pasar un traslado de `BORRADOR` a `RECIBIDO` de un salto; no se puede cambiar lo que se pidió una vez solicitado; no se puede reescribir lo recibido; no se puede "reabrir" una discrepancia resuelta; no se puede escribir en el kardex un saldo que no sea el real.
  - **Kardex con saldo verificado (M3):** el servicio primero actualiza `stocks` (con la fila bloqueada) y luego inserta el movimiento; el trigger compara `balance_after` con la existencia en ese momento. Es `AFTER INSERT` para que los CHECK y FK del movimiento respondan primero con su propio nombre (las pruebas viejas siguen identificando el constraint).
  - **Por qué la tabla de pasos está duplicada (PHP y SQL):** la app necesita el mapa para responder 409 con mensaje claro y para `allowed_actions`; la BD lo necesita para ser defensa independiente. Para que no se desincronicen, una prueba recorre los 49 pares (7 × 7 estados) y exige que la BD acepte exactamente lo que acepta `TransferStateMachine`.
  - **M5 (quien recibió no resuelve):** quien declara el faltante no debe ser quien lo cierra. Se valida en el servicio (403 `SEGREGACION_FUNCIONES`, `reason = ES_RECEPTOR`) y en BD (trigger, porque un CHECK no puede mirar otra tabla).
  - **M6 (`BusinessDenial`):** la Policy ahora niega aprobar al solicitante. Para no perder el código de error específico, la Policy devuelve una respuesta que lleva dentro la excepción de dominio y el renderizador la convierte en el mismo JSON de siempre. `Gate::allows()` devuelve `false` sin lanzar, así `allowed_actions` puede preguntarle a la Policy por cada acción sin consultas extra (solo mira columnas del traslado).
  - **M1:** "en tránsito" = lotes despachados de traslados `EN_TRANSITO` sin recepción. Lo que faltó en una recepción ya no es tránsito: es discrepancia. Se probó que origen + tránsito = total inicial.
  - **M4 (ajustes no idempotentes):** no se implementó `Idempotency-Key`; queda como supuesto S-61 (operación manual, poco frecuente, de regente; el frontend bloqueará el doble clic).
  - **L4:** se permite el AJUSTE positivo sobre lote vencido: registrar unidades que existen no es dispensarlas; RN-01 las sigue excluyendo de FEFO (hay prueba) y quedan en alertas para darlas de baja.
  - **L3:** límite por lista (100, máximo 500) con el total real, en vez de paginación: las alertas son un tablero de lo más urgente.
- Puntos que Daniel debe revisar/entender:
  - En los triggers, la diferencia entre `BEFORE` (puede frenar antes de escribir; lo usamos para pasos de estado, líneas en BORRADOR, lotes vencidos) y `AFTER` (corre después de los CHECK; lo usamos para el saldo del kardex y el faltante de discrepancias).
  - Que el trigger de transición permite "mismo estado → mismo estado" (actualizar notas o `updated_at` no es una transición), mientras `TransferStateMachine` lo rechaza (aprobar dos veces es un error de negocio). La prueba de 49 pares lo contempla.
  - Por qué se tocaron pruebas antiguas de esquema: algunas insertaban líneas en traslados ya despachados o reescribían `quantity_received` (ahora prohibido); se ajustaron para seguir el camino válido (`advanceTransfer`). Una prueba de "anular en tránsito" ahora la frena primero el trigger de transición (el CHECK sigue cubriendo el INSERT).
  - `allowed_actions` es informativo: el endpoint vuelve a autorizar y el servicio vuelve a validar con la fila bloqueada.
- Riesgos o cosas que podrían estar mal:
  - Un `INSERT` directo de un traslado en estado avanzado no se bloquea (S-64); solo `UPDATE` de estado. La app siempre crea en `BORRADOR`.
  - La zona `America/Bogota` está fija en el trigger de lotes vencidos (S-65); si se cambia `BUSINESS_TIMEZONE`, hay que cambiar también el trigger. El reloj que manda es el de PostgreSQL; en la frontera de medianoche, si el reloj del contenedor PHP y el de la BD difieren, el servicio y el trigger podrían discrepar por segundos (el trigger rechazaría y la transacción se deshace).
  - Los triggers que se lanzan desde la BD responden 500 genérico si alguna vez se disparan (no tienen mapeo en `ApiErrorRenderer`); en la práctica el servicio valida antes con su error específico.
  - `TransferDiscrepancyPolicy::resolve` sigue mirando solo el rol; la regla "no el receptor" vive en el servicio y en BD (no hay `allowed_actions` para discrepancias).
  - Los ajustes siguen sin idempotencia (S-61).

### 2026-10-11 — Refuerzo de pruebas de la Fase 3 (traslados, discrepancias, alertas, kardex)
- Agente: qa-tester
- Qué generó la IA:
  - Concurrencia real (procesos PHP separados + barrera `pg_advisory_lock`, sin `RefreshDatabase`) en `tests/Concurrency/TransferConcurrencyTest.php`: 4 procesos reciben a la vez el mismo traslado con cantidades distintas; 4 regentes resuelven a la vez la misma discrepancia con `REINTEGRAR_ORIGEN`; `REINTEGRAR_ORIGEN` vs `PERDIDA` a la vez; y tres pruebas "control" con los workers sin `FOR UPDATE` (doble recepción, doble reintegro, doble despacho). El worker (`tests/Concurrency/bin/dispense-worker.php`) ganó los modos `transfer-receive` y `discrepancy-resolve`, la pausa del modo `unsafe` tras leer tablas de traslados y el resultado `UNIQUE_VIOLATION` (23505).
  - Una corrección de BD: migración `2026_10_11_100400_reject_same_status_transfer_update.php` (ver abajo) + prueba de esquema en `TransferTransitionGuardsTest` y ajuste de la prueba de 49 pares; S-64 actualizado.
  - `tests/Feature/Transfers/TransferConservationTest.php`: flujo por HTTP con recepción parcial y las dos resoluciones; tras cada paso, stock inicial = origen + destino + en tránsito (`GET /stocks/in-transit`) + faltante pendiente + pérdida declarada, y el kardex cuadra; inmutabilidad de `SALIDA_TRASLADO`, `ENTRADA_TRASLADO` y `AJUSTE` de reintegro.
  - `TransferApiTest`: 12 acciones fuera de su estado por HTTP → 409 `TRANSICION_INVALIDA` con el formato uniforme y sin cambios; 8 filas más de `allowed_actions`.
  - `AlertsTest`: borde con umbral configurado (30 sí, 31 no), fechas relativas con `travel()` (91 → 90 → vence hoy → vencido y deja de cubrir el mínimo; mínimo 0 nunca alerta), "hoy" de Bogotá frente a UTC.
  - `TransferServiceTest` (S-59 controlado sin autorización extra, S-52 actores distintos) y `AdjustmentAndKardexApiTest` (S-61: dos ajustes iguales = dos movimientos).
- Qué decisión tomó y por qué:
  - **Bug encontrado (defensa en profundidad):** la prueba "control" de doble recepción sin `FOR UPDATE` terminó con 4 recepciones exitosas y el destino con el cuádruple. Causa: `transfers_check_transition` aceptaba `RECIBIDO -> RECIBIDO` como "sin cambio"; `quantity_received` se reescribía con el mismo valor (permitido) y con recepción total no hay discrepancias que choquen con su UNIQUE. En producción no ocurre (el `FOR UPDATE` serializa y la prueba normal da 1 OK + 3 409), pero la segunda defensa no existía para este caso. Arreglo: el trigger ahora acepta exactamente la tabla de `TransferStateMachine`, incluida la diagonal (quedarse en el mismo estado es inválido). Como el trigger es `BEFORE UPDATE OF status` y Eloquent solo envía columnas que cambiaron, actualizar notas sigue funcionando (hay prueba). Esto **invierte** lo que decía la entrada anterior ("el trigger permite mismo estado → mismo estado").
  - La doble salida por despacho sin bloqueo ya la frenaba el UNIQUE `(transfer_item_id, lot_id)`; el doble reintegro, el trigger que congela la discrepancia resuelta. Se dejaron como pruebas de control.
  - Las pruebas de concurrencia con payloads distintos por proceso verifican que lo guardado es exactamente lo del ganador (sin mezclas), no solo los conteos.
- Puntos que Daniel debe revisar/entender:
  - Por qué una prueba "control" debe poder fallar: si la carrera no ocurre (todos ven el estado ya cambiado), solo habría 409 y la prueba no demostraría nada; por eso exige al menos un `CHECK_VIOLATION`/`UNIQUE_VIOLATION`.
  - El cambio de comportamiento del trigger (diagonal rechazada) y que la migración nueva no edita la anterior.
  - En la corrida de concurrencia el número de aserciones varía (165–167) porque dos pruebas tienen ramas según quién gane (despachar vs anular, reintegrar vs pérdida); el número de pruebas es fijo.
- Riesgos o cosas que podrían estar mal:
  - Un `UPDATE` manual que incluya `status` con el mismo valor (p. ej. un script que reescribe la fila completa) ahora falla; hay que omitir `status` del `SET`.
  - `quantity_received` sigue aceptando reescribir el mismo valor (prueba M2 existente); la protección contra la doble recepción total recae en el trigger de transición, que corre al final de la transacción de recepción (deshace todo).
  - Las pruebas "control" dependen de pausas (300 ms) para forzar la carrera; en una máquina muy lenta podrían dar solo 409 y fallar por "no se reprodujo la carrera" (no por un bug). En 5 corridas seguidas no pasó.

### 2026-10-11 — Fase 4: logs JSON sin datos personales y X-Correlation-Id
- Agente: seguridad-privacidad
- Qué generó la IA:
  - `app/Logging/RedactSensitiveData.php` (procesador Monolog) y `app/Logging/UseRedaction.php` (tap que lo agrega a los canales `json`, `stderr`, `single`, `daily`, `monthly`).
  - Canal `json` en `config/logging.php` (JsonFormatter a stderr) como predeterminado; `LOG_CHANNEL=json` en `docker-compose.yml`, `.env.example` y `backend/.env.example`; `mask_bindings_in_exception_messages` en la conexión pgsql.
  - `app/Http/Middleware/AssignCorrelationId.php` registrado al inicio del grupo `api` en `bootstrap/app.php`.
  - `tests/Support/LogCapture.php` (usa el canal json REAL, solo cambia stderr por un `TestHandler`) y `tests/Feature/Security/LoggingPrivacyTest.php`.
- Qué decisión tomó y por qué:
  - Las excepciones se convierten en un resumen propio en vez de dejar que Monolog serialice el objeto: el mensaje de `QueryException` trae el SQL con valores y la `PDOException` anidada trae la línea `DETAIL` de PostgreSQL ("Key (email)=(…)"). El resumen conserva el SQL con `?`, que es lo útil para depurar.
  - Redacción por **clave** (en cualquier nivel) y por **contenido** (correos; en el mensaje, 6+ dígitos). Se usa `tap` y no `processors` porque funciona con cualquier driver; el procesador de Context de Laravel se agrega después y Monolog ejecuta primero el último agregado, así que la redacción corre al final.
  - El correlation id del cliente se acepta solo con formato estricto (`\A[A-Za-z0-9-]{1,64}\z`); se usa `\z` y no `$` porque `$` acepta un salto de línea final.
  - La IP del usuario va a `Context` (y por tanto a cada línea de log); se considera dato operativo de seguridad del personal, no del paciente (S-73).
- Puntos que Daniel debe revisar/entender:
  - Cómo llega `correlation_id` a las filas: el middleware lo pone en `Context`; `DispenseService`, `KardexService` y `AuditLogger` ya lo leían (antes quedaba `null`). Hay prueba que lo verifica en dispensación, kardex y `audit_logs`.
  - Por qué el mensaje del log queda solo con la primera línea y sin "(Connection: …)".
  - Para ver un ejemplo real, reportar una excepción desde `php artisan tinker` con `LOG_CHANNEL=json` (se probó con un INSERT duplicado en `users`: sale `users_email_unique` y el SQL con `?`, sin el correo).
- Riesgos o cosas que podrían estar mal:
  - Es una red de seguridad, no una garantía: un dato personal en una clave con nombre inocente (p. ej. `nota`) solo se limpia si es un correo. La regla sigue siendo no pasar datos personales al log.
  - El servidor embebido de PHP imprime su propia línea de acceso (texto, no JSON) en stderr; con la búsqueda por POST no lleva el documento, pero en la Fase 7 (php-fpm + Nginx) hay que revisar el formato del access log de Nginx.
  - Números de 6+ dígitos dentro del texto del mensaje (también un id grande) salen como `[NUM]`; en el contexto estructurado se conservan.

### 2026-10-11 — Fase 4: pacientes con enmascarado por rol y bitácora de accesos (RN-10)
- Agente: seguridad-privacidad
- Qué generó la IA:
  - `app/Domain/Patients/PatientMasker.php` (funciones puras), `PatientAccessLogger.php`, `PatientFinder.php`; `app/Policies/PatientPolicy.php` (`viewAny`, `view`, `viewUnmasked`); `PatientResource`, `PrescriptionResource`; `app/Domain/Prescriptions/PrescriptionQuery.php`; `PatientController`, `SearchPatientRequest`; `Prescription::isCurrent()`.
  - Migración `2026_10_11_110000_add_masked_to_patient_access_logs.php`: columna `masked` y CHECK de acciones.
  - Endpoints: `POST /patients/search`, `GET /patients/{id}`, `GET /patients/{id}/prescriptions`.
  - Pruebas: `tests/Unit/PatientMaskerTest.php`, `tests/Feature/Api/PatientApiTest.php`; ajuste de `PrivacyConstraintsTest` (usaba la acción `VER`, ahora inválida por el CHECK).
- Qué decisión tomó y por qué:
  - **Búsqueda por POST y no por GET** (desvío respecto a lo pedido): con GET el documento queda en la URL, que se imprime en el log de acceso del servidor (el embebido de PHP lo hace hoy) y de Nginx, en el historial del navegador y en `Referer` (S-69).
  - Admin no lee pacientes (S-68). El auditor recibe las mismas claves con valores enmascarados y `masked: true`, para que el frontend no cambie de forma.
  - Quién ve sin máscara lo decide la Policy (`viewUnmasked`), que usan tanto el Resource como el logger: la columna `masked` dice exactamente lo que vio el usuario.
  - El acceso se registra después de `Gate::authorize` y antes de responder: un 403 no deja fila (probado en la matriz para todos los endpoints).
  - Búsqueda fallida → `audit_logs` `patient.search_not_found` sin el número (la bitácora de accesos exige `patient_id`).
  - Reservado/disponible de cada línea con `withSum` sobre dispensaciones `PENDIENTE_AUTORIZACION`: la misma cuenta que `DispenseService`.
- Puntos que Daniel debe revisar/entender:
  - `PatientResource` enmascara por defecto si no hay usuario o no tiene permiso (valor seguro).
  - La búsqueda es exacta por HMAC (`DocumentHasher`): no se pueden buscar "parecidos" sin descifrar todo, y eso es intencional.
  - El documento sigue cifrado en la BD; hay prueba que lee la fila cruda tras usar la API.
- Riesgos o cosas que podrían estar mal:
  - Enumeración: el binding de ruta responde 404 a un id inexistente antes de la Policy, así que un admin distingue ids existentes (403) de inexistentes (404). No revela datos.
  - Sin asignación médico ↔ paciente: cualquier médico ve a cualquier paciente (queda en la bitácora).
  - La lista de prescripciones se limita a 50; no hay paginación.

### 2026-10-11 — Fase 4: creación de prescripciones (médico)
- Agente: seguridad-privacidad
- Qué generó la IA: `PrescriptionPolicy`, `StorePrescriptionRequest`, `PrescriptionService`, `PrescriptionController`, ruta `POST /api/v1/prescriptions`, `tests/Feature/Api/PrescriptionApiTest.php` (incluye dispensar de inmediato contra la prescripción creada).
- Qué decisión tomó y por qué: vigencia desde **mañana** (no "solo hoy") por el CHECK `valid_until >= issued_at::date` con `issued_at` en UTC (S-70); número `RX-` + ULID para no necesitar un consecutivo con bloqueo; auditoría con IDs y conteos, sin las indicaciones (texto libre).
- Puntos que Daniel debe revisar/entender: la validación de fechas usa `BusinessDate::today()` (Bogotá) en las reglas `after`/`before_or_equal`.
- Riesgos o cosas que podrían estar mal: no hay edición ni anulación de prescripciones por API; el catálogo no tiene `is_active`, así que no se distingue un producto descatalogado.

### 2026-10-11 — Fase 4: login fallido auditado y administración de usuarios
- Agente: seguridad-privacidad
- Qué generó la IA:
  - `LoginService::recordFailure()` y `LoginService::emailHash()`; `DocumentHasher::key()` pasó a público para reutilizar la clave HMAC.
  - `UserPolicy`, `UserManagementService`, `SelfLockoutException`, `StoreUserRequest`, `UpdateUserRequest`, `UserController`, rutas `GET/POST /users` y `PATCH /users/{id}`; `UserResource` ahora incluye `is_active`.
  - Pruebas: `tests/Feature/Security/LoginAuditTest.php`, `tests/Feature/Api/UserAdminApiTest.php`.
- Qué decisión tomó y por qué:
  - El correo del login fallido se guarda como HMAC con prefijo `login-email:` (S-71): agrupa intentos contra la misma cuenta (sin importar mayúsculas) y no es reversible sin la clave. El motivo (`USUARIO_DESCONOCIDO` / `CLAVE_INCORRECTA` / `USUARIO_INACTIVO`) es solo interno.
  - Revocar tokens también al **cambiar el rol**, no solo al desactivar: la sesión se emitió con los permisos anteriores.
  - Autobloqueo (desactivarse o quitarse admin) → 422 `OPERACION_NO_PERMITIDA` desde el servicio (es regla de negocio, no de rol).
- Puntos que Daniel debe revisar/entender:
  - `audit_logs.metadata` es `jsonb`: PostgreSQL reordena las claves, por eso las pruebas comparan con `toEqual` y no con `toBe`.
  - El `PATCH` ignora `email` y `password` (no están en las reglas) en vez de rechazarlos.
- Riesgos o cosas que podrían estar mal:
  - Una fila de auditoría por intento fallido; con el límite de 5/min por correo + IP y 20/min por IP el volumen está acotado, pero un ataque distribuido puede llenar `audit_logs` (no hay retención definida).
  - No hay restablecimiento de contraseña ni cambio de correo.
  - Dos admins que se desactivan mutuamente al mismo tiempo podrían dejar el sistema sin admins activos (no se bloquea la fila); muy improbable, se recupera por seeder o BD.

### 2026-10-11 — Fase 4: matriz de autorización ampliada y errores de triggers
- Agente: seguridad-privacidad
- Qué generó la IA:
  - `AuthorizationMatrixTest`: 7 endpoints nuevos × 5 roles; prueba "sin autenticar → 401" para los 28 endpoints; un 403 no deja filas en `patient_access_logs`; prueba que falla si alguna ruta `/api/v1` (salvo login/logout) queda fuera de la matriz.
  - `PostgresError::triggerGuard()` y mapeo en `ApiErrorRenderer` de los triggers de traslados a 409 `TRANSICION_INVALIDA` / 403 `SEGREGACION_FUNCIONES` / 422 `LOTE_VENCIDO` (S-76), con pruebas en `DatabaseErrorMappingTest`.
  - Revisión: `route:list` (todas con `auth:sanctum` salvo login, verificado por prueba), sin `dd(`/`dump(`, sin secretos en código, sin SQL crudo con interpolación de variables.
- Qué decisión tomó y por qué: solo se mapean los triggers que significan "el traslado cambió por otro camino"; los que indican un bug (saldo de kardex, faltante incoherente) siguen como 500, igual que la decisión de la Fase 2 para CHECK desconocidos.
- Puntos que Daniel debe revisar/entender: el prefijo `<guard>:` se lee de la primera línea del mensaje del servidor (`errorInfo[2]`), no del SQL, igual que `PostgresError::constraint()`.
- Riesgos o cosas que podrían estar mal:
  - Si se renombra un trigger o cambia el prefijo del mensaje, el mapeo vuelve a 500 sin avisar (hay prueba con el trigger de transición real).
  - El asistente IA (Fase 6) aún no existe: queda pendiente verificar que ninguna herramienta devuelva datos de pacientes.

### 2026-10-11 — Fase 4: hallazgos de la prueba manual en el contenedor
- Agente: seguridad-privacidad
- Qué generó la IA: `redirectGuestsTo(fn () => null)` y `dontReport(BusinessRuleException::class)` en `bootstrap/app.php`, con dos pruebas en `LoggingPrivacyTest`.
- Qué decisión tomó y por qué:
  - Al recrear el contenedor con logs JSON, `curl /api/v1/auth/me` (sin token y sin `Accept: application/json`) respondió **500**: el middleware `Authenticate` intentaba redirigir a la ruta `login`, que no existe en una API solo con token. Venía de antes (las pruebas siempre usan `getJson`). Ahora es 401 `NO_AUTENTICADO`.
  - Cada error de negocio esperado (credenciales inválidas, stock insuficiente…) se escribía en el log como `error` con traza. Son respuestas normales, no fallas; lo sensible ya queda en `audit_logs`.
- Puntos que Daniel debe revisar/entender: los 500 reales (bugs, BD caída) siguen registrándose; solo se excluyen las subclases de `BusinessRuleException`.
- Riesgos o cosas que podrían estar mal: si alguien quiere métricas de errores de negocio desde los logs, ya no estarán ahí (habría que sacarlas de `audit_logs` o agregar un contador).

### 2026-10-11 — Fase 4: corrección de los hallazgos del revisor-senior
- Agente: seguridad-privacidad
- Qué generó la IA:
  - **A1** `LoginService::dummyHash()`: constante bcrypt de costo 12 (de un valor aleatorio descartado) y, con otro costo, `Cache::rememberForever` con el costo en la clave. Pruebas en `AuthHardeningTest` con un proxy de Mockery sobre el `HashManager` real (`shouldNotReceive('make')` con costo 12; `make` una sola vez en 3 logins con costo 5).
  - **A2** migración `2026_10_11_120000_add_must_change_password_to_users`, `EnsurePasswordIsChanged`, `PasswordChangeRequiredException` (403 `CAMBIO_CLAVE_REQUERIDO`), `ChangePasswordRequest`, `POST /auth/password` (`AuthController::changePassword` → `UserManagementService::changeOwnPassword`), restablecer por `PATCH /users/{id}` con `password`, limitador `password` (5/min), `must_change_password` en `UserResource`, seeders y factory en `false`. Pruebas en `tests/Feature/Security/PasswordChangeTest.php`, `UserAdminApiTest` y la matriz.
  - **B3** `App\Http\Requests\PasswordRules` (12+, letras y números, ≤ 72 bytes con `strlen`) usado en crear, restablecer y cambiar.
  - **M1** tap `UseRedaction` en slack, papertrail, syslog y errorlog; prueba que recorre `config('logging.channels')`.
  - **M2** `instructions` y `rejection_reason` en `null` para quien no tiene `viewUnmasked`.
  - **M3** migración `2026_10_11_120100_add_dispensation_view_access_action` y registro `patient.dispensation_view` en `DispensationController::show`; pruebas de 403/404 sin registro (y **B6** para `GET /patients/{id}` inexistente).
  - **B7** limitador `patient-search` (30/min por usuario). **B4** `users_email_unique` → 422 `VALIDACION`. **B1** correlation id siempre del servidor; el del cliente va como `client_correlation_id` y `X-Client-Correlation-Id`.
  - **M4** `docs/openapi.yaml` 0.4.0; validado con PyYAML en el host (no hay `symfony/yaml` en `vendor` y no se agregó una dependencia solo para esto): parsea, los 36 `$ref` resuelven y las 29 rutas de `route:list` están documentadas.
- Qué decisión tomó y por qué:
  - A2 en lo mínimo defendible: la clave temporal no evita que el admin entre UNA vez como el usuario creado y la cambie, pero sí que la use indefinidamente sin dejar `user.password_changed`. El riesgo residual queda en S-77.
  - Restablecer va por el mismo `PATCH /users/{id}` (campo `password`) en vez de un endpoint nuevo, con acción de auditoría propia `user.password_reset`.
  - `emergency` queda sin tap: Laravel lo construye sin taps y solo lo usa si un canal no se puede crear (documentado en S-73).
  - Al mapear `users_email_unique` a 422 (B4), la prueba "un 500 por QueryException no deja PII" dejó de producir un 500; se cambió a un paciente duplicado, que además trae nombres, teléfono y documento en el INSERT (prueba más fuerte). La corrección va en el commit de B1 porque el commit de B4 la dejó roja (se detectó al correr las pruebas afectadas).
- Puntos que Daniel debe revisar/entender:
  - Por qué el hash de relleno debe tener el **mismo costo** que las contraseñas reales y por qué la estática anterior no servía con el servidor embebido de PHP (cada petición es un proceso nuevo).
  - `EnsurePasswordIsChanged` va en el grupo autenticado después de `auth:sanctum`; las rutas permitidas se identifican por nombre (`auth.password`, `auth.me`, `auth.logout`).
  - Los textos libres ocultos usan la misma Policy (`viewUnmasked`) que el enmascarado del paciente.
- Riesgos o cosas que podrían estar mal:
  - El admin sigue siendo de confianza (S-77).
  - La medición por HTTP en el servidor embebido tiene picos en la primera petición de cada worker (arranque del proceso), sin relación con el hash; en régimen estable correo existente e inexistente rondan 0,33 s.
  - El commit `7cda64c` (B4) deja una prueba en rojo hasta `768fd99` (B1); si se hace `git bisect`, tenerlo en cuenta.

### 2026-10-11 — Fase 5: frontend React
- Agente: frontend-react
- Qué generó la IA:
  - `src/api/`: cliente `fetch` único (`client.ts`) con Bearer, `ApiError` normalizado (errores de la API, respuestas no JSON y fallos de red), `X-Correlation-Id` y manejo de 401 (logout) y 403 `CAMBIO_CLAVE_REQUERIDO`; mapa código → mensaje en español (`errors.ts`); tipos de la API v0.4.0; token en `sessionStorage`.
  - `src/auth/`: `AuthProvider` (login, `/auth/me`, logout que vacía la caché), cambio de clave obligatorio, `permissions.ts` (qué muestra cada rol) y rutas protegidas.
  - `src/features/`: dispensación (búsqueda por documento, prescripciones vigentes, vista previa FEFO con `GET /dispensations/preview`, confirmación idempotente, pantalla de autorizaciones), pacientes, traslados (lista, creación, acciones por `allowed_actions`, recepción por lote, discrepancias), inventario (existencias resaltadas, alertas, tránsito, ajustes), kardex y usuarios.
  - 56 pruebas Vitest + Testing Library con `fetch` simulado (`src/test/http.ts`), sin MSW para no agregar dependencias.
- Qué decisión tomó y por qué:
  - No se tocó `vite.config.ts` ni `config/cors.php`: el proxy `/api` → 8080 ya existía y CORS ya permite `http://localhost:5173` con los headers necesarios (verificado con una prueba de humo real).
  - Catálogo derivado de `/stocks` y autorizaciones por número porque faltan endpoints (S-80, S-81); se reporta en vez de crearlos.
  - Idempotency-Key ligada al contenido del formulario (S-83) y guardia síncrona contra doble clic además de `isPending`: TanStack Query notifica `isPending` en el siguiente render.
  - Se renombró `dispenseForm.ts` a `dispenseLines.ts`: en Windows chocaba con `DispenseForm.tsx` (solo difieren en mayúsculas).
- Puntos que Daniel debe revisar/entender:
  - `useCreateDispensation` + `createIdempotencyTracker`: cuándo se reutiliza y cuándo se renueva la clave.
  - Por qué `allowed_actions` decide los botones de traslados y `permissions.ts` solo oculta secciones (la autorización real es del backend).
  - `RequireAuth` y los manejadores de sesión registrados en el cliente (`setSessionHandlers`).
  - La prueba de doble clic se validó quitando la protección: falla si se elimina.
- Riesgos o cosas que podrían estar mal:
  - Sin catálogo real, un producto sin existencias no se puede elegir en traslados; el médico no tiene pantalla para crear prescripciones.
  - La vista previa FEFO puede quedar desactualizada entre la consulta y la confirmación (el backend reasigna).
  - No se probó en navegador real con clics (solo pruebas jsdom y humo HTTP a través del proxy de Vite).

### 2026-10-11 — Fase 5: endpoints de catálogo y listado de dispensaciones
- Agente: backend-dominio
- Qué generó la IA:
  - `GET /api/v1/warehouses` y `GET /api/v1/products` (`CatalogController` → `IndexWarehouseRequest`/`IndexProductRequest` → `WarehousePolicy`/`ProductPolicy` → `Domain/Catalog/CatalogQuery` → `WarehouseSummaryResource`/`ProductResource`).
  - `GET /api/v1/dispensations` (`DispensationController::index` → `IndexDispensationRequest` → `DispensationPolicy::viewAny` → `Domain/Dispensing/DispensationQuery` → `DispensationSummaryResource`).
  - Pruebas: `tests/Feature/Api/CatalogApiTest.php`, `tests/Feature/Api/DispensationIndexApiTest.php` y tres filas nuevas en `AuthorizationMatrixTest` (5 roles + sin autenticar). `docs/openapi.yaml` 0.5.0. Supuestos S-86 a S-88.
- Qué decisión tomó y por qué:
  - Son consultas de solo lectura: no hay transacción ni bloqueos; el flujo es "valida filtros → autoriza → consulta paginada con eager loading → recurso". No mueven stock ni escriben kardex.
  - El listado de dispensaciones usa un recurso **resumido** propio (no `DispensationResource`): solo `patient_id`, sin lotes ni motivo de rechazo. Así no expone datos personales y no necesita una fila de bitácora por paciente; el detalle sigue registrando el acceso (S-87).
  - Catálogos para todos los roles: no tienen datos personales y el médico necesita productos para prescribir (S-86). Bodegas sin filtro de "activas" porque el modelo no tiene esa columna.
  - La búsqueda de productos escapa `%`, `_` y `\` antes del `ILIKE` para que el texto del usuario se busque literal.
- Puntos que Daniel debe revisar/entender:
  - Por qué el listado no registra en `patient_access_logs` y el detalle sí (qué datos expone cada uno).
  - Que `DispensationPolicy::viewAny` tiene los mismos roles que `view` y que la matriz de autorización falla si una ruta nueva no se agrega.
  - El orden por `created_at DESC, id DESC` desempata filas creadas en el mismo instante.
- Riesgos o cosas que podrían estar mal:
  - El `patient_id` del listado es un identificador interno; quien también puede leer pacientes puede cruzarlo (ese cruce sí queda en bitácora).
  - `ILIKE '%texto%'` no usa índice: con un catálogo muy grande convendría un índice trigram (`pg_trgm`). Para el tamaño de una IPS no es necesario.
  - El frontend todavía usa S-80/S-81; hay que migrar las pantallas (S-88).

### 2026-10-11 — Fase 5: frontend con catálogos, prescripción del médico y bandeja de autorizaciones
- Agente: frontend-react
- Qué generó la IA:
  - `features/catalog/useCatalog.ts` ahora usa `GET /warehouses` y `GET /products` (S-86) en vez de derivar de `/stocks`; `useProductSearch(q)` para prescribir.
  - Pantalla del médico dentro de Pacientes: `PrescriptionForm.tsx` + `prescriptionLines.ts` (cuerpo de `POST /prescriptions`, fecha entre mañana y hoy + 365, cantidades 1–10000, indicaciones ≤ 255 en `null` si vacías), búsqueda con `useDebouncedValue` (300 ms), anti doble clic y recarga de la lista de prescripciones. Permiso `createPrescription` (solo médico).
  - `/autorizaciones` como bandeja: `GET /dispensations?status=PENDIENTE_AUTORIZACION` con filtro por bodega, `DecisionControls.tsx` (autorizar / rechazar con motivo) en cada fila y en el detalle `?id=`; se eliminó la búsqueda por número.
  - Pruebas nuevas: catálogo desde los nuevos endpoints, prescripción (envío, validación, doble clic, error 422, auditor sin formulario) y bandeja (lista, filtro, autorizar con doble clic, creador deshabilitado, rechazo con motivo, SEGREGACION_FUNCIONES). 69 pruebas en total.
  - S-80 y S-81 marcados como superados (S-86, S-87/S-88).
- Qué decisión tomó y por qué:
  - La prescripción vive en la pantalla Pacientes (el médico ya la tenía en el menú) en vez de una sección nueva: buscar paciente → ver prescripciones → prescribir es un solo flujo.
  - Los botones de decisión se deshabilitan (no se ocultan) para el creador, con el motivo visible; el backend responde igual `SEGREGACION_FUNCIONES` si se fuerza.
  - El detalle sigue usando `GET /dispensations/{id}` (deja bitácora, S-75); la bandeja solo usa el resumen sin datos del paciente (S-87).
- Puntos que Daniel debe revisar/entender:
  - `useAfterDecision` en `dispensingApi.ts`: qué cachés se actualizan tras autorizar/rechazar.
  - `validUntilRange` calcula mañana/hoy + 365 con la fecha de Bogotá del navegador; la API vuelve a validar con su reloj.
- Riesgos o cosas que podrían estar mal:
  - La búsqueda de productos pide como máximo 20 resultados; con un catálogo grande hay que afinar `q`.
  - La prueba de humo de la bandeja devolvió lista vacía (no hay pendientes en la BD de demo); el flujo con datos se cubrió solo con pruebas simuladas.

### 2026-10-11 — Fase 5: revisión senior (M2, L7, L8) — `allowed_actions` en dispensaciones
- Agente: backend-dominio
- Qué generó la IA:
  - `DispensationPolicy`: `decide` se separó en `authorize` y `reject`. Ambas exigen regente activo **y distinto del creador**; si es el creador niegan con `BusinessDenial` (`SEGREGACION_FUNCIONES`, `reason: MISMO_USUARIO`), igual que `TransferPolicy::approve`. `RejectDispensationRequest::authorize()` devuelve la respuesta de la Policy (no un bool) para conservar ese código.
  - `App\Domain\Dispensing\DispensationActions` (gemelo de `TransferActions`): si la dispensación está `PENDIENTE_AUTORIZACION`, devuelve las acciones que la Policy permite; si no, `[]`.
  - `allowed_actions` en `DispensationResource` y `DispensationSummaryResource` (ambos usan `DispensationResource::allowedActions()`).
  - Se quitó `patient_id` de `DispensationSummaryResource` (S-87 actualizado). El detalle lo conserva.
  - Migración con índice `(warehouse_id, status, created_at)` en `dispensations`: existía `(status, created_at)` pero ninguno empezaba por `warehouse_id`.
  - Pruebas: acciones por usuario y estado en listado y detalle (creador regente: nada; otro regente: `authorize`, `reject`; auxiliar y auditor: nada; COMPLETADA/RECHAZADA: nada), rechazo del creador → 403 `SEGREGACION_FUNCIONES`, y conteo de consultas del listado (6 vs 18 filas: misma cantidad, ≤ 9).
  - OpenAPI 0.5.1.
- Qué decisión tomó y por qué:
  - El **estado** no va en la Policy (igual que en traslados): la Policy responde "¿esta persona puede decidir esta dispensación?" y el servicio responde "¿se puede decidir ahora?" (`TRANSICION_INVALIDA`). `DispensationActions` combina las dos para el frontend.
  - Efecto colateral aceptado: el creador que intenta autorizar una dispensación ya cerrada ahora recibe 403 `SEGREGACION_FUNCIONES` (antes 409 `TRANSICION_INVALIDA`), porque la Policy se evalúa antes que el servicio.
  - Flujo en lenguaje simple: al listar o ver una dispensación, el servidor mira dos cosas sin ir a la BD: si está esperando autorización y si quien pregunta es un regente activo que no la creó. Si ambas se cumplen, le dice al frontend "puedes autorizar o rechazar". Cuando el regente pulsa el botón, el endpoint vuelve a preguntar a la Policy y el servicio bloquea la fila y valida todo otra vez (y la BD también).
- Puntos que Daniel debe revisar/entender:
  - Por qué la prueba de N+1 compara 6 vs 18 filas y no 3 vs 12: si ninguna fila tiene `authorized_by`, Eloquent no lanza la consulta de `authorizer` y la cuenta cambia por eso, no por N+1.
  - `allowed_actions` es una pista de interfaz; la autorización real sigue en el endpoint, el servicio y el CHECK de la BD.
- Riesgos o cosas que podrían estar mal:
  - El frontend (`api/types.ts`, `DecisionControls`) aún no usa `allowed_actions` y `DispensationSummary` sigue declarando `patient_id`; hay que ajustar el tipo y pasar a decidir los botones con `allowed_actions`.

### 2026-10-11 — Fase 5: corrección de los hallazgos del revisor-senior (frontend)
- Agente: frontend-react
- Qué generó la IA:
  - **A1** `errors.ts`: `PRESCRIPCION_NO_VIGENTE` con mensaje por `details.reason` (VENCIDA con fecha, COMPLETADA, ANULADA, PACIENTE_NO_CORRESPONDE, LINEA_NO_CORRESPONDE); `TRASLADO_INVALIDO`, `AJUSTE_INVALIDO` y códigos sin texto propio usan el mensaje del servidor (corto, de una línea); `ERROR_INTERNO` sigue genérico.
  - **M1** la dispensación no preselecciona bodega. **M2** `DecisionControls` habilita con `allowed_actions`; `DispensationSummary` sin `patient_id`. **M3** `newUuid()` con respaldo `crypto.getRandomValues` (UUID v4) si no hay `randomUUID`. **M4** `TransferActions` con key solo por id y error visible aunque no queden acciones. **M6** umbral de alerta desde `alerts.expiry_days` (nivel `withinAlert`). **L1** `/auth/me` sin red no cierra sesión: pantalla con Reintentar. **L2** usuarios: fila con su propia mutación, rol con Guardar/Descartar, reset limpiado solo al éxito. **L10** S-88 corregido. **L11** cabecera de `types.ts`.
  - **M5** pruebas de kardex, creación de traslado, discrepancias y reintentos de dispensación (SIN_CONEXION reutiliza la clave; IDEMPOTENCIA_CONFLICTO la renueva). 104 pruebas en total.
- Qué decisión tomó y por qué:
  - Las pruebas de doble clic de M5 destaparon dos casos reales: tras resolver una discrepancia o decidir una dispensación, el formulario seguía activo hasta recargar la lista y un segundo clic rápido reenviaba (la API respondería 409). Ahora el control se retira al tener éxito.
  - No se muestra `error.message` en `ERROR_INTERNO` aunque venga: con `APP_DEBUG` un 500 podría traer detalle técnico.
  - En M2 los botones se deshabilitan (no se ocultan) cuando la acción no está en `allowed_actions`, con texto que explica; el creador ve "La creaste tú".
  - Cada commit compila por sí solo y lleva su prueba (verificado con `tsc -b` y la suite antes de cada commit).
- Puntos que Daniel debe revisar/entender:
  - `serverMessage()` en `errors.ts`: cuándo se confía en el texto del servidor.
  - El flujo de estado `error` en `AuthProvider`/`RequireAuth` (solo 401 cierra sesión).
  - Por qué la key de `TransferActions` no incluye el estado.
- Riesgos o cosas que podrían estar mal:
  - Si el backend cambia textos de error, la interfaz los mostrará tal cual en los códigos sin mapa propio.
  - `CRITICAL_DAYS` (30) sigue fijo en el cliente; solo la ventana de alerta viene de la API.

### 2026-10-12 — Fase 6: asistente IA de consulta de inventario (backend)
- Agente: ia-asistente
- Qué generó la IA:
  - `app/Domain/Assistant`: interfaz `LlmProvider` (mensajes y herramientas en formato neutral), `LlmResponse`, `ToolCall`, `LlmProviderFactory`; proveedores `MockLlmProvider` (reglas/regex en español, determinista), `OllamaLlmProvider` (`/api/chat` con tools) y `AnthropicLlmProvider` (Messages API con tool use) sobre una base `HttpLlmProvider` que convierte cualquier falla en 503 `ASISTENTE_NO_DISPONIBLE`.
  - `ToolRegistry` (lista blanca → Policy → argumentos conocidos y válidos con `Validator` → ejecución) y 7 herramientas de solo lectura que reutilizan `CatalogQuery`, `InventoryQuery`, `AlertService` y `TransferQuery`.
  - `AssistantService` (bucle de hasta 4 llamadas, 5 herramientas por turno), `QuestionGuard` (entrada), `OutputGuard` (salida), `SystemPrompt` fijo con el catálogo de bodegas.
  - Endpoint `POST /api/v1/assistant/query` (Form Request, Gate `useAssistant`, limitador `assistant`, `AssistantAnswerResource`), `config/assistant.php`, `AssistantServiceProvider`, variables en `.env.example` y compose.
  - `CatalogQuery`: búsqueda de productos sin tildes (`translate()` en SQL); `AlertService::lotsExpiringWithin()` y `expiredLots()`.
  - `TransferSeeder` (un traslado en tránsito y uno con observación maliciosa), `tests/Assistant/eval.json` (18 casos) y `php artisan assistant:eval`.
  - Pruebas: herramientas (lista blanca, rangos, argumentos extra, Policies por rol), servicio (mock, rechazos sin llamar al modelo, herramienta desconocida, inyección escapada, filtro de salida, límite del bucle, PII, logs), API (contrato, roles, 422, 429, 503), proveedores con `Http::fake`, seeder y comando de evaluación. OpenAPI 0.6.0, supuestos S-89…S-96, `docs/asistente-ia.md`.
- Qué decisión tomó y por qué:
  - **Rechazar antes del modelo** las preguntas de pacientes/SQL/escritura/inyección: es determinista y evita mandar datos personales escritos por el usuario a un proveedor externo. El system prompt y el filtro de salida siguen como capas adicionales.
  - **El mock pasa por el mismo bucle** que un modelo real (pide `search_products`, luego la herramienta, luego redacta con el `<tool_result>`), en vez de que el servicio responda directo. Así el eval prueba validación, autorización y serialización de verdad.
  - **`JSON_HEX_TAG`** al serializar resultados: un `</tool_result>` escrito en una observación no puede cerrar el bloque de datos.
  - **Gate `useAssistant` = `StockPolicy::viewAny`** (farmacia y auditor) y cada herramienta vuelve a autorizar con su Policy: sin `if` de roles.
  - El catálogo de bodegas va en el system prompt para que el modelo (y el mock) traduzca "farmacia central" a `FAR-CEN` sin una llamada extra.
  - `TransferSeeder` usa `TransferService` (no `updateOrCreate`): el despacho debe mover stock por el kardex; la idempotencia es por la observación exacta. Se ajustó `SeederTest` porque ahora hay una `SALIDA_TRASLADO` en la semilla.
  - Flujo en lenguaje simple: el usuario pregunta; el servidor primero mira si la pregunta es de pacientes o pide algo prohibido y, si es así, contesta él mismo. Si no, le pasa al modelo las reglas, la pregunta y la lista de herramientas. El modelo pide, por ejemplo, "busca acetaminofén" y luego "lotes que vencen en 60 días en FAR-CEN"; el servidor revisa que la herramienta exista, que el usuario tenga permiso y que los datos tengan sentido, la ejecuta y le devuelve el resultado marcado como dato. Al final revisa la respuesta del modelo antes de mostrarla.
- Puntos que Daniel debe revisar/entender:
  - El orden de `ToolRegistry::execute()` (lista blanca → Policy → argumentos) y por qué la Policy va antes (no revela qué códigos existen).
  - Qué ve un proveedor externo exactamente (`ScriptedLlmProvider` en las pruebas lo registra) y por qué nunca hay datos de pacientes.
  - Las reglas de `QuestionGuard` y `OutputGuard` son listas de patrones: conocer sus falsos positivos (S-92, S-93).
  - El eval asume la semilla limpia: en la BD de desarrollo actual da 17/18 porque la demo de la Fase 5 agotó L-ACE-2402 en Farmacia Central (la respuesta "no hay lotes" es correcta para esos datos). Con `make fresh` da 18/18.
- Riesgos o cosas que podrían estar mal:
  - Los proveedores Ollama y Anthropic solo se probaron con `Http::fake`, no contra un servidor real: el formato sigue la documentación pública (`/api/chat` con `tools`; Messages API `2023-06-01` con `tool_use`/`tool_result`). El nombre de modelo por defecto `claude-sonnet-5-5` debe confirmarse contra los modelos disponibles de la cuenta.
  - El mock no entiende sinónimos ni frases muy distintas a las del eval; es un simulador, no un modelo.
  - Los filtros por patrones pueden bloquear una pregunta legítima ("¿cambia el stock…?") o dejar pasar una inyección ingeniosa; por eso hay varias capas y ninguna herramienta escribe ni devuelve datos de pacientes.
  - `docker-compose.yml` agrega `extra_hosts: host.docker.internal:host-gateway`; requiere recrear el contenedor `api` para que tome las variables nuevas (los valores por defecto de `config/assistant.php` ya coinciden).
  - El eval lee `tests/Assistant/eval.json`; si la imagen de producción (Fase 7) excluye `tests/`, habrá que copiar ese archivo o correr el eval en CI con el código fuente.

### 2026-10-12 — Fase 6: pantalla del asistente IA (frontend)
- Agente: frontend-react
- Qué generó la IA:
  - `api/types.ts` (cabecera a 0.6.0): `AssistantQueryRequest`, `AssistantAnswer`, `AssistantToolUse`, `UntrustedText` y `AssistantResult` como unión discriminada por `tool` (filas de `get_stock`, `get_expiring_lots`/`get_expired_lots`, `get_below_minimum`, `get_transfers`, `list_warehouses`).
  - `features/assistant/assistantApi.ts` (mutación `useAskAssistant`, límites 3–500), `AssistantPage.tsx` (caja con contador, Enter/Shift+Enter, ejemplos clicables, "Consultando…", historial en memoria, rechazo/fuera de alcance diferenciados, herramientas plegables con el proveedor) y `AssistantResultTable.tsx` (tabla por herramienta, aviso de `truncated`, observación no confiable marcada).
  - `errors.ts`: mensaje para `ASISTENTE_NO_DISPONIBLE` (`DEMASIADAS_SOLICITUDES` ya existía; se agregó su prueba).
  - Permiso `askAssistant` (auxiliar, regente, auditor), ruta `/asistente` y entrada "Asistente" en el menú; estilos en `index.css`.
  - Pruebas (`assistant.test.tsx`, 14): respuesta + tabla + herramientas, Enter/Shift+Enter, doble clic = una petición, ejemplo con un clic, refused vs out_of_scope, observación con `<script>`/`<img onerror>` como texto, 503 y 429 comprensibles, validación de longitud, menú y pantalla por rol. Se actualizó la prueba de navegación por rol. Total: 120 pruebas Vitest.
- Qué decisión tomó y por qué:
  - El permiso se llama `askAssistant` y no `useAssistant` (como el Gate del backend) para que ESLint no lo confunda con un hook de React.
  - Pregunta por mutación aunque no cambia datos: cada pregunta es una acción del usuario, no se cachea y no se reintenta sola.
  - Tablas con columnas fijas por herramienta en vez de una tabla genérica: más legible y tipada; lo desconocido no se pinta.
  - Supuestos S-97 (historial en memoria, orden, ejemplos, teclado) y S-98 (texto plano, notas no confiables, detalle plegable).
  - Se verificó que la prueba de doble clic falla si se quita `useSubmitGuard` y la deshabilitación del botón.
- Puntos que Daniel debe revisar/entender:
  - Por qué React ya protege contra XSS al pintar `{texto}` y qué rompería esa garantía (`dangerouslySetInnerHTML`, `href` con `javascript:`).
  - `useSubmitGuard` + `disabled={isPending}`: por qué hacen falta los dos.
  - `AssistantResult` como unión discriminada: el `switch (result.tool)` en `AssistantResultTable`.
- Riesgos o cosas que podrían estar mal:
  - Si el backend cambia la forma de las filas de una herramienta, la tabla puede romperse en tiempo de ejecución (los tipos son a mano, sin validación en runtime).
  - No se probó contra la API real en Docker desde esta tarea (solo con el simulador `mockApi`); conviene una prueba manual con `mock` y la semilla limpia.
  - Con un proveedor real la respuesta puede tardar varios segundos; solo hay "Consultando…", sin botón de cancelar.
