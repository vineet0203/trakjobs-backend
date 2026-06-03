#!/bin/bash
echo "🚀 Starting deployment..."
cd /var/www/Trakjobs-backend
echo "📥 Pulling latest changes..."
git fetch origin
git reset --hard origin/main
echo "📦 Installing dependencies..."
composer install --no-dev --optimize-autoloader
echo "🔄 Running migrations..."
php artisan migrate --force
echo "🧹 Clearing caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
echo "🔒 Setting permissions..."
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data /var/www/Trakjobs-backend
echo "🔄 Restarting services..."
systemctl reload php8.5-fpm
systemctl reload nginx
echo "✅ Deployment completed!"
