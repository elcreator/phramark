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
| `evo-parser` | 8080 | Evolution core on **Laravel/Illuminate** (≈97 Illuminate classes, 213 Illuminate files: database, view, routing, container) + Symfony http-foundation/finder | Normal Evolution frontend, snippet in the parser |
| `evo-latte` | 8081 | Same as above + Latte | aLatteX file view with `evo_tags=false`: the Latte output is final, the core's tag passes (`parseDocumentSource`, `[!…!]`, `cleanUpMODXTags`/`rewriteUrls`) are skipped |
| `evo-latte-parser` | 8085 | Same as `evo-latte` | The identical Latte view with `evo_tags=true` (the aLatteX default): the core runs its tag passes over the rendered output. `evo-latte-parser` − `evo-latte` is the cost of that pass on a page without EVO tags |
| `evo-phalcon` | 8082 | Same Illuminate bootstrap (≈86 classes) + **Phalcon** DB adapter (extension) + Latte | aPhalcon `frontend.takeover=true`. It is *not* a pure Phalcon stack: Evolution's Laravel-based core still boots, Phalcon replaces the data access |
| `drupal` | 8083 | Drupal core (511 core files) on **Symfony** HttpKernel/Routing/HttpFoundation + Twig | Route controller, Drupal DB API, Twig render array returned as a bare response |
| `typo3` | 8084 | TYPO3 core on **Doctrine DBAL** + Fluid; Symfony only for DI/translation (12 classes) | PSR-15 middleware before site resolution, DBAL query builder, Fluid view |
| `winter` | 8086 | Winter CMS (Storm 194 classes + CMS module) on **Laravel/Illuminate** (177 classes, 308 files) + Twig; Symfony http-foundation/translation only (35 classes); PDO wrapped in Doctrine DBAL's `PDOConnection` (12 files) | Full CMS front controller: theme page `/articles/:slug` with a component that queries `phramark_article` through the Illuminate query builder, Twig page template without a layout |
| `modx` | 8087 | [MODX Revolution](https://github.com/modxcms/revolution) core (111 `MODX\Revolution` classes, 132 core files) on **xPDO** (20 classes, 23 files), its own ORM over PDO; no third-party framework on the request path (a few Symfony polyfill files, no Symfony classes) | Full CMS front controller: `modRequest` resolves `/articles/category-NNN` through the alias map (one resource per category), `modParser` renders the template with an uncached snippet that reads `phramark_article` through an xPDO model (`Phramark\Model\Article`) and renders one chunk per article |
| `wordpress-gantry` | 8088 | [WordPress](https://wordpress.org) core (481 `wp-includes` files; procedural plus global classes such as `WP_Query`, `wpdb`, `WP_Rewrite`) with the [Gantry 5](https://github.com/gantry/gantry5) **theme framework** plugin (66 `Gantry` classes, 84 plugin files, 12 RocketTheme toolbox classes) rendering its Hydrogen theme through **Timber** (35 classes) and **Twig 2** (49 classes, 54 files); Symfony yaml/event-dispatcher only for the outline (2 classes) | Full CMS front controller: WordPress resolves `/articles/category-NNN` through its page hierarchy (one child page of "Articles" per category), a must-use plugin's `template_include` hands the page to a template that reads `phramark_article` through `$wpdb` and renders a Twig view (`custom/views` override of the theme) inside the Gantry outline: header, navigation, main, footer and off-canvas sections with their particles. One stack, not two: WordPress is measured only through Gantry, because the framework does nothing without a Gantry theme |
| `evo-sarticles` | 8089 | Same Evolution core, with the [sArticles](https://github.com/Seiger/sArticles) module: the articles are Eloquent models in the module's own tables (`s_articles`, `s_article_translates`, `s_articles_categories`) instead of documents in the tree | **Opt-in** (`--solutions=evo-sarticles`), and the one stack whose point is a comparison with itself: sArticles 1.x against the 2.x branch, which rebuilds the manager on [evo-ui](https://github.com/evolution-cms/evo-ui) and Livewire. The category page still resolves through the document tree and renders the shared contract from the module's models, so guest numbers sit next to the other stacks; the admin round edits articles, not documents |
| `evo-frankenphp` | 8092 | The `evo-parser` request path on classic FrankenPHP: PHP's normal request lifecycle loads a fresh Evolution application on every request | **Opt-in** (`--solutions=evo-frankenphp`), see [FrankenPHP modes](#frankenphp-modes). Works with Evolution refs that do not provide the worker runtime |
| `evo-worker` | 8090 | The `evo-parser` request path, run by Evolution's own [FrankenPHP](https://frankenphp.dev) worker loop instead of PHP-FPM: a new application per request | In the default matrix, on Evolution 3.6.x, see [FrankenPHP modes](#frankenphp-modes). The same site, snippet and fixture as `evo-parser`: the runtime is the only difference |
| `evo-worker-keep` | 8091 | The same, with one booted application kept per worker and reset after each request (`EVO_WORKER_KEEP_APP=1`) | As `evo-worker`. `evo-worker-keep` − `evo-worker` is what booting the providers costs per request |
| `evo-latte-worker`, `evo-latte-worker-keep` | 8093, 8094 | The `evo-latte` request path in the two worker modes | As `evo-worker`. The same packages, view and fixture as `evo-latte`: the runtime is the only difference |
| `evo-latte-parser-worker`, `evo-latte-parser-worker-keep` | 8095, 8096 | The `evo-latte-parser` request path in the two worker modes | As `evo-latte-worker` |
| `evo-phalcon-worker`, `evo-phalcon-worker-keep` | 8097, 8098 | The `evo-phalcon` request path in the two worker modes | As `evo-latte-worker`. Runs the thread-safe build of the Phalcon extension, where the FPM stacks run the non-thread-safe one |

`evo-sarticles` answers a different question from the rest of the table. Every other stack asks what a framework costs inside a CMS request; this one asks whether a rewritten manager is going in the right direction, by running the same editorial round against two versions of one module (`--versions=sarticles@1.2.1,sarticles@2.x`). The six admin actions are the same user intents in both — open the list, open an article, change its title and summary, save, open the form for a new one, save — and each is waited to the state where the editor can act again. How each version gets there is its own doing: 1.x is a manager tab with a plain form, 2.x an evo-ui modal saved over Livewire.

Three things the stack needs that say something about the module, and are worth knowing before reading its numbers:

- **A rich-text editor is part of the stack.** sArticles 1.x renders its editor through `OnRichTextEditorInit` and dies (`implode(): argument #2 must be of type array, null given`) when no plugin answers it, so [`evolution-cms-extras/tinymce5`](https://github.com/evolution-cms-extras/tinymce5) is installed as a versionable part. 2.x puts its summary field in TinyMCE as well, so both versions carry it.
- **1.x cannot save on Evolution 3.5.8 by itself.** The module builds its form action from `sArticlesController::moduleUrl()` without the manager's CSRF token, and the manager answers `403`; the same request with the token is accepted. The admin adapter adds the token so the save can be timed, marked in the code as a workaround to remove once the module posts it.
- **2.x cannot save inside the 128 MB every other stack runs at.** `saveModal` exhausts the memory limit in the Illuminate connection with the 10 000-article fixture seeded, and Livewire then fails on the PHP error printed before its JSON. Both sArticles versions run with `PHP_MEMORY_LIMIT=512M` so the round is measurable at all; what each build really needs is in the recorded PHP peak per action.

October CMS is not part of the comparison: its Composer distribution requires a licence key, which the harness cannot depend on. Its licence-free fork [Winter CMS](https://github.com/wintercms/winter) stands in for that lineage (`winter`). WordPress is not measured on its own either: the `wordpress-gantry` stack answers the question "what does a Gantry 5 page cost", and the framework only renders through one of its themes, so plain WordPress would be a different request path (no Timber, no Twig, no outline) rather than a baseline of the same one.

Stack ids carry no version number, and neither do the labels above: the versions a stack is built from are chosen per run (`--versions`, [Comparing versions](#comparing-versions)), the newest release of every part by default, and read back from the container into every result. The class and file counts are from the traces recorded in `benchmark/results/trace-*.json` at the versions listed under [Versions tested](#versions-tested).

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

- The Evolution stacks resolve a real document tree (closure table, alias path) and read TVs from an EAV table; `evo-parser`/`evo-latte`/`evo-latte-parser` list the cards with `getDocumentChildren()` (document-group access checks included) and read their TVs with one `IN (…)` query through the DBAPI (`$modx->db->select()`), `evo-phalcon` with the same `IN (…)` query but a hand-written card query that skips the access checks. Evolution's own TV helpers (`getTemplateVarOutput`, `getDocumentChildrenTVarOutput`, `getDocumentChildrenTVars`) all query per document, 1 + 20×2 queries for this page (results before 2026-09-30 were measured that way); the DBAPI path skips TV output widgets and `default_text`, which the fixture does not use. Drupal and TYPO3 read one flat, indexed `phramark_article` table. The stacks are therefore comparable as "CMS-native request path over the same fixture", not as identical SQL.
- The `evo-parser` (and `evo-sarticles`) category template is marked as a database template (`templatesource = db`) where the column exists (Evolution 3.5.9+). That is what the manager stores for a template created in it; the fixture's raw row would otherwise be an "automatic" legacy template, for which the core probes the view paths for a file named after the template on every request. The Latte stacks keep "automatic", because they render that alias from a file.
- The Drupal and TYPO3 adapters bypass their CMS page/theme layer (Drupal now as well). The Evolution stacks, Winter, MODX and WordPress run a full CMS front controller (Winter: `Cms\Classes\Controller`, theme page, component, Twig; MODX: `modRequest` → `modResource` → `modParser`, template, snippet, chunk; WordPress: `WP_Rewrite` → `WP_Query` page lookup → `template_include` → Gantry outline through Timber/Twig).
- MODX runs with `cache_resource=0`, the equivalent of Evolution's `enable_cache=0`: the document is parsed on every request instead of being served from `core/cache/resource`. Its default session handler stores a session row in `modx_session` for every guest request (MODX-native behaviour, kept), and its default dashboard widgets that fetch modx.com feeds and update checks server-side are removed so a manager login times the CMS, not the internet.
- WordPress runs with `DISABLE_WP_CRON` and `WP_HTTP_BLOCK_EXTERNAL` (web requests only) and without the dashboard's wordpress.org news and Site Health widgets: a guest request must not spawn a loopback cron request and a manager page load must not wait for api.wordpress.org update checks, so the timed steps measure the CMS, not the internet (the MODX equivalent is its removed feed widgets). Gantry keeps its compiled SCSS and Twig under `wp-content/cache/gantry5`, which the warm-up fills; the object cache is WordPress's default per-request one, so options and post objects are read from MySQL on every request like every other stack's uncached document.
- The WordPress admin workload edits pages in the Classic Editor's Text tab (an official wordpress.org plugin, `wp_default_editor=html`): title input and body textarea, a form post, a redirect back to the editor. That is the same plain-form shape as the Drupal, Evolution and Winter fixtures and keeps the measured server work comparable; the block editor would time a React application's REST round-trips instead.
- The always-on PHP memory probe (`auto_prepend_file`) costs one shutdown function and a file append per request on every stack alike.
- Load generator, MySQL, PHP-FPM and nginx share one host. Use a separate load host for public numbers.

## Run the demo

```sh
docker compose -f benchmark/compose.yaml up --build                        # evo-parser, evo-latte, evo-phalcon
docker compose -f benchmark/compose.yaml --profile cross-cms up --build    # + drupal, typo3, winter, modx, wordpress-gantry
```

Then open http://127.0.0.1:8080/articles/category-042 (8081 … 8088 for the other stacks). Use `127.0.0.1`, not `localhost`: Chromium's dual-stack connect to Docker Desktop's port proxy can stall for 30 s per connection.

Admin panels (user `benchmark` everywhere):

| Stack | Admin URL | Password | Admin fixture |
| --- | --- | --- | --- |
| evo-* | http://127.0.0.1:8080/manager/ (8081, 8082, 8085; 8090, 8091, 8093–8098 with the worker profile) | `benchmark-admin` | Folder "Admin workload" (id 10103) with pages 10104–10108 |
| drupal | http://127.0.0.1:8083/user/login | `benchmark-admin` | Content type "Basic page" with a plain-text body; the five lowest page nodes |
| typo3 | http://127.0.0.1:8084/typo3/ | `Benchmark1!` | Root page "Admin workload" (uid 10103) with pages 10104–10108, each with one text element of the same uid |
| winter | http://127.0.0.1:8086/backend/ | `benchmark-admin` | "Benchmark pages" (plugin `Phramark.Benchmark`, table `phramark_benchmark_pages`) with pages 10104–10108 |
| modx | http://127.0.0.1:8087/manager/ | `benchmark-admin` | Container "Admin workload" (id 10103) with resources 10104–10108 on the "Admin page" template |
| wordpress-gantry | http://127.0.0.1:8088/wp-login.php | `benchmark-admin` | Page "Admin workload" (id 10103) with child pages 10104–10108, edited in the Classic Editor (Text tab) |

Re-running `up` re-seeds the fixture and re-syncs adapter code into existing volumes; a site is reinstalled only when the versions it should be built from changed (its `.phramark-versions` marker, written by the setup from the resolved refs). The setup containers restart nothing, so after a re-seed restart the FPM containers (`docker compose … up -d --force-recreate php-parser …`) or use `bench`, which does.

## The matrix

`benchmark/scripts/bench WORKLOAD JIT PORT [OFFERED_RPS]` runs one cell: it recreates the stack's FPM with `PHP_JIT` set, warms all 100 category pages over the compose network, where the load comes from, runs the fairness gate from the host and from that network, then runs the workload. A stack that cannot be warmed fails the cell: it is never measured cold. The network decides because a published port can stall: on Docker Desktop one stayed silent for minutes after a recreate while the stack answered at once on the network, and the host gate skips a port that does not answer. The JIT mode is read back from the container into the result name, so a result can never claim a mode that did not run.

```sh
benchmark/scripts/bench guest off     8080 100
benchmark/scripts/bench guest tracing 8080 100
benchmark/scripts/bench admin off     8080
benchmark/scripts/bench admin tracing 8080
```

`benchmark/scripts/matrix` runs many cells in one go and ends by recording the container versions and refreshing `docs/results.json`. Without options it runs every reachable stack × guest/admin × JIT off/tracing at 30 rps, then reinstalls the worker stacks from Evolution 3.6.x and runs them the same way; options select a part of the matrix:

```sh
benchmark/scripts/matrix                                            # everything, 30 rps
benchmark/scripts/matrix --solutions=evo-phalcon,winter             # two stacks, all four cells each
benchmark/scripts/matrix --jit=off                                  # JIT off only (or --jit=tracing, --jit=off,tracing)
benchmark/scripts/matrix --workload=guest --rate=50                 # guest cells only, at 50 rps
benchmark/scripts/matrix --solutions=modx --jit=tracing --workload=admin
benchmark/scripts/matrix --repeats=5 --solutions=typo3              # five runs per cell: the spread is the measuring error
DURATION=120s ROUNDS=3 benchmark/scripts/matrix --solutions=drupal,typo3 --jit=off
```

Ports work in place of stack ids (`benchmark/scripts/matrix 30 8082 8086`). A cell that fails is reported at the end and does not stop the others.

### Comparing versions

```sh
benchmark/scripts/matrix --versions=evo@3.5.x,evo@3.5.7,evo@3.5.8            # the four Evolution stacks at each ref
benchmark/scripts/matrix --versions=evo@3.5.x,evo@3.5.8,latte@0.4.0 --solutions=evo-latte-parser
benchmark/scripts/matrix --versions=drupal@11.x,typo3@14.x --workload=guest   # branches: Composer dev versions
benchmark/scripts/matrix --versions=php@8.3,php@8.4 --solutions=modx          # the PHP image, rebuilt per version
benchmark/scripts/matrix --versions=latte@0.2.0,latte@../evo/aLatteX          # a release against the working copy next to this checkout
```

`aPhalcon` is the one part whose default is not its newest release: 0.1.0 on Packagist requires Evolution `^3.5.9`, so it cannot be installed on the 3.5.8 release, while the working copy at `../evo/aPhalcon` requires `^3.5.8` and installs on every Evolution ref the matrix runs. `evo-phalcon` therefore builds from that directory unless `--versions=phalcon@…` names something else (`VersionSpec::products()['phalcon']['default']`), and a checkout without that directory next to it can only run the stack by naming a published version:

```sh
benchmark/scripts/matrix --solutions=evo-phalcon --versions=phalcon@0.1.0,evo@3.5.x
EVO_VERSION=3.5.8 docker compose -f benchmark/compose.yaml up                 # the same pin for a plain up
```

`--versions=PRODUCT@REF,…` names the parts a stack is built from and the refs to compare. A ref resolves to the published tag of that name when one exists, otherwise to the branch of that name (`3.5.x`, `11.x` → `11.x-dev` for Composer packages, `main` → `dev-main`); `latest`, the default of every part but aPhalcon (above), is the newest stable tag. A ref that starts with `..` is a directory on the host, relative to the repository root (`../evo/aLatteX` is the checkout next to this one): the setup containers see the repository's parent at `/host` (`PHRAMARK_SOURCES` to mount another directory, `PHRAMARK_DIR` when the checkout is not named `phramark`), copy the directory in — an extension as a Composer path repository pinned to `dev-local`, so no Packagist release can satisfy the requirement instead; a CMS as its source tree or project directory — and record a fingerprint of its files, so an edited working copy is reinstalled on the next run. `Phramark\VersionSpec` lists the products and their stacks:

| Product | Part | Stacks | Installed from |
| --- | --- | --- | --- |
| `php` | the PHP image (`php:<ref>-fpm-bookworm`, and `dunglas/frankenphp:1-php<ref>-bookworm` for FrankenPHP stacks; `PHP_VERSION` build argument) | all | image rebuild; no path |
| `evo` | Evolution CMS | `evo-*` | git clone of the tag or branch; a path is copied as the site tree |
| `latte` (`alattex`) | aLatteX | `evo-latte`, `evo-latte-parser`, `evo-phalcon` | Composer version or dev branch; a path as a path repository |
| `phalcon` (`aphalcon`) | aPhalcon | `evo-phalcon` | Composer version or dev branch; a path as a path repository |
| `drupal`, `typo3`, `winter` | `drupal/recommended-project`, `typo3/cms-base-distribution`, `wintercms/winter` | the stack | `composer create-project` at the version or dev branch; a path is a project directory, copied (`composer install` when it has no vendor) |
| `modx` | MODX Revolution | `modx` | release archive of the tag; a branch (or a path) is the source tree, built with `_build/transport.core.php` |
| `wordpress` | WordPress core | `wordpress-gantry` | `wp core download` at the tag; a branch is cloned from the WordPress/WordPress build mirror; a path is copied |
| `gantry` | Gantry 5 plugin and Hydrogen theme | `wordpress-gantry` | release archives of the tag (a branch or path is not installable: the packages are assembled by Gantry's build) |
| `classic-editor` | Classic Editor plugin | `wordpress-gantry` | wordpress.org release at the version; a branch is cloned from its repository; a path is copied |

Not every part can be pinned: Illuminate, Symfony, Twig, Doctrine, Latte, Timber and the extensions' own dependencies come with whatever the products above pull in, and are recorded from the container into every result rather than chosen.

The plan is a cartesian product per stack: every stack runs once per combination of the refs of the products it contains, so `evo@3.5.x,evo@3.5.8,latte@0.4.0` runs `evo-parser` at each Evolution ref and the Latte stacks at each Evolution ref paired with aLatteX 0.4.0 (`php benchmark/scripts/version-rounds.php SPEC [STACK...]` prints the plan). Combinations that do not conflict share one provisioning round: the matrix exports the round's `<PRODUCT>_VERSION` variables, runs the setup containers, which reinstall exactly the sites whose resolved versions differ from what they were built from (`.phramark-versions`), rebuilds the images first when the PHP version is one of them, and then runs the cells; `bench` recreates the FPM container for each cell as before, so it serves the new tree. Without `--solutions` only the stacks the named products are part of run. Results carry the label of their build after the stack id (`guest-evo-latte~evo@3.5.8+latte@0.4.0-jit-off-rps-30-<timestamp>.txt`, `admin-evo-latte~evo@3.5.8+latte@0.4.0-jit-off-<timestamp>.json`; the default build has no label), the exact components the container reported inside the file (`---- versions ----` section, `components` in the admin report) and a *Version* column on the results page and in the tables below, so builds of one stack never collapse into one row. The containers stay at the last round's versions afterwards; a plain `up` without the variables takes every part back to `latest`.

Keep `PHP_FPM_PM_MAX_CHILDREN` (default 8), `DURATION` (default 60s), `CONNECTIONS` (32) and `ROUNDS` identical across the cells you compare. Results:

| Cell | File |
| --- | --- |
| guest | `benchmark/results/guest-<stack>-jit-<mode>-rps-<rate>.txt` (raw wrk2 output with the full HdrHistogram) |
| admin | `benchmark/results/admin-<stack>-jit-<mode>-<timestamp>.json` and `.txt` (per-step wall time and server time, summary per action) |

Capacity is the highest offered rate with zero non-2xx responses and p99 below 100 ms; wrk2 reports coordinated-omission-corrected latency, so an offered rate above capacity shows queueing time, not request time. Production reporting: separate load host, 60 s warm-up, 120 s measurement, five repetitions, keep all raw output.

### FrankenPHP modes

The comparison has three FrankenPHP modes and the PHP-FPM reference (`evo-parser`). All use the same category request, seed data, PHP version, JIT mode and 8-way concurrency. The classic stack uses FrankenPHP's normal PHP request lifecycle. The two worker stacks use Evolution 3.6.x's [worker runtime](https://github.com/evolution-cms/evolution/blob/3.6.x/.github/docker/WORKER.md):

| Stack | Mode | Application state between requests |
| --- | --- | --- |
| `evo-frankenphp` | Classic | A normal PHP request lifecycle; Evolution's application is loaded on each request |
| `evo-worker` | Worker, `EVO_WORKER_KEEP_APP=0` | The PHP process and loaded classes; Evolution builds a new application and boots its providers for each request |
| `evo-worker-keep` | Worker, `EVO_WORKER_KEEP_APP=1` | The booted application per worker; request state is reset after each request |

Evolution 3.5.x has no worker runtime, so it is measured on PHP-FPM. Evolution 3.6.x provides the worker runtime, so both worker modes are part of the defaults from there on: `matrix` without options runs the worker stacks from the 3.6.x branch after the FPM stacks, which stay on their default versions, and `--versions=evo@3.6.x` selects them next to the FPM stacks of that ref. A ref older than 3.6 next to it is left out for them. Classic FrankenPHP stays opt-in; run all three alongside the FPM reference from the official 3.6.x branch with:

```sh
benchmark/scripts/matrix --solutions=evo-parser,evo-frankenphp,evo-worker,evo-worker-keep --versions=evo@3.6.x
```

The Latte and Phalcon stacks run in the same two worker modes. Every worker stack alongside the Evolution FPM stacks on one ref:

```sh
benchmark/scripts/matrix --versions=evo@3.6.x
```

These stacks need aLatteX 0.5.1 or newer: in `EVO_WORKER_KEEP_APP=0` mode Evolution includes a plugin on every request, and 0.5.0 stops the worker on the second one with `Cannot redeclare function alattexEvoTagsEnabled()`, so the `-worker` stacks answer about half their requests with 500. The `-keep` stacks are not affected.

All these containers belong to the compose profile `worker`, which a plain `up` leaves out. `matrix` installs the worker sites and starts each container itself. The worker services require `core/includes/worker.runtime.php`; classic FrankenPHP does not.

What is held equal and what is not:

- **Concurrency.** `PHP_FPM_PM_MAX_CHILDREN` (8) sets the classic FrankenPHP thread count and the FPM child count. Worker stacks use 8 application workers plus 8 classic PHP threads for manager requests.
- **Recycling.** A worker restarts after Evolution's default of 500 requests (`EVO_WORKER_MAX_REQUESTS` to change it), where the FPM pool never recycles (`pm.max_requests = 0`): the restarts are part of what the mode costs.
- **Transport.** nginx serves the static files and hands PHP over HTTP instead of FastCGI, one connection per request in both cases.
- **Host.** Worker stacks serve one origin (`EVO_WORKER_ORIGIN`, here `http://` and the stack id) and answer every other with 421, while the harness reaches a stack both as `127.0.0.1:PORT` and as `nginx-…`. nginx sends front-end requests with that origin's `Host`; classic FrankenPHP and the manager keep the client's host.
- **Memory.** `auto_prepend_file` runs once per worker, not per request, so the probe wraps the `frankenphp_handle_request()` call of Evolution's worker loop (in `EvolutionCMS\Runtime`) and writes one line per request with the peak reset at its start: a worker's peak includes what it keeps between requests. The container RSS includes FrankenPHP's Go runtime and Caddy, where an FPM container holds only PHP.
- **Phalcon.** Every PHP image loads the Phalcon extension, whichever stack it serves, and the same release. It is never compiled here: the FPM images copy it out of the official [`phalconphp/cphalcon`](https://hub.docker.com/r/phalconphp/cphalcon) image, without the other extensions that image loads, and FrankenPHP, which needs a thread-safe build, takes the one published with that [release](https://github.com/phalcon/cphalcon/releases). `PHALCON_VERSION` in the three Dockerfiles names it; `tests/run.php` keeps them equal.

`trace-request` and `profile` install their hooks into an FPM container and do not support these stacks.

### Admin workload details

`benchmark/workloads/admin` is a Playwright project with one adapter per CMS (`lib/adapters/`): Evolution manager (iframe shell, `a=27`/`a=4`/`a=5` document forms), Drupal (`/user/login`, `/node/{nid}/edit`, `/node/add/page`, logout confirmation form), TYPO3 backend (tokenised module routes read from the module menu, FormEngine editing a page record and its text element in one form) Winter backend (`/backend/backend/auth/signin`, the plugin's Form/List controller at `/backend/phramark/benchmark/pages/update/{id}` and `/create`; Save is Winter's AJAX framework posting to the `onSave` handler, a create answers with a redirect to the new record's editor), MODX manager (ExtJS: `/manager/` login form, `?a=resource/update&id={id}` and `?a=resource/create&parent=10103` resource forms; Save is a multipart XHR to `/connectors/index.php` with `action=Resource/Update` or `Resource/Create`, after which the manager loads the new resource's editor) and WordPress (`/wp-login.php`, `/wp-admin/post.php?post={id}&action=edit` and `/wp-admin/post-new.php?post_type=page` with the Classic Editor's Text tab; Save is the form post to `post.php`, which redirects back to the editor of the saved or new page, so the id is read from that URL; logout is the nonce-protected admin-bar link). Each recorded step is one editorial action; `ms` is wall time until the admin UI is ready for the next action, `serverMs` is the summed server time of the document requests (form post plus its redirect) within that step, plus Winter's AJAX handler requests (`X-Winter-Request-Handler`) and MODX's connector requests (`/connectors/index.php`: saves, but also the resource tree, toolbar and combo-box loads the manager issues on every page), with static assets excluded. On Winter and MODX an update save leaves the form in place, so the saved values are verified by one untimed reload tagged `verify`.

| Action | Count per round | What is timed |
| --- | --- | --- |
| `login` | 1 | Login form post until the manager shell and its main frame are loaded |
| `open-edit` | 5 | Opening the editor of a seeded page |
| `save-edit` | 5 | Save with `EDITED` appended to title and body, until the editor reloads |
| `open-create` | 5 | Opening the new-document form |
| `save-create` | 5 | Save of the new document, until its editor loads. TYPO3 keeps page and content as separate records, so this step is two saves there: the page, then a text element on it |
| `logout` | 1 | Logout until the login form is shown |

Password hashing is not in any step's `ms` or `serverMs`. Each CMS hashes with its own algorithm and cost (Argon2i on TYPO3, bcrypt at cost 10 or 12 elsewhere), slow on purpose, so a login would otherwise compare hash settings, not frameworks. `benchmark/fixtures/memory-prepend.php` times it inside the request: an unqualified `password_verify()`/`password_hash()`/`crypt()` call in namespaced code resolves a function of the caller's namespace first, so the prepend declares timing forwarders in the namespaces of the Drupal, TYPO3, Laravel (Winter), MODX and Evolution hashers. They are plain functions of the prepend OPcache already holds, so no class is added or autoloaded. WordPress hashes in the global namespace; its mu-plugin marks the check between the last `wp_authenticate_user` filter and the first `check_password` filter. The time lands in the memory log as `hash_ms`, and the report merge subtracts it from the step's wall and server time and keeps it as `hashMs`. A rehash on login (a seeded hash below the CMS's configured cost) is timed on the namespaced stacks but not on WordPress. The seeds already use each CMS's current format, so no rehash happens.

Every step also records memory on both sides:

| Metric | Side | Source |
| --- | --- | --- |
| `phpPeakMb` / `phpPeakRealMb` | PHP | Exact script peak and allocator peak (`memory_get_peak_usage`) of the largest request the step caused; requests are attributed by the `X-Phramark-Step` header the browser sends, logged by `benchmark/fixtures/memory-prepend.php` |
| `containerPeakMb` | PHP | Peak RSS of the stack's PHP container during the run: the anonymous memory of its cgroup (`memory.stat` `anon`, 1 s samples); FrankenPHP also includes its Go runtime and Caddy |
| `containerFootprintMb` | PHP | With `matrix --footprint` (`PHRAMARK_FOOTPRINT=1`): the container's memory as `docker stats` reports it, the RSS plus the page cache of what the container read and wrote. It grows with every file a request leaves behind (a session file per visitor, compiled templates, logs), so a rise here with a flat RSS is I/O, not a leak. Runs from before this split recorded only this figure |
| `jsHeapUsedMb`, `jsHeapTotalMb` | Frontend | Renderer JS heap after the step (Chrome DevTools `Performance.getMetrics`) |
| `domNodes`, `jsListeners` | Frontend | DOM nodes and event listeners alive in the renderer after the step (same source; counts nodes not yet garbage-collected). Chrome keeps one renderer for the whole same-site session, so the heap and node counts of a page-per-action manager include what earlier pages left for the garbage collector; the drop after logout shows that collection |

The guest result files carry the same PHP-side numbers for the whole run (per-request peak percentiles and container peak RSS) in a `---- memory ----` section.

The browser runs in a container on the compose network (`mcr.microsoft.com/playwright`), an untimed warm-up pass precedes the recorded rounds, and `benchmark/scripts/admin-reset PORT` restores the seeded pages and removes created ones before and after the run (Evolution: SQL restore; Drupal: drush script; TYPO3, Winter, MODX and WordPress: re-seed; the WordPress reset also drops the revisions and auto-drafts the editor produced). `ROUNDS=5 benchmark/scripts/admin 8083` repeats the session. To run it from the host instead: `cd benchmark/workloads/admin && npm install && PHRAMARK_BASE_URL=http://127.0.0.1:8083 PHRAMARK_STACK=drupal npx playwright test` (Drupal from the host needs `PHRAMARK_DRUPAL_NIDS=16,17,18,19,20` when the page nodes are not 1–5; the `admin` script looks them up).

## Tracing the request path

```sh
benchmark/scripts/trace-request 8082            # writes benchmark/results/trace-evo-phalcon.json and prints the classification
php benchmark/scripts/trace-summary.php benchmark/results/trace-evo-phalcon.json evo-phalcon
```

The summary exits non-zero when the traced components disagree with the stack's `RuntimeProfile` claim; `composer test` also checks any recorded trace against the claims.

## Profiling a request

```sh
benchmark/scripts/profile 8080 on                       # installs xhprof into the running evo-parser container
curl -H 'X-Phramark-Profile: home' http://127.0.0.1:8080/  # only requests with this header are profiled
benchmark/scripts/profile 8080 report home --top=30     # inclusive wall time; --excl for self time, --callees=Fn::name to drill down
benchmark/scripts/profile 8080 off
```

xhprof stays inert without the header, so an armed container still produces valid runs. The script's header comment shows how to drive a manager page with a login cookie jar. The reports live in the container's `/tmp/xhprof` until `off` or a rebuild.

## Measuring error

A single cell is one number with no error bar. The error of this harness is measured, not assumed:

1. **Repetitions.** `REPEATS=N benchmark/scripts/matrix 30` runs every cell N times; every guest run keeps its own file (`guest-<stack>-jit-<mode>-rps-<rate>-<timestamp>.txt`) and every admin session its own report. `Phramark\ResultSet` groups them per cell and reports, for each metric, the median, min, max and the coefficient of variation (CV = standard deviation / mean). The results site shows the min–max as whiskers on the bars and the CVs in the "Measuring error" table; the tables and the README show the latest run.
2. **Noise floor.** Run one cell 5 times back-to-back (`REPEATS=5 benchmark/scripts/matrix 30 8087`) before comparing anything: its CV is what this host contributes. On the shared laptop that produced the smoke numbers, p50 varies by a few percent between runs and admin actions by 2–10 %; a difference between two stacks below about twice the larger CV is not a result.
3. **Within one run.** wrk2 records the full HdrHistogram, so p50 and p99 come with the whole distribution (raw output in the result file); the admin report keeps the p95, min and max of the five repetitions of each action inside the session, and its warm-up pass keeps a cold OPcache out of round 1.
4. **Systematic error is controlled, not measured:** identical PHP/OPcache/FPM settings, the same fixture and page contract verified before every run, the JIT mode read back from the container, the load generator on the compose network rather than through the host port proxy. What remains systematic and shared by every stack is the host itself (load generator, MySQL, PHP-FPM and nginx on one machine), so the numbers compare stacks with each other, not with a production server.

## Memory over time

Every run keeps its memory series, and the results site draws them as small multiples so a growing line is visible, with the trend (last quarter over first quarter, and a least-squares slope) written under each panel:

| Series | Source | What a rising line means |
| --- | --- | --- |
| Guest: PHP peak per request | `.memory.log` (one line per request from `memory-prepend.php`), cut into 40 time slices | The request itself allocates more as the run goes on: a per-process cache or a leak that survives requests (PHP frees per request, so this is normally flat) |
| Guest: PHP container RSS | `.rss.log` column 2 (cgroup `anon`, 1 s samples) | The resident memory of PHP-FPM or FrankenPHP, including the worker heaps and any runtime memory |
| Guest: PHP container footprint | `.rss.log` column 3 (`docker stats`, with `--footprint`) | The same plus the page cache: what the run wrote to disk |
| Admin: JS heap, DOM nodes per step | the report's steps (Chrome DevTools `Performance.getMetrics` after every action) | The manager's frontend retains state across actions: a single-page manager (ExtJS, React) grows until a full reload; a page-per-action manager returns to its baseline |
| Admin: PHP peak per step and per request | the report's steps and its `.memory.log` | The largest request of each action; a growing line across the five repetitions of one action is a server-side accumulation |

## Results site and exact versions

`benchmark/scripts/matrix` ends by recording the exact versions the containers run (`php benchmark/scripts/versions.php`, read from Composer's `installed.json`, the CMS version files and WP-CLI into `benchmark/results/versions.json`; `--stack=ID --attach=RESULT` writes one stack's components into a result file, which `run` and `admin` do for every result they produce) and by writing `docs/results.json` (`php benchmark/scripts/summary.php --json`): the same collector (`Phramark\ResultSet`) that renders the Markdown tables below. `docs/` is a static site (`index.html`, `site.js`, `CNAME`) published at **https://phramark.artur.work**, where every table is sortable by any column. To refresh it by hand:

```sh
php benchmark/scripts/versions.php                        # benchmark/results/versions.json
php benchmark/scripts/summary.php --json > docs/results.json
php benchmark/scripts/summary.php                         # the Markdown tables of the section below
```

## Tests

```sh
composer test                                         # PHP: fixture plan, page contract, runtime profile, adapter sources
cd benchmark/workloads/admin && npm test              # Node: edit helpers, timeline statistics, report
```

## Manticore: can a CMS be compiled ahead of time?

[Manticore](https://github.com/manticorephp/compiler) is a PHP 8.5 → native AOT compiler: it takes one program (a file, or a `manticore.json` manifest over a source tree and its Composer packages) and links a standalone binary against its own runtime, with no Zend engine, no php-fpm, no extensions. It is not an accelerator for existing PHP files, so it cannot sit behind nginx as another stack of this matrix. `evo-manticore` is therefore an experiment with three questions, all run inside `benchmark/images/manticore` (PHP 8.5 CLI on Debian trixie, clang 19, the compiler bootstrapped from source by its own installer; nothing from that repository runs on the host):

1. **How much of Evolution CMS 3.5 does it compile file by file?** `benchmark/scripts/manticore-compile-all` runs `manticore compile --no-analyze` on every PHP file of the installed `evo-parser` tree (core, manager, assets and all 58 Composer packages) and records the first diagnostic of every failure; `benchmark/scripts/manticore-summary.php` groups them per area and per diagnostic class.
2. **Does the whole program build?** `benchmark/scripts/manticore-build-iterate` runs `manticore build` with `"composer": true` over `core/` with `index.php` as the entry, excludes the file the front end stops on, and retries, so the log lists the blockers in the order the compiler meets them.
3. **What does native code gain on the page assembly the CMS stacks do?** `benchmark/workloads/manticore/category-page.php` renders the shared category page contract (20 article cards, the fixture's TV presence rule, the markup of the `benchmarkCategory` snippet) from in-memory rows, no database, no CMS bootstrap. `benchmark/scripts/manticore-bench` compiles it with `-O2`, checks that the binary and PHP 8.5 print the same checksum, and times both with GNU `time` (best of 5 wall-clock runs, peak RSS): native, PHP with OPcache and JIT off, PHP with the tracing JIT.

```sh
benchmark/scripts/matrix --solutions=evo-manticore            # the job alone; never part of the default matrix
benchmark/scripts/matrix --solutions=winter,evo-manticore     # after the Winter cells
benchmark/scripts/manticore                                   # the same job by hand (MANTICORE_BUILD=1 builds the image locally)
```

`evo-manticore` is opt-in: `matrix` without `--solutions` runs the served stacks and nothing else, and the job only runs when that list names it. It writes into `benchmark/results/manticore` and never touches `docs/results.json`.

Two things the compiler does not tell you: invoked as a bare `manticore` from `PATH` it finds neither its prelude nor its stdlib (both are located relative to `argv[0]`), and the stdlib is then silently not linked, so every `str_replace()` becomes "undefined function". The image sets `MANTICORE_PRELUDE`, `MANTICORE_STDLIB_O` and `MANTICORE_STDLIB_SIG`.

### Caches: what a repeat run reuses

A cold run bootstraps the compiler (about 7 minutes: Zend seeds a native compiler that then rebuilds itself), compiles 9,687 files (8 minutes on 48 cores, 3 hours on a 3-CPU Docker Desktop VM) and rediscovers 41 whole-program blockers one build round at a time. Three caches, each kept where its size fits, take a repeat run down to about 3½ minutes, most of it the timed workload itself:

| Cache | Where | What it saves | Key |
| --- | --- | --- | --- |
| Compiler image `ghcr.io/elcreator/phramark-manticore:latest` (`MANTICORE_IMAGE_TAG` names a version) | GitHub Container Registry, published by `.github/workflows/manticore-image.yml` from the compiler's latest release (on Dockerfile changes, weekly, on demand) | the toolchain install and the compiler bootstrap; `benchmark/scripts/manticore` pulls it and builds locally only with `MANTICORE_BUILD=1` | the compiler version the image reports |
| Result cache `benchmark/results/manticore/compile-cache.tsv` (1 MB, in git) | this repository | the per-file sweep: a file whose content hash was already compiled by the same toolchain is reported from the cache, not compiled (`benchmark/scripts/manticore-cache.php`; 9,686 of 9,687 hits on a repeat, the one miss is a file Evolution regenerates on every setup) | `sha256(file)` + `manticore <version> \| clang <version>`, so an upgraded compiler misses everything and a moved or re-installed tree still hits |
| Exclusion list `benchmark/results/manticore/evo-parser-build-excludes.txt` (in git) | this repository | the whole-program rounds: a repeat starts from the 41 known blockers and reaches the compiler's crash in one round instead of 42 | the file list itself; delete it to rediscover from scratch |
| Object cache (`MANTICORE_OBJ_CACHE=1`, Manticore's own, content-addressed on the emitted LLVM IR) | the `manticore-cache` Docker volume on the host that runs the job | `clang` on unchanged IR — 5.3× on a repeat compile of the same files — for the workload compile and the build rounds | sha1 of the IR text + clang flags |

The object cache is deliberately not published: it is about 300 KB per compiled file (roughly 3 GB for the tree), and the result cache already answers the question the sweep asks. A per-package `.o`/`.sig` cache over the Composer dependency graph — compile every package in `composer.lock` once as a library, cache it by package version, link the application against the cached objects — is the design that would make a *successful* whole-program build incremental; Manticore 0.10 has no such stage (`build` compiles the unioned source set as one unit, and traits and generic classes cannot cross a library boundary), and until the whole program compiles at all there is nothing for it to cache.

### Results (2026-09-20, 48-core Threadripper, Docker, Manticore 0.10.0, clang 19.1.7, PHP 8.5.10)

Raw files: [benchmark/results/manticore](results/manticore) (`evo-parser-per-file.tsv`/`.md`/`.json`, `evo-parser-build-rounds.log`, `category-page.md`/`.json`, `manticore-own-bench.txt`).

**File by file: 9,257 of 9,687 files compile (95.6 %)**, 1.6 s each on average, none time out. Evolution's own code: `core/src` 291/312, `core/lang` 66/66, `manager` 257/336, `core/functions` 11/16, `core/modifiers` 1/6; the big packages on the request path: illuminate 1069/1100, symfony 911/940, doctrine 486/487, nesbot/carbon 915/923. What stops the other 430, by diagnostic class:

| Diagnostic | Files | What it is |
| --- | ---: | --- |
| `MIR.verify: dangling local $x read ... never defined` | 162 | `global $x` / variables that arrive from an including scope: the manager's procedural files, `core/functions`, modifiers |
| `MIR.lower: unsupported statement kind Class` | 42 | a class declared inside `if (!class_exists(...))` or a function (`core/includes/aliases.inc.php`, Carbon's `lazy/` shims) |
| `parse failed: expected ';' after echo` (+ 9 similar) | 45 | inline-HTML templates (`<?= ... ?>` closing a statement): Tracy panels, Whoops and Illuminate console views |
| `lazy body ... unexpected token` | 23 | function bodies the parser accepts lazily and rejects on demand |
| compiler segfault (rc 139) | 18 | `index.php` (the front controller), 13 Predis argument builders, Symfony `AbstractUnicodeString` and `ClosureLoader`, Ramsey `AbstractCollection`, Termwind `Node` |
| `unsupported assign target kind StaticAccess`, references into array elements, `$GLOBALS`/`compact`-style name tables, `#[\Override]` on interface methods | 24 | listed in `evo-parser-per-file.md` |

**Whole program: does not build.** `manticore build` over `core/` with `index.php` as the entry and `"composer": true` (every package in `composer.lock` compiled as source) stops on one file per round; after 41 exclusions — the 35 inline-HTML views above, `Enum`/`Override`-named symbols in three packages, the PHPUnit function file, a `lazy body` in `core/functions/nodes.php` — the front end accepts the tree and the compiler segfaults (round 42, `evo-parser-build-rounds.log`), the same crash `index.php` triggers on its own. Even past that crash a served CMS would need what the compiler lists as unsupported: `extract()` (Evolution's parser and every Blade/Latte view), `eval` (snippets and plugins are PHP strings in the database), dynamic `include` and a MySQL driver (Manticore's PDO is SQLite only).

**Page assembly, native vs PHP 8.5** (`category-page`, 20,000 pages per run, best of 5, output parity checked over all 100 distinct pages):

| Runtime | Wall (s) | Pages/s | Peak RSS (MiB) | vs native |
| --- | ---: | ---: | ---: | ---: |
| native (`-O2`, 1.4 MB static binary, 0.8 s compile) | 2.94 | 6,803 | 171.0 | 1.00× |
| PHP 8.5 OPcache, JIT off | 1.55 | 12,903 | 27.2 | 0.53× |
| PHP 8.5 OPcache, JIT tracing | 1.41 | 14,184 | 29.0 | 0.48× |

The native binary is **2× slower** than the interpreter on this workload and its resident memory grows with the page count (23 MiB at 2,000 pages, 47 MiB at 5,000, 171 MiB at 20,000; PHP stays at 27–29 MiB), which points at the allocator never returning memory or a leak in the string path. The reason is where the time goes: compiled user code is faster (a string-concatenation loop 5×, `sprintf` 1.7× — and `manticore-own-bench.txt` reproduces the project's own suite: native faster on 36 of 45 comparable cases, `fib` 20×, `spectralnorm` 26×, `oop` 10×), but Manticore's standard library is itself PHP compiled to native, and on a CMS page the standard library *is* the work: `htmlspecialchars()` runs 2.5× slower than Zend's C implementation and `crc32()` 9× slower, `sort`/`in_array`/`implode`/`json_pretty` also lose in the project's own table. A page render is one escape call per attribute and text node, so the escape cost dominates and the compiled control flow around it cannot compensate.

So the answer to "evo-manticore" is: not a stack, not a faster one either, at this version. The experiment stays in the repository as a compatibility census of a real CMS tree against the compiler, and as the fixed harness to rerun when a newer Manticore lands.

## Local smoke numbers

Full matrix (`benchmark/scripts/matrix 30`, `DURATION=60s`, `ROUNDS=1`) on one Windows 11 laptop with Docker Desktop, 8 FPM workers, load generator on the same host, 2026-11-06. A smoke run that shows every cell working; single rounds on a shared machine, so treat differences below ~20 % as noise. The worker stacks ran on Evolution 3.6.x with `--versions=evo@3.6.x --repeats=5` the same day: five repetitions per cell, their spread shown as whiskers on the site. Sortable at https://phramark.artur.work; regenerate with `php benchmark/scripts/summary.php`. The versions table names exactly what ran, per stack, linked to the source repositories.

### Versions tested

Recorded from the running containers on 2026-11-06T18:27:24Z: PHP 8.4.27, MySQL 8.4.7, nginx/1.27.5, OPcache validate_timestamps=0, memory_consumption=512, max_accelerated_files=100000.

| Stack | Components (exact versions) |
| --- | --- |
| evo-parser | [PHP](https://github.com/php/php-src) 8.4.27, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.5.9 (branch 3.5.x@6413fdc, 2026-10-31), [illuminate/database](https://github.com/illuminate/database) v12.70.2 |
| evo-latte | [PHP](https://github.com/php/php-src) 8.4.26, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.5.9 (branch 3.5.x@c16d07e, 2026-10-15), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.0, [illuminate/database](https://github.com/illuminate/database) v12.70.0, [latte/latte](https://github.com/nette/latte) v3.1.6 |
| evo-latte-parser | [PHP](https://github.com/php/php-src) 8.4.26, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.5.9 (branch 3.5.x@c16d07e, 2026-10-15), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.0, [illuminate/database](https://github.com/illuminate/database) v12.70.0, [latte/latte](https://github.com/nette/latte) v3.1.6 |
| evo-phalcon | [PHP](https://github.com/php/php-src) 8.4.26, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.5.9 (branch 3.5.x@c16d07e, 2026-10-15), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.0, [elcreator/aphalcon](https://github.com/elcreator/aPhalcon) dev-local (evo/aPhalcon@1c92644748139ad3), [illuminate/database](https://github.com/illuminate/database) v12.70.0, [latte/latte](https://github.com/nette/latte) v3.1.6, [phalcon (extension)](https://github.com/phalcon/cphalcon) 5.9.3 |
| evo-worker | [PHP](https://github.com/php/php-src) 8.4.27, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.6.0 (branch 3.6.x@a8a0c26, 2026-11-05), [illuminate/database](https://github.com/illuminate/database) v13.38.0, [FrankenPHP](https://github.com/php/frankenphp) 1.13.5, worker application new per request, worker requests 500 per worker, then a restart, workers 8 |
| evo-worker-keep | [PHP](https://github.com/php/php-src) 8.4.27, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.6.0 (branch 3.6.x@a8a0c26, 2026-11-05), [illuminate/database](https://github.com/illuminate/database) v13.38.0, [FrankenPHP](https://github.com/php/frankenphp) 1.13.5, worker application kept per worker (EVO_WORKER_KEEP_APP=1), worker requests 500 per worker, then a restart, workers 8 |
| evo-latte-worker | [PHP](https://github.com/php/php-src) 8.4.27, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.6.0 (branch 3.6.x@a8a0c26, 2026-11-05), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.1, [illuminate/database](https://github.com/illuminate/database) v13.38.0, [latte/latte](https://github.com/nette/latte) v3.1.7, [FrankenPHP](https://github.com/php/frankenphp) 1.13.5, worker application new per request, worker requests 500 per worker, then a restart, workers 8 |
| evo-latte-worker-keep | [PHP](https://github.com/php/php-src) 8.4.27, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.6.0 (branch 3.6.x@a8a0c26, 2026-11-05), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.1, [illuminate/database](https://github.com/illuminate/database) v13.38.0, [latte/latte](https://github.com/nette/latte) v3.1.7, [FrankenPHP](https://github.com/php/frankenphp) 1.13.5, worker application kept per worker (EVO_WORKER_KEEP_APP=1), worker requests 500 per worker, then a restart, workers 8 |
| evo-latte-parser-worker | [PHP](https://github.com/php/php-src) 8.4.27, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.6.0 (branch 3.6.x@a8a0c26, 2026-11-05), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.1, [illuminate/database](https://github.com/illuminate/database) v13.38.0, [latte/latte](https://github.com/nette/latte) v3.1.7, [FrankenPHP](https://github.com/php/frankenphp) 1.13.5, worker application new per request, worker requests 500 per worker, then a restart, workers 8 |
| evo-latte-parser-worker-keep | [PHP](https://github.com/php/php-src) 8.4.27, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.6.0 (branch 3.6.x@a8a0c26, 2026-11-05), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.1, [illuminate/database](https://github.com/illuminate/database) v13.38.0, [latte/latte](https://github.com/nette/latte) v3.1.7, [FrankenPHP](https://github.com/php/frankenphp) 1.13.5, worker application kept per worker (EVO_WORKER_KEEP_APP=1), worker requests 500 per worker, then a restart, workers 8 |
| evo-phalcon-worker | [PHP](https://github.com/php/php-src) 8.4.27, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.6.0 (branch 3.6.x@a8a0c26, 2026-11-05), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.1, [elcreator/aphalcon](https://github.com/elcreator/aPhalcon) dev-local (evo/aPhalcon@53a53d7414ce1dd1), [illuminate/database](https://github.com/illuminate/database) v13.38.0, [latte/latte](https://github.com/nette/latte) v3.1.7, [phalcon (extension)](https://github.com/phalcon/cphalcon) 5.23.0, [FrankenPHP](https://github.com/php/frankenphp) 1.13.5, worker application new per request, worker requests 500 per worker, then a restart, workers 8 |
| evo-phalcon-worker-keep | [PHP](https://github.com/php/php-src) 8.4.27, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.6.0 (branch 3.6.x@a8a0c26, 2026-11-05), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.1, [elcreator/aphalcon](https://github.com/elcreator/aPhalcon) dev-local (evo/aPhalcon@53a53d7414ce1dd1), [illuminate/database](https://github.com/illuminate/database) v13.38.0, [latte/latte](https://github.com/nette/latte) v3.1.7, [phalcon (extension)](https://github.com/phalcon/cphalcon) 5.23.0, [FrankenPHP](https://github.com/php/frankenphp) 1.13.5, worker application kept per worker (EVO_WORKER_KEEP_APP=1), worker requests 500 per worker, then a restart, workers 8 |
| evo-frankenphp | [PHP](https://github.com/php/php-src) 8.4.27, [Evolution CMS](https://github.com/evolution-cms/evolution) 3.6.0 (branch 3.6.x@830003b, 2026-10-31), [illuminate/database](https://github.com/illuminate/database) v13.38.0, [FrankenPHP](https://github.com/php/frankenphp) 1.13.4, PHP lifecycle classic FrankenPHP; new application per request, PHP threads 8 |
| drupal | [PHP](https://github.com/php/php-src) 8.4.27, [drupal/core](https://github.com/drupal/core) 11.4.11, [drush/drush](https://github.com/drush-ops/drush) 13.8.0, [symfony/http-foundation](https://github.com/symfony/http-foundation) v7.4.21, [symfony/http-kernel](https://github.com/symfony/http-kernel) v7.4.21, [twig/twig](https://github.com/twigphp/Twig) v3.30.0 |
| typo3 | [PHP](https://github.com/php/php-src) 8.4.27, [doctrine/dbal](https://github.com/doctrine/dbal) 4.4.4, [symfony/http-foundation](https://github.com/symfony/http-foundation) v7.4.19, [typo3/cms-core](https://github.com/TYPO3/typo3) v14.3.7, [typo3fluid/fluid](https://github.com/TYPO3/Fluid) 5.3.2 |
| winter | [PHP](https://github.com/php/php-src) 8.4.27, [doctrine/dbal](https://github.com/doctrine/dbal) 2.13.9, [laravel/framework](https://github.com/laravel/framework) v9.52.23, [symfony/http-foundation](https://github.com/symfony/http-foundation) v6.4.50, [twig/twig](https://github.com/twigphp/Twig) v3.30.0, [winter/storm](https://github.com/wintercms/storm) v1.2.15, [winter/wn-cms-module](https://github.com/wintercms/wn-cms-module) v1.2.15 |
| modx | [PHP](https://github.com/php/php-src) 8.4.27, [MODX Revolution](https://github.com/modxcms/revolution) 3.2.4-pl, [xpdo/xpdo](https://github.com/modxcms/xpdo) v3.1.7 |
| wordpress-gantry | [PHP](https://github.com/php/php-src) 8.4.27, [WordPress](https://github.com/WordPress/WordPress) 7.1.4, [classic-editor](https://github.com/WordPress/classic-editor) 1.7.0, [gantry5](https://github.com/gantry/gantry5) 5.6.7, [g5_hydrogen](https://github.com/gantry/gantry5) 5.6.7, [timber/timber](https://github.com/timber/timber) 1.24.1, [twig/twig](https://github.com/twigphp/Twig) v2.16.1 |

### Guest workload (wrk2, constant offered rate)

| Stack | JIT | Offered | Achieved rps | p50 | p99 | Non-2xx | PHP peak/request (script median) | PHP alloc peak p95 | PHP container peak RSS | PHP container footprint (with page cache) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser (3.5.9) | off | 30 | 30.38 | 35.39ms | 110.33ms | 0 | 0.7 MiB | 2.0 MiB | 27.1 MiB | - |
| evo-parser (3.5.9) | tracing | 30 | 30.37 | 35.81ms | 65.92ms | 0 | 0.8 MiB | 2.0 MiB | 28.9 MiB | - |
| evo-parser (3.5.9 3.5.x@6413fdc) | off | 30 | 30.38 | 36.22ms | 243.33ms | 0 | 0.7 MiB | 2.0 MiB | 22.0 MiB | - |
| evo-parser (3.5.9 3.5.x@6413fdc) | tracing | 30 | 30.38 | 34.37ms | 244.48ms | 0 | 0.8 MiB | 2.0 MiB | 24.2 MiB | - |
| evo-parser (3.6.0 3.6.x@830003b) | off | 30 | 30.38 | 57.31ms | 242.30ms | 0 | 1.1 MiB | 2.0 MiB | 25.4 MiB | - |
| evo-parser (3.6.0 3.6.x@830003b) | tracing | 30 | 30.38 | 62.24ms | 271.36ms | 0 | 1.1 MiB | 2.0 MiB | 27.5 MiB | - |
| evo-latte (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | off | 30 | 30.38 | 42.21ms | 235.90ms | 0 | 0.9 MiB | 2.0 MiB | 23.9 MiB | - |
| evo-latte (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | tracing | 30 | 30.38 | 38.33ms | 205.31ms | 0 | 0.9 MiB | 2.0 MiB | 26.2 MiB | - |
| evo-latte (3.5.9 · aLatteX 0.5.1) | off | 30 | 30.38 | 38.53ms | 74.05ms | 0 | 0.9 MiB | 2.0 MiB | 29.0 MiB | - |
| evo-latte (3.5.9 · aLatteX 0.5.1) | tracing | 30 | 30.38 | 38.56ms | 212.22ms | 0 | 0.9 MiB | 2.0 MiB | 30.9 MiB | - |
| evo-latte-parser (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | off | 30 | 30.37 | 39.78ms | 83.07ms | 0 | 0.9 MiB | 2.0 MiB | 23.9 MiB | - |
| evo-latte-parser (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | tracing | 30 | 30.38 | 39.07ms | 233.60ms | 0 | 0.9 MiB | 2.0 MiB | 25.9 MiB | - |
| evo-latte-parser (3.5.9 · aLatteX 0.5.1) | off | 30 | 30.38 | 38.91ms | 78.08ms | 0 | 0.9 MiB | 2.0 MiB | 29.1 MiB | - |
| evo-latte-parser (3.5.9 · aLatteX 0.5.1) | tracing | 30 | 30.38 | 40.10ms | 243.33ms | 0 | 0.9 MiB | 2.0 MiB | 30.9 MiB | - |
| evo-phalcon (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0 · aPhalcon ../evo/aPhalcon@1c926447) | off | 30 | 30.37 | 38.43ms | 243.46ms | 0 | 0.9 MiB | 2.0 MiB | 24.9 MiB | - |
| evo-phalcon (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0 · aPhalcon ../evo/aPhalcon@1c926447) | tracing | 30 | 30.38 | 39.58ms | 232.32ms | 0 | 0.9 MiB | 2.0 MiB | 26.4 MiB | - |
| evo-phalcon (3.5.9 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74) | off | 30 | 30.37 | 41.15ms | 235.26ms | 0 | 0.9 MiB | 2.0 MiB | 30.1 MiB | - |
| evo-phalcon (3.5.9 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74) | tracing | 30 | 30.37 | 40.61ms | 246.65ms | 0 | 0.9 MiB | 2.0 MiB | 32.5 MiB | - |
| evo-worker (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | off | 30 | 30.37 | 47.55ms | 103.74ms | 0 | 1.2 MiB | 8.0 MiB | 84.6 MiB | - |
| evo-worker (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | tracing | 30 | 30.38 | 46.81ms | 118.33ms | 0 | 1.3 MiB | 12.0 MiB | 89.6 MiB | - |
| evo-worker-keep (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | off | 30 | 30.37 | 50.81ms | 109.76ms | 0 | 1.7 MiB | 8.0 MiB | 85.5 MiB | - |
| evo-worker-keep (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | tracing | 30 | 30.38 | 43.81ms | 93.18ms | 0 | 1.7 MiB | 12.0 MiB | 88.0 MiB | - |
| evo-latte-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | 30 | 30.38 | 48.64ms | 133.12ms | 0 | 1.1 MiB | 8.0 MiB | 83.8 MiB | - |
| evo-latte-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | 30 | 30.38 | 46.08ms | 243.46ms | 0 | 1.4 MiB | 12.0 MiB | 93.0 MiB | - |
| evo-latte-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | 30 | 30.38 | 42.46ms | 97.79ms | 0 | 1.7 MiB | 8.0 MiB | 86.4 MiB | - |
| evo-latte-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | 30 | 30.38 | 41.41ms | 229.76ms | 0 | 2.0 MiB | 14.0 MiB | 87.8 MiB | - |
| evo-latte-parser-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | 30 | 30.38 | 49.44ms | 255.49ms | 0 | 1.2 MiB | 8.0 MiB | 84.5 MiB | - |
| evo-latte-parser-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | 30 | 30.38 | 46.37ms | 101.57ms | 0 | 1.3 MiB | 12.0 MiB | 89.1 MiB | - |
| evo-latte-parser-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | 30 | 30.38 | 39.90ms | 78.59ms | 0 | 1.7 MiB | 8.0 MiB | 87.5 MiB | - |
| evo-latte-parser-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | 30 | 30.38 | 42.97ms | 100.86ms | 0 | 1.9 MiB | 14.0 MiB | 93.1 MiB | - |
| evo-phalcon-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | off | 30 | 30.38 | 48.51ms | 178.69ms | 0 | 1.3 MiB | 8.0 MiB | 98.0 MiB | - |
| evo-phalcon-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | tracing | 30 | 30.38 | 45.95ms | 281.86ms | 0 | 1.5 MiB | 14.0 MiB | 114.1 MiB | - |
| evo-phalcon-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | off | 30 | 30.38 | 35.90ms | 201.60ms | 0 | 1.8 MiB | 10.0 MiB | 99.4 MiB | - |
| evo-phalcon-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | tracing | 30 | 30.38 | 34.81ms | 282.88ms | 0 | 2.1 MiB | 14.0 MiB | 110.4 MiB | - |
| evo-frankenphp (3.6.0 3.6.x@830003b · FrankenPHP 1.13.4) | off | 30 | 30.38 | 62.85ms | 118.78ms | 0 | 1.1 MiB | 2.0 MiB | 38.4 MiB | - |
| evo-frankenphp (3.6.0 3.6.x@830003b · FrankenPHP 1.13.4) | tracing | 30 | 30.38 | 63.46ms | 255.87ms | 0 | 1.1 MiB | 2.0 MiB | 40.7 MiB | - |
| drupal (11.4.11) | off | 30 | 30.38 | 83.01ms | 158.72ms | 0 | 2.2 MiB | 4.0 MiB | 40.3 MiB | - |
| drupal (11.4.11) | tracing | 30 | 30.37 | 82.88ms | 198.53ms | 0 | 2.1 MiB | 4.0 MiB | 41.2 MiB | - |
| typo3 (14.3.7) | off | 30 | 30.37 | 51.84ms | 250.75ms | 0 | 3.1 MiB | 4.0 MiB | 47.3 MiB | - |
| typo3 (14.3.7) | tracing | 30 | 30.37 | 52.45ms | 97.09ms | 0 | 3.1 MiB | 4.0 MiB | 55.9 MiB | - |
| winter (1.2.15) | off | 30 | 30.37 | 89.86ms | 170.24ms | 0 | 1.4 MiB | 2.0 MiB | 37.6 MiB | - |
| winter (1.2.15) | tracing | 30 | 30.38 | 89.73ms | 172.54ms | 0 | 1.4 MiB | 2.0 MiB | 39.6 MiB | - |
| modx (3.2.4-pl) | off | 30 | 30.38 | 115.20ms | 538.62ms | 0 | 0.7 MiB | 2.0 MiB | 27.2 MiB | - |
| modx (3.2.4-pl) | tracing | 30 | 30.38 | 107.01ms | 187.65ms | 0 | 0.7 MiB | 2.0 MiB | 30.7 MiB | - |
| wordpress-gantry (7.1.4 · Gantry 5.6.7) | off | 30 | 30.08 | 194.82ms | 400.64ms | 0 | 4.9 MiB | 6.0 MiB | 85.6 MiB | - |
| wordpress-gantry (7.1.4 · Gantry 5.6.7) | tracing | 30 | 30.14 | 255.10ms | 455.68ms | 0 | 4.9 MiB | 6.0 MiB | 86.3 MiB | - |

### Admin workload (Playwright; medians per action, wall / server)

| Stack | JIT | login | open-edit | save-edit | open-create | save-create | logout | Total wall |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser (3.5.9) | off | 4516.74 / 2166.4 ms | 103.73 / 31.88 ms | 224.71 / 56.49 ms | 67.42 / 16.94 ms | 475.43 / 129.38 ms | 139.34 / 75.75 ms | 9346 ms |
| evo-parser (3.5.9) | tracing | 3135.48 / 930.9 ms | 99.2 / 22.08 ms | 204.42 / 51.8 ms | 70.16 / 14.31 ms | 537.91 / 268.43 ms | 161.28 / 100.02 ms | 8104 ms |
| evo-parser (3.5.9 3.5.x@6413fdc) | off | 3565.39 / 1376.32 ms | 82.08 / 20.34 ms | 152.93 / 51.13 ms | 70.26 / 16.62 ms | 493.79 / 129.15 ms | 198.63 / 141.95 ms | 7954 ms |
| evo-parser (3.5.9 3.5.x@6413fdc) | tracing | 3145.19 / 996.8 ms | 69.23 / 20.13 ms | 119.44 / 50.6 ms | 71.13 / 14.93 ms | 474.76 / 133.01 ms | 169.15 / 120.39 ms | 7336 ms |
| evo-parser (3.6.0 3.6.x@830003b) | off | 3664.13 / 1302.22 ms | 83.72 / 32.86 ms | 165.05 / 54.22 ms | 71.76 / 19.72 ms | 474.33 / 135.61 ms | 269.5 / 159.4 ms | 8479 ms |
| evo-parser (3.6.0 3.6.x@830003b) | tracing | 4065.59 / 1769.63 ms | 103.08 / 22.91 ms | 205.62 / 134.79 ms | 131.13 / 35.46 ms | 500.86 / 141.84 ms | 163.26 / 109.05 ms | 9314 ms |
| evo-latte (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | off | 4109.77 / 1725.28 ms | 81.98 / 34.17 ms | 204.17 / 53.48 ms | 78.55 / 34.49 ms | 506.15 / 144.05 ms | 305.21 / 108.8 ms | 9531 ms |
| evo-latte (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | tracing | 3430.6 / 1234.92 ms | 116.58 / 40.28 ms | 209.86 / 54.18 ms | 99.81 / 17.6 ms | 541.39 / 150.69 ms | 196.6 / 127.96 ms | 8986 ms |
| evo-latte (3.5.9 · aLatteX 0.5.1) | off | 3550.61 / 1400.42 ms | 90.95 / 23.79 ms | 211.79 / 48.71 ms | 78.61 / 21.46 ms | 479.27 / 127.01 ms | 124.44 / 69.03 ms | 8382 ms |
| evo-latte (3.5.9 · aLatteX 0.5.1) | tracing | 3116.51 / 925.58 ms | 118.67 / 72.52 ms | 306.61 / 84.96 ms | 69.9 / 19.5 ms | 476.6 / 125.98 ms | 193.23 / 117.68 ms | 8475 ms |
| evo-latte-parser (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | off | 3834.02 / 1541 ms | 80.27 / 25.96 ms | 169.68 / 54.18 ms | 71.02 / 17.34 ms | 488.99 / 128.22 ms | 201.26 / 132.01 ms | 8707 ms |
| evo-latte-parser (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | tracing | 3095.26 / 986.58 ms | 68.3 / 21.63 ms | 156.4 / 46.12 ms | 56.2 / 15.47 ms | 466.75 / 127.3 ms | 177.02 / 127.63 ms | 7581 ms |
| evo-latte-parser (3.5.9 · aLatteX 0.5.1) | off | 3693.5 / 1254.44 ms | 71.51 / 27.61 ms | 232.74 / 56.26 ms | 71.21 / 22.43 ms | 469.76 / 131.23 ms | 181.7 / 120.67 ms | 8777 ms |
| evo-latte-parser (3.5.9 · aLatteX 0.5.1) | tracing | 3103.97 / 942.57 ms | 73.6 / 23.79 ms | 122.07 / 49.86 ms | 68.83 / 15.99 ms | 493.11 / 153.21 ms | 190.83 / 129.73 ms | 7586 ms |
| evo-phalcon (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0 · aPhalcon ../evo/aPhalcon@1c926447) | off | 3477.25 / 1259.49 ms | 94.55 / 33.02 ms | 212.98 / 63.32 ms | 73.22 / 18.56 ms | 459.41 / 138.06 ms | 135.5 / 79.68 ms | 7867 ms |
| evo-phalcon (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0 · aPhalcon ../evo/aPhalcon@1c926447) | tracing | 3262.05 / 975.97 ms | 73.01 / 24.25 ms | 145.94 / 48.71 ms | 67.36 / 17.04 ms | 481.67 / 152.5 ms | 216.51 / 161.36 ms | 8105 ms |
| evo-phalcon (3.5.9 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74) | off | 3339.26 / 1248.79 ms | 78.46 / 23.29 ms | 145.89 / 52.43 ms | 83.85 / 18.99 ms | 480.89 / 138.14 ms | 164.68 / 108.85 ms | 8000 ms |
| evo-phalcon (3.5.9 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74) | tracing | 3079.27 / 953.04 ms | 99.12 / 25.37 ms | 205.64 / 53.63 ms | 70.9 / 17.87 ms | 453.19 / 122.58 ms | 145.43 / 79.73 ms | 7552 ms |
| evo-worker (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | off | 3752.71 / 1496.74 ms | 89.91 / 40.01 ms | 231.24 / 61.07 ms | 86.34 / 25.79 ms | 518.31 / 166.8 ms | 194.93 / 117.58 ms | 9162 ms |
| evo-worker (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | tracing | 3767.87 / 1488.02 ms | 95.62 / 32.61 ms | 291.94 / 56.99 ms | 80.95 / 23.85 ms | 491.21 / 146.33 ms | 165.34 / 94.49 ms | 8741 ms |
| evo-worker-keep (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | off | 3996.54 / 1770.72 ms | 104.24 / 42.1 ms | 225.68 / 65.02 ms | 96.53 / 34.3 ms | 513.75 / 150.65 ms | 182.21 / 98.93 ms | 10611 ms |
| evo-worker-keep (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | tracing | 3442.34 / 1300.42 ms | 96.42 / 31.61 ms | 233.31 / 55.78 ms | 90.57 / 23.13 ms | 485.45 / 139.38 ms | 160.21 / 97.23 ms | 8767 ms |
| evo-latte-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | 3759.34 / 1602.93 ms | 107.2 / 33.51 ms | 314.03 / 68.48 ms | 122.34 / 32.46 ms | 574.78 / 168.7 ms | 212.75 / 135.51 ms | 9717 ms |
| evo-latte-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | 3505.81 / 1225.89 ms | 81.97 / 28.59 ms | 222.94 / 60.81 ms | 85.39 / 22.8 ms | 482.51 / 144.19 ms | 163.15 / 102.92 ms | 8297 ms |
| evo-latte-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | 3649.28 / 1481.06 ms | 80.89 / 28.8 ms | 131.44 / 57.21 ms | 94.53 / 26.77 ms | 502.97 / 145.37 ms | 208.73 / 134.28 ms | 8291 ms |
| evo-latte-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | 3472.61 / 1230.09 ms | 97.25 / 30.25 ms | 164.19 / 63.99 ms | 79.73 / 22.44 ms | 500.2 / 141.11 ms | 166.93 / 82.18 ms | 8840 ms |
| evo-latte-parser-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | 3943.32 / 1544.31 ms | 86.5 / 30.64 ms | 200.59 / 63.05 ms | 93 / 27 ms | 478.26 / 139.53 ms | 152.36 / 88.62 ms | 8608 ms |
| evo-latte-parser-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | 3507.62 / 1322.86 ms | 80.81 / 29.81 ms | 164.72 / 62.42 ms | 76.85 / 24.16 ms | 473.22 / 140.16 ms | 223.43 / 140.21 ms | 8301 ms |
| evo-latte-parser-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | 4375.92 / 1973.88 ms | 98.78 / 34.7 ms | 158.36 / 63.44 ms | 89.97 / 22.45 ms | 487.99 / 142.01 ms | 213.69 / 150.42 ms | 8821 ms |
| evo-latte-parser-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | 3533.37 / 1276.12 ms | 87.41 / 30.91 ms | 257.6 / 58.01 ms | 93.86 / 26.22 ms | 479.96 / 137.78 ms | 205.03 / 140.9 ms | 8444 ms |
| evo-phalcon-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | off | 3754.75 / 1518.09 ms | 81.4 / 29.17 ms | 227.24 / 58.18 ms | 103.45 / 25.49 ms | 487.28 / 144.07 ms | 169.19 / 96.55 ms | 8738 ms |
| evo-phalcon-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | tracing | 3434.48 / 1287.56 ms | 90.47 / 31.02 ms | 222.35 / 58.22 ms | 115.94 / 31.94 ms | 482.12 / 174.19 ms | 183.22 / 103.95 ms | 8660 ms |
| evo-phalcon-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | off | 3772.3 / 1572.44 ms | 92.07 / 30.56 ms | 209.72 / 62.88 ms | 96.36 / 23.21 ms | 471.1 / 142.51 ms | 194.45 / 135.49 ms | 8380 ms |
| evo-phalcon-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | tracing | 3412.17 / 1260.54 ms | 82.02 / 30.28 ms | 234.23 / 54.96 ms | 85.4 / 22.65 ms | 467.87 / 138.6 ms | 204.77 / 127.73 ms | 8021 ms |
| evo-frankenphp (3.6.0 3.6.x@830003b · FrankenPHP 1.13.4) | off | 3959.98 / 1642.18 ms | 86.06 / 33.68 ms | 245.37 / 66.97 ms | 122.52 / 27.72 ms | 480.27 / 144.85 ms | 173.26 / 113.79 ms | 9065 ms |
| evo-frankenphp (3.6.0 3.6.x@830003b · FrankenPHP 1.13.4) | tracing | 3765.77 / 1524.96 ms | 98.68 / 31.46 ms | 351.88 / 64.67 ms | 91.91 / 21.2 ms | 482.52 / 138.17 ms | 215.34 / 151.81 ms | 8715 ms |
| drupal (11.4.11) | off | 229.64 / 115.44 ms | 164.53 / 115.36 ms | 387.38 / 321.41 ms | 92.24 / 45.08 ms | 362.74 / 296.77 ms | 236.81 / 120.6 ms | 5491 ms |
| drupal (11.4.11) | tracing | 215.95 / 114.42 ms | 151.87 / 105.71 ms | 382.43 / 319.28 ms | 88 / 45.12 ms | 382.64 / 314.61 ms | 228.93 / 111.7 ms | 5422 ms |
| typo3 (14.3.7) | off | 946.38 / 454.82 ms | 507.54 / 351 ms | 858.8 / 389.02 ms | 387.43 / 302.88 ms | 2001.97 / 1092.23 ms | 289.12 / 126.6 ms | 19776 ms |
| typo3 (14.3.7) | tracing | 967.91 / 464.89 ms | 500.84 / 158.4 ms | 747.94 / 374.99 ms | 436.79 / 306.73 ms | 2023.85 / 1200.95 ms | 196.01 / 123.93 ms | 20266 ms |
| winter (1.2.15) | off | 1181.24 / 108.74 ms | 115.24 / 27.53 ms | 125.55 / 37.68 ms | 104.53 / 25.44 ms | 189.52 / 60.57 ms | 134.71 / 74.1 ms | 4310 ms |
| winter (1.2.15) | tracing | 1168.36 / 105.54 ms | 108.2 / 26.75 ms | 123.22 / 35.58 ms | 108.75 / 24.72 ms | 186.01 / 59.92 ms | 140.01 / 75.8 ms | 4031 ms |
| modx (3.2.4-pl) | off | 551.9 / 171.93 ms | 631.58 / 213.04 ms | 269.65 / 129.13 ms | 585.05 / 163.99 ms | 1434.04 / 399.14 ms | 482.91 / 116.48 ms | 15285 ms |
| modx (3.2.4-pl) | tracing | 553.34 / 239.12 ms | 611.94 / 206.28 ms | 265.75 / 99.83 ms | 604.57 / 159.92 ms | 1422.07 / 351.27 ms | 486.5 / 131.93 ms | 15309 ms |
| wordpress-gantry (7.1.4 · Gantry 5.6.7) | off | 359.58 / 90.73 ms | 285.43 / 66.82 ms | 525.77 / 125.63 ms | 312.13 / 79.1 ms | 504.86 / 139.27 ms | 230.55 / 68.98 ms | 8903 ms |
| wordpress-gantry (7.1.4 · Gantry 5.6.7) | tracing | 364.84 / 82.56 ms | 288.27 / 66.76 ms | 448.37 / 129.54 ms | 792.28 / 168.01 ms | 589.44 / 250.64 ms | 208.69 / 95.56 ms | 14074 ms |

### Admin workload memory (medians per action)

| Stack | JIT | Side | login | open-edit | save-edit | open-create | save-create | logout | PHP container peak |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser (3.5.9) | off | PHP peak (script) | 31.63 MiB | 1.1 MiB | 1 MiB | 1.11 MiB | 1.04 MiB | 0.9 MiB | 41.2 MiB |
| evo-parser (3.5.9) | off | Frontend JS heap | 6.71 MiB | 9.01 MiB | 9.75 MiB | 11.97 MiB | 12.96 MiB | 10.81 MiB |  |
| evo-parser (3.5.9) | off | Frontend DOM nodes | 132623 | 133939 | 134012 | 7636 | 8976 | 4874 |  |
| evo-parser (3.5.9) | tracing | PHP peak (script) | 31.56 MiB | 1.1 MiB | 1 MiB | 1.11 MiB | 1.04 MiB | 0.99 MiB | 41 MiB |
| evo-parser (3.5.9) | tracing | Frontend JS heap | 12.71 MiB | 10.75 MiB | 10.61 MiB | 11.05 MiB | 10.29 MiB | 10.37 MiB |  |
| evo-parser (3.5.9) | tracing | Frontend DOM nodes | 268626 | 6877 | 5500 | 6006 | 4883 | 4874 |  |
| evo-parser (3.5.9 3.5.x@6413fdc) | off | PHP peak (script) | 31.64 MiB | 1.1 MiB | 1 MiB | 1.11 MiB | 1.04 MiB | 0.91 MiB | 38.4 MiB |
| evo-parser (3.5.9 3.5.x@6413fdc) | off | Frontend JS heap | 6.68 MiB | 9.03 MiB | 9.71 MiB | 13.74 MiB | 13.38 MiB | 16.6 MiB |  |
| evo-parser (3.5.9 3.5.x@6413fdc) | off | Frontend DOM nodes | 133183 | 134545 | 134559 | 10115 | 11457 | 14327 |  |
| evo-parser (3.5.9 3.5.x@6413fdc) | tracing | PHP peak (script) | 31.56 MiB | 1.1 MiB | 1 MiB | 1.11 MiB | 1.04 MiB | 0.92 MiB | 47.5 MiB |
| evo-parser (3.5.9 3.5.x@6413fdc) | tracing | Frontend JS heap | 6.68 MiB | 10.66 MiB | 10.8 MiB | 13.81 MiB | 14.5 MiB | 10.79 MiB |  |
| evo-parser (3.5.9 3.5.x@6413fdc) | tracing | Frontend DOM nodes | 133182 | 135936 | 134557 | 11179 | 12521 | 4884 |  |
| evo-parser (3.6.0 3.6.x@830003b) | off | PHP peak (script) | 32.2 MiB | 1.48 MiB | 1.38 MiB | 1.48 MiB | 1.42 MiB | 1.29 MiB | 45.8 MiB |
| evo-parser (3.6.0 3.6.x@830003b) | off | Frontend JS heap | 12.8 MiB | 13.49 MiB | 12.86 MiB | 12.82 MiB | 13.75 MiB | 15.96 MiB |  |
| evo-parser (3.6.0 3.6.x@830003b) | off | Frontend DOM nodes | 270745 | 243959 | 241054 | 9000 | 10378 | 13166 |  |
| evo-parser (3.6.0 3.6.x@830003b) | tracing | PHP peak (script) | 32.2 MiB | 1.49 MiB | 1.38 MiB | 1.48 MiB | 1.47 MiB | 1.36 MiB | 42.9 MiB |
| evo-parser (3.6.0 3.6.x@830003b) | tracing | Frontend JS heap | 6.74 MiB | 9.03 MiB | 9.72 MiB | 12.01 MiB | 12.98 MiB | 10.49 MiB |  |
| evo-parser (3.6.0 3.6.x@830003b) | tracing | Frontend DOM nodes | 133703 | 135034 | 135109 | 7949 | 9327 | 5105 |  |
| evo-latte (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | off | PHP peak (script) | 31.49 MiB | 1.11 MiB | 1 MiB | 1.11 MiB | 1.04 MiB | 0.92 MiB | 41.8 MiB |
| evo-latte (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | off | Frontend JS heap | 8.19 MiB | 9.81 MiB | 10.27 MiB | 10.91 MiB | 11.14 MiB | 8.86 MiB |  |
| evo-latte (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | off | Frontend DOM nodes | 267214 | 249069 | 247692 | 6006 | 6513 | 2560 |  |
| evo-latte (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | tracing | PHP peak (script) | 31.49 MiB | 1.11 MiB | 1 MiB | 1.11 MiB | 1.04 MiB | 1 MiB | 40.5 MiB |
| evo-latte (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | tracing | Frontend JS heap | 12.71 MiB | 13.32 MiB | 12.86 MiB | 12.81 MiB | 13.05 MiB | 13.46 MiB |  |
| evo-latte (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | tracing | Frontend DOM nodes | 268594 | 243616 | 240789 | 8704 | 9841 | 9981 |  |
| evo-latte (3.5.9 · aLatteX 0.5.1) | off | PHP peak (script) | 31.64 MiB | 1.11 MiB | 1.01 MiB | 1.12 MiB | 1.05 MiB | 1.1 MiB | 49.6 MiB |
| evo-latte (3.5.9 · aLatteX 0.5.1) | off | Frontend JS heap | 6.7 MiB | 8.98 MiB | 9.73 MiB | 11.98 MiB | 12.59 MiB | 10.42 MiB |  |
| evo-latte (3.5.9 · aLatteX 0.5.1) | off | Frontend DOM nodes | 132650 | 133989 | 134024 | 7636 | 8976 | 4874 |  |
| evo-latte (3.5.9 · aLatteX 0.5.1) | tracing | PHP peak (script) | 31.57 MiB | 1.11 MiB | 1.01 MiB | 1.12 MiB | 1.05 MiB | 1 MiB | 42.2 MiB |
| evo-latte (3.5.9 · aLatteX 0.5.1) | tracing | Frontend JS heap | 12.72 MiB | 11.11 MiB | 10.65 MiB | 10.28 MiB | 10.81 MiB | 12.15 MiB |  |
| evo-latte (3.5.9 · aLatteX 0.5.1) | tracing | Frontend DOM nodes | 268640 | 6877 | 5500 | 4737 | 6077 | 7337 |  |
| evo-latte-parser (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | off | PHP peak (script) | 31.49 MiB | 1.11 MiB | 1 MiB | 1.11 MiB | 1.04 MiB | 0.92 MiB | 36.8 MiB |
| evo-latte-parser (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | off | Frontend JS heap | 8.18 MiB | 10.06 MiB | 10.44 MiB | 10.83 MiB | 10.81 MiB | 14.53 MiB |  |
| evo-latte-parser (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | off | Frontend DOM nodes | 267442 | 249069 | 247692 | 6006 | 6514 | 10010 |  |
| evo-latte-parser (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | tracing | PHP peak (script) | 31.49 MiB | 1.11 MiB | 1 MiB | 1.11 MiB | 1.04 MiB | 1 MiB | 46.3 MiB |
| evo-latte-parser (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | tracing | Frontend JS heap | 6.69 MiB | 9.35 MiB | 9.82 MiB | 11.97 MiB | 11.22 MiB | 11.67 MiB |  |
| evo-latte-parser (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0) | tracing | Frontend DOM nodes | 132649 | 134010 | 134024 | 7636 | 6513 | 6006 |  |
| evo-latte-parser (3.5.9 · aLatteX 0.5.1) | off | PHP peak (script) | 31.64 MiB | 1.11 MiB | 1.01 MiB | 1.12 MiB | 1.05 MiB | 0.92 MiB | 47.6 MiB |
| evo-latte-parser (3.5.9 · aLatteX 0.5.1) | off | Frontend JS heap | 6.75 MiB | 9.79 MiB | 10.09 MiB | 12.37 MiB | 12.61 MiB | 16.12 MiB |  |
| evo-latte-parser (3.5.9 · aLatteX 0.5.1) | off | Frontend DOM nodes | 264487 | 136851 | 134022 | 9007 | 8976 | 12952 |  |
| evo-latte-parser (3.5.9 · aLatteX 0.5.1) | tracing | PHP peak (script) | 31.57 MiB | 1.11 MiB | 1.01 MiB | 1.12 MiB | 1.05 MiB | 1 MiB | 48.1 MiB |
| evo-latte-parser (3.5.9 · aLatteX 0.5.1) | tracing | Frontend JS heap | 6.67 MiB | 10.71 MiB | 11.5 MiB | 11.07 MiB | 9.94 MiB | 10.63 MiB |  |
| evo-latte-parser (3.5.9 · aLatteX 0.5.1) | tracing | Frontend DOM nodes | 132649 | 136851 | 136924 | 6006 | 4883 | 5023 |  |
| evo-phalcon (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0 · aPhalcon ../evo/aPhalcon@1c926447) | off | PHP peak (script) | 31.51 MiB | 1.13 MiB | 1.02 MiB | 1.14 MiB | 1.08 MiB | 0.93 MiB | 46.8 MiB |
| evo-phalcon (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0 · aPhalcon ../evo/aPhalcon@1c926447) | off | Frontend JS heap | 6.68 MiB | 10.08 MiB | 10.53 MiB | 13.88 MiB | 14.53 MiB | 10.77 MiB |  |
| evo-phalcon (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0 · aPhalcon ../evo/aPhalcon@1c926447) | off | Frontend DOM nodes | 132650 | 133989 | 134024 | 11161 | 12501 | 4921 |  |
| evo-phalcon (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0 · aPhalcon ../evo/aPhalcon@1c926447) | tracing | PHP peak (script) | 31.51 MiB | 1.13 MiB | 1.03 MiB | 1.14 MiB | 1.08 MiB | 1.02 MiB | 47.6 MiB |
| evo-phalcon (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0 · aPhalcon ../evo/aPhalcon@1c926447) | tracing | Frontend JS heap | 12.72 MiB | 13.23 MiB | 12.68 MiB | 10.77 MiB | 11.38 MiB | 12.07 MiB |  |
| evo-phalcon (3.5.9 3.5.x@c16d07e · aLatteX 0.5.0 · aPhalcon ../evo/aPhalcon@1c926447) | tracing | Frontend DOM nodes | 268591 | 132304 | 129477 | 6186 | 7056 | 7196 |  |
| evo-phalcon (3.5.9 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74) | off | PHP peak (script) | 31.66 MiB | 1.14 MiB | 1.03 MiB | 1.16 MiB | 1.07 MiB | 0.95 MiB | 47.6 MiB |
| evo-phalcon (3.5.9 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74) | off | Frontend JS heap | 6.67 MiB | 9.77 MiB | 10.03 MiB | 12.05 MiB | 12.91 MiB | 10.78 MiB |  |
| evo-phalcon (3.5.9 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74) | off | Frontend DOM nodes | 132650 | 135401 | 134024 | 7636 | 8976 | 4874 |  |
| evo-phalcon (3.5.9 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74) | tracing | PHP peak (script) | 31.59 MiB | 1.15 MiB | 1.03 MiB | 1.16 MiB | 1.07 MiB | 1.12 MiB | 48 MiB |
| evo-phalcon (3.5.9 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74) | tracing | Frontend JS heap | 6.68 MiB | 9.32 MiB | 9.75 MiB | 11.65 MiB | 12.58 MiB | 10.81 MiB |  |
| evo-phalcon (3.5.9 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74) | tracing | Frontend DOM nodes | 132649 | 133989 | 134024 | 7636 | 8976 | 4874 |  |
| evo-worker (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | off | PHP peak (script) | 32.2 MiB | 1.49 MiB | 1.39 MiB | 1.49 MiB | 1.43 MiB | 1.29 MiB | 94.2 MiB |
| evo-worker (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | off | Frontend JS heap | 6.75 MiB | 9.85 MiB | 10.66 MiB | 12.86 MiB | 11.72 MiB | 9.33 MiB |  |
| evo-worker (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | off | Frontend DOM nodes | 133704 | 136484 | 136559 | 9002 | 7841 | 2725 |  |
| evo-worker (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | tracing | PHP peak (script) | 32.2 MiB | 1.5 MiB | 1.39 MiB | 1.49 MiB | 1.48 MiB | 1.32 MiB | 83.8 MiB |
| evo-worker (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | tracing | Frontend JS heap | 12.81 MiB | 14.66 MiB | 14.74 MiB | 11.69 MiB | 10.84 MiB | 9.81 MiB |  |
| evo-worker (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | tracing | Frontend DOM nodes | 270733 | 265078 | 263663 | 10054 | 8665 | 2741 |  |
| evo-worker-keep (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | off | PHP peak (script) | 32.2 MiB | 1.49 MiB | 1.39 MiB | 1.49 MiB | 1.43 MiB | 1.29 MiB | 95 MiB |
| evo-worker-keep (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | off | Frontend JS heap | 6.74 MiB | 9.83 MiB | 10.59 MiB | 11.94 MiB | 11.73 MiB | 15.24 MiB |  |
| evo-worker-keep (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | off | Frontend DOM nodes | 133705 | 136490 | 136563 | 6463 | 7321 | 7512 |  |
| evo-worker-keep (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | tracing | PHP peak (script) | 32.2 MiB | 1.5 MiB | 1.39 MiB | 1.49 MiB | 1.48 MiB | 1.38 MiB | 97.4 MiB |
| evo-worker-keep (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | tracing | Frontend JS heap | 6.74 MiB | 10.19 MiB | 10.02 MiB | 12.08 MiB | 12.6 MiB | 12.1 MiB |  |
| evo-worker-keep (3.6.0 3.6.x@a8a0c26 · FrankenPHP 1.13.5) | tracing | Frontend DOM nodes | 133704 | 136483 | 135068 | 8012 | 9329 | 7133 |  |
| evo-latte-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | PHP peak (script) | 32.21 MiB | 1.5 MiB | 1.4 MiB | 1.5 MiB | 1.44 MiB | 1.31 MiB | 93.8 MiB |
| evo-latte-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | Frontend JS heap | 6.75 MiB | 9.86 MiB | 10.61 MiB | 14.69 MiB | 14.99 MiB | 18.36 MiB |  |
| evo-latte-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | Frontend DOM nodes | 133714 | 7154 | 5739 | 12884 | 14201 | 18268 |  |
| evo-latte-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | PHP peak (script) | 32.21 MiB | 1.5 MiB | 1.4 MiB | 1.5 MiB | 1.49 MiB | 1.31 MiB | 90.2 MiB |
| evo-latte-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | Frontend JS heap | 12.81 MiB | 13.12 MiB | 12.71 MiB | 11.7 MiB | 12.6 MiB | 17.18 MiB |  |
| evo-latte-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | Frontend DOM nodes | 270753 | 253048 | 251633 | 10054 | 8893 | 14113 |  |
| evo-latte-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | PHP peak (script) | 32.21 MiB | 1.5 MiB | 1.4 MiB | 1.5 MiB | 1.44 MiB | 1.31 MiB | 96.3 MiB |
| evo-latte-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | Frontend JS heap | 6.76 MiB | 9.87 MiB | 10.64 MiB | 15.09 MiB | 13.93 MiB | 8.92 MiB |  |
| evo-latte-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | Frontend DOM nodes | 133714 | 7154 | 5739 | 13540 | 12379 | 2725 |  |
| evo-latte-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | PHP peak (script) | 32.21 MiB | 1.51 MiB | 1.4 MiB | 1.5 MiB | 1.49 MiB | 1.31 MiB | 92.9 MiB |
| evo-latte-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | Frontend JS heap | 12.79 MiB | 13.26 MiB | 12.74 MiB | 12.82 MiB | 12.02 MiB | 9.29 MiB |  |
| evo-latte-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | Frontend DOM nodes | 270789 | 132664 | 129761 | 9002 | 7841 | 2725 |  |
| evo-latte-parser-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | PHP peak (script) | 32.21 MiB | 1.5 MiB | 1.4 MiB | 1.5 MiB | 1.44 MiB | 1.31 MiB | 94.3 MiB |
| evo-latte-parser-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | Frontend JS heap | 12.79 MiB | 13.08 MiB | 12.86 MiB | 11.11 MiB | 9.96 MiB | 12.41 MiB |  |
| evo-latte-parser-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | Frontend DOM nodes | 270753 | 243979 | 241076 | 6283 | 5302 | 7803 |  |
| evo-latte-parser-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | PHP peak (script) | 32.21 MiB | 1.51 MiB | 1.4 MiB | 1.5 MiB | 1.49 MiB | 1.34 MiB | 98 MiB |
| evo-latte-parser-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | Frontend JS heap | 6.75 MiB | 10.51 MiB | 10.3 MiB | 12.51 MiB | 12 MiB | 9.31 MiB |  |
| evo-latte-parser-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | Frontend DOM nodes | 133714 | 137870 | 136455 | 9057 | 7841 | 2780 |  |
| evo-latte-parser-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | PHP peak (script) | 32.21 MiB | 1.5 MiB | 1.4 MiB | 1.5 MiB | 1.44 MiB | 1.31 MiB | 93.8 MiB |
| evo-latte-parser-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | Frontend JS heap | 6.74 MiB | 9.85 MiB | 10.5 MiB | 13.59 MiB | 14.84 MiB | 11.06 MiB |  |
| evo-latte-parser-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | off | Frontend DOM nodes | 133715 | 135069 | 135119 | 11566 | 12944 | 6120 |  |
| evo-latte-parser-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | PHP peak (script) | 32.21 MiB | 1.5 MiB | 1.4 MiB | 1.5 MiB | 1.49 MiB | 1.33 MiB | 97.4 MiB |
| evo-latte-parser-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | Frontend JS heap | 13.22 MiB | 14.71 MiB | 13.79 MiB | 11.66 MiB | 12.86 MiB | 17.17 MiB |  |
| evo-latte-parser-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · FrankenPHP 1.13.5) | tracing | Frontend DOM nodes | 270937 | 243973 | 242558 | 10054 | 8893 | 14113 |  |
| evo-phalcon-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | off | PHP peak (script) | 32.23 MiB | 1.52 MiB | 1.42 MiB | 1.52 MiB | 1.46 MiB | 1.33 MiB | 90.7 MiB |
| evo-phalcon-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | off | Frontend JS heap | 6.75 MiB | 10.46 MiB | 10.95 MiB | 11.41 MiB | 12.33 MiB | 13.42 MiB |  |
| evo-phalcon-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | off | Frontend DOM nodes | 133714 | 128779 | 127364 | 27390 | 9047 | 9189 |  |
| evo-phalcon-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | tracing | PHP peak (script) | 32.23 MiB | 1.53 MiB | 1.42 MiB | 1.52 MiB | 1.51 MiB | 1.34 MiB | 87.8 MiB |
| evo-phalcon-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | tracing | Frontend JS heap | 6.75 MiB | 9.97 MiB | 10 MiB | 12.07 MiB | 12.92 MiB | 11.44 MiB |  |
| evo-phalcon-worker (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | tracing | Frontend DOM nodes | 133715 | 136492 | 135077 | 7951 | 9329 | 6123 |  |
| evo-phalcon-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | off | PHP peak (script) | 32.23 MiB | 1.52 MiB | 1.42 MiB | 1.52 MiB | 1.46 MiB | 1.33 MiB | 94.2 MiB |
| evo-phalcon-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | off | Frontend JS heap | 6.76 MiB | 10 MiB | 10.77 MiB | 13.13 MiB | 11.53 MiB | 11.11 MiB |  |
| evo-phalcon-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | off | Frontend DOM nodes | 133714 | 128776 | 127361 | 131559 | 7504 | 6120 |  |
| evo-phalcon-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | tracing | PHP peak (script) | 32.23 MiB | 1.53 MiB | 1.42 MiB | 1.52 MiB | 1.51 MiB | 1.34 MiB | 87.8 MiB |
| evo-phalcon-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | tracing | Frontend JS heap | 6.75 MiB | 10.21 MiB | 10.03 MiB | 11.97 MiB | 12.59 MiB | 10.85 MiB |  |
| evo-phalcon-worker-keep (3.6.0 3.6.x@a8a0c26 · aLatteX 0.5.1 · aPhalcon ../evo/aPhalcon@53a53d74 · FrankenPHP 1.13.5) | tracing | Frontend DOM nodes | 133714 | 136495 | 135080 | 7951 | 9329 | 5156 |  |
| evo-frankenphp (3.6.0 3.6.x@830003b · FrankenPHP 1.13.4) | off | PHP peak (script) | 32.19 MiB | 1.47 MiB | 1.37 MiB | 1.48 MiB | 1.41 MiB | 1.31 MiB | 29.1 MiB |
| evo-frankenphp (3.6.0 3.6.x@830003b · FrankenPHP 1.13.4) | off | Frontend JS heap | 6.75 MiB | 11.69 MiB | 12.45 MiB | 15 MiB | 16.08 MiB | 18.03 MiB |  |
| evo-frankenphp (3.6.0 3.6.x@830003b · FrankenPHP 1.13.4) | off | Frontend DOM nodes | 133703 | 138012 | 138085 | 10113 | 8891 | 14127 |  |
| evo-frankenphp (3.6.0 3.6.x@830003b · FrankenPHP 1.13.4) | tracing | PHP peak (script) | 32.19 MiB | 1.48 MiB | 1.37 MiB | 1.48 MiB | 1.47 MiB | 1.28 MiB | 34 MiB |
| evo-frankenphp (3.6.0 3.6.x@830003b · FrankenPHP 1.13.4) | tracing | Frontend JS heap | 6.75 MiB | 9.44 MiB | 9.91 MiB | 12.09 MiB | 12.72 MiB | 11.05 MiB |  |
| evo-frankenphp (3.6.0 3.6.x@830003b · FrankenPHP 1.13.4) | tracing | Frontend DOM nodes | 133702 | 135056 | 135107 | 8011 | 9328 | 6173 |  |
| drupal (11.4.11) | off | PHP peak (script) | 3.17 MiB | 4.9 MiB | 4.3 MiB | 4.76 MiB | 4.3 MiB | 2.88 MiB | 62.1 MiB |
| drupal (11.4.11) | off | Frontend JS heap | 11.88 MiB | 19.8 MiB | 20.97 MiB | 35.4 MiB | 36.12 MiB | 44.04 MiB |  |
| drupal (11.4.11) | off | Frontend DOM nodes | 2498 | 4802 | 5018 | 8853 | 9069 | 11084 |  |
| drupal (11.4.11) | tracing | PHP peak (script) | 3.19 MiB | 4.97 MiB | 4.3 MiB | 4.76 MiB | 4.31 MiB | 2.88 MiB | 58.7 MiB |
| drupal (11.4.11) | tracing | Frontend JS heap | 11.9 MiB | 19.73 MiB | 20.9 MiB | 35.01 MiB | 36.11 MiB | 43.89 MiB |  |
| drupal (11.4.11) | tracing | Frontend DOM nodes | 2498 | 4802 | 5021 | 8865 | 9081 | 11096 |  |
| typo3 (14.3.7) | off | PHP peak (script) | 6.36 MiB | 7.38 MiB | 7.45 MiB | 6.63 MiB | 7.03 MiB | 4.55 MiB | 131.6 MiB |
| typo3 (14.3.7) | off | Frontend JS heap | 34.03 MiB | 60.79 MiB | 61.58 MiB | 80.19 MiB | 104.98 MiB | 104.98 MiB |  |
| typo3 (14.3.7) | off | Frontend DOM nodes | 32098 | 56054 | 64728 | 117146 | 111982 | 112266 |  |
| typo3 (14.3.7) | tracing | PHP peak (script) | 6.42 MiB | 7.39 MiB | 7.45 MiB | 6.63 MiB | 7.03 MiB | 4.55 MiB | 78.1 MiB |
| typo3 (14.3.7) | tracing | Frontend JS heap | 28.72 MiB | 64.66 MiB | 64.79 MiB | 124.87 MiB | 130.76 MiB | 132.19 MiB |  |
| typo3 (14.3.7) | tracing | Frontend DOM nodes | 25858 | 60430 | 69090 | 147323 | 164767 | 165135 |  |
| winter (1.2.15) | off | PHP peak (script) | 1.88 MiB | 1.96 MiB | 1.48 MiB | 1.95 MiB | 1.96 MiB | 1.35 MiB | 41.2 MiB |
| winter (1.2.15) | off | Frontend JS heap | 23.46 MiB | 22.71 MiB | 22.77 MiB | 48.55 MiB | 54.27 MiB | 63.34 MiB |  |
| winter (1.2.15) | off | Frontend DOM nodes | 4890 | 4213 | 2513 | 9523 | 10421 | 14453 |  |
| winter (1.2.15) | tracing | PHP peak (script) | 1.94 MiB | 1.96 MiB | 1.5 MiB | 1.95 MiB | 1.96 MiB | 1.35 MiB | 41.9 MiB |
| winter (1.2.15) | tracing | Frontend JS heap | 23.41 MiB | 24.49 MiB | 23.73 MiB | 39.55 MiB | 44.88 MiB | 59.42 MiB |  |
| winter (1.2.15) | tracing | Frontend DOM nodes | 4890 | 4206 | 4239 | 5976 | 6874 | 10906 |  |
| modx (3.2.4-pl) | off | PHP peak (script) | 1.05 MiB | 0.95 MiB | 0.74 MiB | 0.92 MiB | 0.96 MiB | 0.74 MiB | 32.1 MiB |
| modx (3.2.4-pl) | off | Frontend JS heap | 25.46 MiB | 26.3 MiB | 29.23 MiB | 43.07 MiB | 42.76 MiB | 41.56 MiB |  |
| modx (3.2.4-pl) | off | Frontend DOM nodes | 9703 | 8433 | 8568 | 12039 | 9604 | 10448 |  |
| modx (3.2.4-pl) | tracing | PHP peak (script) | 1.2 MiB | 0.96 MiB | 0.74 MiB | 0.92 MiB | 0.96 MiB | 0.74 MiB | 32.8 MiB |
| modx (3.2.4-pl) | tracing | Frontend JS heap | 25.25 MiB | 33.06 MiB | 27.41 MiB | 51.74 MiB | 56.96 MiB | 75.52 MiB |  |
| modx (3.2.4-pl) | tracing | Frontend DOM nodes | 9792 | 6825 | 6979 | 17209 | 18815 | 24771 |  |
| wordpress-gantry (7.1.4 · Gantry 5.6.7) | off | PHP peak (script) | 4.58 MiB | 4.58 MiB | 4.58 MiB | 4.58 MiB | 4.58 MiB | 3.17 MiB | 60.8 MiB |
| wordpress-gantry (7.1.4 · Gantry 5.6.7) | off | Frontend JS heap | 45.48 MiB | 121.54 MiB | 135.84 MiB | 269.63 MiB | 243.76 MiB | 105.77 MiB |  |
| wordpress-gantry (7.1.4 · Gantry 5.6.7) | off | Frontend DOM nodes | 1947 | 19079 | 22870 | 48613 | 45084 | 14854 |  |
| wordpress-gantry (7.1.4 · Gantry 5.6.7) | tracing | PHP peak (script) | 4.57 MiB | 4.57 MiB | 4.57 MiB | 4.57 MiB | 4.57 MiB | 3.16 MiB | 61.7 MiB |
| wordpress-gantry (7.1.4 · Gantry 5.6.7) | tracing | Frontend JS heap | 113.16 MiB | 105.87 MiB | 106.23 MiB | 227.44 MiB | 212.11 MiB | 79.04 MiB |  |
| wordpress-gantry (7.1.4 · Gantry 5.6.7) | tracing | Frontend DOM nodes | 14163 | 17813 | 17453 | 43086 | 39549 | 7435 |  |

## Official Evolution branch comparison (2026-11-01)

Fresh Evo-only installs from the official [`3.5.x`](https://github.com/evolution-cms/evolution/tree/3.5.x) branch at `6413fdc` and [`3.6.x`](https://github.com/evolution-cms/evolution/tree/3.6.x) branch at `830003b`. The 3.5.x branch has no Evolution worker runtime, so it is measured on PHP-FPM. Evolution 3.6.x provides worker mode; the benchmark measures classic FrankenPHP, worker mode with a new app per request, and worker mode with the app retained per worker, as described in the [Evolution worker setup](https://github.com/evolution-cms/evolution/blob/3.6.x/.github/docker/WORKER.md) and [FrankenPHP worker docs](https://github.com/php/frankenphp/blob/main/docs/worker.md). No optional CMS plugin stacks were benchmarked for these refs.

PHP 8.4.27, 30 offered requests/sec, 60 seconds, 32 connections, 8 PHP workers/threads; all cells had zero non-2xx responses.

| Runtime | Evolution ref | JIT | Achieved rps | p50 | p99 | Non-2xx |
| --- | --- | --- | --- | --- | --- | --- |
| PHP-FPM | 3.5.9 `3.5.x@6413fdc` | off | 30.38 | 36.22ms | 243.33ms | 0 |
| PHP-FPM | 3.5.9 `3.5.x@6413fdc` | tracing | 30.38 | 34.37ms | 244.48ms | 0 |
| PHP-FPM | 3.6.0 `3.6.x@830003b` | off | 30.38 | 55.94ms | 116.54ms | 0 |
| PHP-FPM | 3.6.0 `3.6.x@830003b` | tracing | 30.37 | 56.70ms | 245.76ms | 0 |
| FrankenPHP classic | 3.6.0 `3.6.x@830003b` | off | 30.38 | 62.85ms | 118.78ms | 0 |
| FrankenPHP classic | 3.6.0 `3.6.x@830003b` | tracing | 30.38 | 63.46ms | 255.87ms | 0 |
| Worker, full app reload (`EVO_WORKER_KEEP_APP=0`) | 3.6.0 `3.6.x@830003b` | off | 30.38 | 64.29ms | 126.85ms | 0 |
| Worker, full app reload (`EVO_WORKER_KEEP_APP=0`) | 3.6.0 `3.6.x@830003b` | tracing | 30.37 | 61.31ms | 247.81ms | 0 |
| Worker, retained app (`EVO_WORKER_KEEP_APP=1`) | 3.6.0 `3.6.x@830003b` | off | 30.38 | 40.35ms | 221.70ms | 0 |
| Worker, retained app (`EVO_WORKER_KEEP_APP=1`) | 3.6.0 `3.6.x@830003b` | tracing | 30.38 | 38.08ms | 106.94ms | 0 |

Admin workload total wall time (one round; five edits and five creates passed):

| Runtime | JIT off | JIT tracing |
| --- | --- | --- |
| PHP-FPM, 3.5.x | 7954ms | 7336ms |
| PHP-FPM, 3.6.x | 7870ms | 8209ms |
| FrankenPHP classic, 3.6.x | 9065ms | 8715ms |
| Worker, full app reload, 3.6.x | 8504ms | 8575ms |
| Worker, retained app, 3.6.x | 8450ms | 8247ms |
