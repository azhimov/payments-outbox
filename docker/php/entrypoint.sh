#!/bin/sh
set -e

# A demo has no secrets to share: every container may generate its own key.
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(php artisan key:generate --show --no-ansi)"
    export APP_KEY
fi

# Only the api container migrates; relay and consumers wait for it (see compose healthchecks).
if [ "$RUN_MIGRATIONS" = "1" ]; then
    php artisan migrate --force --no-interaction
    # The fictional Demo Store merchant (idempotent).
    php artisan db:seed --force --no-interaction
fi

exec "$@"
