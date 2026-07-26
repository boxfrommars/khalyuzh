FROM composer:2 AS composer

FROM php:8.3-cli-alpine AS base

RUN apk add --no-cache \
        libxml2-dev \
        oniguruma-dev \
        sqlite \
        sqlite-dev \
    && docker-php-ext-install \
        dom \
        mbstring \
        pdo_sqlite \
        xml \
        xmlwriter

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM base AS development

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist

COPY . .

EXPOSE 8000

CMD ["sh", "bin/start-dev.sh"]

FROM base AS production

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY app ./app
COPY bin ./bin
COPY public ./public

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
