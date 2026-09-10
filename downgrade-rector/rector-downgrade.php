<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * Rector config for the `downgrade-rector` action. Driven by two environment variables the
 * action sets: DOWNGRADE_PATHS (space-separated, workspace-relative) and DOWNGRADE_PHP_VERSION.
 *
 * It applies Rector's full downgrade set down to the requested version, so every construct
 * newer than the target — not just `readonly class` — is rewritten to a compatible form.
 */

$workspace = getenv('GITHUB_WORKSPACE') ?: getcwd();

// Split a list input. Written on one line it is whitespace-separated (the compact form); written as
// a multiline block it is one entry per line, which lets an entry contain spaces. Entries are
// trimmed and blanks dropped.
$splitList = static function (string $raw): array {
    $parts = str_contains($raw, "\n")
        ? preg_split('/\R/', $raw)
        : preg_split('/\s+/', trim($raw));

    return array_values(array_filter(array_map('trim', $parts ?: []), static fn(string $p): bool => $p !== ''));
};

$paths = [];
foreach ($splitList((string) getenv('DOWNGRADE_PATHS')) as $path) {
    $paths[] = $workspace . '/' . ltrim($path, '/');
}
$paths === [] and throw new RuntimeException('DOWNGRADE_PATHS resolved to no paths.');

$version = trim((string) getenv('DOWNGRADE_PHP_VERSION')) ?: '8.1';
$supported = ['8.0', '8.1', '8.2', '8.3', '8.4'];
\in_array($version, $supported, true) or throw new RuntimeException(
    "Unsupported target PHP version '{$version}'. Expected one of: " . implode(', ', $supported) . '.',
);

// A glob pattern is passed to Rector verbatim; a plain path is resolved against the workspace.
$skip = [];
foreach ($splitList((string) getenv('DOWNGRADE_SKIP')) as $entry) {
    $skip[] = (str_contains($entry, '*') || str_starts_with($entry, '/'))
        ? $entry
        : $workspace . '/' . ltrim($entry, '/');
}

return RectorConfig::configure()
    ->withPaths($paths)
    ->withSkip($skip)
    // Parse the input as a recent PHP so the lexer accepts syntax newer than the target: we are
    // downgrading, so the sources on the way in use the newer syntax. Rector would otherwise
    // auto-detect the version from the project's composer.json, which the caller pins to the
    // target (require.php and config.platform.php), turning `readonly class` and the like into a
    // parse error before any downgrade rule can run.
    ->withPhpVersion(80_400)
    ->withDowngradeSets(
        php84: $version === '8.4',
        php83: $version === '8.3',
        php82: $version === '8.2',
        php81: $version === '8.1',
        php80: $version === '8.0',
    );
