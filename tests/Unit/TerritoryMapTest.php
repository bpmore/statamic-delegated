<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Territories\Assignment;
use Bpmore\StatamicDelegated\Territories\ContentFacts;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Territories\ScopeType;
use Bpmore\StatamicDelegated\Territories\Territory;
use Bpmore\StatamicDelegated\Territories\TerritoryMap;

// No booted application: these are plain objects, and the map is the hot
// path (spec §6), so it has to be testable in milliseconds.

function territory(int $id, ScopeType $type, string $ref, ?int $parent = null, string $site = 'default'): Territory
{
    return new Territory($id, "T{$id}", "t{$id}", $site, $type, $ref, $parent);
}

function branch(int $id, string $uri, ?int $parent = null, string $site = 'default'): Territory
{
    return territory($id, ScopeType::StructureBranch, $uri, $parent, $site);
}

function page(string $uri, string $collection = 'pages', array $terms = [], string $site = 'default'): ContentFacts
{
    return new ContentFacts($site, $collection, $uri, $terms);
}

function ids(array $territories): array
{
    return array_map(fn (Territory $t): int => $t->id, $territories);
}

// ------------------------------------------------------------ branches

it('contains the branch itself and everything below it', function () {
    $map = new TerritoryMap([branch(1, '/cardiology')]);

    expect(ids($map->containing(page('/cardiology'))))->toBe([1])
        ->and(ids($map->containing(page('/cardiology/trials'))))->toBe([1])
        ->and(ids($map->containing(page('/cardiology/trials/phase-2'))))->toBe([1]);
});

it('does not contain a sibling that shares the prefix', function () {
    // /cardiology must not cover /cardiology-alumni. The Phase 0.3 spike
    // planted exactly this entry to catch a naive LIKE 'prefix%'.
    $map = new TerritoryMap([branch(1, '/cardiology')]);

    expect($map->containing(page('/cardiology-alumni')))->toBe([])
        ->and($map->containing(page('/cardiologyx/anything')))->toBe([])
        ->and($map->owner(page('/cardiology-alumni')))->toBeNull();
});

it('does not contain the parent of the branch, or an unrelated branch', function () {
    $map = new TerritoryMap([branch(1, '/cardiology/trials')]);

    expect($map->containing(page('/cardiology')))->toBe([])
        ->and($map->containing(page('/oncology/trials')))->toBe([])
        ->and($map->containing(page('/')))->toBe([]);
});

it('gives an orphan entry, with no URI, no territory at all', function () {
    // Not in the tree means under no branch — it belongs to nobody, never to
    // everybody. This is the property that closes the duplicate-entry
    // exfiltration found in Phase 0.1.
    $map = new TerritoryMap([branch(1, '/cardiology'), branch(2, '/')]);

    $orphan = new ContentFacts('default', 'pages', null);

    expect($map->containing($orphan))->toBe([])
        ->and($map->owner($orphan))->toBeNull();
});

it('treats a root branch as covering every entry that has a URI', function () {
    $map = new TerritoryMap([branch(1, '/')]);

    expect(ids($map->containing(page('/'))))->toBe([1])
        ->and(ids($map->containing(page('/anything/at/all'))))->toBe([1]);
});

it('compares URIs case-insensitively and ignores trailing slashes', function () {
    $map = new TerritoryMap([branch(1, '/Cardiology/')]);

    expect(ids($map->containing(page('/cardiology'))))->toBe([1])
        ->and(ids($map->containing(page('/CARDIOLOGY/Trials/'))))->toBe([1]);
});

it('walks up to the deepest matching branch: the most specific territory owns the content', function () {
    $map = new TerritoryMap([
        branch(1, '/'),
        branch(2, '/cardiology'),
        branch(3, '/cardiology/trials'),
        branch(4, '/oncology'),
    ]);

    expect(ids($map->containing(page('/cardiology/trials/phase-2'))))->toBe([3, 2, 1])
        ->and($map->owner(page('/cardiology/trials/phase-2'))->id)->toBe(3)
        ->and($map->owner(page('/cardiology/clinical'))->id)->toBe(2)
        ->and($map->owner(page('/finance'))->id)->toBe(1)
        ->and(ids($map->containing(page('/oncology/chemo'))))->toBe([4, 1]);
});

// ------------------------------------------------------------ collections and terms

it('contains content by collection, and nothing from another collection', function () {
    $map = new TerritoryMap([territory(1, ScopeType::Collection, 'news')]);

    expect(ids($map->containing(page('/news/x', 'news'))))->toBe([1])
        ->and(ids($map->containing(new ContentFacts('default', 'news', null))))->toBe([1])
        ->and($map->containing(page('/news/x', 'pages')))->toBe([]);
});

