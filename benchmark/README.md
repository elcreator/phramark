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

- The Evolution stacks resolve a real document tree (closure table, alias path) and read TVs from an EAV table; `evo-parser`/`evo-latte` do it with `getTemplateVarOutput('*')` per card (N+1 queries), `evo-phalcon` with one hand-written `IN (…)` query that also skips document-group access checks. Drupal and TYPO3 read one flat, indexed `phramark_article` table. The stacks are therefore comparable as "CMS-native request path over the same fixture", not as identical SQL.
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
| evo-* | http://127.0.0.1:8080/manager/ (8081, 8082, 8085) | `benchmark-admin` | Folder "Admin workload" (id 10103) with pages 10104–10108 |
| drupal | http://127.0.0.1:8083/user/login | `benchmark-admin` | Content type "Basic page" with a plain-text body; the five lowest page nodes |
| typo3 | http://127.0.0.1:8084/typo3/ | `Benchmark1!` | Root page "Admin workload" (uid 10103) with pages 10104–10108, each with one text element of the same uid |
| winter | http://127.0.0.1:8086/backend/ | `benchmark-admin` | "Benchmark pages" (plugin `Phramark.Benchmark`, table `phramark_benchmark_pages`) with pages 10104–10108 |
| modx | http://127.0.0.1:8087/manager/ | `benchmark-admin` | Container "Admin workload" (id 10103) with resources 10104–10108 on the "Admin page" template |
| wordpress-gantry | http://127.0.0.1:8088/wp-login.php | `benchmark-admin` | Page "Admin workload" (id 10103) with child pages 10104–10108, edited in the Classic Editor (Text tab) |

Re-running `up` re-seeds the fixture and re-syncs adapter code into existing volumes; a site is reinstalled only when the versions it should be built from changed (its `.phramark-versions` marker, written by the setup from the resolved refs). The setup containers restart nothing, so after a re-seed restart the FPM containers (`docker compose … up -d --force-recreate php-parser …`) or use `bench`, which does.

## The matrix

`benchmark/scripts/bench WORKLOAD JIT PORT [OFFERED_RPS]` runs one cell: it recreates the stack's FPM with `PHP_JIT` set, warms all 100 category pages, runs the fairness gate from the host and from inside the compose network, then runs the workload. The JIT mode is read back from the container into the result name, so a result can never claim a mode that did not run.

```sh
benchmark/scripts/bench guest off     8080 100
benchmark/scripts/bench guest tracing 8080 100
benchmark/scripts/bench admin off     8080
benchmark/scripts/bench admin tracing 8080
```

`benchmark/scripts/matrix` runs many cells in one go and ends by recording the container versions and refreshing `docs/results.json`. Without options it runs every reachable stack × guest/admin × JIT off/tracing at 30 rps; options select a part of the matrix:

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
| `php` | the PHP image (`php:<ref>-fpm-bookworm`, `PHP_VERSION` build argument) | all | image rebuild; no path |
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

Every step also records memory on both sides:

| Metric | Side | Source |
| --- | --- | --- |
| `phpPeakMb` / `phpPeakRealMb` | PHP | Exact script peak and allocator peak (`memory_get_peak_usage`) of the largest request the step caused; requests are attributed by the `X-Phramark-Step` header the browser sends, logged by `benchmark/fixtures/memory-prepend.php` |
| `containerPeakMb` | PHP | Peak RSS of the stack's PHP-FPM container during the run: the anonymous memory of its cgroup (`memory.stat` `anon`, 1 s samples), the heaps of PHP-FPM and its workers |
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
| Guest: PHP-FPM RSS | `.rss.log` column 2 (cgroup `anon`, 1 s samples) | The workers' resident memory: OPcache filling, per-worker static caches, or a leak across requests |
| Guest: PHP-FPM container footprint | `.rss.log` column 3 (`docker stats`, with `--footprint`) | The same plus the page cache: what the run wrote to disk |
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

