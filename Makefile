SHELL := /bin/bash
DC    := docker compose
APP   := $(DC) exec -T app

.DEFAULT_GOAL := help

.PHONY: help up down restart logs shell migrate fresh seed test race qa stan pint demo explain

help: ## Показать доступные цели
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Поднять окружение с нуля: зависимости, схема, сид (app:8000, pg:5434, redis:6381)
	@test -f .env || cp .env.example .env
	$(DC) build
	@# Хранилища поднимаются первыми: воркерам без них стартовать не в чем,
	@# а зависимостей ещё нет.
	$(DC) up -d postgres redis supplier-redis
	@# Обязательный шаг. На чистом клоне vendor/ отсутствует, и без установки
	@# зависимостей падает всё, начиная с artisan.
	$(DC) run --rm --no-deps app composer install --no-interaction --prefer-dist --no-progress
	@grep -q '^APP_KEY=base64:' .env || $(DC) run --rm --no-deps app php artisan key:generate --force
	$(DC) up -d
	@$(APP) php artisan migrate --force
	@# Отдельная БД под тесты: набор Race работает без обёрточной транзакции
	@# и TRUNCATE'ит таблицы, поэтому в рабочую БД он ходить не должен.
	@$(DC) exec -T postgres psql -U gamestore -d gamestore -tc \
		"SELECT 1 FROM pg_database WHERE datname='gamestore_test'" | grep -q 1 \
		|| $(DC) exec -T postgres createdb -U gamestore gamestore_test
	@$(APP) php artisan db:seed --force
	@echo ""
	@echo "готово: http://localhost:8000 — каталог засеян, можно make demo"

down: ## Остановить и удалить контейнеры
	$(DC) down

restart: ## Перезапустить
	$(DC) restart

logs: ## Логи приложения и воркеров
	$(DC) logs -f app worker-payments worker-delivery

shell: ## Шелл внутри app
	$(DC) exec app sh

migrate: ## Применить миграции
	$(APP) php artisan migrate --force

fresh: ## Пересоздать схему и залить сид (12 SKU + 50 ключей из ТЗ)
	$(APP) php artisan migrate:fresh --seed --force

seed: ## Только сид
	$(APP) php artisan db:seed --force

test: ## Весь набор тестов
	$(APP) php artisan test

race: ## Только состязательные сценарии (критерии приёмки 1-6)
	$(APP) php artisan test --testsuite=Race

# Кеш результатов сбрасывается перед каждым прогоном намеренно. Larastan
# поднимает приложение из bootstrapFiles, и при тёплом кеше после добавления
# новых файлов анализ падает с «Undefined constant LARAVEL_VERSION» — то есть
# инструмент качества сам становится ненадёжным. Четыре лишние секунды дешевле
# команды, которая иногда врёт.
stan: ## PHPStan level 9
	$(APP) ./vendor/bin/phpstan clear-result-cache >/dev/null
	$(APP) ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress

pint: ## Форматирование
	$(APP) ./vendor/bin/pint

qa: ## Полный гейт: стиль + статанализ + тесты (то же гоняет CI)
	$(APP) ./vendor/bin/pint --test
	$(APP) ./vendor/bin/phpstan clear-result-cache >/dev/null
	$(APP) ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	$(APP) php artisan test

demo: ## Сквозной сценарий: заказ -> вебхук -> выдача
	$(APP) php artisan shop:demo

bulk: ## Сгенерировать 5000 SKU для проверки витрины под объёмом
	$(APP) php artisan shop:seed-bulk --count=5000

explain: ## EXPLAIN (ANALYZE, BUFFERS) горячего запроса витрины
	$(APP) php artisan shop:explain-showcase

reconcile: ## Сверка: оплачен но не выдан, выдан но не оплачен, дрейф остатка
	$(APP) php artisan shop:reconcile --full
