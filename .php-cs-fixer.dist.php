<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Scoped to the test suite and the repository's own config files. The shipped action scripts
// (downgrade/composer-helper.php, downgrade-rector/rector-downgrade.php) are deliberately kept
// PHP 8.0-plain and hand-tuned, so they stay out of the fixer's reach.
return Spiral\CodeStyle\Builder::create()
    ->include(__DIR__ . '/tests')
    ->include(__DIR__ . '/testo.php')
    ->include(__FILE__)
    ->build();
