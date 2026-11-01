<?php

// Always-on PHP-side probe, loaded through auto_prepend_file in every PHP-FPM
// and FrankenPHP stack (identical cost for all of them). At shutdown it appends one JSON line
// per request to /var/log/phramark/memory.log: peak memory, wall time, the
// time spent hashing passwords, and the X-Phramark-Step header a workload may
// send to attribute the request to one of its steps. The benchmark scripts
// truncate the log before a run and summarise it afterwards.
//
// Password hashing is timed so the admin report can take it out of the login
// step: every CMS picks its own algorithm and cost (Argon2i on TYPO3, bcrypt
// at cost 10 or 12 elsewhere), a deliberate slowness that says nothing about
// the framework. PHP cannot wrap an internal function, but an unqualified call
// in namespaced code looks up Namespace\password_verify before the global one,
// so the functions below, declared in the namespace of each CMS's hasher, time
// the call and forward it. They are plain functions of this file, which
// OPcache already holds: no class, no autoloader lookup, nothing for the
// optimized composer class maps. WordPress hashes in the global namespace; its
// mu-plugin marks the check with Phramark\hashing_started() and
// Phramark\hashing_finished() instead.

namespace Phramark {
    // Milliseconds this request has spent hashing passwords, after adding $ms.
    function hashing_ms(float $ms = 0.0): float
    {
        static $total = 0.0;

        return $total += $ms;
    }

    // Calls the global hashing function $name and adds its time.
    function timed_hash(string $name, #[\SensitiveParameter] array $args): mixed
    {
        $started = \hrtime(true);
        try {
            return $name(...$args);
        } finally {
            hashing_ms((\hrtime(true) - $started) / 1e6);
        }
    }

    // The same measurement for code that cannot be intercepted: a mark before
    // the check and one after it (a finish without a start adds nothing).
    function hashing_mark(?int $now): ?int
    {
        static $started = null;
        $previous = $started;
        $started = $now;

        return $previous;
    }

    function hashing_started(): void
    {
        hashing_mark(\hrtime(true));
    }

    function hashing_finished(): void
    {
        $started = hashing_mark(null);
        if ($started !== null) {
            hashing_ms((\hrtime(true) - $started) / 1e6);
        }
    }

    /**
     * The request fields of a log line: read at shutdown in classic PHP, and
     * when the request starts in a worker, which may put its own values into
     * $_SERVER while it serves the request.
     *
     * @return array{step: ?string, method: string, uri: string, started: ?float}
     */
    function request_fields(): array
    {
        return [
            'step' => $_SERVER['HTTP_X_PHRAMARK_STEP'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'started' => isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null,
        ];
    }

    /** @param array{step: ?string, method: string, uri: string, started: ?float} $request */
    function log_request(array $request, float $hashMs): void
    {
        $line = \json_encode([
            't' => \round(\microtime(true), 3),
            'step' => $request['step'],
            'method' => $request['method'],
            'uri' => $request['uri'],
            'status' => \http_response_code(),
            'ms' => $request['started'] !== null ? \round((\microtime(true) - $request['started']) * 1000, 2) : null,
            // peak: bytes the script used; peak_real: bytes the allocator took from the OS.
            'peak' => \memory_get_peak_usage(false),
            'peak_real' => \memory_get_peak_usage(true),
            // Part of "ms" spent hashing passwords (see the top of this file).
            'hash_ms' => \round($hashMs, 2),
        ], JSON_UNESCAPED_SLASHES);
        // PHRAMARK_MEMORY_LOG: the tests' log; the stacks keep the default.
        @\file_put_contents(\getenv('PHRAMARK_MEMORY_LOG') ?: '/var/log/phramark/memory.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

// FrankenPHP worker mode (evo-worker stacks): this file runs once per worker,
// not per request, and a shutdown function only when the worker stops.
// Evolution's worker loop calls frankenphp_handle_request() unqualified from
// this namespace, so, as with the hashing functions below, the call reaches
// this function first: it logs every request it hands on, with the peak reset
// at its start (the peak then includes what the worker keeps between requests).
namespace EvolutionCMS\Runtime {
    function frankenphp_handle_request(callable $handler): bool
    {
        return \frankenphp_handle_request(static function () use ($handler): void {
            $request = \Phramark\request_fields();
            $request['started'] = \microtime(true);
            $hashing = \Phramark\hashing_ms();
            \memory_reset_peak_usage();
            try {
                $handler();
            } finally {
                \Phramark\log_request($request, \Phramark\hashing_ms() - $hashing);
            }
        });
    }
}

// Drupal: Drupal\Core\Password\PhpPassword.
namespace Drupal\Core\Password {
    function password_hash(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('password_hash', $args); }
    function password_verify(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('password_verify', $args); }
}

// TYPO3: the Argon2 and bcrypt hashers; crypt() verifies its legacy formats.
namespace TYPO3\CMS\Core\Crypto\PasswordHashing {
    function password_hash(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('password_hash', $args); }
    function password_verify(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('password_verify', $args); }
    function crypt(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('crypt', $args); }
}

// Winter: the Laravel hashers behind Hash::check().
namespace Illuminate\Hashing {
    function password_hash(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('password_hash', $args); }
    function password_verify(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('password_verify', $args); }
}

// MODX: MODX\Revolution\Hashing\modNative, the users' hash_class.
namespace MODX\Revolution\Hashing {
    function password_hash(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('password_hash', $args); }
    function password_verify(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('password_verify', $args); }
}

// Evolution: EvolutionCMS\Legacy\PasswordHash; crypt() verifies its legacy formats.
namespace EvolutionCMS\Legacy {
    function password_hash(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('password_hash', $args); }
    function password_verify(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('password_verify', $args); }
    function crypt(#[\SensitiveParameter] ...$args) { return \Phramark\timed_hash('crypt', $args); }
}

namespace {
    // A worker script logs per request through the wrapper above.
    if (PHP_SAPI === 'cli' || !empty($_SERVER['FRANKENPHP_WORKER'])) {
        return;
    }

    register_shutdown_function(static function (): void {
        \Phramark\log_request(\Phramark\request_fields(), \Phramark\hashing_ms());
    });
}
