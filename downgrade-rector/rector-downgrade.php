<?php

declare(strict_types=1);

use PhpInternal\Actions\Downgrade\DowngradeInput;
use Rector\Config\RectorConfig;

require __DIR__ . '/DowngradeInput.php';

/**
 * Rector config for the `downgrade-rector` action. Driven by three environment variables the action
 * sets: DOWNGRADE_PATHS and DOWNGRADE_SKIP (whitespace- or newline-separated, workspace-relative)
 * and DOWNGRADE_PHP_VERSION. The input parsing lives in {@see DowngradeInput}; this file only wires
 * the parsed values into Rector.
 *
 * It applies Rector's full downgrade set down to the requested version, so every construct
 * newer than the target — not just `readonly class` — is rewritten to a compatible form.
 */

$workspace = getenv('GITHUB_WORKSPACE') ?: getcwd();

$paths = DowngradeInput::resolvePaths((string) getenv('DOWNGRADE_PATHS'), $workspace);
$version = DowngradeInput::resolveVersion((string) getenv('DOWNGRADE_PHP_VERSION'));
$skip = DowngradeInput::resolveSkip((string) getenv('DOWNGRADE_SKIP'), $workspace);

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
