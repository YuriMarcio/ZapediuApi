FROM php:8.3-fpm

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql bcmath intl zip gd exif pcntl sockets \
    # phpredis (extensão em C) no lugar do predis (PHP puro): sessão, cache e fila passaram a
    # viver no Redis, então são operações de altíssima frequência — nesse regime a extensão
    # nativa é várias vezes mais rápida. O predis segue no composer como fallback local.
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN echo "upload_max_filesize=20M\npost_max_size=22M\nmemory_limit=256M" \
    > /usr/local/etc/php/conf.d/uploads.ini

# Pool do php-fpm dimensionado para o servidor (4 vCPU / 8 GB). O default da imagem é
# pm.max_children=5, teto que travava a API inteira com poucos checkouts simultâneos.
COPY docker/php/zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupmod -g ${GID} www-data \
    && usermod -u ${UID} -g ${GID} www-data

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN sed -i 's/\r$//' /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["/usr/local/bin/entrypoint"]

CMD ["php-fpm"]