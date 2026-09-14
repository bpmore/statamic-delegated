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
 * Invariant 6 (spec §4): a territory admin cannot see users who have no
 * assignment overlapping their territory.
 *
 * The escalation this closes: the head of cardiology lists every account on
 * the site — names, emails, who is in which department — and acts on one of
 * them. Two rules. Visibility is *downward only*: a user assigned, directly
 * or through a group, to a territory the actor administers or to one of its
 * descendants; never to an ancestor, or a department admin could reach the
 * site's root-level editors. And seeing is looser than changing: a user who
 * also belongs to a territory the actor does not administer is visible but
 * read-only to them, because roles and emails are global.
 */

beforeEach(function () {
    Site::role('editor', ['access cp', 'view pages entries', 'edit pages entries']);

    $this->root = Site::territory('site', '/');
    $this->cardiology = Site::territory('cardiology', '/cardiology', parentId: $this->root);
    $this->trials = Site::territory('trials', '/cardiology/trials', parentId: $this->cardiology);
    $this->oncology = Site::territory('oncology', '/oncology', parentId: $this->root);

    Site::group('cardiology-editors');
    Site::group('oncology-editors');
    Site::assign($this->cardiology, 'group:cardiology-editors');
    Site::assign($this->oncology, 'group:oncology-editors');

    Site::user('head', roles: ['editor']);
    Site::assign($this->cardiology, 'user:head', 'admin');

    // Visible to the head of cardiology:
    Site::user('direct', roles: ['editor']);
    Site::assign($this->cardiology, 'user:direct');
    Site::user('via-group', roles: ['editor'], groups: ['cardiology-editors']);
    Site::user('in-trials', roles: ['editor']);
    Site::assign($this->trials, 'user:in-trials');
    Site::user('shared', roles: ['editor']);
    Site::assign($this->cardiology, 'user:shared');
    Site::assign($this->oncology, 'user:shared');

    // Not visible:
    Site::user('onc-only', roles: ['editor']);
    Site::assign($this->oncology, 'user:onc-only');
    Site::user('onc-via-group', roles: ['editor'], groups: ['oncology-editors']);
    Site::user('site-wide', roles: ['editor']);
    Site::assign($this->root, 'user:site-wide');
    Site::user('unassigned', roles: ['editor']);
    Site::user('root', super: true);

    // A member, not an admin.
    Site::user('staff', roles: ['editor']);
    Site::assign($this->cardiology, 'user:staff');
});

const INVISIBLE = ['onc-only', 'onc-via-group', 'site-wide', 'unassigned', 'root'];

function visible(string $actor): array
{
    $ids = app(TerritoryAdministration::class)->visibleUsers(Site::actor($actor));
    sort($ids);

    return $ids;
}

it('shows an admin exactly the users assigned, directly or through a group, to their territory or below it', function () {
    expect(visible('head'))->toBe(['direct', 'head', 'in-trials', 'shared', 'staff', 'via-group']);
});

it('shows nothing to a member who administers nothing', function () {
    // The user-management surface is for admins. A member has no reason to
    // see a list of colleagues' accounts, so they do not get one.
    expect(visible('staff'))->toBe([]);
});

it('shows the same to an admin whose administration comes through a group', function () {
    Site::group('cardiology-heads');
    Site::assign($this->cardiology, 'group:cardiology-heads', 'admin');
    Site::user('head-via-group', roles: ['editor'], groups: ['cardiology-heads']);

    expect(visible('head-via-group'))->toBe(['direct', 'head', 'head-via-group', 'in-trials', 'shared', 'staff', 'via-group']);
});

it('never shows a user assigned only above the admin\'s territory', function () {
    // Downward only. The site-wide editor's membership flows down into
    // cardiology, but the head of cardiology does not get to act on the
    // people who administer them.
    expect(visible('head'))->not->toContain('site-wide');
});

it('refuses to edit, delete or change the roles of a user it cannot see, and changes nothing', function (string $userId) {
    $administration = app(TerritoryAdministration::class);
    $before = User::find($userId)->data()->all();

    $attempts = [
        fn () => $administration->updateUser(Site::actor('head'), $userId, ['name' => 'Taken Over']),
        fn () => $administration->setRoles(Site::actor('head'), $userId, ['editor']),
        fn () => $administration->setRoles(Site::actor('head'), $userId, []),
        fn () => $administration->deleteUser(Site::actor('head'), $userId),
    ];

    foreach ($attempts as $attempt) {
        expect($attempt)->toThrow(EscalationDenied::class);
    }

    expect(User::find($userId))->not->toBeNull("{$userId} was deleted")
        ->and(User::find($userId)->data()->all())->toBe($before);
})->with(INVISIBLE);

