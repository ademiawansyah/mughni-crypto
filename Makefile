.PHONY: build up down restart logs shell artisan composer migrate seed test run

build:
	docker compose build

build-fresh:
	docker compose build --no-cache

up:
	docker compose up -d --remove-orphans

down:
	docker compose down

restart: down up

refresh: down build up

rebuild: down build-fresh up

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

migrate-force:
	docker exec -it al_mughni php artisan migrate --force

migrate-refresh:
	docker exec -it al_mughni php artisan migrate:fresh
	docker exec -it al_mughni php artisan db:seed --force

migrate-rollback:
	docker exec -it al_mughni php artisan migrate:rollback

seed:
	docker exec -it al_mughni php artisan db:seed --force

aux:
	docker exec -it al_mughni ps aux | grep horizon

redis-clear:
	docker exec -it al_mughni php artisan cache:clear
	docker exec -it al_mughni php artisan queue:clear

cron-run-all:
	docker exec -it al_mughni php artisan cron:run-all

clean-redis:
	docker exec redis_db redis-cli FLUSHALL

redis-flush:
	docker exec -it al_mughni php artisan queue:flush
	docker exec -it al_mughni php artisan queue:prune-failed
	docker exec -it al_mughni php artisan cache:clear
	docker exec -it al_mughni php artisan horizon:clear
	docker exec -it al_mughni php artisan horizon:forget

boost-update:
	docker exec -it al_mughni php artisan boost:update --discover

run-scheduler:
	docker exec -it al_mughni php artisan schedule:work

run-queue:
	docker exec -it al_mughni php artisan queue:work --sleep=3 --tries=3

run-worker:
	docker exec -d al_mughni php artisan schedule:work
	docker exec -it al_mughni php artisan queue:work --sleep=3 --tries=3


# usage examples:
# make artisan cmd="key:generate"
# make composer cmd="install --no-dev --optimize-autoloader"
