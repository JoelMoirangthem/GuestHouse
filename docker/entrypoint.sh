#!/usr/bin/env bash
set -e

cd /var/www/html

# The PORT Render injects must reach Apache's config (defaults to 80 locally).
export PORT="${PORT:-80}"
sed -ri -e "s!Listen [0-9]+!Listen ${PORT}!" /etc/apache2/ports.conf
sed -ri -e "s!:[0-9]+>!:${PORT}>!" /etc/apache2/sites-available/000-default.conf

# Cache framework config/routes/views for speed (safe to re-run).
php artisan config:clear || true
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Ensure a storage symlink exists for any public files.
php artisan storage:link || true

# Run database migrations. Add --seed only on the first deploy (set SEED=true).
if [ "${SEED}" = "true" ]; then
    php artisan migrate --force --seed || true
else
    php artisan migrate --force || true
fi

# Keep role permissions in step with the code on every deploy. The seeder is
# idempotent (update-or-create, then sync), so this never duplicates data; it
# only makes a permission change in RolePermissionSeeder reach the live
# database without a full re-seed.
php artisan db:seed --class=RolePermissionSeeder --force || true

# Start Apache in the foreground (PID 1).
exec apache2-foreground
