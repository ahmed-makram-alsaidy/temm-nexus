# ═══════════════════════════════════════════════════════════════════
#  PRODUCTION APPLICATION IMAGE (self-hosted distribution)
#
#  Self-contained: runtime layers mirror infrastructure/docker/php/Dockerfile
#  (keep both in sync), then the application code is baked in and
#  dependencies are installed from composer.lock. Build context is the
#  repository root — the root .dockerignore keeps private/local content
#  (projects/, backups/, docs/, .env, vendor/) out of the build.
#
#  Build:  docker compose -f docker-compose.prod.yml build
# ═══════════════════════════════════════════════════════════════════
FROM php:8.4.25-fpm-alpine

ENV TZ=UTC \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apk add --no-cache \
      linux-headers \
      postgresql-dev postgresql-libs \
      icu-dev icu-libs \
      libzip-dev oniguruma-dev \
      freetype-dev libjpeg-turbo-dev libpng-dev \
      hiredis-dev \
      fcgi \
      supervisor \
      wget \
      postgresql-client \
      curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
      pdo pdo_pgsql pgsql pdo_mysql \
      opcache intl zip bcmath exif pcntl sockets gd \
    && rm -rf /tmp/* /var/cache/apk/*

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis opcache \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

COPY infrastructure/docker/php/conf/php.ini /usr/local/etc/php/conf.d/99-production.ini
COPY infrastructure/docker/php/conf/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY infrastructure/docker/php/conf/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY infrastructure/docker/php/fpm-healthcheck.sh /usr/local/bin/fpm-healthcheck.sh
RUN chmod +x /usr/local/bin/fpm-healthcheck.sh

WORKDIR /var/www/html

# Dependencies first (layer cache): manifest, then vendor from lock.
COPY apps/owner-console/composer.json apps/owner-console/composer.lock /var/www/html/
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --2.8 \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && rm -rf /root/.composer

# Application code (vendor/ is excluded by .dockerignore so the fresh
# install above is not shadowed).
COPY apps/owner-console /var/www/html
COPY VERSION /var/www/html/VERSION

RUN mkdir -p \
      bootstrap/cache \
      storage/framework/cache \
      storage/framework/sessions \
      storage/framework/views \
      storage/app/private \
      storage/app/public \
      storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x artisan

# Frontend assets: Filament's compiled CSS/JS/fonts are generated artifacts
# (gitignored in the source tree, so the COPY above ships without them).
# Publish them here so the image is self-contained; the one-shot compose
# `assets` service re-publishes the same output into the host public/ docroot
# that Caddy serves at the edge.
RUN php artisan filament:assets

EXPOSE 9000
CMD ["php-fpm", "-F"]
