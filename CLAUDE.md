# CLAUDE.md — Prueba técnica FARTMAR IPS (Full Stack Senior)

Este archivo es la memoria del proyecto para Claude Code. Léelo completo antes de cualquier tarea.

## 1. Qué estamos construyendo

Sistema de **dispensación de medicamentos**, **inventario por lote** y **traslados entre bodegas** con trazabilidad completa (kardex) y protección de datos de pacientes. Es una prueba técnica: se evalúa un **núcleo correcto, seguro y bien probado** por encima de muchas funciones a medias. El candidato (Daniel) debe poder explicar y modificar en vivo cualquier parte del código, así que el código debe ser **simple, explícito y legible**, sin magia innecesaria.

### Pesos de evaluación (guían la prioridad)

| Área | Peso |
|---|---|
| Dispensación: FEFO, concurrencia e idempotencia | 20 % |
| Modelo de datos e integridad | 15 % |
| Traslados y kardex | 15 % |
| Docker y DevOps | 12 % |
| Seguridad y privacidad | 10 % |
| Frontend y UX | 10 % |
| Inteligencia artificial | 10 % |
| Pruebas, documentación y calidad | 8 % |

## 2. Stack (obligatorio, no cambiar)

- **Backend:** PHP 8.3+, Laravel (última versión estable que instale `composer create-project`), API REST, Sanctum (tokens), Policies/Gates, Form Requests, API Resources.
- **BD:** PostgreSQL 16 (por `SELECT … FOR UPDATE`, CHECK constraints, triggers e índices parciales).
- **Frontend:** React + TypeScript + Vite, TanStack Query, React Router. Vitest + Testing Library.
- **Calidad:** Laravel Pint, Larastan (nivel ≥ 6), ESLint, Pest (o PHPUnit).
- **IA:** servicio Laravel con interfaz `LlmProvider`; proveedores `mock` (por defecto), `ollama`, y uno externo compatible (Anthropic u OpenAI). Selección con `LLM_PROVIDER`.
- **Infra:** Docker multi-stage, docker-compose, GitHub Actions.

## 3. Estructura del repositorio

```
/
├── backend/                 # Laravel
│   ├── app/Domain/          # Lógica de negocio (NO en controladores)
│   │   ├── Inventory/       # FefoAllocator, StockService, KardexService
│   │   ├── Dispensing/      # DispenseService, IdempotencyGuard
│   │   ├── Transfers/       # TransferService, TransferStateMachine
│   │   ├── Patients/        # PatientAccessLogger, masking
│   │   └── Assistant/       # LlmProvider, tools, AssistantService
│   ├── app/Http/{Controllers,Requests,Resources,Middleware}
│   ├── app/Policies/
│   ├── database/{migrations,seeders,factories}
│   └── tests/{Unit,Feature,Concurrency}
├── frontend/                # React + TS + Vite
├── docker/                  # nginx.conf, entrypoints, php.ini
├── docs/                    # openapi.yaml, DEPLOY.md, ai-log.md, SUSTENTACION.md
├── .github/workflows/ci.yml
├── docker-compose.yml
├── .env.example
├── Makefile                 # make up | make test | make eval
├── README.md
└── AI_USAGE.md
```

## 4. Reglas de negocio (fuente de verdad — cada una debe tener prueba)

- **RN-01** Existencia = bodega + producto + lote. Lote con fecha de vencimiento. Lote vencido (`expires_at < hoy`) **nunca** se dispensa ni se traslada.
- **RN-02** Dispensación consume lotes en **FEFO** (vence primero, sale primero; desempate por `lots.id`). Puede tomar de varios lotes.
- **RN-03** Stock **nunca negativo**, aun con solicitudes simultáneas por la última unidad. Defensa doble: `CHECK (quantity >= 0)` en BD + `SELECT … FOR UPDATE` dentro de transacción.
- **RN-04** Dispensación ligada a paciente + prescripción **vigente**. No más de lo prescrito; se permiten parciales acumuladas (`quantity_dispensed <= quantity_prescribed` con CHECK).
- **RN-05** Medicamento de control especial requiere autorización de un **segundo usuario** con rol `regente_farmacia` (distinto de quien crea la dispensación).
- **RN-06** Todo cambio de stock genera movimiento de kardex: `ENTRADA`, `SALIDA_DISPENSACION`, `SALIDA_TRASLADO`, `ENTRADA_TRASLADO`, `AJUSTE`. **Inmutables** (trigger en PostgreSQL que bloquea UPDATE/DELETE). Correcciones = movimiento `AJUSTE` con motivo obligatorio.
- **RN-07** Traslado: `BORRADOR → SOLICITADO → APROBADO → EN_TRANSITO → RECIBIDO | RECIBIDO_PARCIAL`; `ANULADO` solo antes de `EN_TRANSITO`. Stock sale del origen al **despachar** y entra al destino al **recibir**. Lo no recibido = **discrepancia pendiente** de resolución.
- **RN-08** Quien solicita un traslado **no puede aprobarlo** (Policy + CHECK `approved_by <> requested_by`).
- **RN-09** Crear dispensación es **idempotente**: header `Idempotency-Key` obligatorio; reintento con misma clave y mismo payload devuelve la respuesta original sin nueva salida de stock; misma clave con payload distinto → 409.
- **RN-10** Datos de paciente sensibles: acceso por rol, **enmascarado para auditor**, bitácora de quién consultó qué paciente, **ningún dato personal en logs**.
- **RN-11** Alertas: lotes que vencen en ≤ 90 días (configurable `ALERT_EXPIRY_DAYS`) y productos bajo stock mínimo por bodega.

