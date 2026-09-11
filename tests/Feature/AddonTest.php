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

it('ships a security policy with a contact and a response time', function () {
    // Spec §1: a security contact and a disclosure policy exist before there
    // is a listing to buy. This only proves the file says something in each
    // slot; whether the times are honoured is not something a test can check.
    $policy = (string) file_get_contents(repoPath('SECURITY.md'));

    expect($policy)
        ->toContain('security/advisories/new')
        ->and(preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $policy))->toBe(1)
        ->and(preg_match('/\*\*\d+ (business|calendar) days\*\*/', $policy))->toBe(1);

    // The README is where a buyer looks first, so it carries the contact too.
    expect((string) file_get_contents(repoPath('README.md')))
        ->toContain('SECURITY.md')
        ->toContain('security/advisories/new');
});
