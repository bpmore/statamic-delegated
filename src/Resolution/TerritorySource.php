<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Resolution;

use Bpmore\StatamicDelegated\Territories\Assignment;
use Bpmore\StatamicDelegated\Territories\Territory;

/**
 * Where the resolver's raw material comes from. The database in production;
 * anything at all in a test.
 */
interface TerritorySource
{
    /** @return list<Territory> */
    public function territories(): array;

    /** @return list<Assignment> */
    public function assignments(): array;
}
