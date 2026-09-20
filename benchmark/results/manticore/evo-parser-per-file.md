9687 files: 9257 compile (95.6%), 430 fail, 0 time out; 9686 verdicts from the result cache

| Area | Files | Compile | Share |
| --- | ---: | ---: | ---: |
| `.github/docker/ci/smoke.php` | 1 | 1 | 100% |
| `assets` | 62 | 51 | 82% |
| `core/bootstrap.php` | 1 | 1 | 100% |
| `core/config` | 14 | 14 | 100% |
| `core/database` | 14 | 14 | 100% |
| `core/factory` | 4 | 4 | 100% |
| `core/functions` | 16 | 12 | 75% |
| `core/includes` | 4 | 2 | 50% |
| `core/lang` | 66 | 66 | 100% |
| `core/modifiers` | 6 | 1 | 17% |
| `core/src` | 312 | 292 | 94% |
| `core/storage` | 2 | 1 | 50% |
| `core/tests` | 106 | 106 | 100% |
| `core/vendor (autoload.php)` | 1 | 1 | 100% |
| `core/vendor (blade-ui-kit)` | 14 | 14 | 100% |
| `core/vendor (brianium)` | 22 | 22 | 100% |
| `core/vendor (brick)` | 16 | 9 | 56% |
| `core/vendor (carbonphp)` | 7 | 7 | 100% |
| `core/vendor (composer)` | 360 | 307 | 85% |
| `core/vendor (dmitry-suffi)` | 3 | 3 | 100% |
| `core/vendor (doctrine)` | 487 | 486 | 100% |
| `core/vendor (dragonmantank)` | 10 | 10 | 100% |
| `core/vendor (egulias)` | 87 | 87 | 100% |
| `core/vendor (evolutioncms-services)` | 40 | 40 | 100% |
| `core/vendor (fidry)` | 31 | 30 | 97% |
| `core/vendor (filp)` | 33 | 21 | 64% |
| `core/vendor (fruitcake)` | 2 | 2 | 100% |
| `core/vendor (graham-campbell)` | 3 | 3 | 100% |
| `core/vendor (guzzlehttp)` | 97 | 96 | 99% |
| `core/vendor (hamcrest)` | 78 | 77 | 99% |
| `core/vendor (illuminate)` | 1100 | 1069 | 97% |
| `core/vendor (james-heinrich)` | 17 | 12 | 71% |
| `core/vendor (jean85)` | 6 | 6 | 100% |
| `core/vendor (justinrainbow)` | 157 | 156 | 99% |
| `core/vendor (laravel)` | 99 | 96 | 97% |
| `core/vendor (league)` | 65 | 65 | 100% |
| `core/vendor (marc-mabe)` | 5 | 2 | 40% |
| `core/vendor (mockery)` | 98 | 49 | 50% |
| `core/vendor (monolog)` | 125 | 124 | 99% |
| `core/vendor (myclabs)` | 27 | 26 | 96% |
| `core/vendor (nesbot)` | 923 | 915 | 99% |
| `core/vendor (nikic)` | 271 | 268 | 99% |
| `core/vendor (nunomaduro)` | 72 | 71 | 99% |
| `core/vendor (pestphp)` | 547 | 516 | 94% |
| `core/vendor (phar-io)` | 74 | 74 | 100% |
| `core/vendor (phpdocumentor)` | 160 | 160 | 100% |
| `core/vendor (phpmailer)` | 65 | 64 | 98% |
| `core/vendor (phpoption)` | 4 | 4 | 100% |
| `core/vendor (phpstan)` | 91 | 91 | 100% |
| `core/vendor (phpunit)` | 1138 | 1132 | 99% |
| `core/vendor (predis)` | 664 | 651 | 98% |
| `core/vendor (psr)` | 39 | 39 | 100% |
| `core/vendor (ralouphie)` | 1 | 1 | 100% |
| `core/vendor (ramsey)` | 144 | 143 | 99% |
| `core/vendor (react)` | 10 | 10 | 100% |
| `core/vendor (rosell-dk)` | 95 | 85 | 89% |
| `core/vendor (sebastian)` | 88 | 86 | 98% |
| `core/vendor (secondnetwork)` | 2 | 2 | 100% |
| `core/vendor (seld)` | 10 | 10 | 100% |
| `core/vendor (simplepie)` | 83 | 46 | 55% |
| `core/vendor (staabm)` | 3 | 3 | 100% |
| `core/vendor (symfony)` | 940 | 921 | 98% |
| `core/vendor (ta-tikoma)` | 26 | 26 | 100% |
| `core/vendor (theseer)` | 10 | 10 | 100% |
| `core/vendor (tracy)` | 44 | 39 | 89% |
| `core/vendor (vlucas)` | 41 | 41 | 100% |
| `core/vendor (voku)` | 194 | 194 | 100% |
| `core/vendor (webmozart)` | 5 | 5 | 100% |
| `core/vendor (wikimedia)` | 8 | 8 | 100% |
| `index.php` | 1 | 0 | 0% |
| `manager` | 336 | 257 | 76% |

| Diagnostic (normalised) | Files |
| --- | ---: |
| `compile failed: MIR.verify: dangling local $var read in <fn> but never defined` | 162 |
| `compile failed: MIR.lower: unsupported statement kind Class at line N` | 42 |
| `parse failed: expected ';' after echo at line N` | 36 |
| `compile failed: lazy body <file>` | 23 |
| `timeout: the monitored command dumped core` | 18 |
| `compile failed: MIR.lower: unsupported assign target kind StaticAccess at <file>` | 11 |
| `compile failed (emit): unsupported: cannot take a storable reference to this expression — it has no address, so the reference would be a copy and every write through it would be lost (:N)` | 5 |
| `parse failed: expected ';' after expression at line N` | 4 |
| `parse failed: unexpected token in expression: Semicolon at line N` | 3 |
| `parse failed: expected class name at line N` | 3 |
| `compile failed: MIR.lower: $var as a whole array is unsupported (it would need a runtime name table); use $var['name']` | 3 |
| `compile failed (emit): unsupported: a reference into an element of a array-elemented array (`$var[$var] = &$var`) — the element channel is raw and a reference cell is not. See docs/design/reference-cells.md. (:N)` | 2 |
| `compile failed: MIR.lower: $var needs a literal string key (a dynamic one would need a runtime name table)` | 2 |
| `parse failed: unexpected token in expression: Less at line N` | 2 |
| `compile failed: Brick\Math\BigDecimal::negated() has #[\Override] attribute, but no matching parent method exists at line N` | 1 |
