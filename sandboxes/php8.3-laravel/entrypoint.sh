#!/bin/bash
set -e

if [ -f composer.json ]; then
    composer install --no-interaction --no-progress
fi

if [ -f .env.example ] && [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate
fi

php artisan serve --host=0.0.0.0 --port=8000