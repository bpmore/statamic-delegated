<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests;

use Bpmore\StatamicDelegated\ServiceProvider;
use Statamic\Contracts\Auth\RoleRepository;
use Statamic\Contracts\Auth\UserGroupRepository;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * Boots a real Statamic through Orchestra Testbench.
 *
 * PreventsSavingStacheItemsToDisk is not optional. Without it an entry or
 * collection created in one test is written to disk and still there in the
 * next — which, in a suite whose whole point is proving that access is
 * denied, would let a leaked fixture make a denial test pass for the wrong
 * reason.
 */
abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * AddonTestCase sandboxes users into the fake Stache directory, but roles
     * and groups are YAML files the repositories read from resource_path(),
     * which under Testbench is inside vendor/. Point them at the same
     * directory, which is wiped after every test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        app(RoleRepository::class)->path($this->fakeStacheDirectory.'/roles.yaml');
        app(UserGroupRepository::class)->path($this->fakeStacheDirectory.'/groups.yaml');

        // Roles, permissions and more than one user are Pro features, and so
        // is Delegated. Without this the CountUsers middleware refuses any
        // control-panel request once a second user exists.
        config(['statamic.editions.pro' => true]);
    }
}
