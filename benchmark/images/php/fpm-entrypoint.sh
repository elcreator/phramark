#!/usr/bin/env sh
set -eu

workers=${PHP_FPM_PM_MAX_CHILDREN:-8}
jit_mode=${PHP_JIT:-off}

case "$workers" in
  ''|*[!0-9]*|0) echo 'PHP_FPM_PM_MAX_CHILDREN must be a positive integer.' >&2; exit 2 ;;
esac

case "$jit_mode" in
  off|0) jit=0; buffer=0 ;;
  tracing|1) jit=tracing; buffer=128M ;;
  *) echo 'PHP_JIT must be off or tracing.' >&2; exit 2 ;;
esac

sed "s/__PHP_FPM_PM_MAX_CHILDREN__/$workers/" /usr/local/etc/php-fpm.d/zz-benchmark.conf.template > /usr/local/etc/php-fpm.d/zz-benchmark.conf
printf 'opcache.jit=%s\nopcache.jit_buffer_size=%s\n' "$jit" "$buffer" > /usr/local/etc/php/conf.d/zz-benchmark-jit.ini

mkdir -p /var/log/phramark && : > /var/log/phramark/memory.log && chown -R www-data:www-data /var/log/phramark

exec php-fpm -F