it('contains content by taxonomy term, and nothing untagged or tagged otherwise', function () {
    $map = new TerritoryMap([territory(1, ScopeType::TaxonomyTerm, 'departments::oncology')]);

    expect(ids($map->containing(page('/a', 'news', ['departments::oncology']))))->toBe([1])
        ->and(ids($map->containing(page('/b', 'news', ['departments::cardiology', 'departments::oncology']))))->toBe([1])
        ->and($map->containing(page('/c', 'news', ['departments::cardiology'])))->toBe([])
        ->and($map->containing(page('/d', 'news', [])))->toBe([])
        // A term with the same slug in another taxonomy is a different term.
        ->and($map->containing(page('/e', 'news', ['topics::oncology'])))->toBe([]);
});

it('orders branch before term before collection when all three contain the content', function () {
    // The tree is where content lives; a term is a field an editor could
    // change to hand content to another department; a collection is coarsest.
    $map = new TerritoryMap([
        territory(1, ScopeType::Collection, 'pages'),
        territory(2, ScopeType::TaxonomyTerm, 'departments::oncology'),
        branch(3, '/cardiology'),
    ]);

    $facts = page('/cardiology/x', 'pages', ['departments::oncology']);

    expect(ids($map->containing($facts)))->toBe([3, 2, 1])
        ->and($map->owner($facts)->id)->toBe(3);
});

it('never reaches across sites', function () {
    $map = new TerritoryMap([
        branch(1, '/cardiology', site: 'default'),
        territory(2, ScopeType::Collection, 'pages', site: 'default'),
        territory(3, ScopeType::TaxonomyTerm, 'departments::cardiology', site: 'default'),
    ]);

    $french = page('/cardiology/x', 'pages', ['departments::cardiology'], site: 'french');

    expect($map->containing($french))->toBe([])
        ->and($map->owner($french))->toBeNull();
});

// ------------------------------------------------------------ hierarchy

it('walks ancestors nearest first and lists descendants', function () {
    $map = new TerritoryMap([
        branch(1, '/'),
        branch(2, '/cardiology', parent: 1),
        branch(3, '/cardiology/trials', parent: 2),
        branch(4, '/cardiology/clinics', parent: 2),
        branch(5, '/oncology', parent: 1),
    ]);

    expect(ids($map->ancestors($map->find(3))))->toBe([2, 1])
        ->and(ids($map->ancestors($map->find(1))))->toBe([])
        ->and(ids($map->descendants($map->find(2))))->toEqualCanonicalizing([3, 4])
        ->and(ids($map->descendants($map->find(1))))->toEqualCanonicalizing([2, 3, 4, 5])
        ->and($map->descendants($map->find(5)))->toBe([]);
});

it('treats a dangling parent id as no parent', function () {
    $map = new TerritoryMap([branch(1, '/cardiology', parent: 999)]);

    expect($map->ancestors($map->find(1)))->toBe([]);
});

it('survives a cycle in the parent chain', function () {
    $map = new TerritoryMap([
        branch(1, '/a', parent: 2),
        branch(2, '/b', parent: 1),
    ]);

    expect(ids($map->ancestors($map->find(1))))->toBe([2])
        ->and(ids($map->descendants($map->find(1))))->toBe([2]);
});

// ------------------------------------------------------------ membership

it('gives a subject with no assignment no membership at all', function () {
    $map = new TerritoryMap([branch(1, '/cardiology')], [
        new Assignment(1, 'group:cardiology-editors', Level::Member),
    ]);

    $membership = $map->membershipFor(['user:u1', 'group:oncology-editors']);

    expect($membership->isEmpty())->toBeTrue()
        ->and($membership->has(1))->toBeFalse()
        ->and($membership->isAdminOf(1))->toBeFalse()
        ->and($membership->coversAny($map->containing(page('/cardiology/x'))))->toBeFalse();
});

it('grants membership through a group and through a direct user assignment', function () {
    $map = new TerritoryMap([branch(1, '/cardiology'), branch(2, '/oncology')], [
        new Assignment(1, 'group:cardiology-editors', Level::Member),
        new Assignment(2, 'user:u1', Level::Member),
    ]);

    $viaGroup = $map->membershipFor(['user:u9', 'group:cardiology-editors']);
    $direct = $map->membershipFor(['user:u1']);

    expect($viaGroup->has(1))->toBeTrue()
        ->and($viaGroup->has(2))->toBeFalse()
        ->and($direct->has(2))->toBeTrue()
        ->and($direct->has(1))->toBeFalse();
});

it('is a union across subjects: a user in two departments is in both (spec §11)', function () {
    $map = new TerritoryMap([branch(1, '/cardiology'), branch(2, '/oncology'), branch(3, '/finance')], [
        new Assignment(1, 'group:cardiology-editors', Level::Member),
        new Assignment(2, 'group:oncology-editors', Level::Member),
        new Assignment(3, 'group:finance', Level::Member),
    ]);

    $membership = $map->membershipFor(['user:u1', 'group:cardiology-editors', 'group:oncology-editors']);

    expect($membership->territoryIds())->toEqualCanonicalizing([1, 2])
        ->and($membership->has(3))->toBeFalse();
});

