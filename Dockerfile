FROM node:24-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY resources ./resources
COPY templates ./templates

RUN mkdir -p public && npm run build


FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpq-dev \
    && docker-php-ext-install zip pdo pdo_pgsql \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');" \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY composer.json composer.lock Makefile database.sql ./
RUN composer install

COPY public ./public
COPY templates ./templates

COPY --from=assets /app/public/app.css ./public/app.css

USER www-data

CMD ["bash", "-c", "make start"]
