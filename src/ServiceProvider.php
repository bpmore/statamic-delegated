<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated;

use Bpmore\StatamicDelegated\Administration\DelegatedAdministration;
use Bpmore\StatamicDelegated\Administration\TerritoryAdministration;
use Bpmore\StatamicDelegated\Administration\Visibility;
use Bpmore\StatamicDelegated\Enforcement\ClosesEntryOracles;
use Bpmore\StatamicDelegated\Enforcement\GuardsEntryAbilities;
use Bpmore\StatamicDelegated\Enforcement\GuardsEntryCreation;
use Bpmore\StatamicDelegated\Enforcement\GuardsNavigationTree;
use Bpmore\StatamicDelegated\Enforcement\GuardsStructureTreeUpdate;
use Bpmore\StatamicDelegated\Enforcement\GuardsUserAbilities;
use Bpmore\StatamicDelegated\Enforcement\PrunesStructureTree;
use Bpmore\StatamicDelegated\Enforcement\ScopesEntryListings;
use Bpmore\StatamicDelegated\Enforcement\ScopesUsersListing;
use Bpmore\StatamicDelegated\Resolution\DatabaseTerritorySource;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Resolution\TerritorySource;
use Bpmore\StatamicDelegated\Storage\TerritoryStore;
use Illuminate\Contracts\Foundation\Application;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    // The product is "Delegated" everywhere a person sees it — views, config,
    // commands — never the repository name.
    protected $viewNamespace = 'delegated';

    // Replaces Statamic's duplicate action under its own handle. See the class.
    protected $actions = [
        Actions\DuplicateEntry::class,
    ];

    // Replaces Statamic's entries fieldtype under its own handle, so the
    // relationship picker's query is scoped. See the class.
    protected $fieldtypes = [
        Fieldtypes\Entries::class,
    ];

    // The hidden listing filter that confines the users listing. See the class.
    protected $scopes = [
        Scopes\VisibleUsers::class,
    ];

    // Enforcement points that are middleware. Each acts only on the routes
    // it names and does nothing for a user Exempt::user() names.
    protected $middlewareGroups = [
        'statamic.cp.authenticated' => [
            GuardsEntryCreation::class,
            PrunesStructureTree::class,
            GuardsStructureTreeUpdate::class,
            GuardsNavigationTree::class,
            ScopesUsersListing::class,
            ClosesEntryOracles::class,
        ],
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/delegated.php', 'delegated');

        $this->app->singleton(TerritorySource::class, fn (Application $app): TerritorySource => new DatabaseTerritorySource(
            $app->make('db'),
        ));

        // One resolver, so one cache and one per-request map. Everything that
        // asks who owns what asks this instance.
        $this->app->singleton(TerritoryResolver::class, function (Application $app): TerritoryResolver {
            $config = $app['config']->get('delegated.cache', []);
            // Not `?? 3600`: null is a legitimate setting meaning "until the
            // next change", and ?? would quietly turn it into an hour.
            $ttl = array_key_exists('ttl', $config) ? $config['ttl'] : 3600;

            return new TerritoryResolver(
                $app->make(TerritorySource::class),
                $app->make('cache')->store($config['store'] ?? null),
                $ttl === null ? null : (int) $ttl,
            );
        });

        $this->app->singleton(TerritoryStore::class, fn (Application $app): TerritoryStore => new TerritoryStore(
            $app->make('db'),
            $app->make(TerritoryResolver::class),
        ));

        $this->app->singleton(TerritoryAdministration::class, fn (Application $app): TerritoryAdministration => new DelegatedAdministration(
            $app->make(TerritoryResolver::class),
            $app->make(TerritoryStore::class),
            $app->make(Visibility::class),
        ));
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/delegated.php' => config_path('delegated.php'),
        ], 'delegated-config');

        // On the site's default connection, run by `php artisan migrate` like
        // any other. Delegated's tables are authorisation data about the
        // site's own users, and a site whose users are in a database keeps
        // them on this connection; a separate one would only invite the two
        // to disagree.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'delegated-migrations');

        // Enforcement points, one class per control-panel surface from the
        // Phase 0.1 inventory. Each registers itself; none does anything for
        // a user Exempt::user() names.
        $this->app->make(ScopesEntryListings::class)->register();
        $this->app->make(GuardsEntryAbilities::class)->register();
        $this->app->make(GuardsUserAbilities::class)->register();
    }
}
