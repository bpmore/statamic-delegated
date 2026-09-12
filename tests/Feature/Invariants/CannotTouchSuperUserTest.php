<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Administration\EscalationDenied;
use Bpmore\StatamicDelegated\Administration\TerritoryAdministration;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

/*
 * Invariant 3 (spec §4): a territory admin cannot edit, demote or delete a
 * super user.
 *
 * The escalation this closes: the people who delegate are the only people
 * who can undo delegation. A department admin who could change a super
 * user's email could take the account; who could demote one could remove
 * the site's last unrestricted administrator; who could delete one could
 * lock everyone out. The rule is simpler than three cases: a super user is
 * not a subject a territory admin can act on, in any way, through Delegated.
 * And the flag that makes someone super is off-limits in both directions —
 * no demoting, and no promoting either.
 */

beforeEach(function () {
    Site::role('cardiology-editor', ['access cp', 'view pages entries', 'edit pages entries']);

    $this->cardiology = Site::territory('cardiology', '/cardiology');

    Site::user('head', roles: ['cardiology-editor']);
    Site::assign($this->cardiology, 'user:head', 'admin');

    // A super user who is also listed in cardiology — so they are visible to
    // its admin, and a refusal here is this invariant's and not invariant 6's.
    $root = Site::user('root', roles: ['cardiology-editor'], super: true);
    $root->set('name', 'Root Admin')->save();
    Site::assign($this->cardiology, 'user:root', 'member');

    Site::user('newbie');
});

function superIsUntouched(): void
{
    $root = User::find('root');

    expect($root)->not->toBeNull('the super user was deleted')
        ->and($root->isSuper())->toBeTrue('the super user was demoted')
        ->and($root->get('name'))->toBe('Root Admin')
        ->and($root->email())->toBe('root@example.com')
        ->and(Site::rolesOf('root'))->toBe(['cardiology-editor']);
}

it('refuses to edit a super user, and changes nothing', function () {
    $attempt = fn () => app(TerritoryAdministration::class)->updateUser(Site::actor('head'), 'root', ['name' => 'Someone Else']);

    expect($attempt)->toThrow(EscalationDenied::class);

    try {
        $attempt();
    } catch (EscalationDenied $e) {
        expect($e->invariant)->toBe(EscalationDenied::SUPER_USER);
    }

    superIsUntouched();
})->todo();

it('refuses to change a super user\'s email, which is the account', function () {
    expect(fn () => app(TerritoryAdministration::class)->updateUser(Site::actor('head'), 'root', ['email' => 'head@example.com']))
        ->toThrow(EscalationDenied::class);

    superIsUntouched();
})->todo();

it('refuses to demote a super user by clearing the flag', function () {
    expect(fn () => app(TerritoryAdministration::class)->updateUser(Site::actor('head'), 'root', ['super' => false]))
        ->toThrow(EscalationDenied::class);

    superIsUntouched();
})->todo();

it('refuses to change a super user\'s roles, even to roles the admin holds', function () {
    // Invariant 1 would allow these roles. Invariant 3 refuses the target.
    expect(fn () => app(TerritoryAdministration::class)->setRoles(Site::actor('head'), 'root', ['cardiology-editor']))
        ->toThrow(EscalationDenied::class);

    expect(fn () => app(TerritoryAdministration::class)->setRoles(Site::actor('head'), 'root', []))
        ->toThrow(EscalationDenied::class);

    superIsUntouched();
})->todo();

it('refuses to delete a super user', function () {
    expect(fn () => app(TerritoryAdministration::class)->deleteUser(Site::actor('head'), 'root'))
        ->toThrow(EscalationDenied::class);

    superIsUntouched();
})->todo();

it('refuses to remove a super user from the territory, or add one to it', function () {
    // Neither would escalate anything — a super user needs no membership —
    // but "not a subject a territory admin can act on" is a rule with no
    // exceptions to remember.
    expect(fn () => app(TerritoryAdministration::class)->removeMember(Site::actor('head'), $this->cardiology, 'user:root'))
        ->toThrow(EscalationDenied::class);

    expect(Site::assignments())->toContain(['territory_id' => $this->cardiology, 'subject' => 'user:root', 'level' => 'member']);

    Site::user('root2', super: true);

    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->cardiology, 'user:root2', Level::Member))
        ->toThrow(EscalationDenied::class);

    expect(Site::assignments())->toHaveCount(2);
})->todo();

it('refuses a super user the admin cannot even see', function () {
    // No overlapping assignment, so invariant 6 also applies. Whichever
    // check runs first, the answer is a refusal and nothing changes.
    Site::user('root2', super: true)->set('name', 'Second Root')->save();

    expect(fn () => app(TerritoryAdministration::class)->deleteUser(Site::actor('head'), 'root2'))
        ->toThrow(EscalationDenied::class);

    expect(fn () => app(TerritoryAdministration::class)->updateUser(Site::actor('head'), 'root2', ['name' => 'x']))
        ->toThrow(EscalationDenied::class);

    expect(User::find('root2'))->not->toBeNull()
        ->and(User::find('root2')->get('name'))->toBe('Second Root');
})->todo();

it('refuses to make anyone super', function () {
    // Demote and promote are the same flag. A territory admin who could set
    // it on a colleague could set it on a second account of their own.
    expect(fn () => app(TerritoryAdministration::class)->updateUser(Site::actor('head'), 'newbie', ['super' => true]))
        ->toThrow(EscalationDenied::class);

    expect(User::find('newbie')->isSuper())->toBeFalse();

    Site::assign($this->cardiology, 'user:newbie');

    expect(fn () => app(TerritoryAdministration::class)->updateUser(Site::actor('head'), 'newbie', ['name' => 'N', 'super' => true]))
        ->toThrow(EscalationDenied::class);

    expect(User::find('newbie')->isSuper())->toBeFalse()
        ->and(User::find('newbie')->get('name'))->toBeNull();
})->todo();

it('still lets the admin edit an ordinary member, so the refusals above are about the target', function () {
    Site::assign($this->cardiology, 'user:newbie');

    app(TerritoryAdministration::class)->updateUser(Site::actor('head'), 'newbie', ['name' => 'New Colleague']);

    expect(User::find('newbie')->get('name'))->toBe('New Colleague');
})->todo();

it('does not apply to a super user acting on another', function () {
    Site::user('root2', super: true);

    app(TerritoryAdministration::class)->updateUser(Site::actor('root2'), 'root', ['name' => 'Renamed By Peer']);

    expect(User::find('root')->get('name'))->toBe('Renamed By Peer');
})->todo();
