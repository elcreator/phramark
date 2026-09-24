# The PHP version is a versionable part too (PHP_VERSION build arg,
# `--versions=php@8.3` in benchmark/scripts/matrix); every stack is built
# from the same tag.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git default-mysql-client libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd opcache pdo_mysql zip \
    && pecl install phalcon-5.9.3 \
    && docker-php-ext-enable phalcon \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
COPY benchmark/config/php.ini /usr/local/etc/php/conf.d/benchmark.ini
COPY benchmark/config/php-fpm.conf /usr/local/etc/php-fpm.d/zz-benchmark.conf.template
COPY benchmark/images/php/fpm-entrypoint.sh /usr/local/bin/phramark-fpm
RUN chmod +x /usr/local/bin/phramark-fpm
COPY . /opt/phramark

WORKDIR /var/www/html
