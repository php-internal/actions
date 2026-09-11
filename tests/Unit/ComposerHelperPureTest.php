<?php

declare(strict_types=1);

namespace Tests\Unit;

use PhpInternal\Actions\Downgrade\ComposerHelper;
use Testo\Assert;
use Testo\Test;

require_once \dirname(__DIR__, 2) . '/downgrade/composer-helper.php';

/**
 * Unit coverage of the pure {@see ComposerHelper} transforms — decoded JSON in, decoded JSON out.
 * No filesystem and no subprocess: the CLI contract is covered separately by {@see ComposerHelperTest}.
 */
#[Test]
final class ComposerHelperPureTest
{
    public function loosenRootLowersPhpFloor(): void
    {
        $result = ComposerHelper::loosenRoot(['require' => ['php' => '>=8.2', 'acme/lib' => '^1.0']], '8.1');

        Assert::same($result['require']['php'], '>=8.1');
        Assert::same($result['require']['acme/lib'], '^1.0');
    }

    public function loosenRootReturnsNullWhenNoPhpFloor(): void
    {
        Assert::null(ComposerHelper::loosenRoot(['require' => ['acme/lib' => '^1.0']], '8.1'));
    }

    public function findInstalledUnwrapsComposer2Packages(): void
    {
        $installed = ['packages' => [['name' => 'acme/other'], ['name' => 'acme/lib', 'version' => '1.5.0']]];

        Assert::same(ComposerHelper::findInstalled($installed, 'acme/lib')['version'], '1.5.0');
    }

    public function findInstalledReadsBareComposer1List(): void
    {
        $installed = [['name' => 'acme/lib', 'version' => '1.5.0']];

        Assert::same(ComposerHelper::findInstalled($installed, 'acme/lib')['version'], '1.5.0');
    }

    public function findInstalledReturnsNullWhenAbsent(): void
    {
        Assert::null(ComposerHelper::findInstalled(['packages' => [['name' => 'acme/lib']]], 'acme/absent'));
    }

    public function metapackageManifestCarriesRequire(): void
    {
        $manifest = ComposerHelper::metapackageManifest('acme/meta', ['require' => ['acme/lib' => '^1.0']]);

        Assert::same($manifest, ['name' => 'acme/meta', 'type' => 'metapackage', 'require' => ['acme/lib' => '^1.0']]);
    }

    public function metapackageManifestOmitsAbsentRequire(): void
    {
        $manifest = ComposerHelper::metapackageManifest('acme/meta', []);

        Assert::same($manifest, ['name' => 'acme/meta', 'type' => 'metapackage']);
    }

    public function patchManifestPinsVersionAndLoosensPhp(): void
    {
        $manifest = ComposerHelper::patchManifest(
            ['name' => 'acme/lib', 'require' => ['php' => '>=8.2']],
            ['version' => '1.5.0'],
            '8.1',
        );

        Assert::same($manifest['version'], '1.5.0');
        Assert::same($manifest['require']['php'], '>=8.1');
    }

    public function patchManifestDefaultsMissingVersion(): void
    {
        Assert::same(ComposerHelper::patchManifest(['name' => 'acme/lib'], [], '8.1')['version'], '0.0.0');
    }

    public function patchManifestLeavesManifestWithoutPhpUntouched(): void
    {
        $manifest = ComposerHelper::patchManifest(['name' => 'acme/lib'], ['version' => '1.0.0'], '8.1');

        Assert::false(isset($manifest['require']));
    }

    public function addPathRepositoryPrependsToListForm(): void
    {
        $data = ComposerHelper::addPathRepository(['name' => 'acme/app'], '.php-downgrade/acme/lib');

        Assert::same($data['repositories'][0]['type'], 'path');
        Assert::same($data['repositories'][0]['url'], '.php-downgrade/acme/lib');
        Assert::true($data['repositories'][0]['options']['symlink']);
    }

    public function addPathRepositoryKeepsExistingListEntries(): void
    {
        $existing = ['repositories' => [['type' => 'vcs', 'url' => 'https://example.com/repo.git']]];

        $data = ComposerHelper::addPathRepository($existing, '.php-downgrade/acme/lib');

        Assert::count($data['repositories'], 2);
        Assert::same($data['repositories'][0]['url'], '.php-downgrade/acme/lib');
        Assert::same($data['repositories'][1]['url'], 'https://example.com/repo.git');
    }

    public function addPathRepositoryIsIdempotent(): void
    {
        $once = ComposerHelper::addPathRepository(['name' => 'acme/app'], '.php-downgrade/acme/lib');
        $twice = ComposerHelper::addPathRepository($once, '.php-downgrade/acme/lib');

        Assert::same($once, $twice);
    }

    public function addPathRepositoryUsesKeyedFormWhenExistingIsKeyed(): void
    {
        $existing = ['repositories' => ['private' => ['type' => 'composer', 'url' => 'https://example.com']]];

        $data = ComposerHelper::addPathRepository($existing, '.php-downgrade/acme/lib');

        Assert::true(isset($data['repositories']['private']));
        Assert::same($data['repositories']['php-downgrade-php-downgrade-acme-lib']['url'], '.php-downgrade/acme/lib');
    }
}
