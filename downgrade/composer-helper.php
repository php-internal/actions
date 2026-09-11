<?php

declare(strict_types=1);

namespace PhpInternal\Actions\Downgrade;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use RuntimeException;
use SplFileInfo;

/**
 * JSON surgery for the `downgrade` action. Kept out of the shell because loosening a
 * `require.php` constraint, pinning a path package's version and prepending a path repository
 * are all `composer.json` edits that jq-in-bash makes fragile.
 *
 * Runs on the runner's (target) PHP, so it stays plain 8.0-compatible code — no `readonly`,
 * no enums, no 8.1+ syntax (octal literals are written `0777`, not `0o777`). It never boots the
 * project autoloader; it only reads and rewrites JSON files by hand.
 *
 * The file is split so it can be unit-tested without a subprocess: {@see ComposerHelper}'s pure
 * methods transform decoded JSON (`array -> array`) with no filesystem or process side effects,
 * the I/O and command methods wrap them, and the CLI shim at the very bottom runs only when the
 * file is executed directly — a `require` from a test defines the class without dispatching.
 *
 * Commands:
 *   loosen-root <composer.json> <target>          Lower the root package's own `require.php` to `>=target`.
 *   relieve     <pkg> <dest> <target> <composer.json>
 *                                                 Copy an installed package to <dest>, patch its
 *                                                 composer.json (php => >=target, pin version) and
 *                                                 register <dest> as a path repository in the root.
 */
final class ComposerHelper
{
    // --- Pure transforms: decoded JSON in, decoded JSON out; no filesystem, no exit. ---

    /**
     * Lower the root package's own `php` requirement so Composer stops rejecting the whole install
     * on the root gate. Returns the rewritten manifest, or null when there is nothing to do (the
     * root declares no php floor) so the caller can skip the write.
     */
    public static function loosenRoot(array $data, string $target): ?array
    {
        if (!isset($data['require']['php'])) {
            return null;
        }

        $data['require']['php'] = '>=' . $target;

        return $data;
    }