it('flows membership down the hierarchy, never up', function () {
    $map = new TerritoryMap([
        branch(1, '/cardiology'),
        branch(2, '/cardiology/trials', parent: 1),
        branch(3, '/cardiology/trials/phase-2', parent: 2),
    ], [
        new Assignment(1, 'group:cardiology', Level::Member),
        new Assignment(2, 'group:trials', Level::Member),
    ]);

    $cardiology = $map->membershipFor(['group:cardiology']);
    $trials = $map->membershipFor(['group:trials']);

    expect($cardiology->territoryIds())->toEqualCanonicalizing([1, 2, 3])
        ->and($trials->territoryIds())->toEqualCanonicalizing([2, 3])
        ->and($trials->has(1))->toBeFalse();
});

it('makes a territory admin the admin of its sub-units, and takes the higher level where two routes meet', function () {
    $map = new TerritoryMap([
        branch(1, '/cardiology'),
        branch(2, '/cardiology/trials', parent: 1),
    ], [
        new Assignment(1, 'group:heads', Level::Admin),
        new Assignment(1, 'group:staff', Level::Member),
        new Assignment(2, 'user:u1', Level::Admin),
    ]);

    $head = $map->membershipFor(['group:heads']);
    $staff = $map->membershipFor(['group:staff']);
    $trialsAdminWhoIsAlsoStaff = $map->membershipFor(['user:u1', 'group:staff']);

    expect($head->isAdminOf(1))->toBeTrue()
        ->and($head->isAdminOf(2))->toBeTrue()
        ->and($head->administeredIds())->toEqualCanonicalizing([1, 2])
        ->and($staff->isAdminOf(1))->toBeFalse()
        ->and($staff->isAdminOf(2))->toBeFalse()
        ->and($staff->levelIn(2))->toBe(Level::Member)
        // Member of the parent, admin of the child: admin of the child only.
        ->and($trialsAdminWhoIsAlsoStaff->levelIn(1))->toBe(Level::Member)
        ->and($trialsAdminWhoIsAlsoStaff->levelIn(2))->toBe(Level::Admin)
        ->and($trialsAdminWhoIsAlsoStaff->administeredIds())->toBe([2]);
});

it('ignores an assignment to a territory that does not exist', function () {
    $map = new TerritoryMap([branch(1, '/cardiology')], [
        new Assignment(999, 'user:u1', Level::Admin),
    ]);

    expect($map->membershipFor(['user:u1'])->isEmpty())->toBeTrue();
});

it('answers the question the policy layer asks: does the user reach this content', function () {
    $map = new TerritoryMap([
        branch(1, '/cardiology'),
        branch(2, '/oncology'),
        territory(3, ScopeType::Collection, 'news'),
    ], [
        new Assignment(1, 'group:cardiology', Level::Member),
        new Assignment(3, 'group:cardiology', Level::Member),
    ]);

    $membership = $map->membershipFor(['group:cardiology']);

    expect($membership->coversAny($map->containing(page('/cardiology/trials'))))->toBeTrue()
        ->and($membership->coversAny($map->containing(page('/news/x', 'news'))))->toBeTrue()
        ->and($membership->coversAny($map->containing(page('/oncology/chemo'))))->toBeFalse()
        ->and($membership->coversAny($map->containing(page('/finance'))))->toBeFalse()
        ->and($membership->coversAny($map->containing(new ContentFacts('default', 'pages', null))))->toBeFalse();
});

// ------------------------------------------------------------ administration views

it('lists a subject\'s assignments as written, without inheritance', function () {
    $map = new TerritoryMap([
        branch(1, '/cardiology'),
        branch(2, '/cardiology/trials', parent: 1),
    ], [
        new Assignment(1, 'group:cardiology', Level::Admin),
        new Assignment(2, 'user:u1', Level::Member),
        new Assignment(999, 'user:u1', Level::Admin),
    ]);

    $written = $map->assignmentsFor(['user:u1', 'group:cardiology', 'user:nobody']);

    expect(array_map(fn (Assignment $a): string => "{$a->territoryId}:{$a->subject}:{$a->level->value}", $written))
        ->toEqualCanonicalizing(['2:user:u1:member', '1:group:cardiology:admin'])
        // Membership would say the group is in 2 as well. Assignments do not.
        ->and($map->membershipFor(['group:cardiology'])->has(2))->toBeTrue();
});

it('lists the subjects assigned to given territories, as written', function () {
    $map = new TerritoryMap([
        branch(1, '/cardiology'),
        branch(2, '/cardiology/trials', parent: 1),
        branch(3, '/oncology'),
    ], [
        new Assignment(1, 'group:cardiology', Level::Admin),
        new Assignment(1, 'user:head', Level::Admin),
        new Assignment(2, 'user:trials-only', Level::Member),
        new Assignment(3, 'user:onc', Level::Member),
        new Assignment(1, 'user:both', Level::Member),
        new Assignment(3, 'user:both', Level::Member),
    ]);

    expect($map->subjectsIn([1, 2]))->toEqualCanonicalizing(['group:cardiology', 'user:head', 'user:trials-only', 'user:both'])
        ->and($map->subjectsIn([2]))->toBe(['user:trials-only'])
        ->and($map->subjectsIn([]))->toBe([])
        ->and($map->subjectsIn([999]))->toBe([]);
});
