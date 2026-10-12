FROM php:8.3-fpm-alpine

RUN apk add --no-cache git unzip libzip-dev postgresql-dev icu-dev \
    && docker-php-ext-install pdo_pgsql zip intl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
