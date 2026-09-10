<?php

declare(strict_types=1);

namespace Tests\Unit;

use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Test;
use Tests\Support\Workspace;

#[Test]
final class ComposerHelperTest
{
    private Workspace $ws;

    #[AfterTest]
    public function cleanup(): void
    {
        isset($this->ws) and $this->ws->destroy();
    }

    public function loosenRootLowersPhpRequirement(): void
    {
        $this->ws = Workspace::create();
        $this->ws->writeComposer(['require' => ['php' => '>=8.2', 'acme/lib' => '^1.0']]);

        $result = $this->ws->runHelper('loosen-root', 'composer.json', '8.1');

        Assert::same($result['exit'], 0);
        Assert::same($this->ws->readJson('composer.json')['require']['php'], '>=8.1');
    }

    public function loosenRootIsNoopWithoutPhpRequirement(): void
    {
        $this->ws = Workspace::create();
        $this->ws->writeComposer(['require' => ['acme/lib' => '^1.0']]);
        $before = \file_get_contents($this->ws->path('composer.json'));

        $result = $this->ws->runHelper('loosen-root', 'composer.json', '8.1');

        Assert::same($result['exit'], 0);
        Assert::same(\file_get_contents($this->ws->path('composer.json')), $before);
    }

    public function relievePlainPackageCopiesSourcesAndPatchesManifest(): void
    {
        $this->ws = Workspace::create();
        $this->ws->writeComposer(['require' => ['acme/lib' => '^1.0']]);
        $this->ws->installPackage(
            'acme/lib',
            ['name' => 'acme/lib', 'require' => ['php' => '>=8.2']],
            ['src/Widget.php' => "<?php\n"],
        );
        $this->ws->writeInstalled([[
            'name' => 'acme/lib',
            'version' => '1.5.0',
            'type' => 'library',
            'install-path' => '../acme/lib',
            'require' => ['php' => '>=8.2'],
        ]]);

        $result = $this->ws->runHelper('relieve', 'acme/lib', '.php-downgrade/acme/lib', '8.1', 'composer.json');

        Assert::same($result['exit'], 0);
        $manifest = $this->ws->readJson('.php-downgrade/acme/lib/composer.json');
        Assert::same($manifest['require']['php'], '>=8.1');
        Assert::same($manifest['version'], '1.5.0');
        Assert::true($this->ws->exists('.php-downgrade/acme/lib/src/Widget.php'));
        Assert::false($this->ws->exists('.php-downgrade/acme/lib/vendor'));
        Assert::false($this->ws->exists('.php-downgrade/acme/lib/.git'));

        $repositories = $this->ws->readJson('composer.json')['repositories'];
        Assert::same($repositories[0]['type'], 'path');
        Assert::same($repositories[0]['url'], '.php-downgrade/acme/lib');
        Assert::true($repositories[0]['options']['symlink']);
    }

    public function relieveMetapackageSynthesizesManifestWithoutCopying(): void
    {
        $this->ws = Workspace::create();
        $this->ws->writeComposer(['require' => ['acme/meta' => '^1.0']]);
        $this->ws->writeInstalled([[
            'name' => 'acme/meta',
            'version' => '2.0.0',
            'type' => 'metapackage',
            'install-path' => null,
            'require' => ['php' => '>=8.2', 'acme/lib' => '^1.0'],
        ]]);

        $result = $this->ws->runHelper('relieve', 'acme/meta', '.php-downgrade/acme/meta', '8.1', 'composer.json');

        Assert::same($result['exit'], 0);
        $manifest = $this->ws->readJson('.php-downgrade/acme/meta/composer.json');
        Assert::same($manifest['type'], 'metapackage');
        Assert::same($manifest['version'], '2.0.0');
        Assert::same($manifest['require']['php'], '>=8.1');
        Assert::same($manifest['require']['acme/lib'], '^1.0');
    }

    public function relieveFindsPackageInBareComposer1InstalledList(): void
    {
        $this->ws = Workspace::create();
        $this->ws->writeComposer(['require' => ['acme/lib' => '^1.0']]);
        $this->ws->installPackage('acme/lib', ['name' => 'acme/lib']);
        $this->ws->writeInstalled([[
            'name' => 'acme/lib',
            'version' => '1.5.0',
            'type' => 'library',
            'install-path' => '../acme/lib',
        ]], composer2: false);

        $result = $this->ws->runHelper('relieve', 'acme/lib', '.php-downgrade/acme/lib', '8.1', 'composer.json');

        Assert::same($result['exit'], 0);
        Assert::same($this->ws->readJson('.php-downgrade/acme/lib/composer.json')['version'], '1.5.0');
    }

    public function relieveRegistersKeyedRepositoryAndDeduplicates(): void
    {
        $this->ws = Workspace::create();
        $this->ws->writeComposer([
            'require' => ['acme/lib' => '^1.0'],
            'repositories' => ['private' => ['type' => 'composer', 'url' => 'https://example.com']],
        ]);
        $this->ws->installPackage('acme/lib', ['name' => 'acme/lib']);
        $this->ws->writeInstalled([[
            'name' => 'acme/lib',
            'version' => '1.5.0',
            'type' => 'library',
            'install-path' => '../acme/lib',
        ]]);

        $this->ws->runHelper('relieve', 'acme/lib', '.php-downgrade/acme/lib', '8.1', 'composer.json');
        $this->ws->runHelper('relieve', 'acme/lib', '.php-downgrade/acme/lib', '8.1', 'composer.json');

        $repositories = $this->ws->readJson('composer.json')['repositories'];
        Assert::true(isset($repositories['private']));
        $forDest = \array_filter($repositories, static fn(array $r): bool => ($r['url'] ?? null) === '.php-downgrade/acme/lib');
        Assert::count($forDest, 1);
    }

    public function relieveFailsOnUnknownPackage(): void
    {
        $this->ws = Workspace::create();
        $this->ws->writeComposer(['require' => []]);
        $this->ws->writeInstalled([]);

        $result = $this->ws->runHelper('relieve', 'acme/absent', '.php-downgrade/acme/absent', '8.1', 'composer.json');

        Assert::same($result['exit'], 1);
        Assert::string($result['stderr'])->contains('not present in installed.json');
    }
}
