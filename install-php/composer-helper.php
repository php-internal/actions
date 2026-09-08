<?php

declare(strict_types=1);

/**
 * JSON surgery for the `install-php` action. Kept out of the shell because loosening a
 * `require.php` constraint, pinning a path package's version and prepending a path repository
 * are all `composer.json` edits that jq-in-bash makes fragile.
 *
 * Runs on the runner's (target) PHP, so it stays plain 8.0-compatible code — no `readonly`,
 * no enums, no 8.1+ syntax. It never boots the project autoloader; it only reads and rewrites
 * JSON files by hand.
 *
 * Commands:
 *   loosen-root <composer.json> <target>          Lower the root package's own `require.php` to `>=target`.
 *   relieve     <pkg> <dest> <target> <composer.json>
 *                                                 Copy an installed package to <dest>, patch its
 *                                                 composer.json (php => >=target, pin version) and
 *                                                 register <dest> as a path repository in the root.
 */

$command = $argv[1] ?? '';

switch ($command) {
    case 'loosen-root':
        loosen_root($argv[2], $argv[3]);
        break;
    case 'relieve':
        relieve($argv[2], $argv[3], $argv[4], $argv[5]);
        break;
    default:
        fwrite(STDERR, "Unknown command '{$command}'.\n");
        exit(1);
}

/**
 * Read a JSON file into an associative array.
 */
function read_json(string $path): array
{
    $raw = @file_get_contents($path);
    $raw === false and fail("Cannot read {$path}.");
    $data = json_decode($raw, true);
    is_array($data) or fail("{$path} is not a JSON object.");

    return $data;
}

/**
 * Write an associative array back as pretty JSON, matching Composer's own formatting closely
 * enough that the file stays readable in logs.
 */
function write_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $json === false and fail("Cannot encode {$path}.");
    file_put_contents($path, $json . "\n");
}

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Lower the root package's own `php` requirement so Composer stops rejecting the whole install
 * on the root gate. A no-op when the root already allows the target or declares no php floor.
 */
function loosen_root(string $composerJson, string $target): void
{
    $data = read_json($composerJson);
    if (!isset($data['require']['php'])) {
        return;
    }

    $data['require']['php'] = '>=' . $target;
    write_json($composerJson, $data);
    fwrite(STDERR, "Loosened root require.php to >={$target}.\n");
}

/**
 * Copy one installed package out of `vendor/`, patch its manifest so it advertises target-PHP
 * compatibility, and prepend a path repository pointing at the copy. Composer then has to pick
 * this copy over the Packagist original: the original's untouched `require.php` is excluded by
 * the pinned platform, this copy's loosened one is not.
 */
function relieve(string $pkg, string $dest, string $target, string $composerJson): void
{
    $root = dirname(realpath($composerJson) ?: $composerJson);
    $info = installed_package($root, $pkg);
    $absoluteDest = $root . '/' . ltrim($dest, '/');

    // A metapackage ships no files (and has no install-path), so there is nothing to copy or
    // downgrade; synthesize a manifest carrying its requirements so a path repository can stand in
    // for it. Every other package is copied out of vendor/ and its own manifest patched.
    if (($info['type'] ?? '') === 'metapackage' || ($info['install-path'] ?? null) === null) {
        is_dir($absoluteDest) or mkdir($absoluteDest, 0o777, true);
        $manifest = ['name' => $pkg, 'type' => 'metapackage'];
        if (isset($info['require']) && is_array($info['require'])) {
            $manifest['require'] = $info['require'];
        }
    } else {
        $source = realpath($root . '/vendor/composer/' . $info['install-path']);
        $source === false and fail("Cannot locate the installed sources of {$pkg}.");
        copy_tree($source, $absoluteDest);
        $manifest = read_json($absoluteDest . '/composer.json');
    }

    // Pin the exact installed version so the path repository resolves to a concrete version, and
    // loosen the php floor so the pinned platform accepts it.
    $manifest['version'] = (string) ($info['version'] ?? '0.0.0');
    if (isset($manifest['require']['php'])) {
        $manifest['require']['php'] = '>=' . $target;
    }
    write_json($absoluteDest . '/composer.json', $manifest);

    add_path_repository($composerJson, $dest);
    fwrite(STDERR, "Relieved {$pkg} ({$manifest['version']}) into {$dest}.\n");
}

/**
 * Find a package entry in `vendor/composer/installed.json`.
 */
function installed_package(string $root, string $pkg): array
{
    $data = read_json($root . '/vendor/composer/installed.json');
    // Composer 2 wraps the list in a "packages" key; Composer 1 stored a bare list.
    $packages = $data['packages'] ?? $data;
    foreach ($packages as $entry) {
        if (($entry['name'] ?? '') === $pkg) {
            return $entry;
        }
    }

    fail("Package {$pkg} is not present in installed.json.");
}

/**
 * Prepend a `path` repository to the root manifest, giving the copied package priority and
 * skipping the entry when it is already registered. Handles both the list and the keyed-object
 * form Composer accepts for `repositories`.
 */
function add_path_repository(string $composerJson, string $url): void
{
    $data = read_json($composerJson);
    $entry = ['type' => 'path', 'url' => $url, 'options' => ['symlink' => true]];
    $existing = $data['repositories'] ?? [];

    foreach ($existing as $repo) {
        if (is_array($repo) && ($repo['url'] ?? null) === $url) {
            return;
        }
    }

    $isList = $existing === [] || array_keys($existing) === range(0, count($existing) - 1);
    if ($isList) {
        array_unshift($existing, $entry);
    } else {
        $key = 'php-downgrade-' . str_replace('/', '-', trim($url, './'));
        $existing = array_merge([$key => $entry], $existing);
    }

    $data['repositories'] = $existing;
    write_json($composerJson, $data);
}

/**
 * Recursively copy a directory tree, skipping any nested `vendor/` (dependencies are resolved by
 * the root install, not carried inside a path package) and VCS metadata.
 */
function copy_tree(string $source, string $dest): void
{
    is_dir($dest) or mkdir($dest, 0o777, true);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $top = explode(DIRECTORY_SEPARATOR, $relative)[0];
        if ($top === 'vendor' || $top === '.git') {
            continue;
        }

        $targetPath = $dest . '/' . $relative;
        if ($item->isDir()) {
            is_dir($targetPath) or mkdir($targetPath, 0o777, true);
        } else {
            copy($item->getPathname(), $targetPath);
        }
    }
}
