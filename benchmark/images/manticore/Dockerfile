# PHP 8.5 CLI plus the host toolchain the Manticore AOT compiler
# (https://github.com/manticorephp/compiler) needs to bootstrap itself:
# clang >= 15 (trixie ships 19), pcre2 / openssl dev headers, pkg-config,
# netbase. The installer is run inside the image so nothing from that
# repository executes on the host.
FROM php:8.5-cli-trixie

ENV MANTICORE_HOME=/opt/manticore
ENV PATH=/opt/manticore/bin:$PATH
# The binary resolves its prelude, its stdlib object and the stdlib interface
# relative to argv[0]; a bare `manticore` from PATH has no directory part, and
# without these the prelude is "not found" and the stdlib is silently not
# linked (every str_replace() becomes "undefined function"). Point at all three.
ENV MANTICORE_PRELUDE=/opt/manticore/lib/prelude
ENV MANTICORE_STDLIB_O=/opt/manticore/lib/manticore_stdlib.o
ENV MANTICORE_STDLIB_SIG=/opt/manticore/lib/manticore_stdlib.o.sig

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git ca-certificates curl bash \
        clang gcc libc6-dev binutils make \
        libpcre2-dev libssl-dev libsqlite3-dev libcurl4-openssl-dev libxml2-dev pkg-config netbase \
        default-mysql-client unzip time procps \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

ARG MANTICORE_REF=main
RUN git clone --depth 1 --branch "$MANTICORE_REF" https://github.com/manticorephp/compiler.git /opt/manticore-src \
    && cd /opt/manticore-src \
    && MANTICORE_SRC=/opt/manticore-src bash install.sh \
    && manticore version

COPY . /opt/phramark

WORKDIR /work
