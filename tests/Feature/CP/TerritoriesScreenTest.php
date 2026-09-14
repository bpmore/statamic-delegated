<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Territories\ContentFacts;
use Bpmore\StatamicDelegated\Tests\Support\AbilityScoping;
use Bpmore\StatamicDelegated\Tests\Support\Content;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
 * Phase 4.1: the site-administration screen for territories, assignments
 * and membership. A super user's tool; the Phase 2 invariants constrain
 * territory admins, who get their own screen in 4.2.
 */

beforeEach(function () {
    Content::build();
    Site::role('territory-manager', ['access cp', 'manage territories']);
    Site::role('editor', ['access cp', 'view pages entries']);
    Site::user('root', super: true);
    Site::user('manager', roles: ['territory-manager']);
    Site::user('editor', roles: ['editor']);
    Site::group('cardiology-editors');
});

function territoriesIndex(string $as): TestResponse
{
    return AbilityScoping::as($as)->getJson('/cp/delegated/territories');
}

function createTerritory(string $as, array $payload): TestResponse
{
    return AbilityScoping::as($as)->postJson('/cp/delegated/territories', $payload + [
        'name' => 'Cardiology', 'slug' => 'cardiology', 'site' => 'default', 'scope_type' => 'structure_branch', 'scope_ref' => '/cardiology',
    ]);
}

it('is reachable only with the manage territories permission, which super users have', function () {
    // An unauthorised HTML request is redirected away by Statamic; a JSON one
    // gets the 403. Either way the page is never rendered.
    $html = AbilityScoping::as('editor')->get('/cp/delegated/territories');

    expect($html->status())->toBe(302)
        ->and($html->headers->get('Location'))->not->toContain('/delegated/territories')
        ->and(territoriesIndex('editor')->status())->toBe(403)
        ->and(territoriesIndex('manager')->status())->toBe(200)
        ->and(territoriesIndex('root')->status())->toBe(200)
        ->and(createTerritory('editor', [])->status())->toBe(403);
});

it('renders the Inertia page with everything the form needs', function () {
    Site::territory('cardiology', '/cardiology');

    AbilityScoping::as('root')->get('/cp/delegated/territories')
        ->assertInertia(fn (Assert $page) => $page
            ->component('delegated/Territories')
            ->has('territories', 1)
            ->has('sites', 1)
            ->has('scopeTypes', 3)
            ->has('collections', 2)
            ->has('taxonomies', 1)
            ->has('users')
            ->has('groups', 1)
            ->has('urls.store'));
});

it('lists territories with their parent and assignments', function () {
    $card = Site::territory('cardiology', '/cardiology');
    $trials = Site::territory('trials', '/cardiology/trials', parentId: $card);
    Site::assign($card, 'user:editor', 'admin');
    Site::assign($card, 'group:cardiology-editors');

    $r = territoriesIndex('root');

    $byId = collect($r->json('territories'))->keyBy('id');

    expect($byId[$trials]['parent_name'])->toBe('Cardiology')
        ->and($byId[$card]['parent_name'])->toBeNull()
        ->and(collect($byId[$card]['assignments'])->pluck('subject')->all())->toEqualCanonicalizing(['user:editor', 'group:cardiology-editors'])
        ->and(collect($byId[$card]['assignments'])->firstWhere('subject', 'user:editor')['level'])->toBe('admin')
        ->and(collect($byId[$card]['assignments'])->firstWhere('subject', 'group:cardiology-editors')['label'])->toBe('Cardiology-editors');
});

it('creates a territory, normalises its scope, and the resolver sees it at once', function () {
    $r = createTerritory('root', ['scope_ref' => '/Cardiology/']);

    expect($r->status())->toBe(201)
        ->and($r->json('territory.scope_ref'))->toBe('/cardiology')
        ->and(app(TerritoryResolver::class)->owner(new ContentFacts('default', 'pages', '/cardiology/trials'))?->slug)->toBe('cardiology');
});

