<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\AbilityScoping;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

/*
 * Phase 3.2.h: Statamic's own users screens obey invariant 6 for anyone
 * who belongs to a territory. Users are file-driven under both test cases,
 * so this runs once.
 *
 * Confined: territory members and admins. Not confined: super users, and
 * people in no territory at all — a site needs a non-super user
 * administrator, and has one by keeping them out of every territory.
 */

beforeEach(function () {
    // Statamic requires an elevated session to edit users; that is its own
    // refusal, not the one under test here.
    config(['statamic.users.elevated_sessions_enabled' => false]);

    Site::role('manager', ['access cp', 'view users', 'edit users', 'delete users']);
    Site::role('watcher', ['access cp', 'view users']);

    $this->root = Site::territory('site', '/');
    $this->cardiology = Site::territory('cardiology', '/cardiology', parentId: $this->root);
    $this->trials = Site::territory('trials', '/cardiology/trials', parentId: $this->cardiology);
    $this->oncology = Site::territory('oncology', '/oncology', parentId: $this->root);

    Site::group('cardiology-editors');
    Site::assign($this->cardiology, 'group:cardiology-editors');

    // The head of cardiology holds Statamic's user permissions directly.
    Site::user('head', roles: ['manager']);
    Site::assign($this->cardiology, 'user:head', 'admin');

    Site::user('direct');
    Site::assign($this->cardiology, 'user:direct');
    Site::user('via-group', groups: ['cardiology-editors']);
    Site::user('in-trials');
    Site::assign($this->trials, 'user:in-trials');
    Site::user('shared');
    Site::assign($this->cardiology, 'user:shared');
    Site::assign($this->oncology, 'user:shared');

    Site::user('onc-only');
    Site::assign($this->oncology, 'user:onc-only');
    Site::user('site-wide');
    Site::assign($this->root, 'user:site-wide');
    Site::user('unassigned');

    // A plain member who can view users, and a site-wide user administrator
    // who belongs to no territory.
    Site::user('staff', roles: ['watcher']);
    Site::assign($this->cardiology, 'user:staff');
    Site::user('hr', roles: ['manager']);

    Site::user('root', super: true);
});

function listUsers(string $as, array $query = []): array
{
    $r = AbilityScoping::as($as)->getJson('/cp/users'.($query ? '?'.http_build_query($query) : ''));

    expect($r->status())->toBe(200, 'users listing: '.($r->exception?->getMessage() ?? ''));

    return ['ids' => collect($r->json('data'))->pluck('id')->sort()->values()->all(), 'total' => $r->json('meta.total')];
}

it('shows a territory admin the users they administer, and themselves', function () {
    ['ids' => $ids, 'total' => $total] = listUsers('head');

    expect($ids)->toBe(['direct', 'head', 'in-trials', 'shared', 'staff', 'via-group'])
        ->and($total)->toBe(6);
});

it('shows a plain member only themselves', function () {
    expect(listUsers('staff')['ids'])->toBe(['staff']);
});

it('does not confine a user administrator who belongs to no territory, nor a super user', function () {
    $everyone = User::all()->map->id()->sort()->values()->all();

    expect(listUsers('hr')['ids'])->toBe($everyone)
        ->and(listUsers('root')['ids'])->toBe($everyone);
});

it('scopes a search, and cannot be widened or omitted by the request', function () {
    expect(listUsers('head', ['search' => 'onc'])['ids'])->toBe([])
        ->and(listUsers('head', ['search' => 'direct'])['ids'])->toBe(['direct']);

    // A crafted filters parameter naming someone else's filter, or none at all.
    $crafted = base64_encode(json_encode(['delegated_visible_users' => ['ids' => ['onc-only', 'site-wide']]]));

    expect(listUsers('head', ['filters' => $crafted])['ids'])->toBe(['direct', 'head', 'in-trials', 'shared', 'staff', 'via-group']);
});

it('paginates the confined set', function () {
    $p1 = listUsers('head', ['perPage' => 4, 'page' => 1, 'sort' => 'email', 'order' => 'asc']);
    $p2 = listUsers('head', ['perPage' => 4, 'page' => 2, 'sort' => 'email', 'order' => 'asc']);

    expect(count($p1['ids']))->toBe(4)
        ->and(count($p2['ids']))->toBe(2)
        ->and($p1['total'])->toBe(6);
});

it('refuses the edit screen for a user the admin cannot see or may not change, by direct URL', function () {
    expect(AbilityScoping::as('head')->getJson('/cp/users/onc-only/edit')->status())->toBe(403)
        ->and(AbilityScoping::as('head')->getJson('/cp/users/site-wide/edit')->status())->toBe(403)
        ->and(AbilityScoping::as('head')->getJson('/cp/users/unassigned/edit')->status())->toBe(403)
        ->and(AbilityScoping::as('head')->getJson('/cp/users/root/edit')->status())->toBe(403)
        // Visible but shared with oncology: read-only.
        ->and(AbilityScoping::as('head')->getJson('/cp/users/shared/edit')->status())->toBe(403)
        // Wholly theirs, and themselves.
        ->and(AbilityScoping::as('head')->getJson('/cp/users/direct/edit')->status())->toBe(200)
        ->and(AbilityScoping::as('head')->getJson('/cp/users/in-trials/edit')->status())->toBe(200)
        ->and(AbilityScoping::as('head')->getJson('/cp/users/head/edit')->status())->toBe(200);
});

it('refuses to update a user the admin may not change, and leaves them unchanged', function () {
    $r = AbilityScoping::as('head')->patchJson('/cp/users/onc-only', ['email' => 'onc-only@example.com', 'name' => 'Taken Over']);

    expect($r->status())->toBe(403)
        ->and(User::find('onc-only')->get('name'))->toBeNull();

    $r = AbilityScoping::as('head')->patchJson('/cp/users/shared', ['email' => 'shared@example.com', 'name' => 'Taken Over']);

    expect($r->status())->toBe(403)
        ->and(User::find('shared')->get('name'))->toBeNull();

    $r = AbilityScoping::as('head')->patchJson('/cp/users/direct', ['email' => 'direct@example.com', 'name' => 'Renamed']);

    expect($r->status())->toBe(200)
        ->and(User::find('direct')->get('name'))->toBe('Renamed');
});

it('neither offers nor runs delete on a user the admin cannot see', function () {
    $offered = AbilityScoping::as('head')->postJson('/cp/users/actions/list', ['selections' => ['onc-only']]);

    expect(collect($offered->json())->pluck('handle')->all())->not->toContain('delete');

    $run = AbilityScoping::as('head')->postJson('/cp/users/actions', [
        'action' => 'delete', 'selections' => ['onc-only'], 'context' => [], 'values' => [],
    ]);

    expect($run->status())->toBe(403)
        ->and(User::find('onc-only'))->not->toBeNull();

    $ok = AbilityScoping::as('head')->postJson('/cp/users/actions', [
        'action' => 'delete', 'selections' => ['direct'], 'context' => [], 'values' => [],
    ]);

    expect($ok->status())->toBe(200)
        ->and(User::find('direct'))->toBeNull();
});

it('leaves an unconfined user administrator able to edit anyone but a super user, as Statamic allows', function () {
    $r = AbilityScoping::as('hr')->patchJson('/cp/users/onc-only', ['email' => 'onc-only@example.com', 'name' => 'HR Edit']);

    expect($r->status())->toBe(200)
        ->and(User::find('onc-only')->get('name'))->toBe('HR Edit');
});
