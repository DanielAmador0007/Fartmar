# Prompt orquestador — pégalo en Claude Code al iniciar

> Uso: crea la carpeta del repo, copia `CLAUDE.md` y `.claude/agents/` dentro, haz `git init`, abre `claude` en esa carpeta y pega el bloque de abajo. Luego avanza **fase por fase**: al final de cada una, revisa tú el código y el `docs/ai-log.md` antes de decir "continúa".

---

```
Eres el líder técnico de mi prueba técnica para Desarrollador Full Stack Senior en FARTMAR IPS.
Lee CLAUDE.md completo: es la especificación y la fuente de verdad. El enunciado original está en docs/enunciado.md.

Objetivo: entregar en < 72 h un repositorio con un núcleo CORRECTO, SEGURO y BIEN PROBADO
(dispensación FEFO concurrente e idempotente, inventario por lote, kardex inmutable, traslados con
máquina de estados), más frontend React, asistente IA seguro, Docker/CI y documentación.
Yo debo poder explicar y modificar en vivo cualquier línea: prefiere código simple y explícito.

Cómo trabajar:
1. Trabajas por FASES (abajo). Al iniciar cada fase crea una lista de tareas, delega en los
   subagentes de .claude/agents indicados y espera mis comentarios al terminar la fase.
2. Cada tarea cierra con: tests corriendo de verdad (muestra la salida), linters limpios,
   entrada en docs/ai-log.md y un commit atómico en español (Conventional Commits).
   NUNCA un commit gigante; el historial debe mostrar el avance.
3. Si algo es ambiguo, toma la decisión razonable, regístrala en docs/supuestos.md y sigue.
4. Si una fase se alarga, recorta alcance de lo menos ponderado y anótalo en
   "Qué quedó fuera" — nunca dejes a medias FEFO, concurrencia, idempotencia o traslados.
5. Solo datos sintéticos. Ningún secreto en el repo.
6. Al terminar cada fase invoca a `revisor-senior` y corrige los hallazgos Críticos/Altos.

FASES (orden por peso en la rúbrica y dependencias):

FASE 0 — Andamiaje (≈30 min) · devops-docker-ci
  - Monorepo backend/ (Laravel + Sanctum + Pest + Pint + Larastan) y frontend/ (Vite React TS + ESLint + Vitest).
  - docker-compose mínimo con Postgres 16 funcionando, Makefile (up, down, test, lint, eval, fresh).
  - /health y /ready. Workflow de CI básico (lint + test) en verde desde el día 1.

FASE 1 — Modelo de datos (≈1 h) · arquitecto-datos → qa-tester
  - Todas las migraciones, constraints, triggers de inmutabilidad, modelos y seeders sintéticos.
  - Tests que intentan violar constraints.

FASE 2 — Núcleo de dispensación (≈1.5 h) · backend-dominio → qa-tester → seguridad-privacidad
  - FefoAllocator, StockService + kardex, DispenseService (RN-01..05, RN-09), endpoints.
  - Tests FEFO, idempotencia y CONCURRENCIA REAL sobre la última unidad (procesos paralelos, Postgres).

FASE 3 — Traslados, ajustes y alertas (≈1.5 h) · backend-dominio → qa-tester
  - TransferStateMachine + TransferService (RN-07, RN-08), discrepancias, ajustes, alertas (RN-11).
  - Test exhaustivo de la máquina de estados.

FASE 4 — Seguridad y privacidad (≈45 min) · seguridad-privacidad
  - Policies completas, matriz rol×endpoint testeada, enmascarado auditor, bitácoras,
    logs JSON sin PII con correlation id, cifrado de documento.

FASE 5 — Frontend (≈1.5 h) · frontend-react
  - Login, Dispensación (con vista previa FEFO), Traslados, Inventario + alertas, Kardex.
  - Doble clic + Idempotency-Key, errores comprensibles, acciones por rol, tests Vitest.

FASE 6 — Asistente IA (≈1 h) · ia-asistente
  - LlmProvider + mock/ollama/externo, tools de solo lectura con rol, anti-inyección,
    eval ≥ 12 casos y `php artisan assistant:eval`, pantalla del asistente.

FASE 7 — DevOps completo (≈1 h) · devops-docker-ci
  - Dockerfiles multi-stage no-root, compose con healthchecks y depends_on healthy,
    migración+seed automáticos, un solo comando desde cero (verifícalo con down -v),
    CI completo: lint, Larastan, tests back/front, eval, build imágenes, staging simulado,
    producción con aprobación manual. docs/DEPLOY.md (≤1 página) + scripts de backup/restore.

FASE 8 — Documentación y cierre (≈45 min) · documentador → revisor-senior
  - README, OpenAPI validado, AI_USAGE.draft.md (yo lo completo), docs/SUSTENTACION.md.
  - Revisión final completa y corrección de hallazgos. Prueba de humo end-to-end desde cero.

Empieza por la FASE 0. Antes de escribir código, muéstrame en máx. 15 líneas el plan de la fase
y las versiones exactas que vas a instalar.
```

---

## Prompts útiles durante el trabajo

**Revisión intermedia**
```
Usa revisor-senior sobre el estado actual. Luego dame solo los 5 arreglos de mayor impacto por hora y aplícalos.
```

**Cuando no entiendas algo (hazlo seguido: te lo van a preguntar)**
```
Explícame <archivo/función> como si me lo fueran a preguntar en la sustentación: qué hace, por qué así,
qué alternativa descartamos y qué pasaría si quito <línea clave>. No cambies código.
```

**Verificar concurrencia de verdad**
```
qa-tester: corre el test de concurrencia 10 veces seguidas y muéstrame resultados. Luego desactiva temporalmente
lockForUpdate, córrelo de nuevo para demostrar que detecta el problema, y restaura el código.
```

**Simular el cambio en vivo**
```
Propón 3 cambios pequeños que un evaluador podría pedirme en vivo. Para cada uno dame los pasos, pero NO los hagas:
quiero practicarlos yo y que tú solo revises mi diff.
```

**Recorte por tiempo**
```
Quedan <N> horas. Según los pesos de la rúbrica, ¿qué recorto y qué termino? Actualiza "Qué quedó fuera" en el README.
```
