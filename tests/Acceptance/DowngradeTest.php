<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Test;
use Tests\Support\AcceptanceProject;

/**
 * End-to-end coverage of the downgrade action (install.sh): the two-phase resolve, the failure-driven
 * relief loop, and the follow-up Rector pass. Dependencies are local path packages with Packagist
 * disabled, so the resolve is deterministic; each case pins a different situation the action must
 * handle. Real Composer + Rector run on Linux; on Windows these skip.
 */
#[Test]
final class DowngradeTest
{
    private const READONLY_CLASS = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace %s;

        readonly class Card
        {
            public function __construct(public string $suit) {}
        }
        PHP;

    // A composer plugin whose activation aborts the run. Composer activates an allowed plugin on
    // every install/update — even under --no-scripts — so if the downgrade installs let plugins
    // run, this fails the whole resolve; the action must pass --no-plugins to keep it dormant.
    private const FAILING_PLUGIN = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Acme\Plugin;

        use Composer\Composer;
        use Composer\IO\IOInterface;
        use Composer\Plugin\PluginInterface;

        final class FailingPlugin implements PluginInterface
        {
            public function activate(Composer $composer, IOInterface $io): void
            {
                \fwrite(\STDERR, "composer plugin activated on the downgrade runtime\n");
                exit(1);
            }

            public function deactivate(Composer $composer, IOInterface $io): void {}

            public function uninstall(Composer $composer, IOInterface $io): void {}
        }
        PHP;

    private AcceptanceProject $project;

    #[AfterTest]
    public function cleanup(): void
    {
        isset($this->project) and $this->project->destroy();
    }

    public function relievesAndDowngradesAHardDependency(): void
    {
        $this->project = $this->projectRequiring('acme/hard');
        $this->project->addPathPackage(
            'acme/hard',
            ['version' => '1.0.0', 'require' => ['php' => '>=8.2'], 'autoload' => ['psr-4' => ['Acme\\Hard\\' => 'src/']]],
            ['src/Card.php' => \sprintf(self::READONLY_CLASS, 'Acme\\Hard')],
        );

        $result = $this->project->runInstall('8.1');

        Assert::same($result['exit'], 0, $result['stderr']);
        Assert::true($this->project->exists('.php-downgrade/acme/hard/src/Card.php'));
        Assert::string($this->project->read('.php-downgrade/acme/hard/src/Card.php'))->notContains('readonly class');
        Assert::same($this->project->readJson('composer.json')['repositories'][0]['url'], '.php-downgrade/acme/hard');
    }

    public function relievesAHardMetapackage(): void
    {
        $this->project = $this->projectRequiring('acme/meta');
        $this->project->addPathPackage('acme/meta', ['version' => '1.0.0', 'type' => 'metapackage', 'require' => ['php' => '>=8.2']]);

        $result = $this->project->runInstall('8.1');

        Assert::same($result['exit'], 0, $result['stderr']);
        $manifest = $this->project->readJson('.php-downgrade/acme/meta/composer.json');
        Assert::same($manifest['type'], 'metapackage');
        Assert::same($manifest['require']['php'], '>=8.1');
    }

    public function leavesAFullyCompatibleProjectUntouched(): void
    {
        $this->project = $this->projectRequiring('acme/soft');
        $this->project->addPathPackage('acme/soft', ['version' => '1.0.0', 'require' => ['php' => '>=8.0']]);

        $result = $this->project->runInstall('8.1');

        Assert::same($result['exit'], 0, $result['stderr']);
        Assert::string($result['stdout'])->contains('Nothing to downgrade');
        Assert::false($this->project->exists('.php-downgrade/acme/soft'));
    }

    public function downgradesTheProjectsOwnPathsAlongsideCompatibleDeps(): void
    {
        $this->project = $this->projectRequiring('acme/soft');
        $this->project->addPathPackage('acme/soft', ['version' => '1.0.0', 'require' => ['php' => '>=8.0']]);
        $this->project->writeFile('src/Card.php', \sprintf(self::READONLY_CLASS, 'App'));

        $result = $this->project->runInstall('8.1', ['paths' => 'src']);

        Assert::same($result['exit'], 0, $result['stderr']);
        Assert::string($this->project->read('src/Card.php'))->notContains('readonly class')->contains('class Card');
    }

    public function downgradesAMultilineProjectPathThatContainsSpaces(): void
    {
        $this->project = $this->projectRequiring('acme/soft');
        $this->project->addPathPackage('acme/soft', ['version' => '1.0.0', 'require' => ['php' => '>=8.0']]);
        $this->project->writeFile('weird dir/Card.php', \sprintf(self::READONLY_CLASS, 'App'));

        $result = $this->project->runInstall('8.1', ['paths' => "weird dir/Card.php\n"]);

        Assert::same($result['exit'], 0, $result['stderr']);
        Assert::string($this->project->read('weird dir/Card.php'))->notContains('readonly class');
    }

    public function doesNotRunComposerPluginsDuringInstall(): void
    {
        $this->project = AcceptanceProject::create();
        $this->project->writeComposer([
            'name' => 'acme/app',
            'require' => ['acme/plugin' => '*'],
            // Allow the plugin explicitly: an un-allowed plugin is silently skipped in
            // non-interactive mode, which would hide the bug this test exists to catch.
            'config' => ['allow-plugins' => ['acme/plugin' => true]],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/acme-plugin', 'options' => ['symlink' => true]],
                ['packagist.org' => false],
            ],
        ]);
        $this->project->addPathPackage(
            'acme/plugin',
            [
                'version' => '1.0.0',
                'type' => 'composer-plugin',
                'require' => ['composer-plugin-api' => '^2', 'php' => '>=8.0'],
                'extra' => ['class' => 'Acme\\Plugin\\FailingPlugin'],
                'autoload' => ['psr-4' => ['Acme\\Plugin\\' => 'src/']],
            ],
            ['src/FailingPlugin.php' => self::FAILING_PLUGIN],
        );

        $result = $this->project->runInstall('8.1');

        Assert::same($result['exit'], 0, $result['stderr']);
    }

    private function projectRequiring(string $package): AcceptanceProject
    {
        $project = AcceptanceProject::create();
        $project->writeComposer([
            'name' => 'acme/app',
            'require' => [$package => '*'],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/' . \str_replace('/', '-', $package), 'options' => ['symlink' => true]],
                ['packagist.org' => false],
            ],
        ]);

        return $project;
    }
}
