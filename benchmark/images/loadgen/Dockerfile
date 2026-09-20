FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates git libssl-dev zlib1g-dev \
    && git clone --depth 1 https://github.com/giltene/wrk2.git /wrk2 \
    && make -C /wrk2 \
    && install /wrk2/wrk /usr/local/bin/wrk2 \
    && rm -rf /wrk2 /var/lib/apt/lists/*

COPY benchmark/workloads /workloads

ENTRYPOINT ["wrk2"]
