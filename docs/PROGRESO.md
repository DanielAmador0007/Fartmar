# Estado del proyecto y traspaso entre sesiones

> Documento vivo. Léelo al iniciar una sesión nueva de Claude Code (junto con `CLAUDE.md`).
> Última actualización: 2026-10-08, al cerrar la Fase 1.

## Cómo retomar en un chat nuevo

Pega esto al iniciar:

```
Eres el líder técnico de mi prueba técnica FARTMAR. Lee CLAUDE.md, PROMPT_ORQUESTADOR.md
y docs/PROGRESO.md (estado actual y pendientes). Continúa con la siguiente fase indicada
en PROGRESO.md delegando en los subagentes de .claude/agents. Al cerrar la fase: tests y
linters en verde (muestra salida), ai-log, commits pequeños, actualiza docs/PROGRESO.md y haz push.
```

## Estado por fase

| Fase | Estado | Notas |
|---|---|---|
| 0 Andamiaje | ✅ Hecha | Laravel 13.35 / PHP 8.4, Vite 8 + React 19, compose `fartmar`, CI, `/health` `/ready` |
| 1 Modelo de datos | ✅ Hecha | 10 migraciones, 19 modelos, enums, seeders, 80 tests (214 aserciones) |
| 2 Dispensación (FEFO, concurrencia, idempotencia) | ⏭️ **Siguiente** | backend-dominio → qa-tester → seguridad-privacidad |
| 3 Traslados, ajustes, alertas | Pendiente | |
| 4 Seguridad y privacidad | Pendiente | |
| 5 Frontend | Pendiente | |
| 6 Asistente IA | Pendiente | |
| 7 DevOps completo | Pendiente | |
| 8 Documentación y cierre | Pendiente | |

## Entorno (importante para cualquier agente)

- Windows 11, PowerShell 5.1. **No hay PHP ni Composer en el host**: todo lo de PHP corre en el contenedor
  (`docker compose exec -T api php artisan …`, `./vendor/bin/pest`, `pint --test`, `phpstan analyse`).
- Atajos: `.\make.ps1 up | test | lint | fresh | reset | eval` (Windows) o `make …`.
- En PowerShell 5.1 no uses `2>&1` con comandos nativos (convierte stderr en error); usa `2>$null`.
- Proyecto compose `fartmar`. Puertos host: Postgres **55432**, API **8080**, frontend **5173**.
- **No tocar contenedores Docker ajenos** del usuario (`trigra-dev-*`, `sonarqube-biotrust`, `cea-postgres`,
  `busy_moore`). Prohibido `prune`, `docker rm/rmi/stop/kill` de cosas ajenas (bloqueado en permisos).
- Las pruebas usan la BD `fartmar_test`; `tests/TestCase.php` aborta si la BD no termina en `_test`.
- Usuarios demo (contraseña `password`): ver `docs/modelo-datos.md` (incluye `regente2@fartmar.test` para RN-05/RN-08).
- Repo: https://github.com/DanielAmador0007/Fartmar (rama `main`).

## Bloqueos externos

- **GitHub Actions no corre**: la cuenta tiene un bloqueo de facturación ("account is locked due to a billing
  issue"). Daniel debe resolverlo en GitHub → Settings → Billing. Mientras tanto, validar en local.

## Lo que la Fase 2 debe saber del esquema

- Dispensación en 3 niveles: `dispensations` → `dispensation_items` (por línea de prescripción) →
  `dispensation_item_lots` (lotes asignados por FEFO). Un controlado se crea `PENDIENTE_AUTORIZACION`
  con líneas pero **sin lotes**; los lotes y la salida de stock se hacen al autorizar.
- Insertar la cabecera con `requires_authorization` ya calculado **antes** de las líneas (un trigger
  rechaza líneas de productos controlados si la cabecera no exige autorización).
- Triggers exigen que quien autoriza/rechaza una dispensación y quien aprueba un traslado tenga rol
  `regente_farmacia`, y que sea distinto del creador/solicitante.
- `stocks` tiene `product_id` denormalizado con FK compuesta `(lot_id, product_id)`; índice parcial
  `WHERE quantity > 0` para FEFO.
- Kardex: `quantity > 0`, `direction ±1`, `balance_after >= 0`, FK a `(warehouse_id, lot_id)` de `stocks`;
  la BD **no** valida que `balance_after` = saldo anterior ± cantidad → lo garantiza `KardexService`.
- Traslados: los lotes se fijan al despachar en `transfer_item_lots` (FEFO multi-lote); una discrepancia
  por lote despachado.
- La BD no valida: FEFO, vencimiento, vigencia de prescripción, transiciones de estado (valida la fila
  final, no el paso). Todo eso va en `app/Domain` con pruebas.
- Los seeders escriben stock y kardex directamente (aún no existe `KardexService`); al crearlo, conviene
  que el seeder lo use.

## Riesgos conocidos / deuda abierta

- Triggers de solo inserción no bloquean `TRUNCATE` (necesario para pruebas). En producción: usuario de
  BD no dueño de las tablas (documentar en DEPLOY.md, Fase 7).
- Cambiar `APP_KEY` o `PATIENT_HASH_KEY` invalida documentos cifrados/hashes (sin rutina de re-cifrado).
- La BD no exige que quien resuelve una discrepancia sea regente ni que autorizadores estén activos
  (`is_active`): cubrir en Policies (Fase 3/4) o con trigger.
- Falta `README.md` (Fase 8): pasar ahí las credenciales demo y los comandos.
- Restos de Docker de la Fase 0 que Daniel puede borrar a mano si quiere:
  `docker volume rm fartmar_composer_cache` · `docker rmi fartmar-php-dev:local alpine:3 rhysd/actionlint:latest`.

## Documentos de referencia

- `docs/enunciado.md` — enunciado original.
- `docs/modelo-datos.md` — esquema, ER y credenciales demo.
- `docs/supuestos.md` — supuestos S-01…
- `docs/ai-log.md` — registro de uso de IA por tarea (materia prima de `AI_USAGE.md`).
