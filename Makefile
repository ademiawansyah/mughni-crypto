.PHONY: build up down restart logs shell artisan composer migrate seed test

build:
	docker-compose build

build-fresh:
	docker-compose build --no-cache

up:
	docker-compose up -d --remove-orphans

down:
	docker-compose down

restart: down up

rebuild: down build up

refresh: down build-fresh up

logs:
	docker-compose logs -f

shell:
	docker exec -it al_mughni bash

artisan:
	docker exec -it al_mughni php artisan $(cmd)

composer:
	docker exec -it al_mughni composer $(cmd)

migrate:
	docker exec -it al_mughni php artisan migrate

seed:
	docker exec -it al_mughni php artisan db:seed --force

aux:
	docker exec -it al_mughni ps aux | grep horizon

redis-clear:
	docker exec -it al_mughni php artisan cache:clear
	docker exec -it al_mughni php artisan queue:clear
	docker exec -it al_mughni php artisan config:clear
	docker exec -it al_mughni php artisan route:clear
	docker exec -it al_mughni php artisan view:clear

redis-flush:
	docker exec -it al_mughni php artisan queue:flush
	docker exec -it fotoria php artisan queue:prune-failed


# usage examples:
# make artisan cmd="key:generate"
# make composer cmd="install --no-dev --optimize-autoloader"
