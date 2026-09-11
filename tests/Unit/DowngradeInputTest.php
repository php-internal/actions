<?php

declare(strict_types=1);

namespace Tests\Unit;

use PhpInternal\Actions\Downgrade\DowngradeInput;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

require_once \dirname(__DIR__, 2) . '/downgrade-rector/DowngradeInput.php';

/**
 * Unit coverage of the pure input parsing behind the `downgrade-rector` action: list splitting,
 * version validation, and workspace path resolution.
 */
#[Test]
final class DowngradeInputTest
{
    public function splitListSplitsOnWhitespaceWhenSingleLine(): void
    {
        Assert::same(DowngradeInput::splitList('  src  tests/Unit  '), ['src', 'tests/Unit']);
    }

    public function splitListSplitsPerLineAndPreservesSpacesWhenMultiline(): void
    {
        Assert::same(DowngradeInput::splitList("weird dir/Card.php\nsrc\n"), ['weird dir/Card.php', 'src']);
    }

    public function splitListReturnsEmptyForBlankInput(): void
    {
        Assert::same(DowngradeInput::splitList('   '), []);
    }

    public function resolveVersionAcceptsSupported(): void
    {
        Assert::same(DowngradeInput::resolveVersion('8.3'), '8.3');
    }

    public function resolveVersionFallsBackToDefaultWhenBlank(): void
    {
        Assert::same(DowngradeInput::resolveVersion('  '), '8.1');
    }

    public function resolveVersionRejectsUnsupported(): void
    {
        Expect::exception(\RuntimeException::class);

        DowngradeInput::resolveVersion('7.4');
    }

    public function resolvePathsPrefixesWorkspace(): void
    {
        Assert::same(
            DowngradeInput::resolvePaths("src\n/abs/path", '/work'),
            ['/work/src', '/work/abs/path'],
        );
    }

    public function resolvePathsThrowsWhenEmpty(): void
    {
        Expect::exception(\RuntimeException::class);

        DowngradeInput::resolvePaths('  ', '/work');
    }

    public function resolveSkipPassesGlobsAndAbsolutePathsVerbatim(): void
    {
        Assert::same(
            DowngradeInput::resolveSkip("src/*.php\n/abs/skip\nrel/dir", '/work'),
            ['src/*.php', '/abs/skip', '/work/rel/dir'],
        );
    }

    public function resolveSkipIsEmptyForBlankInput(): void
    {
        Assert::same(DowngradeInput::resolveSkip('', '/work'), []);
    }
}