it('validates what the database would otherwise refuse, with a message', function () {
    $card = Site::territory('cardiology', '/cardiology');

    $cases = [
        'duplicate slug on the site' => [['slug' => 'cardiology', 'scope_ref' => '/heart'], 'slug'],
        'duplicate scope on the site' => [['slug' => 'heart', 'scope_ref' => '/cardiology'], 'scope_ref'],
        'unknown scope type' => [['slug' => 'x', 'scope_type' => 'planet', 'scope_ref' => '/x'], 'scope_type'],
        'branch without a leading slash' => [['slug' => 'x', 'scope_ref' => 'cardiology'], 'scope_ref'],
        'unknown collection' => [['slug' => 'x', 'scope_type' => 'collection', 'scope_ref' => 'nope'], 'scope_ref'],
        'term that does not exist' => [['slug' => 'x', 'scope_type' => 'taxonomy_term', 'scope_ref' => 'departments::radiology'], 'scope_ref'],
        'term not written taxonomy::slug' => [['slug' => 'x', 'scope_type' => 'taxonomy_term', 'scope_ref' => 'cardiology'], 'scope_ref'],
        'unknown site' => [['slug' => 'x', 'site' => 'mars', 'scope_ref' => '/x'], 'site'],
        'unknown parent' => [['slug' => 'x', 'scope_ref' => '/x', 'parent_id' => 999], 'parent_id'],
        'bad slug' => [['slug' => 'Not A Slug', 'scope_ref' => '/x'], 'slug'],
    ];

    foreach ($cases as $case => [$payload, $field]) {
        $r = createTerritory('root', $payload);

        expect($r->status())->toBe(422, $case)
            ->and($r->json("errors.{$field}"))->not->toBeNull("{$case}: expected an error on {$field}");
    }

    // Same slug on another site is fine; so is the same scope.
    config(['statamic.sites.sites' => ['default' => ['name' => 'Default', 'url' => '/', 'locale' => 'en_US'], 'french' => ['name' => 'French', 'url' => '/fr', 'locale' => 'fr_FR']]]);
    Statamic\Facades\Site::setSites(config('statamic.sites.sites'));

    expect(createTerritory('root', ['site' => 'french'])->status())->toBe(201);

    expect(DB::table('delegated_territories')->count())->toBe(2);
});

it('accepts a collection and a term as scopes', function () {
    expect(createTerritory('root', ['slug' => 'news', 'scope_type' => 'collection', 'scope_ref' => 'News'])->json('territory.scope_ref'))->toBe('news')
        ->and(createTerritory('root', ['slug' => 'onc-news', 'scope_type' => 'taxonomy_term', 'scope_ref' => 'Departments::Oncology'])->json('territory.scope_ref'))->toBe('departments::oncology');
});

it('updates a territory and refuses to make it its own ancestor', function () {
    $card = Site::territory('cardiology', '/cardiology');
    $trials = Site::territory('trials', '/cardiology/trials', parentId: $card);
    $phase = Site::territory('phase', '/cardiology/trials/phase-2', parentId: $trials);

    $payload = fn (array $extra) => $extra + ['name' => 'Cardiology', 'slug' => 'cardiology', 'site' => 'default', 'scope_type' => 'structure_branch', 'scope_ref' => '/cardiology'];

    $r = AbilityScoping::as('root')->patchJson("/cp/delegated/territories/{$card}", $payload(['name' => 'Cardiology & Vascular']));
    expect($r->status())->toBe(200)
        ->and($r->json('territory.name'))->toBe('Cardiology & Vascular');

    expect(AbilityScoping::as('root')->patchJson("/cp/delegated/territories/{$card}", $payload(['parent_id' => $card]))->json('errors.parent_id'))->not->toBeNull()
        ->and(AbilityScoping::as('root')->patchJson("/cp/delegated/territories/{$card}", $payload(['parent_id' => $phase]))->json('errors.parent_id'))->not->toBeNull()
        ->and(AbilityScoping::as('root')->patchJson('/cp/delegated/territories/999', $payload([]))->status())->toBe(404);
});

