# FARTMAR — atajos de desarrollo. En Windows sin make: .\make.ps1 <objetivo>
# Todo lo de PHP corre dentro del contenedor "api" (no se requiere PHP en el host).
# El frontend corre en el host (Node 24).

COMPOSE := docker compose
API     := $(COMPOSE) exec -T api

.DEFAULT_GOAL := help
.PHONY: help env up down reset logs shell fresh test test-backend test-concurrency test-frontend lint lint-backend lint-frontend fix eval frontend-install frontend-dev

help: ## Lista los objetivos disponibles
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-18s %s\n", $$1, $$2}'

env: ## Crea .env desde .env.example si no existe
	@test -f .env || cp .env.example .env

up: env ## Construye y levanta db + api (espera a que estén saludables)
	$(COMPOSE) up -d --build --wait

down: ## Detiene los contenedores (conserva datos)
	$(COMPOSE) down

reset: ## Borra los volúmenes DE ESTE PROYECTO (fartmar) y levanta desde cero
	$(COMPOSE) down -v
	$(MAKE) up

logs: ## Sigue los logs de la API
	$(COMPOSE) logs -f api

shell: ## Abre una shell en el contenedor de la API
	$(COMPOSE) exec api sh

fresh: ## Recrea el esquema de la BD de desarrollo y ejecuta seeders
	$(API) php artisan migrate:fresh --seed --force

test: test-backend test-frontend ## Ejecuta todas las pruebas

test-backend: ## Pest (contra la BD fartmar_test; incluye el grupo concurrency)
	$(API) ./vendor/bin/pest

test-concurrency: ## Solo las pruebas de concurrencia real (procesos PHP en paralelo)
	$(API) ./vendor/bin/pest --group=concurrency

test-frontend: ## Vitest
	cd frontend && npm run test:run

lint: lint-backend lint-frontend ## Pint, Larastan, ESLint y tsc

lint-backend:
	$(API) ./vendor/bin/pint --test
	$(API) ./vendor/bin/phpstan analyse --no-progress

lint-frontend:
	cd frontend && npm run lint && npm run typecheck

fix: ## Aplica el formato de Pint
	$(API) ./vendor/bin/pint

eval: ## Evaluación del asistente IA con el proveedor mock
	$(API) php artisan assistant:eval --provider=mock

frontend-install: ## npm ci del frontend
	cd frontend && npm ci

frontend-dev: ## Servidor de desarrollo de Vite (http://localhost:5173)
	cd frontend && npm run dev
