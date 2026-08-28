FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    libxml2-dev \
    oniguruma-dev \
    nginx \
    gettext

RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    bcmath \
    zip

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV CHROME_BIN=/usr/bin/chromium-browser
ENV CHROME_DRIVER=/usr/bin/chromedriver

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chmod -R 775 storage bootstrap/cache \
    && composer install --no-dev --prefer-dist \
    && composer dump-autoload \
    && php artisan storage:link

COPY docker/nginx/railway.conf /etc/nginx/http.d/default.conf.template
COPY docker/nginx/railway.conf /etc/nginx/conf.d/default.conf.template
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY start.sh /start.sh

RUN mkdir -p /var/www/storage/logs \
    && chmod +x /start.sh \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 8080
EXPOSE 9000

CMD ["/start.sh"]
