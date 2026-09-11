<?php

declare(strict_types=1);

arch('the addon declares strict types')
    ->expect('Bpmore\StatamicDelegated')
    ->toUseStrictTypes();

arch('nothing ships with a debugging call left in')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

// Resolution is the hot path and the thing the policy layer and the query
// compiler both depend on. It stays pure — facts in, territories out — so it
// is testable in milliseconds without a booted Statamic and cannot quietly
// grow a dependency on the request, the session or the clock. Statamic and
// the database are met in Resolution\.
//
// Not an arch expectation: Pest resolves a dependency by looking it up among
// the registered PSR-4 prefixes, so a name shallower than any prefix matches
// nothing and the expectation passes without testing anything. Reading the
// tokens does not care how a thing is autoloaded.
it('keeps territory resolution free of any framework', function (string $file) {
    $frameworks = ['Statamic', 'Illuminate', 'Laravel', 'Orchestra', 'Carbon'];

    $violations = array_values(array_unique(array_filter(
        referencedNames($file),
        static fn (string $name): bool => in_array(strtok($name, '\\'), $frameworks, true),
    )));

    expect($violations)->toBe([]);
})->with(phpSources(['src/Territories']));

it('has a territory core to keep pure', function () {
    // Guards the check above: a renamed directory would leave it with no
    // files and nothing to fail on.
    expect(phpSources(['src/Territories']))->not->toBeEmpty();
});
