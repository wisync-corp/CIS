# Ambiente de desenvolvimento/testes; não é um servidor web de produção.
FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev libonig-dev \
 && docker-php-ext-install pdo_mysql pdo_sqlite mbstring zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /srv/cis
COPY composer.json ./
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-progress \
 && mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache database \
 && chmod -R u+rwX storage bootstrap/cache database
COPY docker/entrypoint.sh /usr/local/bin/cis-entrypoint
RUN chmod +x /usr/local/bin/cis-entrypoint
EXPOSE 8000
ENTRYPOINT ["cis-entrypoint"]
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8000"]
