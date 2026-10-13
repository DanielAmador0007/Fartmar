# syntax=docker/dockerfile:1.7
# Imagen "web": build estático de la SPA (Vite) servido por Nginx sin root,
# que además hace de borde para la API (FastCGI a PHP-FPM en el servicio "api").
#   build       Node 24 (la misma versión del host y de CI): npm ci + tsc + vite build
#   production  nginx-unprivileged (usuario 101, puerto 8080), solo dist/ y la config
#
# Contexto ./frontend + contexto adicional "infra" (= ./docker):
#   docker build --build-context infra=docker -t fartmar-web frontend

FROM node:24-alpine AS build

WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund

COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
FROM nginxinc/nginx-unprivileged:1.29-alpine AS production

# Parches de seguridad de Alpine publicados después de la imagen base (Trivy en
# CI falla con HIGH/CRITICAL que ya tienen arreglo). Único paso como root.
USER root
RUN apk upgrade --no-cache

COPY --from=infra nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=infra nginx/fartmar/ /etc/nginx/fartmar/
COPY --from=build /app/dist /usr/share/nginx/html

# Archivos de root y solo lectura para el usuario de Nginx.
USER 101
EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
    CMD wget -qO /dev/null http://127.0.0.1:8080/nginx-health || exit 1
