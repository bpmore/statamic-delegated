<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Administration\EscalationDenied;
use Bpmore\StatamicDelegated\Tests\Support\AbilityScoping;
use Bpmore\StatamicDelegated\Tests\Support\Content;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

/*
 * Phase 4.2: a territory admin's self-service screen. Every write goes
 * through TerritoryAdministration, so these tests are about the screen
 * honouring the invariants' answers — the invariants themselves are proven
 * in tests/Feature/Invariants.
 */

beforeEach(function () {
    Content::build();
    Site::role('cardiology-editor', ['access cp', 'view pages entries', 'edit pages entries']);
    Site::role('news-editor', ['access cp', 'view news entries']);
    Site::role('site-admin', ['access cp', 'edit roles']);

    $this->cardiology = Site::territory('cardiology', '/cardiology');
    $this->trials = Site::territory('trials', '/cardiology/trials', parentId: $this->cardiology);
    $this->oncology = Site::territory('oncology', '/oncology');

    Site::user('head', roles: ['cardiology-editor', 'site-admin']);
    Site::assign($this->cardiology, 'user:head', 'admin');

    Site::user('staff', roles: ['cardiology-editor']);
    Site::assign($this->cardiology, 'user:staff');

    Site::user('shared', roles: ['cardiology-editor', 'news-editor']);
    Site::assign($this->cardiology, 'user:shared');
    Site::assign($this->oncology, 'user:shared');

    Site::user('onc-head');
    Site::assign($this->oncology, 'user:onc-head', 'admin');

    Site::user('newbie');
    Site::user('root', super: true);
    Site::group('cardiology-editors');
});

function myTerritories(string $as): TestResponse
{
    return AbilityScoping::as($as)->getJson('/cp/delegated/my-territories');
}

it('admits only someone who administers a territory, and super users', function () {
    expect(myTerritories('staff')->status())->toBe(403)
        ->and(myTerritories('newbie')->status())->toBe(403)
        ->and(myTerritories('head')->status())->toBe(200)
        ->and(myTerritories('root')->status())->toBe(200);
});

it('renders the Inertia page with the admin\'s territories and grantable roles only', function () {
    AbilityScoping::as('head')->get('/cp/delegated/my-territories')
        ->assertInertia(fn (Assert $page) => $page
            ->component('delegated/MyTerritories')
            ->has('territories', 2)
            ->where('territories.0.name', 'Cardiology')
            ->where('territories.1.name', 'Trials')
            // site-admin carries `edit roles`: never offered, whoever holds it.
            ->where('grantableRoles', [['handle' => 'cardiology-editor', 'title' => 'Cardiology-editor']])
            ->has('levels', 2));
});

it('shows each member with the roles the admin may change and the ones they may not', function () {
    $r = myTerritories('head');

    $members = collect($r->json('territories.0.members'))->keyBy('id');

    expect($members->keys()->all())->toEqualCanonicalizing(['head', 'staff', 'shared'])
        ->and($members['head']['self'])->toBeTrue()
        ->and($members['staff']['editable'])->toBeTrue()
        ->and($members['staff']['roles'])->toBe(['cardiology-editor'])
        // Shared with oncology: shown, read-only.
        ->and($members['shared']['editable'])->toBeFalse()
        ->and($members['shared']['lockedRoles'])->toBe(['news-editor'])
        // Oncology's admin is nobody the head can see, and oncology is not listed.
        ->and(collect($r->json('territories'))->pluck('id')->all())->not->toContain($this->oncology);
});

it('adds a colleague by email with a role the admin holds', function () {
    $r = AbilityScoping::as('head')->postJson("/cp/delegated/my-territories/{$this->cardiology}/members", [
        'subject_type' => 'user', 'subject' => 'newbie@example.com', 'level' => 'member', 'roles' => ['cardiology-editor'],
    ]);

    expect($r->status())->toBe(201)
        ->and(collect($r->json('territories.0.members'))->pluck('id')->all())->toContain('newbie')
        ->and(Site::rolesOf('newbie'))->toBe(['cardiology-editor']);
});

it('refuses with the invariant named, and writes nothing, when the admin oversteps', function () {
    // A role they do not hold (invariant 1).
    $r = AbilityScoping::as('head')->postJson("/cp/delegated/my-territories/{$this->cardiology}/members", [
        'subject_type' => 'user', 'subject' => 'newbie@example.com', 'level' => 'member', 'roles' => ['news-editor'],
    ]);
    expect($r->status())->toBe(403)
        ->and($r->json('invariant'))->toBe(EscalationDenied::ROLE_NOT_HELD)
        ->and(Site::rolesOf('newbie'))->toBe([]);

    // A role that manages roles, even though they hold it (invariant 4).
    $r = AbilityScoping::as('head')->postJson("/cp/delegated/my-territories/{$this->cardiology}/members", [
        'subject_type' => 'user', 'subject' => 'newbie@example.com', 'level' => 'member', 'roles' => ['site-admin'],
    ]);
    expect($r->status())->toBe(403)
        ->and($r->json('invariant'))->toBe(EscalationDenied::ROLE_MANAGEMENT);

    // A territory they do not administer (invariant 2).
    $r = AbilityScoping::as('head')->postJson("/cp/delegated/my-territories/{$this->oncology}/members", [
        'subject_type' => 'user', 'subject' => 'newbie@example.com', 'level' => 'member',
    ]);
    expect($r->status())->toBe(403)
        ->and($r->json('invariant'))->toBe(EscalationDenied::TERRITORY_NOT_ADMINISTERED);

    // A super user (invariant 3).
    $r = AbilityScoping::as('head')->postJson("/cp/delegated/my-territories/{$this->cardiology}/members", [
        'subject_type' => 'user', 'subject' => 'root@example.com', 'level' => 'member',
    ]);
    expect($r->status())->toBe(403)
        ->and($r->json('invariant'))->toBe(EscalationDenied::SUPER_USER);

    expect(collect(Site::assignments())->pluck('subject')->all())->not->toContain('user:newbie');
});

