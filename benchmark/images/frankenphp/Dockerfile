# The FrankenPHP stacks (evo-frankenphp and the worker stacks): the same
# PHP version, extensions and benchmark.ini as benchmark/images/php, served by
# FrankenPHP instead of PHP-FPM. The PHP version follows `--versions=php@…`
# like every other image; FrankenPHP itself is the newest 1.x of that tag.
ARG PHP_VERSION=8.4
ARG PHALCON_VERSION=5.23.0
FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm
ARG PHP_VERSION
ARG PHALCON_VERSION

# git: versions.php reads the commit of a cloned Evolution site in here.
# exif: see benchmark/images/php/Dockerfile (sGallery below 1.8.1 requires it).
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions exif gd opcache pdo_mysql zip

# FrankenPHP's PHP is thread-safe and Phalcon's image is not: the same release
# from the thread-safe build Phalcon publishes with it.
ADD https://github.com/phalcon/cphalcon/releases/download/v${PHALCON_VERSION}/phalcon-php${PHP_VERSION}-ts-ubuntu-gcc-x64.zip /tmp/phalcon.zip
RUN unzip -j /tmp/phalcon.zip phalcon.so -d "$(php -r 'echo ini_get("extension_dir");')" \
    && rm /tmp/phalcon.zip \
    && docker-php-ext-enable phalcon

COPY benchmark/config/php.ini /usr/local/etc/php/conf.d/benchmark.ini
COPY benchmark/config/Caddyfile-worker /etc/phramark/Caddyfile
COPY benchmark/images/frankenphp/entrypoint.sh /usr/local/bin/phramark-frankenphp
RUN chmod +x /usr/local/bin/phramark-frankenphp
COPY . /opt/phramark

WORKDIR /var/www/html
