<?php

declare(strict_types=1);

use Phramark\VersionSpec;

require_once '/opt/phramark/src/VersionSpec.php';

// Resolves the requested ref of a versionable product (Phramark\VersionSpec)
// against what is published: the tag of that name when it exists, otherwise
// the branch of that name; "latest" is the newest stable release. Runs in
// the setup containers, which have git and Composer.
//
//   php resolve-version.php PRODUCT [REF]      REF defaults to $<PRODUCT env>, then "latest"
//
// Prints one line the setup scripts consume:
//   tag 3.5.7                 a published tag (git clone --branch, a release archive, wp core download)
//   branch 3.5.x 851c705abcde a branch and its head commit (a moved branch is a new version)
//   composer 11.x-dev         the Composer version to require
//   image 8.4                 the PHP image tag
//   path /host/evo/aLatteX 1a2b3c… [0.5.0]   a directory on the host (the repository's parent is
//                             mounted at /host, PHRAMARK_DIR names the checkout) and a fingerprint
//                             of its files, so an edited working copy is reinstalled; for a Composer
//                             package also the newest release it stands in for (an inline alias)
// The same line is what the site's .phramark-versions marker records, so a
// setup run reinstalls a site only when this line changes.

/** @return array{tags: list<string>, heads: array<string, string>} */
function remoteRefs(string $repo): array
{
    $tags = [];
    $heads = [];
    foreach (explode("\n", trim((string) shell_exec('git ls-remote --tags --heads --refs ' . escapeshellarg($repo) . ' 2>/dev/null'))) as $line) {
        if (preg_match('#^([0-9a-f]+)\s+refs/(tags|heads)/(.+)$#', trim($line), $m) !== 1) {
            continue;
        }
        if ($m[2] === 'tags') {
            $tags[] = $m[3];
        } else {
            $heads[$m[3]] = $m[1];
        }
    }
    if ($tags === [] && $heads === []) {
        throw new RuntimeException('git ls-remote returned nothing for ' . $repo);
    }

    return ['tags' => $tags, 'heads' => $heads];
}

/**
 * A directory on the host as the setup containers see it (compose mounts the
 * repository's parent at /host; the checkout is /host/$PHRAMARK_DIR).
 */
function hostPath(string $ref): string
{
    $root = '/host/' . (getenv('PHRAMARK_DIR') ?: 'phramark');
    if (!is_dir($root)) {
        throw new RuntimeException(sprintf('%s is not mounted: set PHRAMARK_DIR to the name of this checkout (its parent directory is mounted at /host).', $root));
    }
    $path = realpath($root . '/' . $ref);
    if ($path === false || !is_dir($path)) {
        throw new RuntimeException(sprintf('"%s" is not a directory (looked for %s).', $ref, $root . '/' . $ref));
    }
    if (!str_starts_with($path . '/', '/host/')) {
        throw new RuntimeException(sprintf('"%s" leaves the mounted /host tree.', $ref));
    }

    return $path;
}

/**
 * The files of a working copy that belong to the project: what git tracks
 * plus untracked files that are not ignored (relative paths), or null when
 * the directory is not a git checkout. A checkout also carries what its
 * .gitignore hides (a site's own config, logs, IDE state); installing from
 * it must leave those behind, or the site runs with the developer's setup.
 *
 * @return list<string>|null
 */
