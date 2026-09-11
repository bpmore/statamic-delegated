<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Administration\EscalationDenied;
use Bpmore\StatamicDelegated\Administration\TerritoryAdministration;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Invariant 1 (spec §4): a territory admin cannot grant a role they do not
 * themselves hold.
 *
 * The escalation this closes: a department admin who can edit pages gives a
 * colleague — or themselves, via a second account — a role that can edit
 * roles, and the territory model is over. "Hold" means directly or through a
 * group, because that is what Statamic means by it.
 */

function administration(): TerritoryAdministration
{
    return app(TerritoryAdministration::class);
}

beforeEach(function () {
    Site::role('cardiology-editor', ['access cp', 'view pages entries', 'edit pages entries']);
    Site::role('news-editor', ['access cp', 'view news entries', 'edit news entries']);
    Site::role('site-admin', ['access cp', 'edit roles', 'configure collections']);

    $this->cardiology = Site::territory('cardiology', '/cardiology');

    // The head of cardiology holds one role and administers one territory.
    Site::user('head', roles: ['cardiology-editor']);
    Site::assign($this->cardiology, 'user:head', 'admin');

    // A new colleague with nothing yet.
    Site::user('newbie');
});

it('lets an admin grant a role they hold', function () {
    // The happy path has to exist, or every denial below would pass for the
    // wrong reason.
    administration()->addMember(Site::actor('head'), $this->cardiology, 'user:newbie', Level::Member, ['cardiology-editor']);

    expect(Site::rolesOf('newbie'))->toBe(['cardiology-editor'])
        ->and(Site::assignments())->toContain(['territory_id' => $this->cardiology, 'subject' => 'user:newbie', 'level' => 'member']);
})->todo();

it('refuses a role the admin does not hold, and writes nothing', function () {
    $attempt = fn () => administration()->addMember(Site::actor('head'), $this->cardiology, 'user:newbie', Level::Member, ['site-admin']);

    expect($attempt)->toThrow(EscalationDenied::class);

    try {
        $attempt();
    } catch (EscalationDenied $e) {
        expect($e->invariant)->toBe(EscalationDenied::ROLE_NOT_HELD);
    }

    // No role, and no membership either: the request is refused whole.
    expect(Site::rolesOf('newbie'))->toBe([])
        ->and(Site::assignments())->not->toContain(['territory_id' => $this->cardiology, 'subject' => 'user:newbie', 'level' => 'member']);
})->todo();

it('refuses the whole request when one of several roles is not held', function () {
    // No partial grant: the held role is not applied while the other is
    // refused, because a half-applied request is the harder one to audit.
    expect(fn () => administration()->addMember(
        Site::actor('head'), $this->cardiology, 'user:newbie', Level::Member, ['cardiology-editor', 'site-admin'],
    ))->toThrow(EscalationDenied::class);

    expect(Site::rolesOf('newbie'))->toBe([])
        ->and(Site::assignments())->toHaveCount(1);
})->todo();

it('refuses to change an existing member to a role the admin does not hold', function () {
    Site::assign($this->cardiology, 'user:newbie');

    expect(fn () => administration()->setRoles(Site::actor('head'), 'newbie', ['site-admin']))
        ->toThrow(EscalationDenied::class);

    expect(Site::rolesOf('newbie'))->toBe([]);
})->todo();

it('refuses even when the role is one the admin is trying to give themselves', function () {
    expect(fn () => administration()->setRoles(Site::actor('head'), 'head', ['cardiology-editor', 'site-admin']))
        ->toThrow(EscalationDenied::class);

    expect(Site::rolesOf('head'))->toBe(['cardiology-editor']);
})->todo();

it('counts a role held through a group as held', function () {
    Site::group('cardiology-heads', roles: ['news-editor']);
    Site::user('head-via-group', groups: ['cardiology-heads']);
    Site::assign($this->cardiology, 'group:cardiology-heads', 'admin');

    administration()->addMember(Site::actor('head-via-group'), $this->cardiology, 'user:newbie', Level::Member, ['news-editor']);

    expect(Site::rolesOf('newbie'))->toBe(['news-editor']);
})->todo();

it('does not count a role the group holds if the admin is not in the group', function () {
    Site::group('cardiology-heads', roles: ['news-editor']);

    expect(fn () => administration()->addMember(Site::actor('head'), $this->cardiology, 'user:newbie', Level::Member, ['news-editor']))
        ->toThrow(EscalationDenied::class);

    expect(Site::rolesOf('newbie'))->toBe([]);
})->todo();

it('refuses a role that does not exist rather than treating it as harmless', function () {
    expect(fn () => administration()->addMember(Site::actor('head'), $this->cardiology, 'user:newbie', Level::Member, ['no-such-role']))
        ->toThrow(EscalationDenied::class);

    expect(Site::assignments())->toHaveCount(1);
})->todo();

it('does not apply to a super user', function () {
    // Super users bypass everything by design (spec §8). The invariant
    // constrains delegated admins, not the people who delegate.
    Site::user('root', super: true);

    administration()->addMember(Site::actor('root'), $this->cardiology, 'user:newbie', Level::Member, ['site-admin']);

    expect(Site::rolesOf('newbie'))->toBe(['site-admin']);
})->todo();
