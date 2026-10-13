#!/usr/bin/env bash
# Smoke test del stack levantado (local o en el runner de CI, deploy-staging).
# Verifica, a través de Nginx: sondas, SPA con headers de seguridad, login,
# endpoints autenticados, 401 sin token y logout.
#
# Con SMOKE_DEMO_CHECKS=true (por defecto) además ESCRIBE como el auxiliar demo,
# con lo que ejercita los permisos del rol de BD de la app (fartmar_app):
# búsqueda de paciente (bitácora), dispensación de un producto NO controlado
# con Idempotency-Key y su reintento (misma respuesta, sin nueva salida),
# GET /kardex de esa dispensación y una pregunta al asistente. Exige la semilla
# demo. En producción real: SMOKE_DEMO_CHECKS=false y SMOKE_EMAIL /
# SMOKE_PASSWORD de un usuario de solo lectura (secretos del environment).
#
#   scripts/smoke-test.sh                       (BASE_URL=http://localhost:8080)
#   BASE_URL=https://staging.example SMOKE_DEMO_CHECKS=false SMOKE_EMAIL=... SMOKE_PASSWORD=... scripts/smoke-test.sh
#
# Usa curl, grep, sed y Python 3 (para leer JSON en las comprobaciones demo).
# Sale con código 1 en la primera falla.
#
# "cond && check || fail": fail termina el script, es justo el "si no" buscado.
# shellcheck disable=SC2015
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
SMOKE_EMAIL="${SMOKE_EMAIL:-auxiliar@fartmar.test}"
SMOKE_PASSWORD="${SMOKE_PASSWORD:-password}"
WAIT_SECONDS="${WAIT_SECONDS:-60}"
SMOKE_DEMO_CHECKS="${SMOKE_DEMO_CHECKS:-true}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
HEADERS="$TMP_DIR/headers"
BODY="$TMP_DIR/body"

pass() { printf '  OK   %s\n' "$1"; }
fail() { printf '  FALLA %s\n' "$1" >&2; [ -s "$BODY" ] && head -c 500 "$BODY" >&2 && echo >&2; exit 1; }

# request METHOD PATH [curl args...] -> deja el código HTTP en $STATUS
request() {
    local method="$1" path="$2"
    shift 2
    STATUS=$(curl -sS -o "$BODY" -D "$HEADERS" -w '%{http_code}' -X "$method" \
        -H 'Accept: application/json' "$@" "${BASE_URL}${path}") || STATUS=000
}

header_present() { grep -qi "^$1:" "$HEADERS"; }

# json EXPRESIÓN -> evalúa una expresión de Python sobre el cuerpo (d). Las
# expresiones son fijas de este script, nunca vienen de la respuesta.
# (En Windows "python3" puede ser el acceso directo a la Store: se prueba que funcione.)
PY=""
for candidate in python3 python; do
    if "$candidate" -c 'import json' > /dev/null 2>&1; then PY="$candidate"; break; fi
done
json() {
    "$PY" -c 'import json, sys; d = json.load(open(sys.argv[1], encoding="utf-8")); print(eval(sys.argv[2], {"d": d}))' "$BODY" "$1"
}

echo "Smoke test contra ${BASE_URL}"

# 1. La API puede tardar en quedar lista justo después de un despliegue.
deadline=$((SECONDS + WAIT_SECONDS))
until request GET /ready && [ "$STATUS" = "200" ]; do
    [ "$SECONDS" -ge "$deadline" ] && fail "GET /ready no respondió 200 en ${WAIT_SECONDS}s (último: $STATUS)"
    sleep 2
done
grep -q '"status":"ok"' "$BODY" || fail "GET /ready sin status ok"
pass "GET /ready 200 (BD y migraciones)"

request GET /health
[ "$STATUS" = "200" ] && grep -q '"status":"ok"' "$BODY" || fail "GET /health ($STATUS)"
pass "GET /health 200"

# 2. SPA y headers de seguridad.
request GET / -H 'Accept: text/html'
[ "$STATUS" = "200" ] && grep -q '<div id="root">' "$BODY" || fail "GET / no devolvió la SPA ($STATUS)"
for header in Content-Security-Policy X-Content-Type-Options X-Frame-Options Referrer-Policy; do
    header_present "$header" || fail "falta el header $header en /"
done
if header_present 'X-Powered-By'; then fail "se expone X-Powered-By"; fi
pass "GET / SPA con CSP, nosniff, X-Frame-Options y Referrer-Policy"

request GET /traslados -H 'Accept: text/html'
[ "$STATUS" = "200" ] && grep -q '<div id="root">' "$BODY" || fail "fallback de la SPA en /traslados ($STATUS)"
pass "GET /traslados (fallback de React Router a index.html)"

# 3. Sin token: 401 con el JSON de error uniforme.
request GET /api/v1/stocks
[ "$STATUS" = "401" ] || fail "GET /api/v1/stocks sin token debía ser 401 (fue $STATUS)"
header_present 'X-Correlation-Id' || fail "falta X-Correlation-Id en la respuesta de la API"
pass "GET /api/v1/stocks sin token -> 401 con X-Correlation-Id"

# 4. Login con usuario demo.
request POST /api/v1/auth/login -H 'Content-Type: application/json' \
    --data "{\"email\":\"${SMOKE_EMAIL}\",\"password\":\"${SMOKE_PASSWORD}\",\"device_name\":\"smoke-test\"}"
[ "$STATUS" = "200" ] || fail "login de ${SMOKE_EMAIL} ($STATUS)"
TOKEN=$(sed -n 's/.*"token":"\([^"]*\)".*/\1/p' "$BODY")
[ -n "$TOKEN" ] || fail "el login no devolvió token"
pass "POST /api/v1/auth/login 200 (token emitido)"

