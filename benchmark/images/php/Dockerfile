# The PHP version is a versionable part too (PHP_VERSION build arg,
# `--versions=php@8.3` in benchmark/scripts/matrix); every stack is built
# from the same tag.
ARG PHP_VERSION=8.4
# The newest Phalcon release; its image has no floating tag, so a new release is
# a new value here and in the other two PHP images. Only the extension is taken:
# the image also loads apcu, imagick, redis and more, which no stack may measure,
# and phalcon.so needs nothing but libc, so it runs on this Debian too.
ARG PHALCON_VERSION=5.23.0
FROM phalconphp/cphalcon:v${PHALCON_VERSION}-php${PHP_VERSION} AS phalcon
FROM php:${PHP_VERSION}-fpm-bookworm

# exif: Evolution 3.6.x locks seiger/sgallery below 1.8.1, which requires
# spatie/image and with it ext-exif; sGallery 1.8.1 makes both optional. Drop it
# once no Evolution ref the matrix runs locks the older sGallery.
# opcache is built into PHP 8.5, which Evolution 3.6 supports:
# docker-php-ext-install has no module to install there.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git default-mysql-client libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install exif gd $(php -r 'echo PHP_VERSION_ID >= 80500 ? "" : "opcache";') pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=phalcon /usr/local/lib/php/extensions/*/phalcon.so /tmp/
RUN mv /tmp/phalcon.so "$(php -r 'echo ini_get("extension_dir");')" \
    && docker-php-ext-enable phalcon

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
COPY benchmark/config/php.ini /usr/local/etc/php/conf.d/benchmark.ini
COPY benchmark/config/php-fpm.conf /usr/local/etc/php-fpm.d/zz-benchmark.conf.template
COPY benchmark/images/php/fpm-entrypoint.sh /usr/local/bin/phramark-fpm
RUN chmod +x /usr/local/bin/phramark-fpm
COPY . /opt/phramark

WORKDIR /var/www/html
