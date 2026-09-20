# Shared helpers for the benchmark scripts (POSIX sh, sourced).

# Git Bash on Windows rewrites arguments that look like POSIX paths before
# handing them to docker; container paths must reach docker untouched.
export MSYS_NO_PATHCONV=1

# Maps a published port to the stack id, its PHP-FPM and nginx containers.
stack_for_port() {
  case "$1" in
    8080) stack=evo-parser; php=phramark-php-parser-1; nginx=phramark-nginx-parser-1; target=nginx-parser ;;
    8081) stack=evo-latte; php=phramark-php-latte-1; nginx=phramark-nginx-latte-1; target=nginx-latte ;;
    8082) stack=evo-phalcon; php=phramark-php-phalcon-1; nginx=phramark-nginx-phalcon-1; target=nginx-phalcon ;;
    8083) stack=drupal-11; php=phramark-php-drupal-11-1; nginx=phramark-nginx-drupal-11-1; target=nginx-drupal-11 ;;
    8084) stack=typo3; php=phramark-php-typo3-1; nginx=phramark-nginx-typo3-1; target=nginx-typo3 ;;
    8085) stack=evo-latte-parser; php=phramark-php-latte-parser-1; nginx=phramark-nginx-latte-parser-1; target=nginx-latte-parser ;;
    *) echo "PORT must be 8080 through 8085" >&2; exit 2 ;;
  esac
}

# Reads the JIT mode the running FPM container was started with, so a result
# is labelled by what actually ran rather than by what the caller intended.
jit_for_container() {
  mode=$(docker exec "$1" sed -n 's/^opcache\.jit=//p' /usr/local/etc/php/conf.d/zz-benchmark-jit.ini 2>/dev/null || true)
  case "$mode" in
    tracing) echo tracing ;;
    0|'') echo off ;;
    *) echo "$mode" ;;
  esac
}

# Repository root in a form Docker on this host accepts as a bind mount.
repo_root() {
  if command -v cygpath >/dev/null 2>&1; then
    cygpath -w "$(pwd)"
  else
    pwd
  fi
}

# PHP-side memory log of a stack (benchmark/fixtures/memory-prepend.php).
memory_log_reset() {
  docker exec "$1" sh -c ': > /var/log/phramark/memory.log'
}
memory_log_fetch() {
  docker exec "$1" cat /var/log/phramark/memory.log > "$2"
}

# Samples a container's resident memory (docker stats) once a second into
# FILE until memory_sampler_stop; the peak is what the stack needed as a
# process, as opposed to per-request PHP peaks.
memory_sampler_start() {
  container=$1; file=$2
  : > "$file"
  ( while :; do docker stats --no-stream --format '{{.MemUsage}}' "$container" 2>/dev/null | sed 's#/.*##' >> "$file"; sleep 1; done ) >/dev/null 2>&1 </dev/null &
  memory_sampler_pid=$!
}
memory_sampler_stop() {
  kill "$memory_sampler_pid" 2>/dev/null || true
  wait "$memory_sampler_pid" 2>/dev/null || true
}
# Converts docker's "123.4MiB"/"1.2GiB" samples to the peak in MiB.
memory_sampler_peak_mib() {
  awk '{ v=$1; u=v; gsub(/[0-9.]/, "", u); gsub(/[^0-9.]/, "", v); if (u=="GiB") v*=1024; else if (u=="KiB") v/=1024; else if (u=="B") v/=1048576; if (v>max) max=v } END { printf "%.1f", max }' "$1"
}
