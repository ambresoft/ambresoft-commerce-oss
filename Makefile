.PHONY: install check backend frontend up down

install:
	docker compose build app
	docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist
	pnpm install --frozen-lockfile

check: backend frontend

backend:
	docker compose run --rm app composer check

frontend:
	pnpm check

up:
	docker compose up -d app

down:
	docker compose down
