# FARTMAR — atajos. En Windows sin make: .\make.ps1 <objetivo>
# Todo lo de PHP corre en contenedores (no se requiere PHP en el host).
#   make up    stack tipo producción (lo que corre el evaluador): http://localhost:8080
#   make dev   entorno de desarrollo (código montado, Pest, Larastan); test, lint,
#              eval y fresh lo levantan solos si hace falta.
# El frontend de desarrollo corre en el host (Node 24): make frontend-dev.

COMPOSE     := docker compose
COMPOSE_DEV := docker compose -f docker-compose.yml -f docker-compose.dev.yml
API         := $(COMPOSE_DEV) exec -T api
DEV_IMAGE   := fartmar-api-dev:local

.DEFAULT_GOAL := help
.PHONY: help env up dev dev-ensure down reset logs shell fresh test test-backend test-concurrency test-frontend lint lint-backend lint-frontend fix eval smoke backup restore frontend-install frontend-dev

help: ## Lista los objetivos disponibles
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-18s %s\n", $$1, $$2}'

env: ## Crea .env desde .env.example si no existe
	@test -f .env || cp .env.example .env

up: env ## Stack tipo producción desde cero o actualizado (espera a que todo esté sano)
	$(COMPOSE) up -d --build --wait

dev: env ## Entorno de desarrollo (override docker-compose.dev.yml)
	$(COMPOSE_DEV) up -d --build --wait

# Si la API que corre no es la de desarrollo (p. ej. tras `make up`), levanta el
# entorno de desarrollo antes de ejecutar Pest/Larastan/eval.
dev-ensure: env
	@if [ "$$(docker inspect -f '{{.Config.Image}}' $$($(COMPOSE) ps -q api) 2>/dev/null)" != "$(DEV_IMAGE)" ]; then \
		echo "La API en ejecución no es la de desarrollo: levantando make dev..."; \
		$(COMPOSE_DEV) up -d --build --wait; \
	fi

down: ## Detiene los contenedores (conserva datos)
	$(COMPOSE_DEV) down

reset: ## Borra los volúmenes DE ESTE PROYECTO (fartmar) y levanta desarrollo desde cero
	$(COMPOSE_DEV) down -v
	$(MAKE) dev

logs: ## Sigue los logs de api, web y scheduler
	$(COMPOSE) logs -f api web scheduler

shell: ## Abre una shell en el contenedor de la API
	$(COMPOSE) exec api sh

fresh: dev-ensure ## Recrea el esquema de la BD de desarrollo y ejecuta seeders
	$(API) php artisan migrate:fresh --seed --force

test: test-backend test-frontend ## Ejecuta todas las pruebas

test-backend: dev-ensure ## Pest (BD fartmar_test; incluye el grupo concurrency)
	$(API) ./vendor/bin/pest

test-concurrency: dev-ensure ## Solo las pruebas de concurrencia real (procesos PHP en paralelo)
	$(API) ./vendor/bin/pest --group=concurrency

test-frontend: ## Vitest
	cd frontend && npm run test:run

lint: lint-backend lint-frontend ## Pint, Larastan, ESLint y tsc

lint-backend: dev-ensure
	$(API) ./vendor/bin/pint --test
	$(API) ./vendor/bin/phpstan analyse --no-progress

lint-frontend:
	cd frontend && npm run lint && npm run typecheck

fix: dev-ensure ## Aplica el formato de Pint
	$(API) ./vendor/bin/pint

eval: dev-ensure ## Evaluación del asistente IA (mock) sobre la BD semilla; `make fresh` para partir de datos limpios
	$(API) php artisan assistant:eval --provider=mock

smoke: ## Smoke test contra el stack levantado (BASE_URL=http://localhost:8080)
	bash scripts/smoke-test.sh

backup: ## Respaldo pg_dump -Fc en backups/ (RETENTION_DAYS=14)
	bash scripts/backup.sh

restore: ## Prueba de restauración en BD temporal: make restore FILE=backups/x.dump
	bash scripts/restore.sh "$(FILE)"

frontend-install: ## npm ci del frontend
	cd frontend && npm ci

frontend-dev: ## Servidor de desarrollo de Vite (http://localhost:5173)
	cd frontend && npm run dev
