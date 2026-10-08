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

## Modelo de datos (Fase 1)

| # | Supuesto | Motivo |
|---|---|---|
| S-16 | `stocks.product_id` está **denormalizado** (se puede derivar del lote) y una FK compuesta `(lot_id, product_id) → lots(id, product_id)` impide que sea inconsistente. El mismo patrón se usa en kardex, dispensaciones y traslados. | Consultar y bloquear existencias por bodega + producto (FEFO, alertas de mínimo) sin join, sin riesgo de que el producto no coincida con el lote. |
| S-17 | El número de documento del paciente se guarda **cifrado** (cast `encrypted`, AES-256 con `APP_KEY`) y además `document_hash = HMAC-SHA256("TIPO:NÚMERO")` con `PATIENT_HASH_KEY` (o `APP_KEY` si no se define). El número se normaliza (sin puntos, guiones ni espacios). | El cifrado no permite buscar ni garantizar unicidad; el HMAC sí. Se usa HMAC y no SHA-256 simple porque los documentos tienen pocos valores posibles y un hash sin clave se revierte por fuerza bruta. |
| S-18 | El teléfono del paciente también se cifra; nombres y fecha de nacimiento quedan en claro (se necesitan para buscar y mostrar, y se enmascaran en la API según el rol). | Equilibrio entre privacidad y usabilidad. |
| S-19 | La dispensación se modela en tres niveles: `dispensations` (cabecera) → `dispensation_items` (línea de prescripción y cantidad) → `dispensation_item_lots` (lotes FEFO). | Una dispensación de control especial queda `PENDIENTE_AUTORIZACION` con sus líneas pero **sin lotes**; los lotes y la salida de stock se asignan al autorizar (CLAUDE.md §6.4). |
| S-20 | El traslado sigue el mismo patrón: `transfer_items` (producto y cantidad solicitada) → `transfer_item_lots` (lotes despachados y recibidos). Los lotes se eligen **al despachar** (FEFO, sin vencidos). Una discrepancia por lote despachado. | Entre la solicitud y el despacho el stock cambia; asignar lotes al despachar evita reservar lotes que luego se vencen o se agotan. |
| S-21 | Una prescripción es **vigente** si `status = 'ACTIVA'` y `valid_until >= hoy` (zona de negocio). Estados: `ACTIVA`, `COMPLETADA`, `ANULADA`. Un producto aparece una sola vez por prescripción. | El enunciado no define estados de prescripción. |
| S-22 | Quien **autoriza o rechaza** un controlado y quien **aprueba** un traslado debe tener rol `regente_farmacia`; lo verifica un trigger además de la Policy. | Defensa en profundidad de RN-05 y de la tabla de roles. |
| S-23 | Las tablas append-only (kardex, bitácoras) bloquean UPDATE/DELETE por trigger, pero **no TRUNCATE ni DROP** (los usan las pruebas y `migrate:fresh`). En producción la app debe conectarse con un usuario de BD que no sea dueño de las tablas. | Se documenta como riesgo residual. |
| S-24 | `idempotency_key` es UNIQUE **global** (no por usuario). El cliente debe generar un UUID por intento lógico. | Simplicidad; una colisión de UUID entre usuarios es despreciable y, si ocurre, el `request_hash` distinto devuelve 409. |
| S-25 | Las columnas polimórficas (`kardex_movements.reference_type`, `audit_logs.auditable_type`) guardan alias cortos (`dispensation`, `transfer`, …) vía `Relation::enforceMorphMap`. | Renombrar una clase PHP no rompe datos históricos inmutables. |
| S-26 | Datos semilla: vencimientos **relativos a hoy**; re-ejecutar el seeder recalcula fechas de lotes y la vigencia de las prescripciones, pero **no modifica existencias ya creadas** (el kardex es inmutable). Usuarios de demo con contraseña `password` (ver `docs/modelo-datos.md`). | La demo siempre muestra lote vencido, por vencer (< 30 días) y en ventana de alerta, sin importar cuándo se levante. |

## Dispensación y API (Fase 2)

