# 🚀 Production Deployment Checklist

## 📋 Pre-Launch Checklist for Client Issue & Requirements Tracking System

### ✅ 1. Environment Configuration

- [ ] **Set APP_ENV=production** in `.env`
- [ ] **Set APP_DEBUG=false** in `.env`
- [ ] **Generate new APP_KEY** using `php artisan key:generate`
- [ ] **Update APP_URL** to production domain
- [ ] **Remove development API keys** (Xendit development keys)
- [ ] **Set secure database credentials**
- [ ] **Configure production EmailIt API keys**

### ✅ 2. Security Configuration

- [ ] **Enable HTTPS** and update APP_URL
- [ ] **Set SESSION_SECURE_COOKIE=true** for HTTPS
- [ ] **Set SESSION_ENCRYPT=true**
- [ ] **Configure SANCTUM_STATEFUL_DOMAINS** with production domain
- [ ] **Update CORS configuration** in `config/cors.php`
- [ ] **Set strong database passwords**
- [ ] **Configure firewall rules**

### ✅ 3. Performance Optimization

- [ ] **Run config cache**: `php artisan config:cache`
- [ ] **Run route cache**: `php artisan route:cache`
- [ ] **Run view cache**: `php artisan view:cache`
- [ ] **Run event cache**: `php artisan event:cache`
- [ ] **Optimize autoloader**: `composer install --optimize-autoloader --no-dev`
- [ ] **Build production assets**: `npm run build`

### ✅ 4. Database & Storage

- [ ] **Run migrations**: `php artisan migrate --force`
- [ ] **Seed production data**: `php artisan db:seed --class=ProductionSeeder`
- [ ] **Configure S3 storage** for file uploads
- [ ] **Set up database backups**
- [ ] **Configure Redis** for caching and queues

### ✅ 5. Queue & Job Management

- [ ] **Set QUEUE_CONNECTION=redis** in production
- [ ] **Configure queue workers** with supervisor
- [ ] **Set up queue monitoring**
- [ ] **Test email notifications** are working

### ✅ 6. Logging & Monitoring

- [ ] **Set LOG_LEVEL=error** or **warning** in production
- [ ] **Set LOG_CHANNEL=daily** for log rotation
- [ ] **Configure log storage** and rotation
- [ ] **Set up application monitoring**
- [ ] **Configure error tracking** (e.g., Sentry)

### ✅ 7. Web Server Configuration

- [ ] **Configure Nginx/Apache** virtual host
- [ ] **Set up SSL certificates** (Let's Encrypt recommended)
- [ ] **Configure proper file permissions**
  - `storage/` and `bootstrap/cache/` should be writable
  - Other directories should not be writable
- [ ] **Hide sensitive files** (.env, composer files)

### ✅ 8. Testing in Production-like Environment

- [ ] **Test user authentication**
- [ ] **Test email notifications**
- [ ] **Test file uploads**
- [ ] **Test report creation and management**
- [ ] **Test admin functions**
- [ ] **Test API endpoints**

### ✅ 9. Backup & Recovery

- [ ] **Set up automated database backups**
- [ ] **Set up file storage backups**
- [ ] **Create disaster recovery plan**
- [ ] **Test backup restoration process**

## 🔧 Production Commands

### Cache Commands
```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Create production caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### Asset Building
```bash
# Install production dependencies
composer install --optimize-autoloader --no-dev

# Build production assets
npm ci --only=production
npm run build
```

### Queue Management
```bash
# Start queue workers
php artisan queue:work --sleep=3 --tries=3 --max-time=3600

# Or use supervisor for process management
```

## 🛡️ Security Headers (Nginx Example)

```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "no-referrer-when-downgrade" always;
add_header Content-Security-Policy "default-src 'self' http: https: data: blob: 'unsafe-inline'" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

## 📊 Monitoring Endpoints

- [ ] **Health check endpoint**: `/health`
- [ ] **Application monitoring**
- [ ] **Database connection monitoring**
- [ ] **Queue status monitoring**
- [ ] **Email service monitoring**

## 🚨 Emergency Procedures

- [ ] **Maintenance mode**: `php artisan down --refresh=15`
- [ ] **Exit maintenance mode**: `php artisan up`
- [ ] **Database rollback plan**
- [ ] **Emergency contacts list**

---

## ⚠️ IMPORTANT NOTES

1. **Never commit `.env` file** to version control
2. **Always test in staging** environment first
3. **Have rollback plan** ready
4. **Monitor logs** after deployment
5. **Keep backups** of database and files
6. **Update dependencies** regularly for security

## 📞 Support

For deployment issues, contact:
- **System Administrator**: [admin@sidracore.com]
- **Development Team**: [dev@sidracore.com]