function gitFiles(string $path): ?array
{
    if (!file_exists($path . '/.git')) {
        return null;
    }
    // The host tree belongs to another uid: git refuses it without safe.directory.
    $process = proc_open(['git', '-c', 'safe.directory=*', '-C', $path, 'ls-files', '-z', '-co', '--exclude-standard'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return null;
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        return null;
    }

    return array_values(array_filter(explode("\0", $output), 'strlen'));
}

/**
 * A fingerprint of a directory's files (paths, sizes, mtimes; vendor,
 * node_modules and .git skipped): of the project's files in a git checkout
 * (gitFiles), of every file elsewhere.
 */
function directoryFingerprint(string $path): string
{
    $hash = hash_init('sha256');
    $entries = [];
    $files = gitFiles($path);
    if ($files !== null) {
        foreach ($files as $file) {
            if (preg_match('#(^|/)(\.git|vendor|node_modules)/#', $file) !== 1 && is_file($path . '/' . $file)) {
                $entries[] = '/' . $file . ' ' . filesize($path . '/' . $file) . ' ' . filemtime($path . '/' . $file);
            }
        }
    } else {
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => !in_array($file->getFilename(), ['.git', 'vendor', 'node_modules'], true),
        ));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $entries[] = substr($file->getPathname(), strlen($path)) . ' ' . $file->getSize() . ' ' . $file->getMTime();
            }
        }
    }
    sort($entries);
    hash_update($hash, implode("\n", $entries));

    return substr(hash_final($hash), 0, 16);
}

/** @return list<string> */
function composerVersions(string $package): array
{
    $json = json_decode((string) shell_exec('composer show -a ' . escapeshellarg($package) . ' --format=json --no-interaction 2>/dev/null'), true);
    if (!is_array($json) || !isset($json['versions'])) {
        throw new RuntimeException('Composer lists no versions for ' . $package);
    }

    return array_values($json['versions']);
}

function resolveVersion(string $product, ?string $ref = null): string
{
    $products = VersionSpec::products();
    $id = VersionSpec::product($product) ?? throw new InvalidArgumentException('Unknown product ' . $product);
    $definition = $products[$id];
    // The PHP image is a build argument of the host (the official image sets
    // PHP_VERSION itself, so the container's environment does not count).
    if ($definition['kind'] === 'image') {
        return 'image ' . ($ref === null || $ref === VersionSpec::LATEST ? $definition['default'] : $ref);
    }
    $ref = $ref ?? (getenv($definition['env']) ?: null) ?? VersionSpec::LATEST;
    if (VersionSpec::isPath($ref)) {
        if (!VersionSpec::acceptsPath($id)) {
            throw new RuntimeException(sprintf('%s cannot be installed from a directory.', $id));
        }
        $path = hostPath($ref);
        $line = 'path ' . $path . ' ' . directoryFingerprint($path);
        if ($definition['kind'] === 'composer' && isset($definition['package'])) {
            // The newest release, which the working copy stands in for: other
            // packages' constraints on it (aPhalcon needs aLatteX ^0.5) are
            // met through an inline alias to that version.
            $line .= ' ' . VersionSpec::resolveComposer(VersionSpec::LATEST, composerVersions($definition['package']));
        }

        return $line;
    }
    switch ($definition['kind']) {
        case 'composer':
            return 'composer ' . VersionSpec::resolveComposer($ref, composerVersions($definition['package']));
        case 'wp-plugin':
            // wordpress.org releases are not tagged on GitHub: a version number
            // is taken as a release ("latest" included, WP-CLI resolves it), any
            // other name as a branch of the plugin's repository.
            if ($ref === VersionSpec::LATEST || preg_match('/^v?\d+(\.\d+)*$/', $ref) === 1) {
                return 'tag ' . ltrim($ref, 'v');
            }
            $refs = remoteRefs($definition['repo']);
            $resolved = VersionSpec::resolveGit($ref, [], $refs['heads']);

            return 'branch ' . $resolved['name'] . ' ' . $resolved['commit'];
        default:
            $refs = remoteRefs($definition['repo']);
            $resolved = VersionSpec::resolveGit($ref, $refs['tags'], $refs['heads']);

            return $resolved['kind'] === 'tag' ? 'tag ' . $resolved['name'] : 'branch ' . $resolved['name'] . ' ' . $resolved['commit'];
    }
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        echo resolveVersion($argv[1] ?? throw new InvalidArgumentException('Usage: resolve-version.php PRODUCT [REF]'), $argv[2] ?? null), "\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, ($argv[1] ?? 'version') . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}