it('names the invariant when it refuses an invisible ordinary user', function () {
    try {
        app(TerritoryAdministration::class)->updateUser(Site::actor('head'), 'onc-only', ['name' => 'x']);
        $this->fail('not refused');
    } catch (EscalationDenied $e) {
        expect($e->invariant)->toBe(EscalationDenied::USER_NOT_VISIBLE);
    }
});

it('shows a shared user but refuses to change them', function () {
    // Visible: they are in cardiology. Read-only: they are also in oncology,
    // and their roles and email are the same account there.
    expect(visible('head'))->toContain('shared');

    $administration = app(TerritoryAdministration::class);

    foreach ([
        fn () => $administration->updateUser(Site::actor('head'), 'shared', ['name' => 'x']),
        fn () => $administration->setRoles(Site::actor('head'), 'shared', ['editor']),
        fn () => $administration->deleteUser(Site::actor('head'), 'shared'),
    ] as $attempt) {
        try {
            $attempt();
            $this->fail('not refused');
        } catch (EscalationDenied $e) {
            expect($e->invariant)->toBe(EscalationDenied::USER_SHARED);
        }
    }

    expect(User::find('shared'))->not->toBeNull()
        ->and(User::find('shared')->get('name'))->toBeNull()
        ->and(Site::rolesOf('shared'))->toBe(['editor']);
});

it('still lets the admin remove a shared user from their own territory', function () {
    // That is within cardiology, and leaves oncology's membership alone.
    app(TerritoryAdministration::class)->removeMember(Site::actor('head'), $this->cardiology, 'user:shared');

    $remaining = array_values(array_filter(Site::assignments(), fn (array $a): bool => $a['subject'] === 'user:shared'));

    expect($remaining)->toBe([['territory_id' => $this->oncology, 'subject' => 'user:shared', 'level' => 'member']])
        ->and(visible('head'))->not->toContain('shared');
});

it('lets the admin change and delete a user who is wholly theirs, so the refusals are about overlap', function () {
    $administration = app(TerritoryAdministration::class);

    $administration->updateUser(Site::actor('head'), 'in-trials', ['name' => 'Trials Editor']);
    expect(User::find('in-trials')->get('name'))->toBe('Trials Editor');

    $administration->deleteUser(Site::actor('head'), 'direct');
    expect(User::find('direct'))->toBeNull()
        ->and(visible('head'))->not->toContain('direct');
});

it('stops showing a user as soon as their overlapping assignment is gone', function () {
    app(TerritoryAdministration::class)->removeMember(Site::actor('head'), $this->trials, 'user:in-trials');

    expect(visible('head'))->not->toContain('in-trials');

    expect(fn () => app(TerritoryAdministration::class)->updateUser(Site::actor('head'), 'in-trials', ['name' => 'x']))
        ->toThrow(EscalationDenied::class);
});

it('does not apply to a super user, who sees everyone', function () {
    expect(visible('root'))->toBe([
        'direct', 'head', 'in-trials', 'onc-only', 'onc-via-group', 'root', 'shared', 'site-wide', 'staff', 'unassigned', 'via-group',
    ]);

    app(TerritoryAdministration::class)->updateUser(Site::actor('root'), 'onc-only', ['name' => 'Seen By Root']);
    expect(User::find('onc-only')->get('name'))->toBe('Seen By Root');
});

it('removes every assignment that named a deleted user', function () {
    Site::assign($this->trials, 'user:direct');

    app(TerritoryAdministration::class)->deleteUser(Site::actor('head'), 'direct');

    expect(array_filter(Site::assignments(), fn (array $a): bool => $a['subject'] === 'user:direct'))->toBe([]);
});

it('rejects a malformed subject outright', function (string $subject) {
    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->cardiology, $subject, Level::Member))
        ->toThrow(InvalidArgumentException::class);
})->with(['direct', 'user:', 'role:editor', ':direct', '']);

it('rejects a user or group that does not exist, before any invariant is consulted', function () {
    // Not an escalation — a bad request — but a request that writes nothing.
    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->cardiology, 'user:ghost', Level::Member))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(TerritoryAdministration::class)->addMember(Site::actor('head'), $this->cardiology, 'group:ghosts', Level::Member))
        ->toThrow(InvalidArgumentException::class);

    expect(array_filter(Site::assignments(), fn (array $a): bool => str_contains($a['subject'], 'ghost')))->toBe([]);
});
