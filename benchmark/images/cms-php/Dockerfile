# Same base and configuration as benchmark/images/php/Dockerfile plus the
# extensions Drupal, TYPO3 and WordPress (mysqli) require, and WP-CLI. Kept
# self-contained on purpose: a
# "FROM phramark-php-parser" would build from whatever that tag held before
# the current compose build, silently drifting from the Evolution images.
# The PHP version is a versionable part too (PHP_VERSION build arg,
# `--versions=php@8.3` in benchmark/scripts/matrix); every stack is built
# from the same tag.
ARG PHP_VERSION=8.4
# Phalcon: see benchmark/images/php/Dockerfile.
ARG PHALCON_VERSION=5.23.0
FROM phalconphp/cphalcon:v${PHALCON_VERSION}-php${PHP_VERSION} AS phalcon
FROM php:${PHP_VERSION}-fpm-bookworm

# opcache: built into PHP 8.5, see benchmark/images/php/Dockerfile.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git default-mysql-client libzip-dev unzip \
        libfreetype6-dev libicu-dev libjpeg62-turbo-dev libonig-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install $(php -r 'echo PHP_VERSION_ID >= 80500 ? "" : "opcache";') pdo_mysql mysqli zip gd intl mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=phalcon /usr/local/lib/php/extensions/*/phalcon.so /tmp/
RUN mv /tmp/phalcon.so "$(php -r 'echo ini_get("extension_dir");')" \
    && docker-php-ext-enable phalcon

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp
COPY benchmark/config/php.ini /usr/local/etc/php/conf.d/benchmark.ini
COPY benchmark/config/php-fpm.conf /usr/local/etc/php-fpm.d/zz-benchmark.conf.template
COPY benchmark/images/php/fpm-entrypoint.sh /usr/local/bin/phramark-fpm
RUN chmod +x /usr/local/bin/phramark-fpm
COPY . /opt/phramark

WORKDIR /var/www/html
