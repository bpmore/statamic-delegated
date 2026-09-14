<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests;

use Statamic\Eloquent\Entries\Entry as EloquentEntry;
use Statamic\Eloquent\Entries\UuidEntryModel;
use Statamic\Eloquent\ServiceProvider as EloquentDriverServiceProvider;

/**
 * The same booted Statamic, with entries in the database instead of the
 * Stache. Everything else — collections, trees, taxonomies, users — stays on
 * the file driver, which is a configuration Statamic supports and the one
 * most likely to catch a query that only works on one side.
 *
 * Every scenario under tests/Feature/Eloquent runs here; the flat-file copy
 * runs under TestCase. A scenario that passes on one and fails on the other
 * is exactly the class of bug SECURITY.md names.
 */
abstract class EloquentTestCase extends TestCase
{
    private const ENTRIES_MIGRATION = __DIR__.'/../vendor/statamic/eloquent-driver/database/migrations/entries/2024_03_07_100000_create_entries_table_with_string_ids.php';

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [EloquentDriverServiceProvider::class]);
    }

    /**
     * The driver decides which repositories it replaces in register(), from
     * config a site would have published. That is before defineEnvironment()
     * runs, so the config has to be set here, between LoadConfiguration and
     * RegisterProviders.
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.eloquent-driver.entries', [
            'driver' => 'eloquent',
            'model' => UuidEntryModel::class,
            'entry' => EloquentEntry::class,
            'map_data_to_columns' => false,
        ]);
    }

    /**
     * The driver's entries migration is not registered with the migrator —
     * it ships two variants of the same table and lets a site publish one —
     * so it is run by path, after RefreshDatabase (a trait on the test, whose
     * methods would override any hook defined here) has migrated the rest.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => self::ENTRIES_MIGRATION, '--realpath' => true])->run();
    }
}
