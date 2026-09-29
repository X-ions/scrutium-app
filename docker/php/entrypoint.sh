#!/usr/bin/env bash
#
# Container entrypoint.
#
# Only the web/worker role runs migrations. If every replica ran them at boot,
# a rolling deploy would have N processes racing to alter the same tables, and
# a partially-applied migration would take the app down mid-deploy. The `migrate`
# role is a deliberate, single-shot step in the deploy instead.

set -euo pipefail

cd /var/www/html

ROLE="${APP_ROLE:-web}"

wait_for_database() {
    local attempts="${DB_WAIT_ATTEMPTS:-30}"

    echo "Waiting for the database…"

    for ((i = 1; i <= attempts; i++)); do
        if php artisan db:show --json >/dev/null 2>&1 || php artisan migrate:status >/dev/null 2>&1; then
            echo "Database is available."

            return 0
        fi

        sleep 2
    done

    echo "Database did not become available after ${attempts} attempts." >&2

    return 1
}

# Published assets are built in a separate stage. A missing manifest means the
# image is broken, and failing loudly here beats a blank page in production.
if [ ! -f public/build/manifest.json ]; then
    echo "public/build/manifest.json is missing. The asset build stage did not run." >&2

    exit 1
fi

php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true
php artisan view:clear >/dev/null 2>&1 || true

case "$ROLE" in
    web)
        wait_for_database
        php artisan storage:link --force >/dev/null 2>&1 || true
        exec php-fpm
        ;;

    worker)
        wait_for_database
        # The scheduler should fire one job per minute on exactly one replica.
        # Running it here as well as in the scheduler service would double-dispatch.
        exec php artisan queue:work \
            --queue="${APP_QUEUE:-default},publishing,webhooks" \
            --sleep="${QUEUE_SLEEP:-3}" \
            --tries="${QUEUE_TRIES:-3}" \
            --max-time="${QUEUE_MAX_TIME:-3600}" \
            --max-jobs="${QUEUE_MAX_JOBS:-1000}" \
            --name="worker-$$" \
            --verbose
        ;;

    scheduler)
        wait_for_database
        exec php artisan schedule:work
        ;;

    migrate)
        wait_for_database
        php artisan migrate --force
        php artisan storage:link --force >/dev/null 2>&1 || true
        exec php artisan config:cache
        ;;

    command)
        wait_for_database
        exec php "$@"
        ;;

    *)
        exec "$@"
        ;;
esac
