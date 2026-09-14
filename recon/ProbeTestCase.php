<?php

/**
 * Boots Statamic core alone — Delegated's service provider is deliberately
 * left out — so the probes measure what Statamic does by itself on the
 * vendored `statamic/cms`. Run from this repository:
 *
 *   vendor/bin/phpunit -c phpunit.xml recon/DelegatedInventoryProbeTest.php --no-progress 2>&1 \
 *     | sed -n '/PROBE RESULTS/,/=======/p'
 *
 * Until Phase 5.4 the probes extended a host site's TestCase; that tied the
 * inventory to whatever version the host happened to run, which is not what
 * "re-verify on every minor" needs.
 */

namespace Probe;

use Bpmore\StatamicDelegated\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class ProbeTestCase extends AddonTestCase
{
    // Only used by AddonTestCase to locate this package on disk.
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getPackageProviders($app)
    {
        return array_values(array_filter(parent::getPackageProviders($app), fn (string $p): bool => $p !== ServiceProvider::class));
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Routes registered at boot; env vars in a host, config here.
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.api.enabled', true);
        $app['config']->set('statamic.graphql.enabled', true);
        $app['config']->set('statamic.revisions.enabled', true);
        $app['config']->set('statamic.users.two_factor_enabled', false);
        $app['config']->set('statamic.users.elevated_sessions_enabled', false);
    }
}
