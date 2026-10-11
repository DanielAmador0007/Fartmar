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
