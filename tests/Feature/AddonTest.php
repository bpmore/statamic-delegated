<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Statamic\Facades\Addon;

it('registers itself with Statamic', function () {
    $addon = Addon::get('bpmore/statamic-delegated');

    expect($addon)->not->toBeNull()
        // The repository is named for the platform; the product is not. The
        // slug names the config file, the view namespace and the commands.
        ->and($addon->slug())->toBe('delegated');
});

it('is running against Statamic 6', function () {
    // The addon reaches into policy and listing internals that differ between
    // majors. A suite that passed against 5.x would be proving nothing.
    //
    // Not Statamic::version(): under Testbench that shells out to Composer in
    // the skeleton application, which has no lock file, and throws.
    expect(InstalledVersions::getPrettyVersion('statamic/cms'))->toStartWith('v6.');
});

it('pins statamic/cms to a tested range rather than an open-ended one', function () {
    // Spec §7: policy and listing internals are not a stable API. The
    // constraint is widened only after a new minor has been verified, and
    // this guards against it quietly drifting back to ^6.0.
    $composer = json_decode((string) file_get_contents(repoPath('composer.json')), true);
    $constraint = $composer['require']['statamic/cms'];

    expect($constraint)
        ->not->toStartWith('^')
        ->not->toStartWith('>=6.0')
        ->and(preg_match('/^(~|>=)\d+\.\d+/', $constraint))->toBe(1);
});
