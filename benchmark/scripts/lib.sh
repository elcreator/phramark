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
    8083) stack=drupal; php=phramark-php-drupal-1; nginx=phramark-nginx-drupal-1; target=nginx-drupal ;;
    8084) stack=typo3; php=phramark-php-typo3-1; nginx=phramark-nginx-typo3-1; target=nginx-typo3 ;;
    8085) stack=evo-latte-parser; php=phramark-php-latte-parser-1; nginx=phramark-nginx-latte-parser-1; target=nginx-latte-parser ;;
    8086) stack=winter; php=phramark-php-winter-1; nginx=phramark-nginx-winter-1; target=nginx-winter ;;
    8087) stack=modx; php=phramark-php-modx-1; nginx=phramark-nginx-modx-1; target=nginx-modx ;;
    8088) stack=wordpress-gantry; php=phramark-php-wordpress-gantry-1; nginx=phramark-nginx-wordpress-gantry-1; target=nginx-wordpress-gantry ;;
    *) echo "PORT must be 8080 through 8088" >&2; exit 2 ;;
  esac
}

# The published port of a stack id (the inverse of stack_for_port).
port_for_stack() {
  case "$1" in
    evo-parser) echo 8080 ;;
    evo-latte) echo 8081 ;;
    evo-phalcon) echo 8082 ;;
    drupal) echo 8083 ;;
    typo3) echo 8084 ;;
    evo-latte-parser) echo 8085 ;;
    winter) echo 8086 ;;
    modx) echo 8087 ;;
    wordpress-gantry) echo 8088 ;;
    *) echo "unknown stack '$1' (evo-parser, evo-latte, evo-latte-parser, evo-phalcon, drupal, typo3, winter, modx, wordpress-gantry; evo-manticore is a job without a port)" >&2; return 2 ;;
  esac
}

# The version label of the current run (PHRAMARK_VERSION, e.g.
# "evo@3.5.x+latte@0.4.0", set per stack by matrix --versions) as a result
# file name segment after the stack id: guest-evo-latte~evo@3.5.x-jit-off-….
# Empty without a label. The same rule is in Phramark\VersionSpec::fileTag
# and benchmark/workloads/admin/lib/report.mjs.
version_suffix() {
  [ -z "${PHRAMARK_VERSION:-}" ] || printf '~%s' "$(printf '%s' "$PHRAMARK_VERSION" | sed 's/[^A-Za-z0-9@.+_-]/_/g')"
}

# Records the exact component versions of the stack's container into a
# result file (versions.php --attach), so a result says what it measured.
attach_versions() {
  php benchmark/scripts/versions.php --stack="$1" --attach="$2" --label="${PHRAMARK_VERSION:-}" >/dev/null || echo "versions of $1 not recorded in $2" >&2
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

# Samples a container's resident memory (docker stats) into FILE until
# memory_sampler_stop, one "EPOCH VALUE" line per sample (docker stats itself
# takes a second or two, so the timestamp, not the line number, is the time
# axis); the peak is what the stack needed as a process, as opposed to
# per-request PHP peaks.
memory_sampler_start() {
  container=$1; file=$2
  : > "$file"
  ( while :; do sample=$(docker stats --no-stream --format '{{.MemUsage}}' "$container" 2>/dev/null | sed 's#/.*##'); [ -n "$sample" ] && echo "$(date +%s) $sample" >> "$file"; sleep 1; done ) >/dev/null 2>&1 </dev/null &
  memory_sampler_pid=$!
}
memory_sampler_stop() {
  kill "$memory_sampler_pid" 2>/dev/null || true
  wait "$memory_sampler_pid" 2>/dev/null || true
}
# Converts docker's "123.4MiB"/"1.2GiB" samples to the peak in MiB.
memory_sampler_peak_mib() {
  awk '{ v=$NF; u=v; gsub(/[0-9.]/, "", u); gsub(/[^0-9.]/, "", v); if (u=="GiB") v*=1024; else if (u=="KiB") v/=1024; else if (u=="B") v/=1048576; if (v>max) max=v } END { printf "%.1f", max }' "$1"
}
