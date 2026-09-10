<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A throwaway project tree for exercising downgrade/composer-helper.php as a black box: it builds
 * the root composer.json, a vendor/composer/installed.json, and on-disk package sources, then runs
 * the helper in a subprocess against them. The helper is the real shipped script, invoked exactly
 * as install.sh invokes it, so these tests cover its CLI contract (argv, exit codes, file edits).
 */
final class Workspace
{
    public readonly string $root;

    private function __construct(string $root)
    {
        $this->root = $root;
    }

    public static function create(): self
    {
        $root = \sys_get_temp_dir() . '/downgrade-helper-' . \bin2hex(\random_bytes(6));
        \mkdir($root, 0o777, true);

        return new self($root);
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . \ltrim($relative, '/');
    }

    /**
     * Write the root composer.json.
     */
    public function writeComposer(array $data): void
    {
        $this->writeJson('composer.json', $data);
    }

    /**
     * Write vendor/composer/installed.json. Composer 2 wraps the list in a "packages" key;
     * pass composer2: false to emit the bare Composer 1 list instead.
     */
    public function writeInstalled(array $packages, bool $composer2 = true): void
    {
        $this->writeJson(
            'vendor/composer/installed.json',
            $composer2 ? ['packages' => $packages] : $packages,
        );
    }

    /**
     * Lay down an installed package's sources under vendor/<name>/, always seeding a nested
     * vendor/ and a .git/ so a copy can be checked for skipping them. Extra files map a
     * relative path to its contents.
     */
    public function installPackage(string $name, array $manifest, array $files = []): void
    {
        $dir = "vendor/{$name}";
        $this->writeFile("{$dir}/composer.json", (string) \json_encode($manifest));
        $this->writeFile("{$dir}/vendor/nested/skip.php", "<?php // must not be copied\n");
        $this->writeFile("{$dir}/.git/config", "[core]\n");

        foreach ($files as $relative => $contents) {
            $this->writeFile("{$dir}/{$relative}", $contents);
        }
    }

    /**
     * Run the shipped helper in a subprocess with this workspace as the working directory.
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function runHelper(string ...$args): array
    {
        $helper = \dirname(__DIR__, 2) . '/downgrade/composer-helper.php';
        $process = \proc_open(
            [\PHP_BINARY, $helper, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        \is_resource($process) or throw new \RuntimeException('Failed to start the helper process.');

        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return ['exit' => \proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function readJson(string $relative): array
    {
        return (array) \json_decode((string) \file_get_contents($this->path($relative)), true);
    }

    public function exists(string $relative): bool
    {
        return \file_exists($this->path($relative));
    }

    public function destroy(): void
    {
        if (!\is_dir($this->root)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($this->root);
    }

    private function writeJson(string $relative, array $data): void
    {
        $this->writeFile($relative, (string) \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    private function writeFile(string $relative, string $contents): void
    {
        $path = $this->path($relative);
        $dir = \dirname($path);
        \is_dir($dir) or \mkdir($dir, 0o777, true);
        \file_put_contents($path, $contents);
    }
}