`evo-manticore` is opt-in: `matrix` without `--solutions` runs the nine served stacks and nothing else, and the job only runs when that list names it. It writes into `benchmark/results/manticore` and never touches `docs/results.json`.

Two things the compiler does not tell you: invoked as a bare `manticore` from `PATH` it finds neither its prelude nor its stdlib (both are located relative to `argv[0]`), and the stdlib is then silently not linked, so every `str_replace()` becomes "undefined function". The image sets `MANTICORE_PRELUDE`, `MANTICORE_STDLIB_O` and `MANTICORE_STDLIB_SIG`.

### Caches: what a repeat run reuses

A cold run bootstraps the compiler (about 7 minutes: Zend seeds a native compiler that then rebuilds itself), compiles 9,687 files (8 minutes on 48 cores, 3 hours on a 3-CPU Docker Desktop VM) and rediscovers 41 whole-program blockers one build round at a time. Three caches, each kept where its size fits, take a repeat run down to about 3½ minutes, most of it the timed workload itself:

| Cache | Where | What it saves | Key |
| --- | --- | --- | --- |
| Compiler image `ghcr.io/elcreator/phramark-manticore:<version>` | GitHub Container Registry, published by `.github/workflows/manticore-image.yml` (on Dockerfile changes, monthly, on demand) | the toolchain install and the compiler bootstrap; `benchmark/scripts/manticore` pulls it and builds locally only with `MANTICORE_BUILD=1` | the compiler version the image reports |
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

Full matrix (`benchmark/scripts/matrix 30`, `DURATION=60s`, `ROUNDS=1`) on one Windows 11 laptop with Docker Desktop, 8 FPM workers, load generator on the same host, 2026-09-20. A smoke run that shows every cell working; single rounds on a shared machine, so treat differences below ~20 % as noise. Sortable at https://phramark.artur.work; regenerate with `php benchmark/scripts/summary.php`. The versions table names exactly what ran, per stack, linked to the source repositories.

### Versions tested

Recorded from the running containers on 2026-09-20T14:43:04Z: PHP 8.4.25, MySQL 8.4.7, nginx/1.27.5, OPcache validate_timestamps=0, memory_consumption=512, max_accelerated_files=100000.