it('answers an unknown email or group with a validation error rather than an escalation', function () {
    $r = AbilityScoping::as('head')->postJson("/cp/delegated/my-territories/{$this->cardiology}/members", [
        'subject_type' => 'user', 'subject' => 'nobody@example.com', 'level' => 'member',
    ]);
    expect($r->status())->toBe(422)->and($r->json('errors.subject'))->not->toBeNull();

    $r = AbilityScoping::as('head')->postJson("/cp/delegated/my-territories/{$this->cardiology}/members", [
        'subject_type' => 'group', 'subject' => 'no-such-group', 'level' => 'member',
    ]);
    expect($r->status())->toBe(422);

    $r = AbilityScoping::as('head')->postJson("/cp/delegated/my-territories/{$this->cardiology}/members", [
        'subject_type' => 'group', 'subject' => 'cardiology-editors', 'level' => 'admin',
    ]);
    expect($r->status())->toBe(201)
        ->and(collect($r->json('territories.0.members'))->firstWhere('subject', 'group:cardiology-editors')['level'])->toBe('admin');
});

it('removes a member from the admin\'s territory and refuses one elsewhere', function () {
    $r = AbilityScoping::as('head')->deleteJson("/cp/delegated/my-territories/{$this->cardiology}/members", ['subject_type' => 'user', 'subject_id' => 'staff']);
    expect($r->status())->toBe(200)
        ->and(collect(Site::assignments())->pluck('subject')->all())->not->toContain('user:staff');

    $r = AbilityScoping::as('head')->deleteJson("/cp/delegated/my-territories/{$this->oncology}/members", ['subject_type' => 'user', 'subject_id' => 'onc-head']);
    expect($r->status())->toBe(403)
        ->and(collect(Site::assignments())->pluck('subject')->all())->toContain('user:onc-head');
});

it('sets the roles the admin holds on a wholly-owned member and keeps the rest', function () {
    User::find('staff')->assignRole('news-editor')->save();

    $r = AbilityScoping::as('head')->patchJson("/cp/delegated/my-territories/{$this->cardiology}/members/staff/roles", ['roles' => []]);
    expect($r->status())->toBe(200)
        ->and(Site::rolesOf('staff'))->toBe(['news-editor']);

    $r = AbilityScoping::as('head')->patchJson("/cp/delegated/my-territories/{$this->cardiology}/members/staff/roles", ['roles' => ['cardiology-editor']]);
    expect(Site::rolesOf('staff'))->toBe(['cardiology-editor', 'news-editor']);

    // A shared member is read-only (invariant 6).
    $r = AbilityScoping::as('head')->patchJson("/cp/delegated/my-territories/{$this->cardiology}/members/shared/roles", ['roles' => []]);
    expect($r->status())->toBe(403)
        ->and($r->json('invariant'))->toBe(EscalationDenied::USER_SHARED)
        ->and(Site::rolesOf('shared'))->toBe(['cardiology-editor', 'news-editor']);
});

it('lets the admin rename their territory and nothing else about it', function () {
    $r = AbilityScoping::as('head')->patchJson("/cp/delegated/my-territories/{$this->cardiology}", ['name' => 'Cardiology & Vascular']);
    expect($r->status())->toBe(200)
        ->and($r->json('territories.0.name'))->toBe('Cardiology & Vascular');

    // The route accepts only a name; the invariant would refuse scope anyway.
    $r = AbilityScoping::as('head')->patchJson("/cp/delegated/my-territories/{$this->cardiology}", ['name' => 'X', 'scope_ref' => '/']);
    expect($r->status())->toBe(200)
        ->and(DB::table('delegated_territories')->find($this->cardiology)->scope_ref)->toBe('/cardiology');

    expect(AbilityScoping::as('head')->patchJson("/cp/delegated/my-territories/{$this->oncology}", ['name' => 'Mine'])->status())->toBe(403);
});

it('shows a super user every territory', function () {
    expect(collect(myTerritories('root')->json('territories'))->pluck('id')->all())
        ->toEqualCanonicalizing([$this->cardiology, $this->trials, $this->oncology]);
});
