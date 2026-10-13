# AI_USAGE — BORRADOR (no es la versión final)

> **Para Daniel:** este borrador lo armó la IA a partir de `docs/ai-log.md`, `.claude/agents/` y el historial de git.
> Solo contiene hechos verificables (qué herramienta, para qué tarea, qué registró el log). **No** afirma qué revisaste,
> aceptaste o corregiste tú: eso lo tienes que escribir con tus palabras en los bloques marcados `<!-- DANIEL: … -->`
> (el evaluador lo preguntará en la sustentación). Cuando lo termines, renómbralo a `AI_USAGE.md` y borra esta nota y
> lo que no uses.

## 1. Herramientas usadas

| Herramienta | Uso |
|---|---|
| **Claude Code** (CLI de Anthropic), modelo **Claude Opus 5.5** | Generación de código, pruebas, documentación y revisión. 184 de los 190 commits del repositorio llevan el trailer `Co-Authored-By: Claude Opus 5.5`. |
| **Subagentes de Claude Code** (`.claude/agents/*.md`, `model: inherit`, es decir, el mismo modelo de la sesión) | Cada fase se delegó a agentes especializados con instrucciones propias que remiten a `CLAUDE.md`. |
| `CLAUDE.md` | Especificación maestra que leen todos los agentes: reglas RN-01…RN-11, roles, stack, decisiones ya tomadas, convenciones y definición de terminado. |
| `PROMPT_ORQUESTADOR.md` y `docs/PROGRESO.md` | Prompt inicial por fases y documento de traspaso entre sesiones (estado, pendientes, lo que cada fase debe saber de la anterior). |

<!-- DANIEL: si usaste otras herramientas (ChatGPT, Copilot, búsquedas, documentación oficial), agrégalas aquí. -->

### Estructura de agentes

| Agente | Para qué se usó (según `docs/ai-log.md`) |
|---|---|
| `devops-docker-ci` | Fase 0 (andamiaje, compose, CI básico, `/health` `/ready`) y Fase 7 (imágenes multi-stage sin root, compose tipo producción, rol de BD de la app, CI/CD, respaldos, `DEPLOY.md`) |
| `arquitecto-datos` | Fase 1: migraciones con CHECK, FK compuestas y triggers; modelos, enums y seeders sintéticos |
| `backend-dominio` | Fases 2, 3 y 5: FEFO, `StockService`, kardex, `DispenseService`, idempotencia, traslados, ajustes, alertas, endpoints de catálogo |
| `qa-tester` | Pruebas de constraints, concurrencia real con procesos PHP y barrera de `pg_advisory_lock`, casos borde |
| `seguridad-privacidad` | Revisiones de seguridad (Fases 2 y 4), logs sin PII, correlation id, pacientes enmascarados, bitácora, usuarios |
| `frontend-react` | Fase 5 y pantalla del asistente (Fase 6) |
| `ia-asistente` | Fase 6: `LlmProvider` (mock/Ollama/Anthropic), herramientas de solo lectura, guardas contra inyección, `assistant:eval` |
| `revisor-senior` | Revisión de solo lectura al cierre de cada fase contra la rúbrica; produjo hallazgos Críticos/Altos/Medios/Bajos |
| `documentador` | Fase 8: README, OpenAPI, este borrador y la guía de sustentación |

Flujo de trabajo: una rama por fase (`feat/fase-N-…`) → PR a `main`. Cada tarea cerraba con pruebas, linters, una entrada
en `docs/ai-log.md` y commits pequeños.

<!-- DANIEL: describe en 2–3 frases cómo trabajaste tú con esto. Por ejemplo: qué le pedías al orquestador, si leías
el ai-log o el diff antes de decir "continúa", si corriste las pruebas tú mismo, qué decidiste tú y qué dejaste a la IA.
Escribe solo lo que de verdad hiciste. -->

## 2. Para qué tareas

- **Diseño y modelo de datos:** esquema PostgreSQL con defensas en BD (CHECK, triggers de inmutabilidad y de transición).
- **Núcleo de negocio:** FEFO multi-lote, concurrencia con `FOR UPDATE` ordenado, idempotencia, control especial en dos
  pasos, máquina de estados de traslados, discrepancias.
- **Pruebas:** ~930 pruebas Pest (incluidas 20 de concurrencia con procesos reales) y ~120 Vitest; pruebas de "control"
  que quitan el bloqueo para demostrar que la prueba detecta el problema.
- **Seguridad:** revisiones dedicadas por fase y corrección de hallazgos.
- **Frontend, asistente IA, Docker/CI y documentación.**

## 3. Un ejemplo de algo que acepté y por qué

