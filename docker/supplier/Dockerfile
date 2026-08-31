# Заглушка поставщика: один PHP-файл на встроенном сервере.
# Фреймворк здесь был бы лишним — это стенд, а не часть системы.
FROM php:8.3-cli-alpine

RUN apk add --no-cache --virtual .build autoconf g++ make linux-headers \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build \
    && rm -rf /tmp/pear

WORKDIR /srv
COPY index.php /srv/index.php

EXPOSE 8080

# Несколько воркеров: при проверке таймаутов заглушка спит по 30 секунд,
# и однопоточный сервер не смог бы одновременно отвечать на probe.
ENV PHP_CLI_SERVER_WORKERS=8

CMD ["php", "-S", "0.0.0.0:8080", "/srv/index.php"]
