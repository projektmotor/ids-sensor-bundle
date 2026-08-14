ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine

# ext-redis wird für den Redis-Streams-Transport gebraucht, apcu für Zähler/Heartbeat/Breaker.
RUN apk add --no-cache git unzip $PHPIZE_DEPS \
 && pecl install redis apcu \
 && docker-php-ext-enable redis apcu \
 && apk del $PHPIZE_DEPS

COPY --from=composer/composer:2-bin /composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-ids.ini

ENV COMPOSER_CACHE_DIR=/composer-cache

WORKDIR /app
