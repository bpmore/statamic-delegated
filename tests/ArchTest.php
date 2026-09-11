<?php

declare(strict_types=1);

arch('the addon declares strict types')
    ->expect('Bpmore\StatamicDelegated')
    ->toUseStrictTypes();

arch('nothing ships with a debugging call left in')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();
