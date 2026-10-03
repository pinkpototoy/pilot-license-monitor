#!/bin/sh
set -e

php artisan migrate --force

exec apache2-foreground