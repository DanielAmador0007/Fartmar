# Uso de inteligencia artificial

## 1. Herramientas usadas

Usé una sola herramienta de IA: **Claude Code** (CLI de Anthropic) con el modelo **Claude Opus 5.5**. 201 de los 208
commits de `main` llevan el trailer `Co-Authored-By: Claude Opus 5.5`; los demás son merges de PR.

| Pieza | Para qué |
|---|---|
| `CLAUDE.md` | Especificación que escribí para que todos los agentes trabajaran con las mismas reglas: RN-01…RN-11, roles, stack, decisiones de diseño ya tomadas, convenciones y definición de terminado. |
| Subagentes (`.claude/agents/*.md`) | Un agente por área: `arquitecto-datos`, `backend-dominio`, `qa-tester`, `seguridad-privacidad`, `frontend-react`, `ia-asistente`, `devops-docker-ci`, `documentador` y `revisor-senior`. |
| `PROMPT_ORQUESTADOR.md` y `docs/PROGRESO.md` | Prompt de arranque por fases y documento de traspaso entre sesiones. |
| `docs/ai-log.md` | Registro por tarea de qué generó la IA, qué decidió y qué debía revisar yo. Es la base de este documento. |

### Cómo trabajé

Yo definí el problema antes de generar código: escribí `CLAUDE.md` con las reglas de negocio, los roles y las
decisiones de diseño (concurrencia con `FOR UPDATE` ordenado, kardex inmutable, idempotencia por clave, control especial
en dos pasos), y partí el trabajo en fases, cada una en su rama y con su PR a `main`. En cada fase la IA implementaba a
través de los agentes. Antes de decir "continúa", leía el resumen de la fase y la entrada de `docs/ai-log.md`, revisaba
los cambios en el PR y levantaba la aplicación para probarla a mano. Un agente revisor de solo lectura (`revisor-senior`)
evaluaba cada fase contra la rúbrica; mi condición para cerrar una fase era que no quedaran hallazgos Críticos, Altos ni
Medios abiertos.

## 2. Para qué tareas

- **Modelo de datos:** esquema PostgreSQL con defensas en la BD (CHECK, FK compuestas, triggers de inmutabilidad del
  kardex y de transición de estados).
- **Núcleo de negocio:** FEFO multi-lote, concurrencia, idempotencia, control especial, máquina de estados de traslados,
  discrepancias, ajustes y alertas.
- **Pruebas:** ~937 pruebas Pest contra PostgreSQL real (20 de concurrencia con procesos reales) y ~121 Vitest.
- **Seguridad y privacidad:** enmascarado, bitácora de acceso a pacientes, logs sin PII y correlation id.
- **Frontend, asistente IA, Docker/CI y documentación.**

## 3. Algo que acepté y por qué: el control especial en dos pasos

**Qué propuso la IA.** Un medicamento de control especial (RN-05) se dispensa en dos pasos. El auxiliar crea la
dispensación en estado `PENDIENTE_AUTORIZACION`, sin asignar lotes y sin sacar stock. Después, un regente distinto la
autoriza desde su propia sesión, y solo en ese momento se ejecuta FEFO y se registra la salida en el kardex. La
alternativa era que el auxiliar enviara usuario y clave del regente en el mismo request.

**Por qué lo acepté.**

- **Trazabilidad real:** cada persona actúa con su propio token. Queda registrado quién creó y quién autorizó, en
  momentos distintos. Con las credenciales en el mismo request, la "autorización" ocurre en la pantalla del auxiliar y
  no hay una segunda revisión de verdad.
- **No se comparten claves:** el regente nunca escribe su contraseña en el equipo de otra persona.
- **No se reserva stock de más:** mientras está pendiente no se mueve inventario, y FEFO se calcula al autorizar, con
  los lotes vigentes de ese momento.
- **La regla no vive solo en el código:** además de la Policy, los triggers de la BD exigen que quien autoriza tenga rol
  `regente_farmacia` y sea distinto del creador. Si alguien se salta la API, la BD lo rechaza.

**Cómo lo comprobé.** Hay pruebas de que el creador no puede autorizar (403 `SEGREGACION_FUNCIONES`), de que un rol
distinto a regente no puede, y de que el stock no cambia hasta la autorización. A mano, en la aplicación: creé la dispensación de un controlado con el auxiliar, comprobé que no podía autorizarla con el
mismo usuario y la autoricé con `regente2@fartmar.test`; luego revisé en el kardex que la salida apareció solo después.

## 4. Algo que corregí y por qué

No reescribí código a mano. Mi forma de corregir a la IA fue meter en el flujo un revisor independiente
(`revisor-senior`) y pruebas que intentan romper el sistema, y no cerrar ninguna fase con hallazgos Críticos, Altos o
Medios abiertos. El caso más importante que salió de ese proceso:

**La actualización perdida en el stock (Fase 2).** La primera versión calculaba la nueva cantidad en PHP y la guardaba
con `save()`. El agente de pruebas lanzó varios procesos a la vez por la última unidad. Sin el bloqueo, todos leían
`quantity = 1` y todos escribían `quantity = 0`, así que se entregaban varias unidades y el CHECK `quantity >= 0` nunca
se disparaba, porque ningún proceso escribía un negativo. Es decir: la "defensa en BD" que yo había pedido no servía tal
como estaba escrito el código.

**Qué se cambió.** El descuento pasó a ser relativo en la BD (`UPDATE … SET quantity = quantity - n … RETURNING`),
siempre dentro de una transacción con `SELECT … FOR UPDATE` ordenado por vencimiento y lote. Ahora el CHECK sí es la
última línea de defensa. Además se agregaron pruebas de control que quitan el bloqueo y demuestran que la prueba de
concurrencia falla sin él.

**Por qué importa.** RN-03 era la regla más pesada de la prueba. Este caso me dejó claro que la IA puede escribir
código que "se ve" correcto y pasa las pruebas secuenciales; por eso exigí pruebas de concurrencia con procesos reales y
no con `RefreshDatabase`.


## 5. Cómo verifiqué el código

- Revisé los cambios de cada fase en su PR antes de fusionar, y leí el resumen y la entrada de `docs/ai-log.md`.
- Levanté el stack con `docker compose up -d --build --wait` y probé la aplicación a mano:
  login por rol, dispensación con vista previa FEFO, autorización
  de controlado, traslado completo con recepción parcial y discrepancia, vista del auditor con datos enmascarados,
  preguntas al asistente.
- Corrí `make test` (Pest + Vitest), `make lint` (Pint, Larastan nivel 6, ESLint) y `make eval` (18/18).
- El CI de GitHub Actions corre en verde en `main`: lint, pruebas y eval, imágenes con Trivy, staging simulado y
  publicación en GHCR.

## 6. Límites que reconozco

- Los proveedores Ollama y Anthropic del asistente solo se probaron con respuestas simuladas (`Http::fake`); la
  evaluación usa el proveedor `mock`.
- Las pruebas automáticas del frontend corren en jsdom; no hay pruebas end-to-end en un navegador.
- Buena parte del código lo generó la IA. Lo que me corresponde es haber definido las reglas y las decisiones, revisado
  y probado el resultado y poder explicar y modificar cualquier parte.
