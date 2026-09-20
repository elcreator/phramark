# Phramark

Phramark measures how PHP framework components behave inside real CMS request paths. It has two workloads over one deterministic fixture (100 categories, 10,000 articles, seed `424242`):

| Workload | Driver | What it measures |
| --- | --- | --- |
| `guest` | wrk2 at a fixed offered rate over all 100 category pages | Throughput capacity and latency of the public request path |
| `admin` | Playwright session in the CMS manager | Latency of editorial actions: log in, edit 5 pages (append `EDITED` to title and body, save), create 5 pages (save), log out |

Both run with PHP JIT off or on, which gives the four-cell matrix per stack: guest/JIT off, guest/JIT tracing, admin/JIT off, admin/JIT tracing.

## Stacks and what they really execute

The framework column is not the marketing name: it is what a warm category request actually loads, recorded by `benchmark/scripts/trace-request` (included files per Composer package, userland classes per namespace) and asserted against `Phramark\RuntimeProfile` by `benchmark/scripts/trace-summary.php`.

| ID | Port | Request path (traced) | Notes |
| --- | --- | --- | --- |
| `evo-parser` | 8080 | Evolution 3.5 core on **Laravel/Illuminate** (≈97 Illuminate classes, 213 Illuminate files: database, view, routing, container) + Symfony http-foundation/finder | Normal Evolution frontend, snippet in the parser |
| `evo-latte` | 8081 | Same as above + Latte | aLatteX file view with `evo_tags=false`: the Latte output is final, the core's tag passes (`parseDocumentSource`, `[!…!]`, `cleanUpMODXTags`/`rewriteUrls`) are skipped |
| `evo-latte-parser` | 8085 | Same as `evo-latte` | The identical Latte view with `evo_tags=true` (the aLatteX default): the core runs its tag passes over the rendered output. `evo-latte-parser` − `evo-latte` is the cost of that pass on a page without EVO tags |
| `evo-phalcon` | 8082 | Same Illuminate bootstrap (≈86 classes) + **Phalcon** DB adapter (extension) + Latte | aPhalcon `frontend.takeover=true`. It is *not* a pure Phalcon stack: Evolution's Laravel-based core still boots, Phalcon replaces the data access |
| `drupal-11` | 8083 | Drupal 11 core (511 core files) on **Symfony** HttpKernel/Routing/HttpFoundation + Twig | Route controller, Drupal DB API, Twig render array returned as a bare response |
| `typo3` | 8084 | TYPO3 14 core on **Doctrine DBAL** + Fluid; Symfony only for DI/translation (12 classes) | PSR-15 middleware before site resolution, DBAL query builder, Fluid view |

October CMS is not part of the comparison: its Composer distribution requires a licence key, which the harness cannot depend on.

Every stack serves `/articles/category-042` (no trailing slash, HTTP 200, no redirect) with the same visible contract: 20 article cards with `hero_image`, `author` and `reading_time` values whose presence follows the fixture's deterministic TV distribution. `benchmark/scripts/verify` checks this contract on every reachable stack, from the host and (in `bench`) from inside the compose network, the way the load generator sees it.

## Fairness review: what was wrong and what changed

The first result set in this repository compared stacks that were not doing the same work. All of it is fixed and now guarded by tests or the verify gate.

