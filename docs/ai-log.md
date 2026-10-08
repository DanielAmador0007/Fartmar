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
