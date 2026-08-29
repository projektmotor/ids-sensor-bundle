ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine

# apcu für Zähler, Heartbeat-Drosselung, Breaker-Zustand und Token-Cache. ext-redis ist
# mit dem Wechsel auf die REST-Schnittstelle (Konzept 3.6) entfallen.
RUN apk add --no-cache git unzip $PHPIZE_DEPS \
 && pecl install apcu \
 && docker-php-ext-enable apcu \
 && apk del $PHPIZE_DEPS

COPY --from=composer/composer:2-bin /composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-ids.ini

ENV COMPOSER_CACHE_DIR=/composer-cache

WORKDIR /app