| Finding | Effect on the numbers | Fix |
| --- | --- | --- |
| The seed never assigned the TVs to the benchmark template (`site_tmplvar_templates` empty), so `evo-parser` and `evo-latte` rendered **empty** author/hero/reading-time values while `evo-phalcon` read the value table directly | The two "slow" Evolution stacks were measured doing less rendering work, not more | Seed the assignment; `verify` fails any stack with missing values |
| The parser template wrapped the page in `<main>…</main>`, producing a second document around the doctype | Different bytes per stack | Template is `[[benchmarkCategory]]` only |
| Evolution `seostrict` redirected the canonical URL (302 → trailing slash) while the wrk2 workload used the slash form and the warm-up used the other | Warm-up warmed a redirect; a redirect counts as success in wrk | `seostrict=0`; one canonical URL form everywhere; `verify` rejects redirects |
| Drupal route `/articles/category-{category}` never matched (Drupal placeholders must span a whole segment) and the controller was instantiated without its service (leading `\` in the route) | Drupal always answered 404 | `/articles/{category}` with a requirement, `ContainerInjectionInterface` |
| Drupal rendered the contract inside its full theme page (branding block, second doctype) while TYPO3 returns the bare template | Drupal did more work than its peers | Controller returns the rendered render array as a bare `Response` |
| TYPO3 middleware had no `Services.yaml` (no autowiring), was ordered after site resolution with no site configured, used the removed `StandaloneView` and a `\PDO::PARAM_INT` type the DBAL rejects | TYPO3 always answered 404/500 | Fixed for TYPO3 14 (view factory, `ParameterType`), ordered before `typo3/cms-frontend/site` |
| TYPO3 `trustedHostsPattern` allowed only `localhost`, but the load generator connects as `nginx-typo3:80` | 100 % HTTP 500 under load with fast latencies; single host requests passed | Trust all hosts; `bench` verifies through the compose network too |
| OPcache left at defaults (128 MiB, 10,000 files) while Drupal and TYPO3 load 500–700 files per request from trees far larger than that | Restart/eviction risk only for the larger CMSs | 512 MiB, 100,000 files, realpath cache, identical for all |
| nginx resolved the FPM address once at start; a recreated FPM container (JIT switch, reset) silently broke the stack | 502s or, worse, requests routed to another stack's FPM | Docker DNS resolver with per-request upstream variable, on every stack |
| `opcache.validate_timestamps=0` keeps Evolution's PHP site cache and any code change until FPM restarts | Re-seeded settings invisible to the running stack | Setup and reset scripts restart FPM; documented |
| Results were collected at an offered rate (100 rps) far above two stacks' capacity | wrk2's coordinated-omission correction turns saturation into 15 s "latencies" | `run` prints a saturation note; compare at a rate every stack sustains, then search capacity |

Remaining, documented differences that are design choices rather than bugs:

- The Evolution stacks resolve a real document tree (closure table, alias path) and read TVs from an EAV table; `evo-parser`/`evo-latte` do it with `getTemplateVarOutput('*')` per card (N+1 queries), `evo-phalcon` with one hand-written `IN (…)` query that also skips document-group access checks. Drupal and TYPO3 read one flat, indexed `phramark_article` table. The stacks are therefore comparable as "CMS-native request path over the same fixture", not as identical SQL.
- Both cross-CMS adapters bypass their CMS page/theme layer (Drupal now as well). Only the Evolution stacks run a full CMS front controller.
- The always-on PHP memory probe (`auto_prepend_file`) costs one shutdown function and a file append per request on every stack alike.
- Load generator, MySQL, PHP-FPM and nginx share one host. Use a separate load host for public numbers.

## Run the demo

```sh
docker compose -f benchmark/compose.yaml up --build                        # evo-parser, evo-latte, evo-phalcon
docker compose -f benchmark/compose.yaml --profile cross-cms up --build    # + drupal-11, typo3
```

Then open http://127.0.0.1:8080/articles/category-042 (8081 … 8085 for the other stacks). Use `127.0.0.1`, not `localhost`: Chromium's dual-stack connect to Docker Desktop's port proxy can stall for 30 s per connection.

Admin panels (user `benchmark` everywhere):

| Stack | Admin URL | Password | Admin fixture |
| --- | --- | --- | --- |
| evo-* | http://127.0.0.1:8080/manager/ (8081, 8082, 8085) | `benchmark-admin` | Folder "Admin workload" (id 10103) with pages 10104–10108 |
| drupal-11 | http://127.0.0.1:8083/user/login | `benchmark-admin` | Content type "Basic page" with a plain-text body; the five lowest page nodes |
| typo3 | http://127.0.0.1:8084/typo3/ | `Benchmark1!` | Root page "Admin workload" (uid 10103) with pages 10104–10108, each with one text element of the same uid |

Re-running `up` re-seeds the fixture and re-syncs adapter code into existing volumes. The setup containers restart nothing, so after a re-seed restart the FPM containers (`docker compose … up -d --force-recreate php-parser …`) or use `bench`, which does.

## The matrix

`benchmark/scripts/bench WORKLOAD JIT PORT [OFFERED_RPS]` runs one cell: it recreates the stack's FPM with `PHP_JIT` set, warms all 100 category pages, runs the fairness gate from the host and from inside the compose network, then runs the workload. The JIT mode is read back from the container into the result name, so a result can never claim a mode that did not run.

```sh
benchmark/scripts/bench guest off     8080 100
benchmark/scripts/bench guest tracing 8080 100
benchmark/scripts/bench admin off     8080
benchmark/scripts/bench admin tracing 8080
```

Keep `PHP_FPM_PM_MAX_CHILDREN` (default 8), `DURATION` (default 60s), `CONNECTIONS` (32) and `ROUNDS` identical across the cells you compare. Results:

| Cell | File |
| --- | --- |
| guest | `benchmark/results/guest-<stack>-jit-<mode>-rps-<rate>.txt` (raw wrk2 output with the full HdrHistogram) |
| admin | `benchmark/results/admin-<stack>-jit-<mode>-<timestamp>.json` and `.txt` (per-step wall time and server time, summary per action) |

Capacity is the highest offered rate with zero non-2xx responses and p99 below 100 ms; wrk2 reports coordinated-omission-corrected latency, so an offered rate above capacity shows queueing time, not request time. Production reporting: separate load host, 60 s warm-up, 120 s measurement, five repetitions, keep all raw output.

### Admin workload details

`benchmark/workloads/admin` is a Playwright project with one adapter per CMS (`lib/adapters/`): Evolution manager (iframe shell, `a=27`/`a=4`/`a=5` document forms), Drupal (`/user/login`, `/node/{nid}/edit`, `/node/add/page`, logout confirmation form) and TYPO3 backend (tokenised module routes read from the module menu, FormEngine editing a page record and its text element in one form). Each recorded step is one editorial action; `ms` is wall time until the admin UI is ready for the next action, `serverMs` is the summed server time of the document requests (form post plus its redirect) within that step, with static assets excluded.

| Action | Count per round | What is timed |
| --- | --- | --- |
| `login` | 1 | Login form post until the manager shell and its main frame are loaded |
| `open-edit` | 5 | Opening the editor of a seeded page |
| `save-edit` | 5 | Save with `EDITED` appended to title and body, until the editor reloads |
| `open-create` | 5 | Opening the new-document form |
| `save-create` | 5 | Save of the new document, until its editor loads. TYPO3 keeps page and content as separate records, so this step is two saves there: the page, then a text element on it |
| `logout` | 1 | Logout until the login form is shown |

Every step also records memory on both sides:

| Metric | Side | Source |
| --- | --- | --- |
| `phpPeakMb` / `phpPeakRealMb` | PHP | Exact script peak and allocator peak (`memory_get_peak_usage`) of the largest request the step caused; requests are attributed by the `X-Phramark-Step` header the browser sends, logged by `benchmark/fixtures/memory-prepend.php` |
| `containerPeakMb` | PHP | Peak resident memory of the stack's PHP-FPM container during the run (`docker stats`, 1 s samples) |
| `jsHeapUsedMb`, `jsHeapTotalMb` | Frontend | Renderer JS heap after the step (Chrome DevTools `Performance.getMetrics`) |
| `domNodes`, `jsListeners` | Frontend | DOM nodes and event listeners alive in the renderer after the step (same source; counts nodes not yet garbage-collected) |

The guest result files carry the same PHP-side numbers for the whole run (per-request peak percentiles and container peak RSS) in a `---- memory ----` section.

The browser runs in a container on the compose network (`mcr.microsoft.com/playwright`), an untimed warm-up pass precedes the recorded rounds, and `benchmark/scripts/admin-reset PORT` restores the seeded pages and removes created ones before and after the run (Evolution: SQL restore; Drupal: drush script; TYPO3: re-seed). `ROUNDS=5 benchmark/scripts/admin 8083` repeats the session. To run it from the host instead: `cd benchmark/workloads/admin && npm install && PHRAMARK_BASE_URL=http://127.0.0.1:8083 PHRAMARK_STACK=drupal-11 npx playwright test` (Drupal from the host needs `PHRAMARK_DRUPAL_NIDS=16,17,18,19,20` when the page nodes are not 1–5; the `admin` script looks them up).

## Tracing the request path

```sh
benchmark/scripts/trace-request 8082            # writes benchmark/results/trace-evo-phalcon.json and prints the classification
php benchmark/scripts/trace-summary.php benchmark/results/trace-evo-phalcon.json evo-phalcon
```

The summary exits non-zero when the traced components disagree with the stack's `RuntimeProfile` claim; `composer test` also checks any recorded trace against the claims.

## Tests

```sh
composer test                                         # PHP: fixture plan, page contract, runtime profile, adapter sources
cd benchmark/workloads/admin && npm test              # Node: edit helpers, timeline statistics, report
```

## Local smoke numbers

Full matrix (`DURATION=30s ROUNDS=1 benchmark/scripts/matrix 30`) on one Windows 11 laptop with Docker Desktop, 8 FPM workers, load generator on the same host, 2026-09-20. A smoke run that shows every cell working; single rounds on a shared machine, so treat differences below ~20 % as noise. Regenerate with `php benchmark/scripts/summary.php`.

### Guest workload (wrk2, constant offered rate)

| Stack | JIT | Offered | Achieved rps | p50 | p99 | Non-2xx | PHP peak/request (script median) | PHP alloc peak p95 | FPM container peak RSS |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser | off | 30 | 29.96 | 373.50ms | 626.69ms | 0 | 1.8 MiB | 4.0 MiB | 78.0 MiB |
| evo-parser | tracing | 30 | 30.06 | 327.68ms | 573.44ms | 0 | 1.8 MiB | 4.0 MiB | 86.9 MiB |
| evo-latte | off | 30 | 30.01 | 380.16ms | 688.64ms | 0 | 1.8 MiB | 4.0 MiB | 81.9 MiB |
| evo-latte | tracing | 30 | 30.07 | 331.77ms | 559.61ms | 0 | 1.9 MiB | 4.0 MiB | 89.5 MiB |
| evo-latte-parser | off | 30 | 29.96 | 370.43ms | 625.15ms | 0 | 1.8 MiB | 4.0 MiB | 81.3 MiB |
| evo-latte-parser | tracing | 30 | 30.07 | 322.82ms | 587.26ms | 0 | 1.9 MiB | 4.0 MiB | 89.9 MiB |
| evo-phalcon | off | 30 | 30.89 | 48.19ms | 98.62ms | 0 | 1.0 MiB | 2.0 MiB | 71.1 MiB |
| evo-phalcon | tracing | 30 | 29.82 | 45.44ms | 105.41ms | 0 | 1.0 MiB | 2.0 MiB | 79.2 MiB |
| drupal-11 | off | 30 | 30.75 | 76.80ms | 137.34ms | 0 | 2.1 MiB | 4.0 MiB | 70.2 MiB |
| drupal-11 | tracing | 30 | 30.85 | 74.11ms | 163.20ms | 0 | 2.1 MiB | 4.0 MiB | 88.4 MiB |
| typo3 | off | 30 | 30.88 | 45.85ms | 82.30ms | 0 | 3.1 MiB | 4.0 MiB | 81.5 MiB |
| typo3 | tracing | 30 | 30.88 | 45.69ms | 74.11ms | 0 | 3.1 MiB | 4.0 MiB | 178.2 MiB |

### Admin workload (Playwright; medians per action, wall / server)

| Stack | JIT | login | open-edit | save-edit | open-create | save-create | logout | Total wall |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser | off | 1801.45 / 231.22 ms | 164.97 / 97.02 ms | 742.83 / 418.82 ms | 150.34 / 84.15 ms | 855.68 / 501.98 ms | 171.98 / 120.64 ms | 11528 ms |
| evo-parser | tracing | 1843.25 / 289.29 ms | 203.36 / 131.78 ms | 868.98 / 544.45 ms | 163.14 / 110.5 ms | 957.57 / 625.22 ms | 285.19 / 243.68 ms | 13261 ms |
| evo-latte | off | 1796.47 / 236.22 ms | 167.32 / 96.98 ms | 765.55 / 424.76 ms | 140.41 / 83.26 ms | 867.98 / 529.1 ms | 221.56 / 175.61 ms | 11963 ms |
| evo-latte | tracing | 1842.31 / 276.43 ms | 209.94 / 137.18 ms | 878.58 / 541.34 ms | 170.51 / 111.35 ms | 953.84 / 627.61 ms | 269.11 / 226.12 ms | 13154 ms |
| evo-latte-parser | off | 1853.42 / 246.8 ms | 159.19 / 99 ms | 748 / 413.92 ms | 138.18 / 87.62 ms | 829.9 / 483.38 ms | 224.78 / 177.56 ms | 11878 ms |
| evo-latte-parser | tracing | 1821.65 / 271.61 ms | 208.76 / 134.19 ms | 863.98 / 537.28 ms | 166.75 / 108.63 ms | 933.58 / 596.27 ms | 210.25 / 160.3 ms | 12892 ms |
| evo-phalcon | off | 1806.83 / 246.35 ms | 163.94 / 104.18 ms | 754.28 / 424.28 ms | 142.53 / 83.66 ms | 852.59 / 505.07 ms | 175.33 / 119.83 ms | 11712 ms |
| evo-phalcon | tracing | 1829.27 / 299.92 ms | 184.85 / 128.04 ms | 901.17 / 558.27 ms | 168.52 / 111.92 ms | 975.54 / 651.23 ms | 279.95 / 240.1 ms | 13437 ms |
| drupal-11 | off | 518.95 / 410.19 ms | 255.78 / 202.62 ms | 756.37 / 696.64 ms | 91.75 / 44.16 ms | 733.56 / 677.63 ms | 288.67 / 167.95 ms | 9804 ms |
| drupal-11 | tracing | 386.45 / 276.98 ms | 150.76 / 104.48 ms | 356.49 / 294.94 ms | 92.25 / 44.09 ms | 359.91 / 288.75 ms | 262.23 / 108.65 ms | 5411 ms |
| typo3 | off | 1662.1 / 1241.17 ms | 482.85 / 145.47 ms | 721.04 / 368.34 ms | 275.92 / 98.89 ms | 1730.76 / 749.64 ms | 183.32 / 110.4 ms | 17971 ms |
| typo3 | tracing | 1483.14 / 1000.97 ms | 495.56 / 154.29 ms | 781.4 / 360.74 ms | 392.34 / 305.72 ms | 1864.73 / 974.53 ms | 175.52 / 109.05 ms | 19072 ms |

### Admin workload memory (medians per action)

| Stack | JIT | Side | login | open-edit | save-edit | open-create | save-create | logout | FPM container peak |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser | off | PHP peak (script) | 2.5 MiB | 1.23 MiB | 5.94 MiB | 1.26 MiB | 5.93 MiB | 2.12 MiB | 83.8 MiB |
| evo-parser | off | Frontend JS heap | 14.03 MiB | 11.54 MiB | 12.86 MiB | 8.99 MiB | 9.02 MiB | 10.2 MiB |  |
| evo-parser | off | Frontend DOM nodes | 27289 | 8294 | 9742 | 3600 | 3732 | 3872 |  |
| evo-parser | tracing | PHP peak (script) | 2.56 MiB | 1.42 MiB | 11.44 MiB | 1.45 MiB | 11.43 MiB | 2.25 MiB | 95.6 MiB |
| evo-parser | tracing | Frontend JS heap | 14.01 MiB | 10.07 MiB | 9.42 MiB | 9.24 MiB | 9.04 MiB | 8.82 MiB |  |
| evo-parser | tracing | Frontend DOM nodes | 27228 | 5472 | 4094 | 3713 | 3732 | 2557 |  |
| evo-latte | off | PHP peak (script) | 2.53 MiB | 1.24 MiB | 5.95 MiB | 1.27 MiB | 5.94 MiB | 2.13 MiB | 95.9 MiB |
| evo-latte | off | Frontend JS heap | 14.02 MiB | 11.57 MiB | 12.79 MiB | 8.98 MiB | 9.4 MiB | 8.83 MiB |  |
| evo-latte | off | Frontend DOM nodes | 27356 | 8294 | 9742 | 3600 | 3671 | 2557 |  |
| evo-latte | tracing | PHP peak (script) | 2.59 MiB | 1.61 MiB | 11.45 MiB | 1.42 MiB | 11.44 MiB | 2.25 MiB | 90 MiB |
| evo-latte | tracing | Frontend JS heap | 14.86 MiB | 10.07 MiB | 9.83 MiB | 9.28 MiB | 9.03 MiB | 9.44 MiB |  |
| evo-latte | tracing | Frontend DOM nodes | 27309 | 5466 | 5414 | 3594 | 3732 | 3539 |  |
| evo-latte-parser | off | PHP peak (script) | 2.53 MiB | 1.24 MiB | 5.95 MiB | 1.27 MiB | 5.94 MiB | 2.13 MiB | 99.5 MiB |
| evo-latte-parser | off | Frontend JS heap | 14 MiB | 16.15 MiB | 17.08 MiB | 10.2 MiB | 10.21 MiB | 10.58 MiB |  |
| evo-latte-parser | off | Frontend DOM nodes | 27371 | 21118 | 19542 | 5415 | 4877 | 5017 |  |
| evo-latte-parser | tracing | PHP peak (script) | 2.59 MiB | 1.61 MiB | 11.45 MiB | 1.42 MiB | 11.44 MiB | 2.25 MiB | 92.5 MiB |
| evo-latte-parser | tracing | Frontend JS heap | 14.83 MiB | 11.13 MiB | 12.11 MiB | 10.2 MiB | 9.38 MiB | 9.24 MiB |  |
| evo-latte-parser | tracing | Frontend DOM nodes | 27309 | 7482 | 8930 | 4848 | 3671 | 2557 |  |
| evo-phalcon | off | PHP peak (script) | 2.59 MiB | 1.24 MiB | 6.02 MiB | 1.28 MiB | 6.01 MiB | 2.14 MiB | 90.8 MiB |
| evo-phalcon | off | Frontend JS heap | 13.99 MiB | 9.8 MiB | 9.38 MiB | 9.26 MiB | 10.54 MiB | 13.14 MiB |  |
| evo-phalcon | off | Frontend DOM nodes | 27309 | 5472 | 4094 | 4429 | 5497 | 8097 |  |
| evo-phalcon | tracing | PHP peak (script) | 2.6 MiB | 1.39 MiB | 11.52 MiB | 1.42 MiB | 11.51 MiB | 2.26 MiB | 98.6 MiB |
| evo-phalcon | tracing | Frontend JS heap | 14.02 MiB | 9.82 MiB | 9.85 MiB | 10.54 MiB | 9.17 MiB | 9.23 MiB |  |
| evo-phalcon | tracing | Frontend DOM nodes | 27309 | 5411 | 5414 | 6350 | 3610 | 2557 |  |
| drupal-11 | off | PHP peak (script) | 3.11 MiB | 4.82 MiB | 4.23 MiB | 4.69 MiB | 4.23 MiB | 2.83 MiB | 97 MiB |
| drupal-11 | off | Frontend JS heap | 11.91 MiB | 20.13 MiB | 20.91 MiB | 34.99 MiB | 36.09 MiB | 44.23 MiB |  |
| drupal-11 | off | Frontend DOM nodes | 2504 | 4808 | 5027 | 8865 | 9084 | 11102 |  |
| drupal-11 | tracing | PHP peak (script) | 3.11 MiB | 4.89 MiB | 4.23 MiB | 4.69 MiB | 4.23 MiB | 2.83 MiB | 79.8 MiB |
| drupal-11 | tracing | Frontend JS heap | 11.87 MiB | 19.72 MiB | 20.88 MiB | 34.92 MiB | 36.02 MiB | 43.85 MiB |  |
| drupal-11 | tracing | Frontend DOM nodes | 2501 | 4805 | 5021 | 8862 | 9078 | 11099 |  |
| typo3 | off | PHP peak (script) | 6.27 MiB | 7.28 MiB | 7.34 MiB | 6.53 MiB | 6.93 MiB | 4.5 MiB | 33.5 MiB |
| typo3 | off | Frontend JS heap | 34.13 MiB | 43.14 MiB | 36.38 MiB | 38.76 MiB | 36.52 MiB | 40.86 MiB |  |
| typo3 | off | Frontend DOM nodes | 34136 | 38430 | 35259 | 24280 | 30889 | 19910 |  |
| typo3 | tracing | PHP peak (script) | 6.33 MiB | 7.28 MiB | 7.34 MiB | 6.53 MiB | 6.93 MiB | 4.5 MiB | 32.9 MiB |
| typo3 | tracing | Frontend JS heap | 31.93 MiB | 71 MiB | 71.28 MiB | 121.17 MiB | 130.7 MiB | 18.07 MiB |  |
| typo3 | tracing | Frontend DOM nodes | 32859 | 70225 | 78869 | 153005 | 147777 | 12401 |  |
