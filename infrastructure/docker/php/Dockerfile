# Production PHP-FPM 8.4 image for Laravel apps (owner-console + project template).
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
      pdo pdo_pgsql pgsql \
      opcache intl zip bcmath exif pcntl sockets gd \
    && rm -rf /tmp/* /var/cache/apk/*

# PECL redis needs a compiler toolchain (phpize). Install as throwaway build-deps.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis opcache \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

COPY conf/php.ini /usr/local/etc/php/conf.d/99-production.ini
COPY conf/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY conf/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf

WORKDIR /var/www/html
EXPOSE 9000
CMD ["php-fpm", "-F"]
COPY fpm-healthcheck.sh /usr/local/bin/fpm-healthcheck.sh
RUN chmod +x /usr/local/bin/fpm-healthcheck.sh
