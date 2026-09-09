.PHONY: dev down logs occ shell enable restart clean test test-mentions lint cs appstore

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

# Show how @ mentions translate in both directions, without needing a running
# Nextcloud, two linked accounts, or a poll cycle. Runs in a throwaway PHP
# container when php isn't installed locally.
test-mentions:
	@php tests/mention-roundtrip.php 2>/dev/null \
		|| docker run --rm -v "$$PWD":/w -w /w php:8.3-cli php tests/mention-roundtrip.php

lint:
	composer run lint

cs:
	composer run cs:check

appstore:
	krankerl package
