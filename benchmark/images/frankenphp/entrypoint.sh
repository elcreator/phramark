#!/usr/bin/env sh
# FrankenPHP counterpart of fpm-entrypoint.sh: the same JIT, memory limit and
# memory log, with PHP_FPM_PM_MAX_CHILDREN as the number of Evolution workers.
set -eu

workers=${PHP_FPM_PM_MAX_CHILDREN:-8}
jit_mode=${PHP_JIT:-off}
memory_limit=${PHP_MEMORY_LIMIT:-}

case "$workers" in
  ''|*[!0-9]*|0) echo 'PHP_FPM_PM_MAX_CHILDREN must be a positive integer.' >&2; exit 2 ;;
esac

case "$jit_mode" in
  off|0) jit=0; buffer=0 ;;
  tracing|1) jit=tracing; buffer=128M ;;
  *) echo 'PHP_JIT must be off or tracing.' >&2; exit 2 ;;
esac

printf 'opcache.jit=%s\nopcache.jit_buffer_size=%s\n' "$jit" "$buffer" > /usr/local/etc/php/conf.d/zz-benchmark-jit.ini
if [ -n "$memory_limit" ]; then
  printf 'memory_limit=%s\n' "$memory_limit" > /usr/local/etc/php/conf.d/zz-benchmark-memory.ini
else
  rm -f /usr/local/etc/php/conf.d/zz-benchmark-memory.ini
fi

mkdir -p /var/log/phramark && : > /var/log/phramark/memory.log && chmod 666 /var/log/phramark/memory.log

PHRAMARK_THREADS=$workers
export PHRAMARK_THREADS

if [ "${EVO_WORKER_MODE:-0}" = 1 ]; then
  if [ ! -f /var/www/html/core/includes/worker.runtime.php ]; then
    echo 'This site has no FrankenPHP worker mode (core/includes/worker.runtime.php); install it from an Evolution that has it, e.g. --versions=evo@3.6.x.' >&2
    exit 1
  fi

  # Workers serve the front end; classic PHP threads serve the manager too.
  # FrankenPHP starts worker threads on top of num_threads (1.13: 8 + 8).
  PHRAMARK_WORKERS=$workers
  PHRAMARK_WORKER_HOST=${EVO_WORKER_ORIGIN#*://}
  export PHRAMARK_WORKERS PHRAMARK_WORKER_HOST
fi

exec frankenphp run --config /etc/phramark/Caddyfile --adapter caddyfile
