<?php

declare(strict_types=1);

namespace PhpInternal\Actions\Downgrade;

use RuntimeException;

/**
 * Parses the list/version inputs the `downgrade-rector` action feeds to Rector. Kept apart from
 * {@see \Rector\Config\RectorConfig} wiring in rector-downgrade.php so the parsing — list splitting,
 * version validation, workspace resolution — is pure and unit-testable without booting Rector.
 */
final class DowngradeInput
{
    /**
     * PHP versions Rector ships a downgrade set for.
     */
    public const SUPPORTED_VERSIONS = ['8.0', '8.1', '8.2', '8.3', '8.4'];

    /**
     * Split a list input. Written on one line it is whitespace-separated (the compact form); written
     * as a multiline block it is one entry per line, which lets an entry contain spaces. Entries are
     * trimmed and blanks dropped.
     *
     * @return list<string>
     */
    public static function splitList(string $raw): array
    {
        $parts = str_contains($raw, "\n")
            ? preg_split('/\R/', $raw)
            : preg_split('/\s+/', trim($raw));

        return array_values(array_filter(
            array_map('trim', $parts ?: []),
            static fn(string $p): bool => $p !== '',
        ));
    }

    /**
     * Validate the target version, falling back to the default when the input is blank.
     */
    public static function resolveVersion(string $raw, string $default = '8.1'): string
    {
        $version = trim($raw) ?: $default;
        in_array($version, self::SUPPORTED_VERSIONS, true) or throw new RuntimeException(
            "Unsupported target PHP version '{$version}'. Expected one of: "
            . implode(', ', self::SUPPORTED_VERSIONS) . '.',
        );

        return $version;
    }

    /**
     * Resolve the `paths` input to absolute workspace paths. Throws when it resolves to nothing —
     * Rector must be given at least one path to rewrite.
     *
     * @return list<string>
     */
    public static function resolvePaths(string $raw, string $workspace): array
    {
        $paths = [];
        foreach (self::splitList($raw) as $path) {
            $paths[] = $workspace . '/' . ltrim($path, '/');
        }
        $paths === [] and throw new RuntimeException('DOWNGRADE_PATHS resolved to no paths.');

        return $paths;
    }

    /**
     * Resolve the `skip` input. A glob pattern (contains `*`) or an already-absolute path is passed
     * to Rector verbatim; a plain relative path is resolved against the workspace.
     *
     * @return list<string>
     */
    public static function resolveSkip(string $raw, string $workspace): array
    {
        $skip = [];
        foreach (self::splitList($raw) as $entry) {
            $skip[] = (str_contains($entry, '*') || str_starts_with($entry, '/'))
                ? $entry
                : $workspace . '/' . ltrim($entry, '/');
        }

        return $skip;
    }
}
