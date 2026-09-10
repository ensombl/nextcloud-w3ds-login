.PHONY: dev down logs occ shell wait enable restart clean test lint cs appstore

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

# On a fresh volume the entrypoint is still unpacking Nextcloud into
# /var/www/html when the container reports as started, so occ does not exist
# yet. Poll until it answers before running any occ command.
wait:
	@echo "Waiting for Nextcloud to finish installing..."
	@for i in $$(seq 1 120); do \
		if docker compose exec --user www-data app php occ status >/dev/null 2>&1; then \
			echo "Nextcloud is ready."; exit 0; \
		fi; \
		sleep 2; \
	done; \
	echo "Timed out waiting for Nextcloud; check 'make logs'."; exit 1

# Talk (spreed) is not part of the Nextcloud base image, and chat sync is a
# no-op without it, so install it alongside our own app.
enable: wait
	docker compose exec --user www-data app php occ app:install spreed || \
		docker compose exec --user www-data app php occ app:enable spreed
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
