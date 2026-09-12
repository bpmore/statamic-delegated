<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Administration\EscalationDenied;
use Bpmore\StatamicDelegated\Administration\TerritoryAdministration;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Invariant 5 (spec §4): a territory admin cannot widen their own territory.
 *
 * The escalation this closes: the head of cardiology edits their territory's
 * scope from /cardiology to /, or to the whole pages collection, or makes
 * oncology a child of cardiology so that administration flows down into it.
 * Rather than defining "wider" — and getting the definition wrong once — the
 * scope of a territory (type, reference, parent, site) is immutable to
 * anyone who administers it, and to anyone who administers an ancestor,
 * since a widened child widens what flows down to them. An admin may rename
 * a territory. Nothing else.
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
});

function territoryRow(int $id): object
{
    return DB::table('delegated_territories')->find($id);
}

function scopeOf(int $id): array
{
    $row = territoryRow($id);

    return [
        'site' => $row->site,
        'scope_type' => $row->scope_type,
        'scope_ref' => $row->scope_ref,
        'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
    ];
}

it('lets an admin rename their territory, so the refusals below are about scope', function () {
    app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, ['name' => 'Cardiology & Vascular']);

    expect(territoryRow($this->cardiology)->name)->toBe('Cardiology & Vascular')
        ->and(scopeOf($this->cardiology))->toBe(['site' => 'default', 'scope_type' => 'structure_branch', 'scope_ref' => '/cardiology', 'parent_id' => $this->root]);
});

it('refuses to widen the branch to the root, and changes nothing', function () {
    $before = scopeOf($this->cardiology);
    $attempt = fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, ['scope_ref' => '/']);

    expect($attempt)->toThrow(EscalationDenied::class);

    try {
        $attempt();
    } catch (EscalationDenied $e) {
        expect($e->invariant)->toBe(EscalationDenied::OWN_TERRITORY);
    }

    expect(scopeOf($this->cardiology))->toBe($before);
});

it('refuses to move the branch sideways', function () {
    // Not wider, but not theirs: /oncology's content is exactly what the
    // territory model exists to keep from them.
    $before = scopeOf($this->cardiology);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, ['scope_ref' => '/oncology']))
        ->toThrow(EscalationDenied::class);

    expect(scopeOf($this->cardiology))->toBe($before);
});

it('refuses even to narrow the branch', function () {
    // Narrowing looks harmless. It is refused because "scope is immutable to
    // its admin" has no edge cases, and "scope may only shrink" has several:
    // the admin cannot undo it, and a narrowed parent under a wide child is
    // a shape nobody meant.
    $before = scopeOf($this->cardiology);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, ['scope_ref' => '/cardiology/trials']))
        ->toThrow(EscalationDenied::class);

    expect(scopeOf($this->cardiology))->toBe($before);
});

it('refuses to change the kind of scope', function () {
    // A branch becoming a collection is the whole collection.
    $before = scopeOf($this->cardiology);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, ['scope_type' => 'collection', 'scope_ref' => 'pages']))
        ->toThrow(EscalationDenied::class);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, ['scope_type' => 'collection']))
        ->toThrow(EscalationDenied::class);

    expect(scopeOf($this->cardiology))->toBe($before);
});

it('refuses to re-parent their own territory', function () {
    $before = scopeOf($this->cardiology);

    foreach ([['parent_id' => null], ['parent_id' => $this->oncology]] as $changes) {
        expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, $changes))
            ->toThrow(EscalationDenied::class);
    }

    expect(scopeOf($this->cardiology))->toBe($before);
});

it('refuses to make another territory a child of theirs', function () {
    // Administration flows down (Phase 1.4). Parenting oncology under
    // cardiology would make the head of cardiology oncology's admin.
    $before = scopeOf($this->oncology);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->oncology, ['parent_id' => $this->cardiology]))
        ->toThrow(EscalationDenied::class);

    expect(scopeOf($this->oncology))->toBe($before);
});

it('refuses to widen a child territory they administer', function () {
    // Widening /cardiology/trials to / widens what flows down to its
    // admin, which is the head of cardiology.
    $before = scopeOf($this->trials);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->trials, ['scope_ref' => '/']))
        ->toThrow(EscalationDenied::class);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->trials, ['parent_id' => $this->root]))
        ->toThrow(EscalationDenied::class);

    expect(scopeOf($this->trials))->toBe($before);
});

it('refuses to move the territory to another site', function () {
    $before = scopeOf($this->cardiology);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, ['site' => 'french']))
        ->toThrow(EscalationDenied::class);

    expect(scopeOf($this->cardiology))->toBe($before);
});

it('refuses the whole request when a rename is mixed with a scope change', function () {
    $before = scopeOf($this->cardiology);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, ['name' => 'Everything', 'scope_ref' => '/']))
        ->toThrow(EscalationDenied::class);

    expect(territoryRow($this->cardiology)->name)->toBe('Cardiology')
        ->and(scopeOf($this->cardiology))->toBe($before);
});

it('refuses a change it does not recognise rather than treating it as harmless', function () {
    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, ['id' => $this->root]))
        ->toThrow(EscalationDenied::class);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->cardiology, ['something_new' => 'x']))
        ->toThrow(EscalationDenied::class);

    expect(territoryRow($this->cardiology)->id)->toBe($this->cardiology);
});

it('refuses a member who is not an admin, even a rename', function () {
    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('staff'), $this->cardiology, ['name' => 'Mine Now']))
        ->toThrow(EscalationDenied::class);

    expect(territoryRow($this->cardiology)->name)->toBe('Cardiology');
});

it('refuses a territory the admin does not administer, even a rename', function () {
    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->oncology, ['name' => 'Cardiology Annex']))
        ->toThrow(EscalationDenied::class);

    expect(fn () => app(TerritoryAdministration::class)->updateTerritory(Site::actor('head'), $this->root, ['name' => 'Cardiology']))
        ->toThrow(EscalationDenied::class);

    expect(territoryRow($this->oncology)->name)->toBe('Oncology')
        ->and(territoryRow($this->root)->name)->toBe('Site');
});

it('does not apply to a super user', function () {
    Site::user('root', super: true);

    // The whole collection: wider than the branch, and not a scope another
    // territory already owns (the unique index would refuse that, rightly).
    app(TerritoryAdministration::class)->updateTerritory(Site::actor('root'), $this->cardiology, ['scope_type' => 'collection', 'scope_ref' => 'Pages', 'parent_id' => null]);

    expect(scopeOf($this->cardiology))->toBe(['site' => 'default', 'scope_type' => 'collection', 'scope_ref' => 'pages', 'parent_id' => null]);
});
