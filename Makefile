# ============================================================
# Intechral Client Portal — Developer Makefile
# ============================================================

APP_DIR = intechral-client-portal

.PHONY: help up down restart build install fresh test lint shell logs

# Default target
help:
	@echo ""
	@echo "  Intechral Client Portal — Dev Commands"
	@echo "  ======================================="
	@echo "  make up         Start all containers"
	@echo "  make down       Stop all containers"
	@echo "  make restart    Restart all containers"
	@echo "  make build      Rebuild Docker images"
	@echo "  make install    First-time setup (build + install deps + migrate + seed)"
	@echo "  make fresh      Rebuild DB and re-seed (destroys data)"
	@echo "  make test       Run the full test suite"
	@echo "  make lint       Run Laravel Pint (PHP CS)"
	@echo "  make shell      Open a shell in the app container"
	@echo "  make logs       Tail logs from all containers"
	@echo ""

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose down && docker compose up -d

build:
	docker compose build --no-cache

install: build
	docker compose up -d
	docker compose exec app composer install
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate --seed
	docker compose exec app npm install
	docker compose exec app npm run build
	@echo ""
	@echo "  Setup complete. Open http://localhost:8080"
	@echo ""

fresh:
	docker compose exec app php artisan migrate:fresh --seed
	@echo "Database reset and re-seeded."

test:
	docker compose exec app php artisan test

lint:
	docker compose exec app ./vendor/bin/pint

shell:
	docker compose exec app bash

logs:
	docker compose logs -f
