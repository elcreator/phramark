# The FrankenPHP stacks (evo-frankenphp, evo-worker, evo-worker-keep): the same
# PHP version, extensions and benchmark.ini as benchmark/images/php, served by
# FrankenPHP instead of PHP-FPM. The PHP version follows `--versions=php@…`
# like every other image; FrankenPHP itself is the newest 1.x of that tag.
ARG PHP_VERSION=8.4
FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm

# git: versions.php reads the commit of a cloned Evolution site in here.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions exif gd opcache pdo_mysql zip

COPY benchmark/config/php.ini /usr/local/etc/php/conf.d/benchmark.ini
COPY benchmark/config/Caddyfile-worker /etc/phramark/Caddyfile
COPY benchmark/images/frankenphp/entrypoint.sh /usr/local/bin/phramark-frankenphp
RUN chmod +x /usr/local/bin/phramark-frankenphp
COPY . /opt/phramark

WORKDIR /var/www/html
