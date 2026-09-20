FROM mcr.microsoft.com/playwright:v1.63.0-noble

WORKDIR /workload
COPY benchmark/workloads/admin/package.json benchmark/workloads/admin/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY benchmark/workloads/admin ./

ENV PHRAMARK_RESULTS_DIR=/results
ENTRYPOINT ["npx", "playwright", "test"]
