# Ambiente de desenvolvimento/testes; não é um servidor web de produção.
FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev libonig-dev libpq-dev \
 && docker-php-ext-install pdo_mysql pdo_sqlite pdo_pgsql mbstring zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /srv/cis
COPY . .
# Criar as pastas do Laravel ANTES do Composer: post-autoload-dump executa Artisan.
# /bin/sh (dash) não suporta expansão por chavetas: usar caminhos explícitos.
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
    storage/logs bootstrap/cache database \
 && chmod -R u+rwX storage bootstrap/cache database \
 && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-progress --no-scripts \
 && php artisan package:discover --ansi
COPY docker/entrypoint.sh /usr/local/bin/cis-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/cis-entrypoint && chmod +x /usr/local/bin/cis-entrypoint
EXPOSE 8000
ENTRYPOINT ["/bin/sh", "/usr/local/bin/cis-entrypoint"]
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8000"]
