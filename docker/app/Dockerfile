# PHP 8.3 намеренно: Pest 5 и PHPUnit 13 требуют 8.4, а хост-машина на 8.3.
# Одна версия и в контейнере, и на хосте — чтобы composer install не расходился.
FROM php:8.3-cli-alpine

RUN apk add --no-cache \
        git unzip icu-dev postgresql-dev linux-headers autoconf g++ make \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl bcmath pcntl sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make linux-headers \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# opcache выключен намеренно: гоночные тесты гоняют код сразу после правок
RUN printf 'memory_limit=512M\nerror_reporting=E_ALL\ndisplay_errors=Off\nlog_errors=On\n' \
    > /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /app

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
