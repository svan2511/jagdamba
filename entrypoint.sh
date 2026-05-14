#!/bin/sh
set -e

echo "Starting Laravel entrypoint on Render..."

# Wait for database (Aiven MySQL)
echo "Waiting for database connection on $DB_HOST:$DB_PORT..."
until nc -z -v $DB_HOST $DB_PORT; do
  >&2 echo "DB not ready yet - sleeping 2s"
  sleep 2
done
echo "Database is ready!"



# Run migrations (idempotent with --force)
echo "Running migrations and seeders..."
php artisan migrate:fresh --seed

# Install Passport keys and clients (safe to run multiple times)
# echo "Installing/Updating Passport..."
# php artisan passport:install --force --no-interaction

# Extra optimizations if needed (already in build, but harmless)
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Entrypoint complete! Starting Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf