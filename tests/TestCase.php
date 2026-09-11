<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests;

use Bpmore\StatamicDelegated\ServiceProvider;
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
}
