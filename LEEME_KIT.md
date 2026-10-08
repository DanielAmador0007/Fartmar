# Kit de agentes — Prueba técnica FARTMAR IPS

## Contenido
| Archivo | Para qué |
|---|---|
| `CLAUDE.md` | Especificación maestra: reglas RN-01..11, roles, stack, decisiones ya tomadas, convenciones, definición de terminado. Claude Code lo lee siempre. |
| `PROMPT_ORQUESTADOR.md` | El prompt inicial (8 fases priorizadas por la rúbrica) + prompts útiles durante el trabajo. |
| `.claude/agents/arquitecto-datos.md` | Esquema PostgreSQL, constraints, triggers, seeders sintéticos. |
| `.claude/agents/backend-dominio.md` | FEFO, dispensación concurrente e idempotente, traslados, kardex, API. |
| `.claude/agents/seguridad-privacidad.md` | Sanctum, Policies, enmascarado, bitácoras, logs sin PII. |
| `.claude/agents/frontend-react.md` | Las 4 pantallas + login + asistente, UX anti doble clic. |
| `.claude/agents/ia-asistente.md` | Parte C: proveedores LLM, tools de solo lectura, anti-inyección, eval. |
| `.claude/agents/devops-docker-ci.md` | Docker multi-stage, compose, GitHub Actions, DEPLOY.md. |
| `.claude/agents/qa-tester.md` | Pruebas FEFO, estados, concurrencia real, idempotencia. |
| `.claude/agents/revisor-senior.md` | Revisor de solo lectura que te califica con la rúbrica. |
| `.claude/agents/documentador.md` | README, OpenAPI, borrador AI_USAGE, guía de sustentación. |

## Cómo arrancar
1. `mkdir fartmar-dispensacion && cd fartmar-dispensacion && git init`
2. Copia `CLAUDE.md` y la carpeta `.claude/` a la raíz.
3. Guarda el enunciado como `docs/enunciado.md` (convierte el .docx o pega el texto).
4. Crea el repo en GitHub (público facilita la aprobación manual del pipeline) y haz el primer commit: `chore: especificación y agentes`.
5. `claude` → pega el bloque de `PROMPT_ORQUESTADOR.md`.
6. Avanza fase por fase; revisa cada una antes de continuar.

## Plan de las 72 h (sugerido)
- **Día 1:** Fases 0–3 (el 50 % de la nota está aquí). Al final: revisor-senior.
- **Día 2:** Fases 4–7. Probar `docker compose down -v && make up` desde cero.
- **Día 3 (mañana):** Fase 8, completar tú `AI_USAGE.md`, estudiar `docs/SUSTENTACION.md`, practicar 2–3 cambios en vivo **a mano**. Entregar con margen (no al límite del plazo).

## Ojo con esto
- **Tú respondes por el código.** En la sustentación harás un cambio en vivo; usa el prompt "Explícame…" en cada pieza clave y practica los cambios sin IA.
- **AI_USAGE.md debe ser verdadero.** El kit deja un borrador con huecos `[DANIEL: completar]`; llénalos con correcciones reales que hiciste (anótalas mientras trabajas).
- Los subagentes no ven la conversación principal: por eso cada uno remite a `CLAUDE.md`. Si cambias una decisión, cámbiala allí.
- Si el pipeline de producción con aprobación manual no está disponible en tu plan de GitHub para repos privados, usa repo público o documenta la alternativa.
