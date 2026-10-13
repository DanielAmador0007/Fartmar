#!/usr/bin/env bash
# Smoke test del stack levantado (local o en el runner de CI, deploy-staging).
# Verifica, a través de Nginx: sondas, SPA con headers de seguridad, login con
# un usuario demo, un endpoint autenticado, 401 sin token y logout.
#
#   scripts/smoke-test.sh                       (BASE_URL=http://localhost:8080)
#   BASE_URL=https://staging.example SMOKE_EMAIL=... SMOKE_PASSWORD=... scripts/smoke-test.sh
#
# Solo usa curl, grep y sed (sin jq). Sale con código 1 en la primera falla.
#
# "cond && check || fail": fail termina el script, es justo el "si no" buscado.
# shellcheck disable=SC2015
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
SMOKE_EMAIL="${SMOKE_EMAIL:-auxiliar@fartmar.test}"
SMOKE_PASSWORD="${SMOKE_PASSWORD:-password}"
WAIT_SECONDS="${WAIT_SECONDS:-60}"

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

# 6. Logout revoca el token.
request POST /api/v1/auth/logout -H "Authorization: Bearer ${TOKEN}"
[ "$STATUS" = "204" ] || fail "POST /api/v1/auth/logout ($STATUS)"
request GET /api/v1/auth/me -H "Authorization: Bearer ${TOKEN}"
[ "$STATUS" = "401" ] || fail "el token sigue vigente tras logout ($STATUS)"
pass "POST /api/v1/auth/logout 204 y el token queda revocado"

echo "Smoke test OK"