| Stack | Components (exact versions) |
| --- | --- |
| evo-parser | [Evolution CMS](https://github.com/evolution-cms/evolution) 3.5.9 (3.5.x@851c705, 2026-09-10), [illuminate/database](https://github.com/illuminate/database) v12.69.2 |
| evo-latte | [Evolution CMS](https://github.com/evolution-cms/evolution) 3.5.9 (3.5.x@851c705, 2026-09-10), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.0, [illuminate/database](https://github.com/illuminate/database) v12.69.2, [latte/latte](https://github.com/nette/latte) v3.1.6 |
| evo-latte-parser | [Evolution CMS](https://github.com/evolution-cms/evolution) 3.5.9 (3.5.x@851c705, 2026-09-10), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.0, [illuminate/database](https://github.com/illuminate/database) v12.69.2, [latte/latte](https://github.com/nette/latte) v3.1.6 |
| evo-phalcon | [Evolution CMS](https://github.com/evolution-cms/evolution) 3.5.9 (3.5.x@851c705, 2026-09-10), [elcreator/alattex](https://github.com/elcreator/aLatteX) 0.5.0, [elcreator/aphalcon](https://github.com/elcreator/aPhalcon) 0.1.0, [illuminate/database](https://github.com/illuminate/database) v12.69.2, [latte/latte](https://github.com/nette/latte) v3.1.6, [phalcon (extension)](https://github.com/phalcon/cphalcon) 5.9.3 |
| drupal | [drupal/core](https://github.com/drupal/core) 11.4.7, [drush/drush](https://github.com/drush-ops/drush) 13.8.0, [symfony/http-foundation](https://github.com/symfony/http-foundation) v7.4.19, [symfony/http-kernel](https://github.com/symfony/http-kernel) v7.4.19, [twig/twig](https://github.com/twigphp/Twig) v3.28.0 |
| typo3 | [doctrine/dbal](https://github.com/doctrine/dbal) 4.4.4, [symfony/http-foundation](https://github.com/symfony/http-foundation) v7.4.19, [typo3/cms-core](https://github.com/TYPO3/typo3) v14.3.7, [typo3fluid/fluid](https://github.com/TYPO3/Fluid) 5.3.2 |
| winter | [doctrine/dbal](https://github.com/doctrine/dbal) 2.13.9, [laravel/framework](https://github.com/laravel/framework) v9.52.22, [symfony/http-foundation](https://github.com/symfony/http-foundation) v6.4.46, [twig/twig](https://github.com/twigphp/Twig) v3.29.0, [winter/storm](https://github.com/wintercms/storm) v1.2.14, [winter/wn-cms-module](https://github.com/wintercms/wn-cms-module) v1.2.14 |
| modx | [MODX Revolution](https://github.com/modxcms/revolution) 3.2.4-pl, [xpdo/xpdo](https://github.com/modxcms/xpdo) v3.1.7 |
| wordpress-gantry | [WordPress](https://github.com/WordPress/WordPress) 7.1.1, [classic-editor](https://github.com/WordPress/classic-editor) 1.7.0, [gantry5](https://github.com/gantry/gantry5) 5.6.4, [g5_hydrogen](https://github.com/gantry/gantry5) 5.6.4, [timber/timber](https://github.com/timber/timber) 1.24.1, [twig/twig](https://github.com/twigphp/Twig) v2.16.1 |

### Guest workload (wrk2, constant offered rate)

| Stack | JIT | Offered | Achieved rps | p50 | p99 | Non-2xx | PHP peak/request (script median) | PHP alloc peak p95 | FPM container peak RSS |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser | off | 30 | 29.99 | 392.96ms | 743.42ms | 0 | 1.8 MiB | 4.0 MiB | 81.4 MiB |
| evo-parser | tracing | 30 | 30.03 | 351.74ms | 668.67ms | 0 | 1.8 MiB | 4.0 MiB | 91.6 MiB |
| evo-latte | off | 30 | 30.03 | 380.16ms | 690.69ms | 0 | 1.8 MiB | 4.0 MiB | 84.5 MiB |
| evo-latte | tracing | 30 | 30.08 | 346.37ms | 642.56ms | 0 | 1.9 MiB | 4.0 MiB | 93.8 MiB |
| evo-latte-parser | off | 30 | 30.03 | 384.51ms | 644.61ms | 0 | 1.8 MiB | 4.0 MiB | 84.2 MiB |
| evo-latte-parser | tracing | 30 | 30.07 | 333.05ms | 573.95ms | 0 | 1.9 MiB | 4.0 MiB | 93.6 MiB |
| evo-phalcon | off | 30 | 30.37 | 50.88ms | 169.73ms | 0 | 1.0 MiB | 2.0 MiB | 74.8 MiB |
| evo-phalcon | tracing | 30 | 30.38 | 48.74ms | 246.40ms | 0 | 1.0 MiB | 2.0 MiB | 83.3 MiB |
| drupal | off | 30 | 30.37 | 79.68ms | 145.66ms | 0 | 2.1 MiB | 4.0 MiB | 71.2 MiB |
| drupal | tracing | 30 | 30.37 | 81.73ms | 166.53ms | 0 | 2.1 MiB | 4.0 MiB | 72.7 MiB |
| typo3 | off | 30 | 30.38 | 46.43ms | 100.86ms | 0 | 3.1 MiB | 4.0 MiB | 81.8 MiB |
| typo3 | tracing | 30 | 30.38 | 49.82ms | 110.78ms | 0 | 3.1 MiB | 4.0 MiB | 90.0 MiB |
| winter | off | 30 | 30.38 | 90.05ms | 197.38ms | 0 | 1.4 MiB | 2.0 MiB | 82.1 MiB |
| winter | tracing | 30 | 30.38 | 86.46ms | 219.90ms | 0 | 1.4 MiB | 2.0 MiB | 93.5 MiB |
| modx | off | 30 | 30.38 | 115.14ms | 211.84ms | 0 | 0.7 MiB | 2.0 MiB | 50.3 MiB |
| modx | tracing | 30 | 30.38 | 103.93ms | 188.03ms | 0 | 0.7 MiB | 2.0 MiB | 58.0 MiB |
| wordpress-gantry | off | 30 | 30.14 | 249.98ms | 436.99ms | 0 | 4.9 MiB | 6.0 MiB | 131.0 MiB |
| wordpress-gantry | tracing | 30 | 30.12 | 229.12ms | 400.38ms | 0 | 4.9 MiB | 6.0 MiB | 148.2 MiB |

### Admin workload (Playwright; medians per action, wall / server)

| Stack | JIT | login | open-edit | save-edit | open-create | save-create | logout | Total wall |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser | off | 1845.75 / 313.98 ms | 168.43 / 105.14 ms | 771.67 / 443.13 ms | 144.34 / 87.02 ms | 894.2 / 524.75 ms | 172.22 / 125.13 ms | 11892 ms |
| evo-parser | tracing | 1813.44 / 285.87 ms | 212.37 / 133.86 ms | 896.32 / 563.29 ms | 174.63 / 109.53 ms | 978.25 / 651.88 ms | 224.84 / 163.45 ms | 13463 ms |
| evo-latte | off | 1815.63 / 251.83 ms | 164.43 / 104.95 ms | 750.15 / 420.12 ms | 141.7 / 85.41 ms | 977.99 / 530.85 ms | 237.49 / 190.7 ms | 12143 ms |
| evo-latte | tracing | 1831.94 / 291.98 ms | 218.51 / 140.78 ms | 887.51 / 562.3 ms | 187.68 / 121.4 ms | 987.77 / 668.48 ms | 269.89 / 220.81 ms | 13515 ms |
| evo-latte-parser | off | 1848.89 / 252.74 ms | 163.48 / 107.58 ms | 769.19 / 423.94 ms | 141.39 / 83.72 ms | 846.46 / 512.25 ms | 171.5 / 116.52 ms | 12086 ms |
| evo-latte-parser | tracing | 1830.28 / 290.46 ms | 213.61 / 136.35 ms | 902.91 / 565.11 ms | 187.24 / 122.36 ms | 996.67 / 654.97 ms | 204.87 / 153.18 ms | 13523 ms |
| evo-phalcon | off | 1828.4 / 254.03 ms | 191.26 / 111.62 ms | 822.23 / 472.78 ms | 145.68 / 88.5 ms | 872.71 / 529.98 ms | 181.32 / 131.11 ms | 12256 ms |
| evo-phalcon | tracing | 1827.44 / 280.16 ms | 204.76 / 135.79 ms | 899.38 / 567.5 ms | 173.66 / 118.76 ms | 971.41 / 643.73 ms | 223.49 / 174.25 ms | 13393 ms |
| drupal | off | 438.04 / 323.07 ms | 184.56 / 123.62 ms | 408.37 / 350.09 ms | 95.76 / 51.34 ms | 377.69 / 312.74 ms | 228.35 / 112.08 ms | 6048 ms |
| drupal | tracing | 393.18 / 283.32 ms | 168.08 / 120.09 ms | 366.58 / 304.22 ms | 90.22 / 45.64 ms | 361.35 / 302.04 ms | 240.87 / 114.47 ms | 5516 ms |
| typo3 | off | 1540.28 / 1046.71 ms | 470.23 / 155.25 ms | 780.94 / 399.2 ms | 282.51 / 110.61 ms | 1766.2 / 762.9 ms | 192.55 / 106.57 ms | 18461 ms |
| typo3 | tracing | 1532.43 / 1048.91 ms | 492.05 / 151.92 ms | 832.47 / 386 ms | 329.31 / 124.17 ms | 2016.74 / 1207.93 ms | 373.2 / 328.92 ms | 20554 ms |
| winter | off | 1266.67 / 205.39 ms | 140.25 / 35.36 ms | 134.34 / 42.68 ms | 133.91 / 36.25 ms | 228.5 / 75.64 ms | 144.38 / 79.2 ms | 4742 ms |
| winter | tracing | 1334.07 / 222.71 ms | 119.37 / 32.52 ms | 124.54 / 34.29 ms | 118.56 / 32.06 ms | 201.4 / 64 ms | 148.33 / 73.57 ms | 4304 ms |
| modx | off | 639.83 / 377.5 ms | 640.16 / 249.61 ms | 279.43 / 138.07 ms | 599.16 / 182.47 ms | 1451.74 / 389.44 ms | 501.18 / 122.3 ms | 15748 ms |
| modx | tracing | 730.8 / 384.02 ms | 629.49 / 207.05 ms | 275.27 / 108.5 ms | 597.13 / 166.69 ms | 1435.32 / 354.56 ms | 504.28 / 84.22 ms | 15706 ms |
| wordpress-gantry | off | 534.92 / 262.91 ms | 288.93 / 71.61 ms | 506.83 / 122.44 ms | 329.05 / 79.2 ms | 501.8 / 141.32 ms | 211.23 / 73.44 ms | 9101 ms |
| wordpress-gantry | tracing | 526.12 / 249.59 ms | 279.28 / 61.21 ms | 440.89 / 128.02 ms | 342.2 / 276.81 ms | 480.77 / 130.28 ms | 255.3 / 91.11 ms | 8488 ms |

### Admin workload memory (medians per action)

| Stack | JIT | Side | login | open-edit | save-edit | open-create | save-create | logout | FPM container peak |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser | off | PHP peak (script) | 2.5 MiB | 1.23 MiB | 5.94 MiB | 1.26 MiB | 5.93 MiB | 2.12 MiB | 93.3 MiB |
| evo-parser | off | Frontend JS heap | 14.01 MiB | 10.11 MiB | 10.18 MiB | 8.46 MiB | 9.33 MiB | 9.75 MiB |  |
| evo-parser | off | Frontend DOM nodes | 27228 | 5472 | 5414 | 2455 | 3732 | 3919 |  |
| evo-parser | tracing | PHP peak (script) | 2.56 MiB | 1.44 MiB | 11.44 MiB | 1.45 MiB | 11.43 MiB | 2.25 MiB | 99 MiB |
| evo-parser | tracing | Frontend JS heap | 14.01 MiB | 10.23 MiB | 10.18 MiB | 9.56 MiB | 9.42 MiB | 10.97 MiB |  |
| evo-parser | tracing | Frontend DOM nodes | 27289 | 5411 | 5414 | 3539 | 3732 | 5017 |  |
| evo-latte | off | PHP peak (script) | 2.53 MiB | 1.24 MiB | 5.95 MiB | 1.27 MiB | 5.94 MiB | 2.13 MiB | 93.6 MiB |
| evo-latte | off | Frontend JS heap | 13.99 MiB | 16.38 MiB | 16.98 MiB | 9.87 MiB | 9.05 MiB | 10.98 MiB |  |
| evo-latte | off | Frontend DOM nodes | 27309 | 18098 | 19546 | 4582 | 3732 | 5017 |  |
| evo-latte | tracing | PHP peak (script) | 2.59 MiB | 1.61 MiB | 11.45 MiB | 1.42 MiB | 11.44 MiB | 2.25 MiB | 98.4 MiB |
| evo-latte | tracing | Frontend JS heap | 13.98 MiB | 10.17 MiB | 10.77 MiB | 9.87 MiB | 9.09 MiB | 9.18 MiB |  |
| evo-latte | tracing | Frontend DOM nodes | 27309 | 5411 | 6920 | 4732 | 3549 | 2557 |  |
| evo-latte-parser | off | PHP peak (script) | 2.53 MiB | 1.24 MiB | 5.95 MiB | 1.27 MiB | 5.94 MiB | 2.13 MiB | 89.3 MiB |
| evo-latte-parser | off | Frontend JS heap | 13.99 MiB | 15.03 MiB | 15.21 MiB | 9.9 MiB | 10.24 MiB | 11.56 MiB |  |
| evo-latte-parser | off | Frontend DOM nodes | 27310 | 15205 | 13833 | 4582 | 5710 | 5999 |  |
| evo-latte-parser | tracing | PHP peak (script) | 2.59 MiB | 1.61 MiB | 11.45 MiB | 1.42 MiB | 11.44 MiB | 2.25 MiB | 93.9 MiB |
| evo-latte-parser | tracing | Frontend JS heap | 14.01 MiB | 9.77 MiB | 9.35 MiB | 9.28 MiB | 10.13 MiB | 10.11 MiB |  |
| evo-latte-parser | tracing | Frontend DOM nodes | 27310 | 5411 | 4094 | 3774 | 4877 | 3872 |  |
| evo-phalcon | off | PHP peak (script) | 2.59 MiB | 1.24 MiB | 6.02 MiB | 1.28 MiB | 6.01 MiB | 2.14 MiB | 95.5 MiB |
| evo-phalcon | off | Frontend JS heap | 14 MiB | 16.06 MiB | 17.02 MiB | 9.28 MiB | 9.03 MiB | 10.95 MiB |  |
| evo-phalcon | off | Frontend DOM nodes | 27356 | 18769 | 19542 | 3600 | 3732 | 5017 |  |
| evo-phalcon | tracing | PHP peak (script) | 2.6 MiB | 1.59 MiB | 11.52 MiB | 1.39 MiB | 11.51 MiB | 2.26 MiB | 92.8 MiB |
| evo-phalcon | tracing | Frontend JS heap | 14 MiB | 15.07 MiB | 15.52 MiB | 9.86 MiB | 9.31 MiB | 12.22 MiB |  |
| evo-phalcon | tracing | Frontend DOM nodes | 27309 | 15207 | 13890 | 4521 | 3732 | 7828 |  |
| drupal | off | PHP peak (script) | 3.11 MiB | 4.82 MiB | 4.23 MiB | 4.69 MiB | 4.23 MiB | 2.83 MiB | 87.2 MiB |
| drupal | off | Frontend JS heap | 11.9 MiB | 19.73 MiB | 20.89 MiB | 34.96 MiB | 36.06 MiB | 43.86 MiB |  |
| drupal | off | Frontend DOM nodes | 2498 | 4805 | 5021 | 8862 | 9078 | 11093 |  |
| drupal | tracing | PHP peak (script) | 3.11 MiB | 4.89 MiB | 4.23 MiB | 4.69 MiB | 4.23 MiB | 2.83 MiB | 82.1 MiB |
| drupal | tracing | Frontend JS heap | 11.87 MiB | 20.16 MiB | 20.94 MiB | 35.05 MiB | 36.15 MiB | 43.91 MiB |  |
| drupal | tracing | Frontend DOM nodes | 2498 | 4802 | 5021 | 8865 | 9084 | 11102 |  |
| typo3 | off | PHP peak (script) | 6.27 MiB | 7.28 MiB | 7.34 MiB | 6.53 MiB | 6.93 MiB | 4.5 MiB | 32.8 MiB |
| typo3 | off | Frontend JS heap | 34.23 MiB | 43.26 MiB | 36.46 MiB | 41.58 MiB | 36.68 MiB | 41.31 MiB |  |
| typo3 | off | Frontend DOM nodes | 34035 | 38306 | 38673 | 30690 | 25474 | 19852 |  |
| typo3 | tracing | PHP peak (script) | 6.33 MiB | 7.28 MiB | 7.34 MiB | 6.53 MiB | 6.93 MiB | 4.5 MiB | 32.9 MiB |
| typo3 | tracing | Frontend JS heap | 31.78 MiB | 69.77 MiB | 70.06 MiB | 120.99 MiB | 130.84 MiB | 18.29 MiB |  |
| typo3 | tracing | Frontend DOM nodes | 32930 | 67647 | 76294 | 153051 | 147824 | 12409 |  |
| winter | off | PHP peak (script) | 1.88 MiB | 1.96 MiB | 1.42 MiB | 1.95 MiB | 1.96 MiB | 1.35 MiB | 85.4 MiB |
| winter | off | Frontend JS heap | 23.55 MiB | 24.4 MiB | 23.82 MiB | 48.27 MiB | 53.58 MiB | 63 MiB |  |
| winter | off | Frontend DOM nodes | 5230 | 4262 | 4295 | 9579 | 10477 | 14516 |  |
| winter | tracing | PHP peak (script) | 1.91 MiB | 1.96 MiB | 1.49 MiB | 1.95 MiB | 1.97 MiB | 1.35 MiB | 94.5 MiB |
| winter | tracing | Frontend JS heap | 23.58 MiB | 24.82 MiB | 25.47 MiB | 46.36 MiB | 46.7 MiB | 60.83 MiB |  |
| winter | tracing | Frontend DOM nodes | 5229 | 4289 | 4322 | 7802 | 8707 | 12746 |  |
| modx | off | PHP peak (script) | 1.05 MiB | 0.95 MiB | 0.74 MiB | 0.92 MiB | 0.96 MiB | 0.74 MiB | 63.5 MiB |
| modx | off | Frontend JS heap | 23.12 MiB | 19.01 MiB | 22.14 MiB | 54.27 MiB | 45.21 MiB | 28.81 MiB |  |
| modx | off | Frontend DOM nodes | 9869 | 4189 | 2031 | 12011 | 9859 | 2197 |  |
| modx | tracing | PHP peak (script) | 1.2 MiB | 0.95 MiB | 0.74 MiB | 0.92 MiB | 0.96 MiB | 0.74 MiB | 72.1 MiB |
| modx | tracing | Frontend JS heap | 22.9 MiB | 37.69 MiB | 32.9 MiB | 41.82 MiB | 37.97 MiB | 28.81 MiB |  |
| modx | tracing | Frontend DOM nodes | 9706 | 9270 | 9570 | 11714 | 9701 | 2197 |  |
| wordpress-gantry | off | PHP peak (script) | 4.57 MiB | 4.57 MiB | 4.57 MiB | 4.57 MiB | 4.57 MiB | 3.16 MiB | 85.1 MiB |
| wordpress-gantry | off | Frontend JS heap | 65.64 MiB | 97.82 MiB | 93.37 MiB | 219.01 MiB | 233.59 MiB | 311.37 MiB |  |
| wordpress-gantry | off | Frontend DOM nodes | 5407 | 14736 | 13018 | 41763 | 45571 | 60605 |  |
| wordpress-gantry | tracing | PHP peak (script) | 4.56 MiB | 4.56 MiB | 4.56 MiB | 4.56 MiB | 4.56 MiB | 3.15 MiB | 33.3 MiB |
| wordpress-gantry | tracing | Frontend JS heap | 44.58 MiB | 97.79 MiB | 85.59 MiB | 130.51 MiB | 156.61 MiB | 207.04 MiB |  |
| wordpress-gantry | tracing | Frontend DOM nodes | 1947 | 13143 | 9486 | 21733 | 25541 | 38293 |  |