    /**
     * Find a package entry in a decoded `vendor/composer/installed.json`. Composer 2 wraps the list
     * in a "packages" key; Composer 1 stored a bare list. Returns null when the package is absent.
     */
    public static function findInstalled(array $installedJson, string $pkg): ?array
    {
        $packages = $installedJson['packages'] ?? $installedJson;
        foreach ($packages as $entry) {
            if (is_array($entry) && ($entry['name'] ?? '') === $pkg) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Synthesize a manifest for a metapackage, which ships no files to copy or downgrade: it carries
     * only the name, type and (if any) requirements, so a path repository can stand in for it.
     */
    public static function metapackageManifest(string $pkg, array $info): array
    {
        $manifest = ['name' => $pkg, 'type' => 'metapackage'];
        if (isset($info['require']) && is_array($info['require'])) {
            $manifest['require'] = $info['require'];
        }

        return $manifest;
    }

    /**
     * Pin the exact installed version so the path repository resolves to a concrete version, and
     * loosen the php floor so the pinned platform accepts the copy.
     */
    public static function patchManifest(array $manifest, array $info, string $target): array
    {
        $manifest['version'] = (string) ($info['version'] ?? '0.0.0');
        if (isset($manifest['require']['php'])) {
            $manifest['require']['php'] = '>=' . $target;
        }

        return $manifest;
    }

    /**
     * Prepend a `path` repository to the root manifest, giving the copied package priority and
     * skipping the entry when it is already registered. Handles both the list and the keyed-object
     * form Composer accepts for `repositories`. Returns the rewritten manifest (idempotent).
     */
    public static function addPathRepository(array $data, string $url): array
    {
        $entry = ['type' => 'path', 'url' => $url, 'options' => ['symlink' => true]];
        $existing = $data['repositories'] ?? [];

        foreach ($existing as $repo) {
            if (is_array($repo) && ($repo['url'] ?? null) === $url) {
                return $data;
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

        return $data;
    }

    // --- I/O wrappers: fail with a HelperError instead of exiting, so a caller can catch them. ---

    /**
     * Read a JSON file into an associative array.
     */
    public static function readJson(string $path): array
    {
        $raw = @file_get_contents($path);
        $raw === false and self::fail("Cannot read {$path}.");
        $data = json_decode($raw, true);
        is_array($data) or self::fail("{$path} is not a JSON object.");

        return $data;
    }

    /**
     * Write an associative array back as pretty JSON, matching Composer's own formatting closely
     * enough that the file stays readable in logs.
     */
    public static function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json === false and self::fail("Cannot encode {$path}.");
        file_put_contents($path, $json . "\n");
    }

    /**
     * Recursively copy a directory tree, skipping any nested `vendor/` (dependencies are resolved by
     * the root install, not carried inside a path package) and VCS metadata.
     */
    public static function copyTree(string $source, string $dest): void
    {
        is_dir($dest) or mkdir($dest, 0777, true);

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
                is_dir($targetPath) or mkdir($targetPath, 0777, true);
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    // --- Commands: wire the pure transforms to the filesystem and report progress on stderr. ---

    /**
     * Lower the root package's own `require.php` to `>=target`. A no-op when the root already
     * declares no php floor.
     */
    public static function commandLoosenRoot(string $composerJson, string $target): void
    {
        $loosened = self::loosenRoot(self::readJson($composerJson), $target);
        if ($loosened === null) {
            return;
        }

        self::writeJson($composerJson, $loosened);
        self::note("Loosened root require.php to >={$target}.");
    }

    /**
     * Copy one installed package out of `vendor/`, patch its manifest so it advertises target-PHP
     * compatibility, and prepend a path repository pointing at the copy. Composer then has to pick
     * this copy over the Packagist original: the original's untouched `require.php` is excluded by
     * the pinned platform, this copy's loosened one is not.
     */
    public static function commandRelieve(string $pkg, string $dest, string $target, string $composerJson): void
    {
        $root = dirname(realpath($composerJson) ?: $composerJson);
        $info = self::findInstalled(self::readJson($root . '/vendor/composer/installed.json'), $pkg)
            ?? self::fail("Package {$pkg} is not present in installed.json.");
        $absoluteDest = $root . '/' . ltrim($dest, '/');

        // A metapackage ships no files (and has no install-path), so there is nothing to copy or
        // downgrade; synthesize a manifest carrying its requirements. Every other package is copied
        // out of vendor/ and its own manifest patched.
        if (($info['type'] ?? '') === 'metapackage' || ($info['install-path'] ?? null) === null) {
            is_dir($absoluteDest) or mkdir($absoluteDest, 0777, true);
            $manifest = self::metapackageManifest($pkg, $info);
        } else {
            $source = realpath($root . '/vendor/composer/' . $info['install-path']);
            $source === false and self::fail("Cannot locate the installed sources of {$pkg}.");
            self::copyTree($source, $absoluteDest);
            $manifest = self::readJson($absoluteDest . '/composer.json');
        }

        $manifest = self::patchManifest($manifest, $info, $target);
        self::writeJson($absoluteDest . '/composer.json', $manifest);

        self::writeJson($composerJson, self::addPathRepository(self::readJson($composerJson), $dest));
        self::note("Relieved {$pkg} ({$manifest['version']}) into {$dest}.");
    }

    /**
     * Dispatch an argv vector to a command. Returns the process exit code; a {@see HelperError}
     * (a validation or I/O failure) or an unknown command becomes exit 1 with a message on stderr.
     */
    public static function dispatch(array $argv): int
    {
        $command = $argv[1] ?? '';

        try {
            switch ($command) {
                case 'loosen-root':
                    self::commandLoosenRoot($argv[2], $argv[3]);
                    break;
                case 'relieve':
                    self::commandRelieve($argv[2], $argv[3], $argv[4], $argv[5]);
                    break;
                default:
                    self::fail("Unknown command '{$command}'.");
            }
        } catch (HelperError $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    /**
     * @return never
     */
    private static function fail(string $message): void
    {
        throw new HelperError($message);
    }

    private static function note(string $message): void
    {
        fwrite(STDERR, $message . "\n");
    }
}

/**
 * A recoverable failure (bad input, missing file, absent package). Caught by {@see
 * ComposerHelper::dispatch()} and turned into a non-zero exit; in a test it surfaces as a normal
 * exception instead of killing the process.
 */
final class HelperError extends RuntimeException
{
}

// CLI shim: run only when this file is the executed script. A `require` from a test (where argv[0]
// is the test runner, not this file) defines the classes above without dispatching.
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === realpath(__FILE__)) {
    exit(ComposerHelper::dispatch($_SERVER['argv']));
}
