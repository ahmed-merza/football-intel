<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

/**
 * Rector is a manual-trigger refactoring pass — NOT a pre-commit hook.
 *
 * Run deliberately:
 *   composer rector          # apply changes
 *   composer rector:check    # dry-run, exit 1 if changes would be made
 *
 * When: before a phase handoff, before a major PR, monthly maintenance.
 * Why: keeps code aligned with Laravel 13 idioms + PHP 8.3+ type coverage
 *      without producing noisy commits on every change.
 */

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/database/migrations/0001_01_01_000000_create_users_table.php',
        __DIR__.'/database/migrations/0001_01_01_000001_create_cache_table.php',
        __DIR__.'/database/migrations/0001_01_01_000002_create_jobs_table.php',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelLevelSetList::UP_TO_LARAVEL_110,
    ])
    ->withImportNames(
        importShortClasses: false,
        removeUnusedImports: true,
    );