# 5. Endpoints autenticados.
request GET /api/v1/auth/me -H "Authorization: Bearer ${TOKEN}"
[ "$STATUS" = "200" ] && grep -q "\"email\":\"${SMOKE_EMAIL}\"" "$BODY" || fail "GET /api/v1/auth/me ($STATUS)"
pass "GET /api/v1/auth/me 200"

request GET '/api/v1/stocks?per_page=5' -H "Authorization: Bearer ${TOKEN}"
[ "$STATUS" = "200" ] && grep -q '"data":\[{' "$BODY" || fail "GET /api/v1/stocks con token ($STATUS o sin filas)"
pass "GET /api/v1/stocks 200 con existencias"

# 6. Escrituras con los datos demo (rol fartmar_app).
if [ "$SMOKE_DEMO_CHECKS" = "true" ]; then
    [ -n "$PY" ] || fail "SMOKE_DEMO_CHECKS=true necesita python3 para leer JSON"
    auth=(-H "Authorization: Bearer ${TOKEN}" -H 'Content-Type: application/json')

    request POST /api/v1/patients/search "${auth[@]}" --data '{"document_type":"CC","document_number":"9900000001"}'
    [ "$STATUS" = "200" ] || fail "POST /api/v1/patients/search ($STATUS)"
    patient_id=$(json 'd["data"][0]["id"] if d["data"] else ""')
    [ -n "$patient_id" ] || fail "no está la paciente demo (CC 9900000001): ¿falta la semilla? Use SMOKE_DEMO_CHECKS=false"
    pass "POST /api/v1/patients/search 200 (paciente demo, queda en la bitácora)"

    request GET "/api/v1/patients/${patient_id}/prescriptions" "${auth[@]}"
    [ "$STATUS" = "200" ] || fail "GET prescripciones del paciente ($STATUS)"
    # Primera línea vigente, NO controlada y con saldo por entregar.
    line=$(json '" ".join(next(([str(p["id"]), str(i["id"])] for p in d["data"] if p["is_current"] for i in p["items"] if not i["product"]["is_controlled"] and i["quantity_available"] > 0), []))')
    [ -n "$line" ] || fail "la prescripción demo ya no tiene saldo no controlado (make fresh o down -v)"
    read -r prescription_id item_id <<< "$line"

    request GET '/api/v1/warehouses?per_page=100' "${auth[@]}"
    warehouse_id=$(json 'next((w["id"] for w in d["data"] if w["code"] == "FAR-CEN"), "")')
    [ -n "$warehouse_id" ] || fail "no está la bodega demo FAR-CEN"

    key="$("$PY" -c 'import uuid; print(uuid.uuid4())')"
    payload="{\"patient_id\":${patient_id},\"prescription_id\":${prescription_id},\"warehouse_id\":${warehouse_id},\"items\":[{\"prescription_item_id\":${item_id},\"quantity\":1}]}"
    request POST /api/v1/dispensations "${auth[@]}" -H "Idempotency-Key: ${key}" --data "$payload"
    [ "$STATUS" = "201" ] || fail "POST /api/v1/dispensations ($STATUS)"
    dispensation_id=$(json 'd["data"]["id"]')
    first_body="$(cat "$BODY")"
    pass "POST /api/v1/dispensations 201 (FEFO y salida de stock con fartmar_app)"

    request POST /api/v1/dispensations "${auth[@]}" -H "Idempotency-Key: ${key}" --data "$payload"
    [ "$STATUS" = "200" ] || fail "el reintento con la misma Idempotency-Key debía ser 200 (fue $STATUS)"
    grep -qi '^Idempotent-Replayed: true' "$HEADERS" || fail "el reintento no trae Idempotent-Replayed: true"
    [ "$(cat "$BODY")" = "$first_body" ] || fail "el reintento no devolvió la misma respuesta"
    pass "reintento con la misma Idempotency-Key: 200, misma respuesta"

    request GET "/api/v1/kardex?reference_type=dispensation&reference_id=${dispensation_id}" "${auth[@]}"
    [ "$STATUS" = "200" ] || fail "GET /api/v1/kardex ($STATUS)"
    units=$(json 'sum(m["quantity"] for m in d["data"] if m["type"] == "SALIDA_DISPENSACION")')
    [ "$units" = "1" ] || fail "el kardex de la dispensación debía sumar 1 unidad de salida (suma $units)"
    pass "GET /api/v1/kardex: una sola salida de 1 unidad pese al reintento"

    request POST /api/v1/assistant/query "${auth[@]}" --data '{"question":"Que bodegas existen?"}'
    [ "$STATUS" = "200" ] && [ "$(json 'd["data"]["outcome"]')" = "answered" ] || fail "POST /api/v1/assistant/query ($STATUS)"
    pass "POST /api/v1/assistant/query 200 (proveedor configurado)"
else
    echo "  --   comprobaciones con datos demo omitidas (SMOKE_DEMO_CHECKS=${SMOKE_DEMO_CHECKS})"
fi

# 7. Logout revoca el token.
request POST /api/v1/auth/logout -H "Authorization: Bearer ${TOKEN}"
[ "$STATUS" = "204" ] || fail "POST /api/v1/auth/logout ($STATUS)"
request GET /api/v1/auth/me -H "Authorization: Bearer ${TOKEN}"
[ "$STATUS" = "401" ] || fail "el token sigue vigente tras logout ($STATUS)"
pass "POST /api/v1/auth/logout 204 y el token queda revocado"

echo "Smoke test OK"