| # | Supuesto | Motivo |
|---|---|---|
| S-27 | La API de negocio vive bajo **`/api/v1/*`** (precisa S-12). | Versionar desde el inicio permite cambios incompatibles sin romper el frontend. |
| S-28 | `request_hash` = SHA-256 de **usuario + payload canónico** (líneas ordenadas por `prescription_item_id`). La misma `Idempotency-Key` enviada por otro usuario es un conflicto (409). | Un reintento legítimo siempre lo hace el mismo usuario; así nadie obtiene la dispensación de otro adivinando su clave. |
| S-29 | Crear responde **201**; un reintento idéntico responde **200** con `Idempotent-Replayed: true` y el mismo cuerpo. Sin header `Idempotency-Key` → 422 `VALIDACION` (formato: 8–100 caracteres `[A-Za-z0-9_-:.]`, se recomienda UUID). | Distinguir creación de repetición sin que el cliente tenga que comparar cuerpos. |
| S-30 | Si **cualquier** línea de una dispensación es de control especial, **toda** la dispensación queda `PENDIENTE_AUTORIZACION` y nada sale hasta que se autorice. | Una dispensación es una unidad (una entrega). El frontend puede separar controlados y no controlados en dos solicitudes si se quiere entregar lo demás de inmediato. |
| S-31 | Una dispensación pendiente **reserva cantidad de la prescripción** (no se puede pedir de nuevo lo que ya está esperando autorización) pero **no reserva stock**: al autorizar se vuelven a validar vigencia, saldo y existencias, y puede fallar con `STOCK_INSUFICIENTE`. Rechazar libera la reserva. | Reservar stock exigiría otra tabla y liberar reservas vencidas; validar al autorizar es más simple y nunca deja stock negativo. |
| S-32 | En controlados, el movimiento de kardex queda a nombre del **regente que autoriza** (es quien ejecuta la salida); quien la creó queda en `dispensations.created_by`. | Trazabilidad: ambos usuarios quedan registrados. |
| S-33 | Cuando todas las líneas de una prescripción quedan entregadas, la prescripción pasa automáticamente a `COMPLETADA`. | S-21 define COMPLETADA como "todo dispensado". |
| S-34 | La vista previa FEFO (`GET /dispensations/preview`) es por producto + bodega + cantidad, **no bloquea filas** y es orientativa: la asignación real se hace al confirmar, con bloqueo. | Mostrar lotes antes de confirmar sin retener existencias de otros usuarios. |
| S-35 | RN-01 (nunca un lote vencido) aplica a `SALIDA_DISPENSACION` y `SALIDA_TRASLADO`. Un `AJUSTE` negativo **sí** puede retirar un lote vencido (baja por vencimiento, con motivo). | Sin esa excepción, el stock vencido no podría darse de baja nunca. |
| S-36 | Login: mismo mensaje si el correo no existe, la clave es incorrecta o el usuario está inactivo; límite de 10 intentos por minuto. Los tokens de Sanctum no expiran por ahora (`expiration = null`); se revisa en la fase de seguridad. | No revelar qué correos existen; frenar fuerza bruta. |
| S-37 | Las Policies exigen además que el usuario esté **activo** (`is_active`). | Un usuario desactivado con un token antiguo no debe poder autorizar ni dispensar. |
| S-38 | Si una dispensación **falla** (stock insuficiente, prescripción excedida…), su `Idempotency-Key` no queda registrada (la transacción se deshace): un reintento con la misma clave se procesa como nuevo. | El cliente puede reintentar con la misma clave cuando se corrige la causa (p. ej. llega stock) sin generar una clave nueva; nunca hay doble salida porque el fallo no movió nada. |
| S-39 | Pedir sobre una prescripción `COMPLETADA` responde `PRESCRIPCION_NO_VIGENTE` (422), no `PRESCRIPCION_EXCEDIDA`: primero se valida el estado de la prescripción y después el saldo de cada línea. | El estado explica mejor la causa ("ya se entregó todo") que el saldo en cero. |
