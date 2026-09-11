<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Administration\EscalationDenied;
use Bpmore\StatamicDelegated\Administration\TerritoryAdministration;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Invariant 2 (spec §4): a territory admin cannot add a user to a territory
 * they do not administer.
 *
 * The escalation this closes: the head of cardiology puts a colleague — or
 * themselves — into oncology, or into the root of the site, and the
 * compartment is open. "Administer" means an admin-level assignment to the
 * territory or to one of its ancestors (Phase 1.4: membership flows down);
 * being a member is not administering, and administering a child is not
 * administering its parent.
 */

beforeEach(function () {
    Site::role('cardiology-editor', ['access cp', 'view pages entries', 'edit pages entries']);

    $this->root = Site::territory('site', '/');
    $this->cardiology = Site::territory('cardiology', '/cardiology', parentId: $this->root);
    $this->trials = Site::territory('trials', '/cardiology/trials', parentId: $this->cardiology);
    $this->oncology = Site::territory('oncology', '/oncology', parentId: $this->root);

    Site::user('head', roles: ['cardiology-editor']);
    Site::assign($this->cardiology, 'user:head', 'admin');

    Site::user('staff', roles: ['cardiology-editor']);
    Site::assign($this->cardiology, 'user:staff', 'member');

    Site::user('newbie');
    Site::group('visitors');
});

function assignmentsFor(string $subject): array
{
    return array_values(array_filter(Site::assignments(), fn (array $a): bool => $a['subject'] === $subject));
}

it('lets an admin add a user to the territory they administer', function () {
    app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->cardiology, 'user:newbie', Level::Member);

    expect(assignmentsFor('user:newbie'))->toBe([
        ['territory_id' => $this->cardiology, 'subject' => 'user:newbie', 'level' => 'member'],
    ]);
})->todo();

it('lets an admin add a user to a child of the territory they administer', function () {
    // Administration flows down the hierarchy with membership (Phase 1.4).
    app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->trials, 'user:newbie', Level::Member);

    expect(assignmentsFor('user:newbie'))->toBe([
        ['territory_id' => $this->trials, 'subject' => 'user:newbie', 'level' => 'member'],
    ]);
})->todo();

it('refuses a territory the admin has nothing to do with, and writes nothing', function () {
    $attempt = fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->oncology, 'user:newbie', Level::Member);

    expect($attempt)->toThrow(EscalationDenied::class);

    try {
        $attempt();
    } catch (EscalationDenied $e) {
        expect($e->invariant)->toBe(EscalationDenied::TERRITORY_NOT_ADMINISTERED);
    }

    expect(assignmentsFor('user:newbie'))->toBe([]);
})->todo();

it('refuses the parent of the territory the admin administers', function () {
    // Up is not down. An admin of /cardiology adding someone to / would be
    // handing out the whole site.
    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->root, 'user:newbie', Level::Member))
        ->toThrow(EscalationDenied::class);

    expect(assignmentsFor('user:newbie'))->toBe([]);
})->todo();

it('refuses a member who is not an admin, even in their own territory', function () {
    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('staff'), $this->cardiology, 'user:newbie', Level::Member))
        ->toThrow(EscalationDenied::class);

    expect(assignmentsFor('user:newbie'))->toBe([]);
})->todo();

it('refuses whatever the level being granted', function () {
    // Adding an admin to a territory you do not administer is worse, not
    // different.
    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->oncology, 'user:newbie', Level::Admin))
        ->toThrow(EscalationDenied::class);

    expect(assignmentsFor('user:newbie'))->toBe([]);
})->todo();

it('refuses a group subject just as it refuses a user', function () {
    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->oncology, 'group:visitors', Level::Member))
        ->toThrow(EscalationDenied::class);

    expect(assignmentsFor('group:visitors'))->toBe([]);
})->todo();

it('refuses the admin adding themselves elsewhere', function () {
    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->oncology, 'user:head', Level::Member))
        ->toThrow(EscalationDenied::class);

    expect(assignmentsFor('user:head'))->toBe([
        ['territory_id' => $this->cardiology, 'subject' => 'user:head', 'level' => 'admin'],
    ]);
})->todo();

it('refuses to remove a member from a territory the admin does not administer', function () {
    // "Add and remove members within their own territory": removal is the
    // same boundary. Removing oncology's admin would be a denial of service
    // on another department.
    Site::user('onc-head');
    Site::assign($this->oncology, 'user:onc-head', 'admin');

    expect(fn () => app(TerritoryAdministration::class)->removeMember(Site::actor('head'), $this->oncology, 'user:onc-head'))
        ->toThrow(EscalationDenied::class);

    expect(assignmentsFor('user:onc-head'))->toHaveCount(1);
})->todo();

it('refuses a territory that does not exist rather than treating it as harmless', function () {
    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head'), 999, 'user:newbie', Level::Member))
        ->toThrow(EscalationDenied::class);

    expect(assignmentsFor('user:newbie'))->toBe([]);
})->todo();

it('checks the territory before touching roles, so a refused request grants no role either', function () {
    // The roles are ones the admin holds, so invariant 1 would allow them.
    // The territory is not theirs, so nothing at all may happen.
    expect(fn () => app(TerritoryAdministration::class)->addMember(
        Site::actor('head'), $this->oncology, 'user:newbie', Level::Member, ['cardiology-editor'],
    ))->toThrow(EscalationDenied::class);

    expect(Site::rolesOf('newbie'))->toBe([])
        ->and(assignmentsFor('user:newbie'))->toBe([]);
})->todo();

it('lets an admin who administers two territories add to either', function () {
    Site::user('dual-head');
    Site::assign($this->cardiology, 'user:dual-head', 'admin');
    Site::assign($this->oncology, 'user:dual-head', 'admin');

    app(TerritoryAdministration::class)->addMember(Site::actor('dual-head'), $this->cardiology, 'user:newbie', Level::Member);
    app(TerritoryAdministration::class)->addMember(Site::actor('dual-head'), $this->oncology, 'user:newbie', Level::Member);

    expect(assignmentsFor('user:newbie'))->toHaveCount(2);
})->todo();

it('counts administration held through a group', function () {
    Site::group('cardiology-heads');
    Site::user('head-via-group', groups: ['cardiology-heads']);
    Site::assign($this->cardiology, 'group:cardiology-heads', 'admin');

    app(TerritoryAdministration::class)->addMember(Site::actor('head-via-group'), $this->cardiology, 'user:newbie', Level::Member);

    expect(assignmentsFor('user:newbie'))->toHaveCount(1);

    // But not beyond what the group administers.
    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head-via-group'), $this->oncology, 'user:newbie', Level::Member))
        ->toThrow(EscalationDenied::class);
})->todo();

it('does not apply to a super user', function () {
    Site::user('root', super: true);

    app(TerritoryAdministration::class)->addMember(Site::actor('root'), $this->oncology, 'user:newbie', Level::Admin);

    expect(assignmentsFor('user:newbie'))->toBe([
        ['territory_id' => $this->oncology, 'subject' => 'user:newbie', 'level' => 'admin'],
    ]);
})->todo();