it('deletes a territory: its assignments go, its children become roots, the resolver forgets it', function () {
    $card = Site::territory('cardiology', '/cardiology');
    $trials = Site::territory('trials', '/cardiology/trials', parentId: $card);
    Site::assign($card, 'user:editor');

    $r = AbilityScoping::as('root')->deleteJson("/cp/delegated/territories/{$card}");

    expect($r->status())->toBe(200)
        ->and(collect($r->json('territories'))->pluck('id')->all())->toBe([$trials])
        ->and(DB::table('delegated_assignments')->count())->toBe(0)
        ->and(DB::table('delegated_territories')->find($trials)->parent_id)->toBeNull()
        ->and(app(TerritoryResolver::class)->owner(new ContentFacts('default', 'pages', '/cardiology')))->toBeNull()
        ->and(app(TerritoryResolver::class)->owner(new ContentFacts('default', 'pages', '/cardiology/trials'))?->slug)->toBe('trials');
});

it('adds and removes assignments, recording who granted them', function () {
    $card = Site::territory('cardiology', '/cardiology');

    $r = AbilityScoping::as('root')->postJson("/cp/delegated/territories/{$card}/assignments", ['subject_type' => 'user', 'subject_id' => 'editor', 'level' => 'admin']);
    expect($r->status())->toBe(201);

    $r = AbilityScoping::as('root')->postJson("/cp/delegated/territories/{$card}/assignments", ['subject_type' => 'group', 'subject_id' => 'cardiology-editors', 'level' => 'member']);
    expect($r->status())->toBe(201);

    $rows = collect(Site::assignments());
    expect($rows->pluck('subject')->all())->toEqualCanonicalizing(['user:editor', 'group:cardiology-editors'])
        ->and(DB::table('delegated_assignments')->where('subject_id', 'editor')->value('granted_by'))->toBe('root')
        ->and(app(TerritoryResolver::class)->membershipFor(['user:editor'])->isAdminOf($card))->toBeTrue();

    // Changing a level is an update, not a second row.
    AbilityScoping::as('root')->postJson("/cp/delegated/territories/{$card}/assignments", ['subject_type' => 'user', 'subject_id' => 'editor', 'level' => 'member']);
    expect(DB::table('delegated_assignments')->where('subject_id', 'editor')->count())->toBe(1)
        ->and(app(TerritoryResolver::class)->membershipFor(['user:editor'])->isAdminOf($card))->toBeFalse();

    $r = AbilityScoping::as('root')->deleteJson("/cp/delegated/territories/{$card}/assignments", ['subject_type' => 'user', 'subject_id' => 'editor']);
    expect($r->status())->toBe(200)
        ->and(app(TerritoryResolver::class)->membershipFor(['user:editor'])->has($card))->toBeFalse();
});

it('refuses an assignment to someone who does not exist, or with a bad level', function () {
    $card = Site::territory('cardiology', '/cardiology');

    expect(AbilityScoping::as('root')->postJson("/cp/delegated/territories/{$card}/assignments", ['subject_type' => 'user', 'subject_id' => 'ghost', 'level' => 'member'])->status())->toBe(422)
        ->and(AbilityScoping::as('root')->postJson("/cp/delegated/territories/{$card}/assignments", ['subject_type' => 'group', 'subject_id' => 'ghosts', 'level' => 'member'])->status())->toBe(422)
        ->and(AbilityScoping::as('root')->postJson("/cp/delegated/territories/{$card}/assignments", ['subject_type' => 'user', 'subject_id' => 'editor', 'level' => 'owner'])->status())->toBe(422)
        ->and(AbilityScoping::as('root')->postJson("/cp/delegated/territories/{$card}/assignments", ['subject_type' => 'robot', 'subject_id' => 'editor', 'level' => 'member'])->status())->toBe(422)
        ->and(AbilityScoping::as('root')->postJson('/cp/delegated/territories/999/assignments', ['subject_type' => 'user', 'subject_id' => 'editor', 'level' => 'member'])->status())->toBe(404)
        ->and(Site::assignments())->toBe([]);
});

it('ships a built control panel bundle where Statamic looks for it', function () {
    $build = repoPath('resources/dist/build');

    expect(is_file($build.'/manifest.json'))->toBeTrue('no manifest.json in resources/dist/build — run `npm run build`');

    $manifest = json_decode((string) file_get_contents($build.'/manifest.json'), true);

    expect($manifest)->toHaveKey('resources/js/cp.js')
        ->and(is_file($build.'/'.$manifest['resources/js/cp.js']['file']))->toBeTrue();
});
