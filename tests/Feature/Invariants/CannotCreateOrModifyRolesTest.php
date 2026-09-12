<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Administration\EscalationDenied;
use Bpmore\StatamicDelegated\Administration\TerritoryAdministration;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Facades\Role;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

/*
 * Invariant 4 (spec §4): a territory admin cannot create or modify roles.
 *
 * Two halves. The surface simply has no such operation — a structural test
 * keeps it that way. And no role that *carries* the power to manage roles,
 * users or groups, or to bypass the entry policy, can be handed out through
 * Delegated, whoever holds it: otherwise invariant 1 becomes a relay, where
 * a super user who once gave the head of cardiology `edit roles` has given
 * it to everyone the head ever adds.
 */

const MANAGEMENT_PERMISSIONS = [
    'super',
    'edit roles',
    'edit user groups',
    'edit users',
    'create users',
    'delete users',
    'change passwords',
    'assign roles',
    'assign user groups',
    'impersonate users',
    // Not a user permission, but EntryPolicy::before() treats it as super
    // (Phase 0.1). Handing it out ends the territory model.
    'configure collections',
];

beforeEach(function () {
    Site::role('cardiology-editor', ['access cp', 'view pages entries', 'edit pages entries']);

    $this->cardiology = Site::territory('cardiology', '/cardiology');

    Site::user('head', roles: ['cardiology-editor']);
    Site::assign($this->cardiology, 'user:head', 'admin');

    Site::user('member', roles: ['cardiology-editor']);
    Site::assign($this->cardiology, 'user:member', 'member');

    Site::user('newbie');
});

it('offers no way to create, modify or delete a role', function () {
    // Pinned by name. Adding an operation here is a deliberate act that has
    // to change this list, not something that slips in with a feature.
    $methods = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(TerritoryAdministration::class))->getMethods(),
    );
    sort($methods);

    expect($methods)->toBe([
        'addMember', 'deleteUser', 'removeMember', 'setRoles', 'updateTerritory', 'updateUser', 'visibleUsers',
    ]);

    foreach ((new ReflectionClass(TerritoryAdministration::class))->getMethods() as $method) {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            expect($type instanceof ReflectionNamedType && $type->getName() === Statamic\Contracts\Auth\Role::class)
                ->toBeFalse("{$method->getName()} accepts a Role");
        }
    }
});

it('refuses to grant a role that carries a management permission, even one the admin holds', function (string $permission) {
    // Misconfiguration by a super user: the head holds the dangerous role.
    // Invariant 1 would allow it. Invariant 4 does not.
    Site::role('dangerous', ['access cp', $permission]);
    Site::user('careless-head', roles: ['cardiology-editor', 'dangerous']);
    Site::assign($this->cardiology, 'user:careless-head', 'admin');

    $attempt = fn () => app(TerritoryAdministration::class)->addMember(
        Site::actor('careless-head'), $this->cardiology, 'user:newbie', Level::Member, ['dangerous'],
    );

    expect($attempt)->toThrow(EscalationDenied::class);

    try {
        $attempt();
    } catch (EscalationDenied $e) {
        expect($e->invariant)->toBe(EscalationDenied::ROLE_MANAGEMENT);
    }

    expect(Site::rolesOf('newbie'))->toBe([])
        ->and(Site::assignments())->toHaveCount(3);
})->with(MANAGEMENT_PERMISSIONS)->todo();

it('refuses the same through setRoles on an existing member', function (string $permission) {
    Site::role('dangerous', ['access cp', $permission]);
    Site::user('careless-head', roles: ['cardiology-editor', 'dangerous']);
    Site::assign($this->cardiology, 'user:careless-head', 'admin');

    expect(fn () => app(TerritoryAdministration::class)->setRoles(Site::actor('careless-head'), 'member', ['cardiology-editor', 'dangerous']))
        ->toThrow(EscalationDenied::class);

    expect(Site::rolesOf('member'))->toBe(['cardiology-editor']);
})->with(['super', 'edit roles', 'edit users', 'configure collections'])->todo();

it('refuses a role that is harmless today but is edited to be dangerous later', function () {
    // The check is on the role's permissions at the time of the grant, not
    // on its name or on what it held when the admin was given it.
    Site::role('grows', ['access cp']);
    Site::user('head2', roles: ['cardiology-editor', 'grows']);
    Site::assign($this->cardiology, 'user:head2', 'admin');

    app(TerritoryAdministration::class)->addMember(Site::actor('head2'), $this->cardiology, 'user:newbie', Level::Member, ['grows']);
    expect(Site::rolesOf('newbie'))->toBe(['grows']);

    Role::find('grows')->permissions(['access cp', 'edit roles'])->save();

    Site::user('newbie2');
    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head2'), $this->cardiology, 'user:newbie2', Level::Member, ['grows']))
        ->toThrow(EscalationDenied::class);

    expect(Site::rolesOf('newbie2'))->toBe([]);
})->todo();

it('refuses role or group changes smuggled through updateUser', function () {
    // updateUser edits profile fields. Roles go through setRoles, where
    // invariants 1 and 4 look at them; groups carry roles, so the same.
    foreach ([
        ['roles' => ['cardiology-editor']],
        ['groups' => ['some-group']],
        ['name' => 'Fine', 'roles' => ['cardiology-editor']],
    ] as $attributes) {
        expect(fn () => app(TerritoryAdministration::class)->updateUser(Site::actor('head'), 'member', $attributes))
            ->toThrow(EscalationDenied::class);
    }

    expect(Site::rolesOf('member'))->toBe(['cardiology-editor'])
        ->and(User::find('member')->get('name'))->toBeNull();
})->todo();

it('still lets the admin grant an ordinary role they hold, so the refusals are about the permissions', function () {
    app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->cardiology, 'user:newbie', Level::Member, ['cardiology-editor']);

    expect(Site::rolesOf('newbie'))->toBe(['cardiology-editor']);
})->todo();

it('does not apply to a super user', function () {
    Site::role('role-admin', ['access cp', 'edit roles']);
    Site::user('root', super: true);

    app(TerritoryAdministration::class)->addMember(Site::actor('root'), $this->cardiology, 'user:newbie', Level::Member, ['role-admin']);

    expect(Site::rolesOf('newbie'))->toBe(['role-admin']);
})->todo();
