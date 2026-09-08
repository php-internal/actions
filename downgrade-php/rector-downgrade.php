<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * Rector config for the `downgrade-php` action. Driven by two environment variables the
 * action sets: DOWNGRADE_PATHS (space-separated, workspace-relative) and DOWNGRADE_PHP_VERSION.
 *
 * It applies Rector's full downgrade set down to the requested version, so every construct
 * newer than the target — not just `readonly class` — is rewritten to a compatible form.
 */

$workspace = getenv('GITHUB_WORKSPACE') ?: getcwd();

$paths = [];
foreach (preg_split('/\s+/', trim((string) getenv('DOWNGRADE_PATHS'))) ?: [] as $path) {
    $path === '' or $paths[] = $workspace . '/' . ltrim($path, '/');
}
$paths === [] and throw new RuntimeException('DOWNGRADE_PATHS resolved to no paths.');

$version = trim((string) getenv('DOWNGRADE_PHP_VERSION')) ?: '8.1';
$supported = ['8.0', '8.1', '8.2', '8.3', '8.4'];
\in_array($version, $supported, true) or throw new RuntimeException(
    "Unsupported target PHP version '{$version}'. Expected one of: " . implode(', ', $supported) . '.',
);

// A glob pattern is passed to Rector verbatim; a plain path is resolved against the workspace.
$skip = [];
foreach (preg_split('/\s+/', trim((string) getenv('DOWNGRADE_SKIP'))) ?: [] as $entry) {
    if ($entry === '') {
        continue;
    }
    $skip[] = (str_contains($entry, '*') || str_starts_with($entry, '/'))
        ? $entry
        : $workspace . '/' . ltrim($entry, '/');
}

return RectorConfig::configure()
    ->withPaths($paths)
    ->withSkip($skip)
    ->withDowngradeSets(
        php84: $version === '8.4',
        php83: $version === '8.3',
        php82: $version === '8.2',
        php81: $version === '8.1',
        php80: $version === '8.0',
    );