<!-- DANIEL: elige UNO que de verdad hayas revisado y explica con tus palabras por qué lo aceptaste. Candidatos tomados
del log (verifica cuál revisaste tú): -->

Candidatos (del `docs/ai-log.md`; no implican que Daniel los haya revisado):

- **Control especial en dos pasos** (Fase 1–2): el controlado se crea `PENDIENTE_AUTORIZACION` sin lotes ni salida de
  stock, y el regente lo autoriza desde su sesión. Alternativa descartada: credenciales del regente en el mismo request.
- **Búsqueda de pacientes por `POST` en vez de `GET`** (Fase 4, S-69): con `GET` el documento quedaría en la URL, en los
  logs de acceso de Nginx, en el historial y en el `Referer`.
- **Bloquear la prescripción antes de insertar la dispensación** (corrección de la revisión de la Fase 2): el INSERT con
  FK toma `FOR KEY SHARE` sobre la prescripción y un `FOR UPDATE` posterior producía deadlocks con 8 procesos.
- **Mock como proveedor por defecto** del asistente: evaluación determinista sin llaves, pasando por el mismo bucle,
  validación y Policies que un modelo real.

> **[DANIEL: completar]** — Qué acepté: … Por qué: … Cómo lo comprobé: …

## 4. Un ejemplo de algo que rechacé o corregí y por qué

<!-- DANIEL: el enunciado pide algo que TÚ rechazaste o corregiste. Los casos de abajo los corrigió la propia IA (un
agente sobre el trabajo de otro); úsalos solo si participaste en la decisión, y dilo tal cual fue. Si corregiste algo a
mano (un nombre, un texto, una regla, un supuesto, un mensaje de error), ese es el mejor ejemplo. -->

Candidatos registrados en el log como correcciones (hechas por agentes; confirmar el papel de Daniel):

- **`make test` borraba la BD de desarrollo** (Fase 0): Laravel leía `DB_DATABASE` de `$_SERVER` antes que el `<env>`
  de PHPUnit; se corrigió con `<server>` en `phpunit.xml` y un guard en `tests/TestCase.php` que aborta si la BD no
  termina en `_test`.
- **Actualización perdida en el stock** (Fase 2, `qa-tester`): `save()` con un valor calculado en PHP permitía que, sin
  `FOR UPDATE`, varios procesos escribieran `quantity = 0` y el CHECK nunca viera un negativo. Se cambió a
  `UPDATE … quantity = quantity - n RETURNING`.
- **Trigger de transición que aceptaba "mismo estado"** (Fase 3): permitía `RECIBIDO → RECIBIDO` y una doble recepción
  sin bloqueo duplicaba la entrada. Lo encontró una prueba de control; se corrigió con una migración nueva.
- **El modelo veía el texto de las observaciones de traslados** (revisión de la Fase 6, A1): se cambió para enviar solo
  `has_notes`; la UI recibe el texto marcado como no confiable.
- **Hallazgo L3 de la revisión de la Fase 6 no aplicado** "por indicación del coordinador" (nombre de modelo
  `claude-sonnet-5-5`): <!-- DANIEL: si esa indicación fue tuya, este es un ejemplo real de algo que rechazaste; explica por qué. -->

> **[DANIEL: completar]** — Qué rechacé/corregí: … Qué proponía la IA: … Por qué no lo acepté: …

## 5. Cómo verifiqué el código

Lo que el log registra que se ejecutó (por los agentes):

- Pest contra PostgreSQL real (nunca SQLite) y Vitest en cada tarea; Pint, Larastan nivel 6, ESLint y `tsc`.
- Pruebas de mutación manual: quitar `FOR UPDATE`, bloquear en otro orden o calcular "hoy" en UTC y ver que las pruebas
  fallan (y revertir).
- Pruebas de humo contra el stack levantado (`scripts/smoke-test.sh`), respaldo y restauración, Trivy local.
- `revisor-senior` al cierre de cada fase; los Críticos/Altos/Medios se corrigieron antes de cerrar.

<!-- DANIEL: escribe aquí lo que verificaste TÚ: qué pruebas corriste, qué flujos probaste a mano en la UI, qué partes
leíste línea por línea (por ejemplo DispenseService, StockService, TransferService), qué cambiaste a mano. No copies la
lista de arriba como propia si no lo hiciste. -->

> **[DANIEL: completar]**

## 6. Límites que reconozco

- Los proveedores Ollama y Anthropic solo se probaron con respuestas simuladas (`Http::fake`).
- El frontend no se probó con clics en un navegador real durante el desarrollo (solo jsdom y humo HTTP).
- GitHub Actions no pudo ejecutarse durante el desarrollo por un bloqueo de facturación; el pipeline se validó en local.

<!-- DANIEL: ajusta esta lista si ya probaste algo de esto por tu cuenta. -->
