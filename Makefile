.PHONY: dev down logs occ shell enable restart clean test lint cs appstore

dev:
	docker compose up --build -d

down:
	docker compose down

logs:
	docker compose logs -f app

occ:
	docker compose exec --user www-data app php occ $(CMD)

shell:
	docker compose exec app bash

enable:
	docker compose exec --user www-data app php occ app:enable w3ds_login

restart:
	docker compose restart app

clean:
	docker compose down -v

test:
	composer run test

lint:
	composer run lint

cs:
	composer run cs:check

appstore:
	krankerl package
