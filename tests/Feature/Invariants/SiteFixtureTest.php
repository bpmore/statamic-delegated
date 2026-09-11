<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Contracts\Auth\RoleRepository;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

uses(RefreshDatabase::class);

/*
 * The invariant tests are only as good as the fixtures they run on. If a
 * role silently failed to save, or an actor came back with no roles, a
 * denial test would pass for the wrong reason. This pins the harness.
 */

it('builds roles, groups and users in the sandbox and nowhere else', function () {
    Site::role('editor', ['access cp']);
    Site::group('editors', roles: ['editor']);
    Site::user('direct', roles: ['editor']);
    Site::user('member', groups: ['editors']);
    Site::user('root', super: true);

    expect(Role::find('editor'))->not->toBeNull()
        ->and(UserGroup::find('editors')->roles()->map->handle()->values()->all())->toBe(['editor'])
        ->and(User::find('direct')->roles()->map->handle()->values()->all())->toBe(['editor'])
        ->and(User::find('member')->explicitRoles()->all())->toBe([])
        ->and(User::find('member')->roles()->map->handle()->values()->all())->toBe(['editor'])
        ->and(User::find('root')->isSuper())->toBeTrue()
        // The YAML files landed in the directory that is wiped after the test.
        ->and(app(RoleRepository::class))->not->toBeNull()
        ->and(file_exists(repoPath('tests/__fixtures__/dev-null/roles.yaml')))->toBeTrue()
        ->and(file_exists(repoPath('tests/__fixtures__/dev-null/groups.yaml')))->toBeTrue();
});

it('describes an actor with the roles they hold directly and through groups, and their subjects', function () {
    Site::role('editor');
    Site::role('reviewer');
    Site::group('editors', roles: ['editor']);
    Site::user('alice', roles: ['reviewer'], groups: ['editors']);

    $actor = Site::actor('alice');

    expect($actor->userId)->toBe('alice')
        ->and($actor->isSuper)->toBeFalse()
        ->and($actor->roles)->toEqualCanonicalizing(['reviewer', 'editor'])
        ->and($actor->subjects)->toEqualCanonicalizing(['user:alice', 'group:editors'])
        ->and($actor->holdsRole('editor'))->toBeTrue()
        ->and($actor->holdsRole('site-admin'))->toBeFalse()
        ->and($actor->rolesNotHeld(['editor', 'site-admin', 'other']))->toBe(['site-admin', 'other']);
});

it('writes territories and assignments the resolver sees immediately', function () {
    $cardiology = Site::territory('cardiology', '/cardiology');
    Site::user('head');
    Site::assign($cardiology, 'user:head', 'admin');

    $membership = app(TerritoryResolver::class)->membershipFor(Site::actor('head')->subjects);

    expect($membership->isAdminOf($cardiology))->toBeTrue()
        ->and(Site::assignments())->toBe([['territory_id' => $cardiology, 'subject' => 'user:head', 'level' => 'admin']]);
});
