<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Territories\ContentFacts;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Territories\ScopeType;
use Bpmore\StatamicDelegated\Territories\TerritoryMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Blink;

uses(RefreshDatabase::class);

function seedTerritory(array $overrides = []): int
{
    return (int) DB::table('delegated_territories')->insertGetId(array_merge([
        'name' => 'Cardiology',
        'slug' => 'cardiology',
        'site' => 'default',
        'scope_type' => 'structure_branch',
        'scope_ref' => '/cardiology',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function seedAssignment(int $territoryId, string $type, string $id, string $level = 'member'): void
{
    DB::table('delegated_assignments')->insert([
        'territory_id' => $territoryId,
        'subject_type' => $type,
        'subject_id' => $id,
        'level' => $level,
        'granted_by' => null,
        'granted_at' => now(),
    ]);
}

function resolver(): TerritoryResolver
{
    return app(TerritoryResolver::class);
}

/** Queries against the two Delegated tables since the log was last flushed. */
function delegatedQueries(): int
{
    return count(array_filter(
        DB::getQueryLog(),
        fn (array $q): bool => str_contains($q['query'], 'delegated_'),
    ));
}

beforeEach(function () {
    // A fresh process would have neither; a fresh test must not inherit
    // either from the one before it.
    Blink::store('delegated')->flush();
    Cache::flush();
});

it('is one instance for the whole application', function () {
    expect(resolver())->toBe(app(TerritoryResolver::class));
});

it('resolves ownership from the database', function () {
    $cardiology = seedTerritory();
    $trials = seedTerritory(['name' => 'Trials', 'slug' => 'trials', 'scope_ref' => '/cardiology/trials', 'parent_id' => $cardiology]);
    seedTerritory(['name' => 'News', 'slug' => 'news', 'scope_type' => 'collection', 'scope_ref' => 'news']);

    $owner = resolver()->owner(new ContentFacts('default', 'pages', '/cardiology/trials/phase-2'));

    expect($owner)->not->toBeNull()
        ->and($owner->id)->toBe($trials)
        ->and($owner->scopeType)->toBe(ScopeType::StructureBranch)
        ->and($owner->parentId)->toBe($cardiology)
        ->and(resolver()->owner(new ContentFacts('default', 'news', '/news/x'))->slug)->toBe('news')
        ->and(resolver()->owner(new ContentFacts('default', 'pages', '/oncology')))->toBeNull()
        ->and(resolver()->owner(new ContentFacts('default', 'pages', null)))->toBeNull();
});

it('normalises a scope_ref that was stored untidily', function () {
    // Write-time normalisation is the rule, but a row edited by hand must
    // still compare correctly rather than silently cover nothing.
    seedTerritory(['scope_ref' => '/Cardiology/']);

    expect(resolver()->owner(new ContentFacts('default', 'pages', '/cardiology/x')))->not->toBeNull();
});

it('resolves membership from assignments to users and groups, with inheritance', function () {
    $cardiology = seedTerritory();
    $trials = seedTerritory(['name' => 'Trials', 'slug' => 'trials', 'scope_ref' => '/cardiology/trials', 'parent_id' => $cardiology]);
    $oncology = seedTerritory(['name' => 'Oncology', 'slug' => 'oncology', 'scope_ref' => '/oncology']);
    seedAssignment($cardiology, 'group', 'cardiology-editors');
    seedAssignment($cardiology, 'user', 'head-of-cardiology', 'admin');

    $editor = resolver()->membershipFor(['user:some-editor', 'group:cardiology-editors']);
    $head = resolver()->membershipFor(['user:head-of-cardiology']);
    $stranger = resolver()->membershipFor(['user:someone-else', 'group:oncology-editors']);

    expect($editor->territoryIds())->toEqualCanonicalizing([$cardiology, $trials])
        ->and($editor->has($oncology))->toBeFalse()
        ->and($editor->isAdminOf($cardiology))->toBeFalse()
        ->and($head->levelIn($trials))->toBe(Level::Admin)
        ->and($head->has($oncology))->toBeFalse()
        ->and($stranger->isEmpty())->toBeTrue();
});

it('reads the database once per request, however many rows are resolved', function () {
    seedTerritory();
    seedAssignment(1, 'group', 'g');

    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach (range(1, 200) as $i) {
        resolver()->owner(new ContentFacts('default', 'pages', "/cardiology/page-{$i}"));
        resolver()->membershipFor(["user:u{$i}", 'group:g']);
    }

    // Two: one for territories, one for assignments. Spec §6 forbids
    // anything per row.
    expect(delegatedQueries())->toBe(2);
});

it('serves the next request from the cache without touching the database', function () {
    seedTerritory();
    resolver()->map();

    // A new request: the per-request memo is gone, the cache is not.
    Blink::store('delegated')->flush();
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(resolver()->owner(new ContentFacts('default', 'pages', '/cardiology')))->not->toBeNull()
        ->and(delegatedQueries())->toBe(0);
});

it('does not serve a stale map after invalidate()', function () {
    $cardiology = seedTerritory();
    expect(resolver()->membershipFor(['user:u1'])->has($cardiology))->toBeFalse();

    // Something granted membership and told the resolver.
    seedAssignment($cardiology, 'user', 'u1');
    resolver()->invalidate();

    expect(resolver()->membershipFor(['user:u1'])->has($cardiology))->toBeTrue();

    // And revoked it: the denial must be as prompt as the grant.
    DB::table('delegated_assignments')->delete();
    resolver()->invalidate();

    expect(resolver()->membershipFor(['user:u1'])->has($cardiology))->toBeFalse();
});

it('invalidates every cached copy, including on other processes, by bumping a version', function () {
    // Another process holds the map under the old version key. After a bump
    // here, its next read misses; nothing has to enumerate or scan keys.
    seedTerritory();
    resolver()->map();
    $before = resolver()->version();

    resolver()->invalidate();

    expect(resolver()->version())->toBe($before + 1)
        ->and(Cache::has('delegated.territories.map.v'.$before))->toBeTrue('the old copy is orphaned, not rewritten')
        ->and(Cache::has('delegated.territories.map.v'.($before + 1)))->toBeFalse('nothing is rebuilt until it is asked for');
});

it('would still miss a change it was not told about, once the TTL passes', function () {
    // The backstop for a row edited by hand. Not a substitute for
    // invalidate(): within the TTL the change is invisible, by design.
    $cardiology = seedTerritory();
    resolver()->map();

    seedAssignment($cardiology, 'user', 'u1');
    Blink::store('delegated')->flush();
    expect(resolver()->membershipFor(['user:u1'])->has($cardiology))->toBeFalse();

    $this->travel(config('delegated.cache.ttl') + 1)->seconds();
    Blink::store('delegated')->flush();
    expect(resolver()->membershipFor(['user:u1'])->has($cardiology))->toBeTrue();
});

it('honours a null TTL by keeping the map until the next change', function () {
    config(['delegated.cache.ttl' => null]);
    $this->app->forgetInstance(TerritoryResolver::class);

    $cardiology = seedTerritory();
    resolver()->map();
    seedAssignment($cardiology, 'user', 'u1');

    $this->travel(365)->days();
    Blink::store('delegated')->flush();

    expect(resolver()->membershipFor(['user:u1'])->has($cardiology))->toBeFalse();

    resolver()->invalidate();
    expect(resolver()->membershipFor(['user:u1'])->has($cardiology))->toBeTrue();
});

it('resolves with no territories at all as "nobody owns anything"', function () {
    expect(resolver()->map()->isEmpty())->toBeTrue()
        ->and(resolver()->owner(new ContentFacts('default', 'pages', '/anything')))->toBeNull()
        ->and(resolver()->membershipFor(['user:u1'])->isEmpty())->toBeTrue();
});

it('survives a cache that refuses to unserialize objects, as Laravel 13 does by default', function () {
    // The host's config/cache.php has `serializable_classes => false`, which
    // hands back __PHP_Incomplete_Class for any cached object. Testbench's
    // skeleton does not set it, and its array store never serialises at
    // all, so the suite never saw the resolver break on a real site until
    // it did. Run with the real setting on a store that really serialises.
    config([
        'cache.serializable_classes' => false,
        'cache.default' => 'file',
        'cache.stores.file' => ['driver' => 'file', 'path' => $this->fakeStacheDirectory.'/cache'],
    ]);
    Cache::forgetDriver('file');
    $this->app->forgetInstance(TerritoryResolver::class);
    Blink::store('delegated')->flush();

    $cardiology = seedTerritory();
    seedAssignment($cardiology, 'user', 'u1', 'admin');

    // First read builds and caches; a second request reads from the cache.
    resolver()->map();
    Blink::store('delegated')->flush();

    $map = resolver()->map();

    expect($map)->toBeInstanceOf(TerritoryMap::class)
        ->and($map->find($cardiology)?->scopeRef)->toBe('/cardiology')
        ->and($map->membershipFor(['user:u1'])->isAdminOf($cardiology))->toBeTrue()
        ->and(resolver()->owner(new ContentFacts('default', 'pages', '/cardiology/x'))?->id)->toBe($cardiology);
});

it('rebuilds rather than trusts a cached value of the wrong shape', function () {
    $cardiology = seedTerritory();
    resolver()->map();
    Blink::store('delegated')->flush();

    Cache::put('delegated.territories.map.v'.resolver()->version(), 'garbage', 3600);

    expect(resolver()->owner(new ContentFacts('default', 'pages', '/cardiology'))?->id)->toBe($cardiology);
});
