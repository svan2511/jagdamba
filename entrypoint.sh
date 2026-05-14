#!/bin/sh
set -e

echo "Starting Laravel entrypoint on Render (SQLite)..."

# SQLite ke liye DB wait skip kar sakte ho
echo "Using SQLite - No TCP database server to wait for."

# Optional: Ensure database directory exists
mkdir -p database
touch database/database.sqlite 2>/dev/null || true
chmod 664 database/database.sqlite 2>/dev/null || true

echo "Running migrations and seeders..."
php artisan migrate:fresh --seed --force

# Cache clear & optimize
echo "Optimizing Laravel..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Entrypoint complete! Starting Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf