<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Test;
use Tests\Support\AcceptanceProject;

/**
 * End-to-end coverage of the downgrade-rector action: it installs a throwaway Rector and rewrites
 * the given sources in place. These run real Composer + Rector on Linux; on Windows they skip.
 */
#[Test]
final class DowngradeRectorTest
{
    // A readonly class is PHP 8.2 syntax; Rector's downgrade set rewrites it to a plain class with
    // readonly properties, so it parses on 8.1. (Enums, by contrast, are not downgraded by the
    // action even at target 8.0 — see DowngradeTest for what the resolve/relief path covers.)
    private const NEWER_SYNTAX = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Acme\Demo;

        readonly class Card
        {
            public function __construct(public string $suit) {}
        }
        PHP;

    private AcceptanceProject $project;

    #[AfterTest]
    public function cleanup(): void
    {
        isset($this->project) and $this->project->destroy();
    }

    public function downgradesReadonlyClassToTarget(): void
    {
        $this->project = AcceptanceProject::create();
        $this->project->writeFile('src/Card.php', self::NEWER_SYNTAX);

        $result = $this->project->runRector('src', '8.1');

        Assert::same($result['exit'], 0, $result['stderr']);
        $downgraded = $this->project->read('src/Card.php');
        Assert::string($downgraded)->notContains('readonly class')->contains('class Card');
    }

    public function downgradesMultilinePathsWhereAnEntryContainsSpaces(): void
    {
        $this->project = AcceptanceProject::create();
        $this->project->writeFile('src/Card.php', self::NEWER_SYNTAX);
        $this->project->writeFile('weird dir/Card.php', self::NEWER_SYNTAX);

        $result = $this->project->runRector("src\nweird dir/Card.php\n", '8.1');

        Assert::same($result['exit'], 0, $result['stderr']);
        Assert::string($this->project->read('src/Card.php'))->notContains('readonly class');
        Assert::string($this->project->read('weird dir/Card.php'))->notContains('readonly class');
    }

    public function skipInputLeavesMatchingPathUntouched(): void
    {
        $this->project = AcceptanceProject::create();
        $this->project->writeFile('src/Card.php', self::NEWER_SYNTAX);
        $this->project->writeFile('stubs/Legacy.php', self::NEWER_SYNTAX);

        $result = $this->project->runRector('src stubs', '8.1', 'stubs');

        Assert::same($result['exit'], 0, $result['stderr']);
        Assert::string($this->project->read('src/Card.php'))->notContains('readonly class');
        Assert::same($this->project->read('stubs/Legacy.php'), self::NEWER_SYNTAX);
    }
}
