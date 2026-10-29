# AlphaMaster-API — Laravel on PHP 8.5 (Apache mod_php, no CGI)
#
# Targets:
#   production (default, last stage) — hardened runtime image
#   dev                              — dev dependencies for running the test suite (never deployed)

# ---- Composer dependencies (Composer never ships in the production image)
FROM composer:2 AS vendor
WORKDIR /app
COPY src/composer.json src/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY src/ ./
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ---- Shared PHP base (extensions only)
FROM php:8.5-apache AS base
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev \
 && docker-php-ext-install pdo_mysql bcmath intl zip \
 && rm -rf /var/lib/apt/lists/*

# ---- Test image: dev dependencies, no hardening ini (PHPUnit needs putenv)
FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY src/composer.json src/composer.lock ./
RUN composer install --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY src/ ./
RUN composer dump-autoload --optimize \
 && php artisan package:discover --ansi
CMD ["vendor/bin/phpunit"]

# ---- Production runtime
FROM base AS production

# Apache: no CGI handlers, no version leaks, unprivileged port, docroot = public/
COPY apache/security.conf /etc/apache2/conf-available/zz-security.conf
COPY apache/site.conf /etc/apache2/sites-available/000-default.conf
RUN a2dismod -f cgi cgid autoindex status 2>/dev/null || true \
 && a2enmod rewrite headers \
 && a2enconf zz-security \
 && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
 && rm -f /usr/local/bin/php-cgi

WORKDIR /var/www/html
COPY --from=vendor --chown=root:www-data /app /var/www/html

# Package discovery runs before the hardened ini is installed; tests never ship
RUN rm -rf tests phpunit.xml \
 && php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R o-rwx /var/www/html

# Production php.ini + hardening overrides
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY php/security.ini "$PHP_INI_DIR/conf.d/zz-security.ini"

# Run as non-root
USER www-data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD php -d allow_url_fopen=1 -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);'
