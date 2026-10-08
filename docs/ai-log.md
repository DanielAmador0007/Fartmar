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
