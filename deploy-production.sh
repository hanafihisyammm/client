#!/bin/bash

# 🚀 Production Deployment Script
# Client Issue & Requirements Tracking System
# Sidra Core Sdn Bhd

echo "🚀 Starting production deployment..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

# Check if running in production environment
if [ "$APP_ENV" != "production" ]; then
    print_warning "APP_ENV is not set to production. Please verify environment configuration."
fi

# 1. Clear all caches
print_info "Clearing application caches..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear
print_status "Caches cleared"

# 2. Install production dependencies
print_info "Installing production dependencies..."
composer install --optimize-autoloader --no-dev --no-interaction
print_status "Composer dependencies installed"

# 3. Run database migrations
print_info "Running database migrations..."
php artisan migrate --force
print_status "Database migrations completed"

# 4. Build production assets
print_info "Building production assets..."
npm ci --only=production
npm run build
print_status "Assets built"

# 5. Create production caches
print_info "Creating production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
print_status "Production caches created"

# 6. Set proper file permissions
print_info "Setting file permissions..."
chmod -R 755 storage bootstrap/cache
chmod -R 644 storage/logs
print_status "File permissions set"

# 7. Link storage
print_info "Linking storage..."
php artisan storage:link
print_status "Storage linked"

# 8. Queue restart (if using queues)
print_info "Restarting queue workers..."
php artisan queue:restart
print_status "Queue workers restarted"

# 9. Verify configuration
print_info "Verifying configuration..."

# Check if APP_DEBUG is false
if php artisan tinker --execute="echo config('app.debug') ? 'true' : 'false';" | grep -q "true"; then
    print_error "APP_DEBUG is still enabled! Set APP_DEBUG=false in production."
else
    print_status "APP_DEBUG is correctly disabled"
fi

# Check if APP_ENV is production
if php artisan tinker --execute="echo config('app.env');" | grep -q "production"; then
    print_status "APP_ENV is correctly set to production"
else
    print_warning "APP_ENV is not set to production"
fi

# Check if config is cached
if [ -f bootstrap/cache/config.php ]; then
    print_status "Configuration is cached"
else
    print_warning "Configuration cache not found"
fi

print_info "Deployment completed!"
echo ""
echo "🎉 Production deployment finished successfully!"
echo ""
print_info "Next steps:"
echo "  1. Update DNS records to point to this server"
echo "  2. Configure SSL certificates"
echo "  3. Set up monitoring and backups"
echo "  4. Test all functionality"
echo ""
print_warning "Remember to:"
echo "  - Monitor application logs"
echo "  - Set up queue workers with supervisor"
echo "  - Configure automated backups"
echo "  - Test email notifications"