## 5. Roles

| Rol | Permisos |
|---|---|
| `auxiliar_farmacia` | Dispensar, consultar inventario, crear y recibir traslados |
| `regente_farmacia` | Todo lo anterior + aprobar traslados, autorizar control especial, ajustes de inventario, resolver discrepancias |
| `medico` | Crear prescripciones, consultar pacientes |
| `auditor` | Solo lectura; datos de paciente enmascarados |
| `admin` | Usuarios y catálogos |

Los permisos se centralizan en Policies/Gates. Nunca chequear roles con `if` sueltos en controladores.

## 6. Decisiones de diseño ya tomadas (no reabrir sin motivo)

1. **Concurrencia:** transacción + `lockForUpdate()` sobre filas de `stocks` ordenadas por `(expires_at, lot_id)` para orden de bloqueo consistente (evita deadlocks). `DB::transaction($fn, 3)` para reintentar ante deadlock. El CHECK de BD es la última línea de defensa.
2. **Kardex con saldo:** cada movimiento guarda `quantity` (>0), `direction` (+1/-1) y `balance_after`. Inmutable vía trigger.
3. **Idempotencia:** columna `idempotency_key` UNIQUE + `request_hash` en `dispensations`. La clave se inserta dentro de la misma transacción que descuenta stock; si choca el UNIQUE, se devuelve la dispensación existente.
4. **Control especial en dos pasos:** la dispensación de un medicamento controlado se crea en estado `PENDIENTE_AUTORIZACION` sin mover stock; un regente distinto la autoriza y ahí se ejecuta FEFO y salida. (Alternativa descartada: credenciales del regente en el mismo request — peor trazabilidad.)
5. **Paciente:** documento de identidad cifrado (`encrypted` cast) + `document_hash` (HMAC) para búsqueda exacta. Enmascarado en API Resource según rol. `patient_access_logs` en cada lectura.
6. **Logs:** JSON a stdout, middleware `X-Correlation-Id`, procesador Monolog que redacta claves sensibles. Nunca loguear bodies de requests de pacientes.
7. **IA:** el LLM solo invoca herramientas de lectura predefinidas; los argumentos se validan en servidor; los resultados de herramientas se tratan como **datos no confiables** (delimitados); no se envían datos de pacientes al modelo.

## 7. Convenciones

- Código e identificadores en **inglés**; mensajes al usuario, docs y commits en **español**.
- Controladores delgados: validan (Form Request), autorizan (Policy), llaman a un servicio de `app/Domain`, devuelven un Resource.
- Errores de negocio como excepciones de dominio (`InsufficientStockException`, `ExpiredLotException`, `PrescriptionExceededException`…) mapeadas a JSON uniforme: `{ "error": { "code": "STOCK_INSUFICIENTE", "message": "…", "details": {} } }` con 409/422/403.
- Fechas en UTC en BD; zona `America/Bogota` para "hoy" de vencimientos (documentar supuesto).
- Seeders idempotentes (`updateOrCreate`), solo datos **sintéticos**.
- **Commits pequeños y frecuentes** con Conventional Commits en español: `feat(dispensacion): asignación FEFO multi-lote`. Un commit por unidad lógica; nunca un commit gigante final.

## 8. Definición de terminado (por tarea)

- [ ] Tests nuevos/actualizados y pasando (`make test`).
- [ ] `pint --test`, `phpstan`, `eslint` limpios.
- [ ] Regla de negocio defendida en BD cuando sea posible, no solo en código.
- [ ] Entrada agregada a `docs/ai-log.md` (ver §9).
- [ ] Commit hecho.

## 9. Registro de uso de IA (obligatorio)

Después de cada tarea relevante, agrega en `docs/ai-log.md`:

```
### <fecha> — <tarea>
- Agente: <nombre>
- Qué generó la IA:
- Qué decisión tomó y por qué:
- Puntos que Daniel debe revisar/entender:
- Riesgos o cosas que podrían estar mal:
```

Este log es la materia prima para que Daniel escriba `AI_USAGE.md` con **sus propias palabras** (qué aceptó, qué corrigió). No escribas AI_USAGE.md inventando revisiones que Daniel no hizo.

## 10. Qué NO hacer

- No usar datos reales de pacientes ni de la IPS.
- No poner secretos en el repo (solo `.env.example`).
- No meter lógica de negocio en controladores ni en componentes React.
- No usar `RefreshDatabase` en la prueba de concurrencia (envuelve en transacción y oculta el problema): usar `DatabaseMigrations`/truncate y procesos reales.
- No sobre-ingeniar (sin CQRS, event sourcing, microservicios). Simple y defendible.
