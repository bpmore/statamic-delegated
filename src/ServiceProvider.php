<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    // The product is "Delegated" everywhere a person sees it — views, config,
    // commands — never the repository name.
    protected $viewNamespace = 'delegated';

    public function bootAddon(): void
    {
        // On the site's default connection, run by `php please migrate` like
        // any other. Delegated's tables are authorisation data about the
        // site's own users, and a site whose users are in a database keeps
        // them on this connection; a separate one would only invite the two
        // to disagree.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'delegated-migrations');

        // Enforcement is deliberately absent until the Phase 2 escalation
        // invariants exist as tests (see PROGRESS.md).
    }
}
