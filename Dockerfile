FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer