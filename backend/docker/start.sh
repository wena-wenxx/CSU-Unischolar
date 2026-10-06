#!/bin/sh
# Runs every time the backend container starts.
set -e

# Create the tables (safe to repeat: only new migrations run).
php artisan migrate --force

# Load demo data. Safe to repeat: every seeder skips data that already exists.
# Set SEED_DEMO_DATA=false on the hosting platform to turn this off.
if [ "${SEED_DEMO_DATA:-true}" = "true" ]; then
  php artisan db:seed --force
fi

# Make uploaded files reachable at /storage/...
php artisan storage:link --force || true

# Start the web server on the port the platform gives us.
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
