<?php

declare(strict_types=1);

namespace Tests\Support;

use Testo\Core\Exception\SkipTest;

/**
 * A real, runnable project tree for exercising the composite actions end to end: it lays down a
 * composer.json plus local path-repository packages, then invokes the shipped bash entry points
 * (downgrade/install.sh, downgrade-rector/downgrade.sh) exactly as GitHub Actions would — same env
 * vars, same working directory. Composer and Rector run for real.
 *
 * Local packages are wired through path repositories with Packagist disabled, so the resolve is
 * deterministic and offline; only the throwaway rector/rector install reaches the network.
 *
 * The scripts need bash, composer and php on the same OS as this runner. On Windows the spawned
 * bash is a foreign OS (WSL), so {@see create()} skips the test rather than fighting path
 * translation; the suite runs for real on Linux (CI, or local WSL).
 */
final class AcceptanceProject
{
    public readonly string $root;
    private readonly string $repoRoot;

    private function __construct(string $root, string $repoRoot)
    {
        $this->root = $root;
        $this->repoRoot = $repoRoot;
    }

    public static function create(): self
    {
        \PHP_OS_FAMILY === 'Windows' and throw new SkipTest('Acceptance scripts need a same-OS bash; skipped on Windows.');
        self::tool('bash') or throw new SkipTest('bash not found.');
        self::tool('composer') or throw new SkipTest('composer not found.');

        $root = \sys_get_temp_dir() . '/downgrade-acceptance-' . \bin2hex(\random_bytes(6));
        \mkdir($root, 0o777, true);

        return new self($root, \dirname(__DIR__, 2));
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . \ltrim($relative, '/');
    }

    public function writeComposer(array $data): void
    {
        $this->writeFile('composer.json', (string) \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Register a local package as a path repository and lay down its sources under packages/<slug>.
     * Packagist stays disabled, so the resolve only ever sees these local packages.
     *
     * @param array<string, string> $files relative path within the package => contents
     */
    public function addPathPackage(string $name, array $manifest, array $files = []): void
    {
        $slug = \str_replace('/', '-', $name);
        $manifest['name'] = $name;
        $this->writeFile("packages/{$slug}/composer.json", (string) \json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        foreach ($files as $relative => $contents) {
            $this->writeFile("packages/{$slug}/{$relative}", $contents);
        }
    }

    public function writeFile(string $relative, string $contents): void
    {
        $path = $this->path($relative);
        $dir = \dirname($path);
        \is_dir($dir) or \mkdir($dir, 0o777, true);
        \file_put_contents($path, $contents);
    }

    /**
     * Run downgrade/install.sh against this project.
     *
     * @param array{paths?: string, deps?: string, skip?: string} $options
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function runInstall(string $target, array $options = []): array
    {
        return $this->runScript('downgrade/install.sh', [
            'INPUT_PHP_VERSION' => $target,
            'INPUT_PATHS' => $options['paths'] ?? '',
            'INPUT_DEPENDENCY_VERSIONS' => $options['deps'] ?? 'highest',
            'INPUT_SKIP' => $options['skip'] ?? '',
            'GITHUB_ACTION_PATH' => $this->repoRoot . '/downgrade',
        ]);
    }

    /**
     * Run downgrade-rector/downgrade.sh against this project.
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function runRector(string $paths, string $target, string $skip = ''): array
    {
        return $this->runScript('downgrade-rector/downgrade.sh', [
            'INPUT_PATHS' => $paths,
            'INPUT_PHP_VERSION' => $target,
            'INPUT_SKIP' => $skip,
            'GITHUB_ACTION_PATH' => $this->repoRoot . '/downgrade-rector',
        ]);
    }

    public function exists(string $relative): bool
    {
        return \file_exists($this->path($relative));
    }

    public function read(string $relative): string
    {
        return (string) \file_get_contents($this->path($relative));
    }

    public function readJson(string $relative): array
    {
        return (array) \json_decode($this->read($relative), true);
    }

    public function destroy(): void
    {
        \is_dir($this->root) and $this->runScript('', [], "rm -rf " . \escapeshellarg($this->root));
    }

    private static function tool(string $name): bool
    {
        $process = \proc_open(
            ['bash', '-lc', 'command -v ' . \escapeshellarg($name)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            return false;
        }
        \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return \proc_close($process) === 0;
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runScript(string $script, array $env, ?string $rawCommand = null): array
    {
        $command = $rawCommand ?? 'bash ' . \escapeshellarg($this->repoRoot . '/' . $script);
        $process = \proc_open(
            ['bash', '-lc', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            [...\getenv(), ...$env, 'GITHUB_WORKSPACE' => $this->root],
        );
        \is_resource($process) or throw new \RuntimeException('Failed to start bash.');

        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return ['exit' => \proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
