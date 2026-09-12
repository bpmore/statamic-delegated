<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Administration\EscalationDenied;
use Bpmore\StatamicDelegated\Administration\TerritoryAdministration;
use Bpmore\StatamicDelegated\Audit\AuditLog;
use Bpmore\StatamicDelegated\Storage\TerritoryStore;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Tests\Support\AbilityScoping;
use Bpmore\StatamicDelegated\Tests\Support\Content;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 * Phase 4.3: the audit log. Every write through the store or through
 * delegated administration leaves a row; every refusal under an invariant
 * leaves a row naming the invariant. Reading is scoped like everything
 * else: a territory admin sees their territories' rows and nobody's name
 * from outside them; a site administrator sees it all.
 */

beforeEach(function () {
    Content::build();
    Site::role('cardiology-editor', ['access cp', 'view pages entries']);
    Site::role('news-editor', ['access cp', 'view news entries']);
    Site::role('territory-manager', ['access cp', 'manage territories']);

    $this->cardiology = Site::territory('cardiology', '/cardiology');
    $this->oncology = Site::territory('oncology', '/oncology');

    Site::user('head', roles: ['cardiology-editor']);
    Site::assign($this->cardiology, 'user:head', 'admin');
    Site::user('staff', roles: ['cardiology-editor']);
    Site::assign($this->cardiology, 'user:staff');
    Site::user('onc-head', roles: ['news-editor']);
    Site::assign($this->oncology, 'user:onc-head', 'admin');
    Site::user('newbie');
    Site::user('manager', roles: ['territory-manager']);
    Site::user('root', super: true);

    // Seeding above is history we did not make; start each test clean.
    DB::table('delegated_audit')->delete();
});

/** @return list<array<string, mixed>> oldest first, for reading top to bottom */
function auditRows(): array
{
    return DB::table('delegated_audit')->orderBy('id')->get()
        ->map(fn (object $r): array => [
            'action' => $r->action,
            'actor' => $r->actor_id,
            'target' => "{$r->target_type}:{$r->target_id}",
            'territory' => $r->territory_id === null ? null : (int) $r->territory_id,
            'before' => $r->before === null ? null : json_decode($r->before, true),
            'after' => $r->after === null ? null : json_decode($r->after, true),
        ])->all();
}

function admin(): TerritoryAdministration
{
    return app(TerritoryAdministration::class);
}

function siteAudit(string $as): TestResponse
{
    return AbilityScoping::as($as)->getJson('/cp/delegated/audit');
}

function territoryAudit(string $as, int $territory): TestResponse
{
    return AbilityScoping::as($as)->getJson("/cp/delegated/my-territories/{$territory}/audit");
}

// ------------------------------------------------------------ what is written

it('records a territory\'s creation, change and deletion with before and after', function () {
    $store = app(TerritoryStore::class);

    $id = $store->createTerritory(['name' => 'Radiology', 'slug' => 'radiology', 'site' => 'default', 'scope_type' => 'structure_branch', 'scope_ref' => '/radiology'], 'root');
    $store->updateTerritory($id, ['name' => 'Radiology & Imaging', 'slug' => 'radiology'], 'root');
    $store->putAssignment($id, 'user', 'newbie', Level::Member, 'root');
    $store->deleteTerritory($id, 'root');

    $rows = auditRows();

    expect(array_column($rows, 'action'))->toBe(['territory.created', 'territory.updated', 'assignment.granted', 'territory.deleted'])
        ->and(array_unique(array_column($rows, 'actor')))->toBe(['root'])
        ->and(array_unique(array_column($rows, 'territory')))->toBe([$id])
        ->and($rows[0]['before'])->toBeNull()
        ->and($rows[0]['after']['name'])->toBe('Radiology')
        ->and($rows[0]['after']['scope_ref'])->toBe('/radiology')
        // Only what changed: the slug was sent unchanged and is not in the row.
        ->and($rows[1]['before'])->toBe(['name' => 'Radiology'])
        ->and($rows[1]['after'])->toBe(['name' => 'Radiology & Imaging'])
        ->and($rows[2]['target'])->toBe('user:newbie')
        ->and($rows[2]['after'])->toBe(['level' => 'member'])
        ->and($rows[3]['before']['name'])->toBe('Radiology & Imaging')
        ->and($rows[3]['before']['assignments'])->toBe(['user:newbie (member)'])
        ->and($rows[3]['after'])->toBeNull();
});

it('records an assignment\'s grant, change and revocation, and nothing for a no-op', function () {
    $store = app(TerritoryStore::class);

    $store->putAssignment($this->cardiology, 'user', 'newbie', Level::Member, 'head');
    $store->putAssignment($this->cardiology, 'user', 'newbie', Level::Member, 'head'); // same again
    $store->putAssignment($this->cardiology, 'user', 'newbie', Level::Admin, 'head');
    $store->deleteAssignment($this->cardiology, 'user', 'newbie', 'head');

    expect(auditRows())->toBe([
        ['action' => 'assignment.granted', 'actor' => 'head', 'target' => 'user:newbie', 'territory' => $this->cardiology, 'before' => null, 'after' => ['level' => 'member']],
        ['action' => 'assignment.changed', 'actor' => 'head', 'target' => 'user:newbie', 'territory' => $this->cardiology, 'before' => ['level' => 'member'], 'after' => ['level' => 'admin']],
        ['action' => 'assignment.revoked', 'actor' => 'head', 'target' => 'user:newbie', 'territory' => $this->cardiology, 'before' => ['level' => 'admin'], 'after' => null],
    ]);
});

it('records what delegated administration does to a user, in each territory it concerns', function () {
    $head = Site::actor('head');

    admin()->addMember($head, $this->cardiology, 'user:newbie', Level::Member, ['cardiology-editor']);
    admin()->setRoles($head, 'newbie', []);
    admin()->updateUser($head, 'newbie', ['name' => 'New B.']);
    admin()->removeMember($head, $this->cardiology, 'user:newbie');

    $rows = auditRows();

    expect(array_column($rows, 'action'))->toBe(['assignment.granted', 'user.roles_set', 'user.roles_set', 'user.updated', 'assignment.revoked'])
        ->and(array_unique(array_column($rows, 'actor')))->toBe(['head'])
        ->and(array_unique(array_column($rows, 'territory')))->toBe([$this->cardiology])
        ->and($rows[1]['before'])->toBe(['roles' => []])
        ->and($rows[1]['after'])->toBe(['roles' => ['cardiology-editor']])
        ->and($rows[2]['before'])->toBe(['roles' => ['cardiology-editor']])
        ->and($rows[2]['after'])->toBe(['roles' => []])
        ->and($rows[3]['before'])->toBe(['name' => null])
        ->and($rows[3]['after'])->toBe(['name' => 'New B.']);
});

it('records a user\'s deletion before the user is gone, then one revocation per territory', function () {
    admin()->deleteUser(Site::actor('root'), 'staff');

    $rows = auditRows();

    expect(array_column($rows, 'action'))->toBe(['user.deleted', 'assignment.revoked'])
        ->and($rows[0]['before']['email'])->toBe('staff@example.com')
        ->and($rows[0]['territory'])->toBe($this->cardiology)
        ->and($rows[1]['before'])->toBe(['level' => 'member', 'because' => 'user deleted'])
        ->and($rows[1]['actor'])->toBe('root');
});

it('records a refusal with the invariant that refused it, and writes nothing else', function () {
    $head = Site::actor('head');

    expect(fn () => admin()->addMember($head, $this->cardiology, 'user:newbie', Level::Member, ['news-editor']))
        ->toThrow(EscalationDenied::class);
    expect(fn () => admin()->addMember($head, $this->oncology, 'user:newbie', Level::Member))
        ->toThrow(EscalationDenied::class);
    expect(fn () => admin()->updateUser($head, 'onc-head', ['name' => 'X']))
        ->toThrow(EscalationDenied::class);
    expect(fn () => admin()->updateTerritory($head, $this->cardiology, ['scope_ref' => '/']))
        ->toThrow(EscalationDenied::class);

    $rows = auditRows();

    expect(array_column($rows, 'action'))->toBe(['refused.add_member', 'refused.add_member', 'refused.update_user', 'refused.update_territory'])
        ->and(array_column(array_column($rows, 'after'), 'invariant'))->toBe([
            EscalationDenied::ROLE_NOT_HELD,
            EscalationDenied::TERRITORY_NOT_ADMINISTERED,
            EscalationDenied::USER_NOT_VISIBLE,
            EscalationDenied::OWN_TERRITORY,
        ])
        ->and($rows[0]['target'])->toBe('user:newbie')
        ->and($rows[1]['territory'])->toBe($this->oncology)
        ->and($rows[3]['target'])->toBe("territory:{$this->cardiology}")
        ->and(Site::rolesOf('newbie'))->toBe([]);
});

it('is append-only: the log offers no way to change or remove a row', function () {
    $methods = collect((new ReflectionClass(AuditLog::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map->getName()->reject(fn (string $m): bool => str_starts_with($m, '__'))->values()->all();

    expect($methods)->toEqualCanonicalizing(['record', 'forTerritories', 'latest'])
        ->and(Schema::hasColumns('delegated_audit', ['actor_id', 'action', 'target_type', 'target_id', 'territory_id', 'before', 'after', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('delegated_audit', 'updated_at'))->toBeFalse();
});

// ------------------------------------------------------------ who may read what

it('shows the site-wide log only to a holder of manage territories', function () {
    app(TerritoryStore::class)->putAssignment($this->cardiology, 'user', 'newbie', Level::Member, 'root');

    expect(siteAudit('head')->status())->toBe(403)
        ->and(siteAudit('staff')->status())->toBe(403)
        ->and(siteAudit('manager')->status())->toBe(200)
        ->and(siteAudit('root')->status())->toBe(200)
        ->and(siteAudit('manager')->json('entries.0.action'))->toBe('assignment.granted');
});

it('shows a territory\'s log to its admins and super users, and to nobody else', function () {
    expect(territoryAudit('head', $this->cardiology)->status())->toBe(200)
        ->and(territoryAudit('root', $this->cardiology)->status())->toBe(200)
        ->and(territoryAudit('staff', $this->cardiology)->status())->toBe(403)
        ->and(territoryAudit('onc-head', $this->oncology)->status())->toBe(200)
        ->and(territoryAudit('onc-head', $this->cardiology)->status())->toBe(403)
        ->and(territoryAudit('manager', $this->cardiology)->status())->toBe(403)
        ->and(territoryAudit('head', $this->oncology)->status())->toBe(403)
        ->and(territoryAudit('head', 999)->status())->toBe(404);
});

it('confines a territory admin\'s log to their territory\'s rows, newest first', function () {
    $store = app(TerritoryStore::class);
    $store->putAssignment($this->oncology, 'user', 'newbie', Level::Member, 'onc-head');
    $store->putAssignment($this->cardiology, 'user', 'newbie', Level::Member, 'head');
    $store->deleteAssignment($this->cardiology, 'user', 'newbie', 'head');

    $entries = territoryAudit('head', $this->cardiology)->json('entries');

    expect(array_column($entries, 'action'))->toBe(['assignment.revoked', 'assignment.granted'])
        ->and(array_unique(array_column(array_column($entries, 'territory'), 'id')))->toBe([$this->cardiology])
        ->and($entries[0]['actor'])->toBe(['label' => 'you', 'id' => 'head'])
        // Removed from the territory, but they were in it when the row was written: named.
        ->and($entries[0]['target'])->toBe(['label' => 'newbie@example.com', 'id' => 'newbie'])
        ->and($entries[0]['territory']['name'])->toBe('Cardiology');
});

it('never shows a territory admin the name of an actor from outside their territory', function () {
    $store = app(TerritoryStore::class);
    // A site administrator and an admin of another territory both touch cardiology.
    $store->putAssignment($this->cardiology, 'user', 'newbie', Level::Member, 'manager');
    $store->updateTerritory($this->cardiology, ['name' => 'Cardiology (renamed)'], 'onc-head');
    $store->putAssignment($this->cardiology, 'user', 'staff', Level::Admin, null);

    $entries = territoryAudit('head', $this->cardiology)->json('entries');

    expect(array_column($entries, 'action'))->toBe(['assignment.changed', 'territory.updated', 'assignment.granted'])
        ->and($entries[0]['actor'])->toBe(['label' => 'the system', 'id' => null])
        ->and($entries[1]['actor'])->toBe(['label' => 'someone outside your territory', 'id' => null])
        ->and($entries[2]['actor'])->toBe(['label' => 'someone outside your territory', 'id' => null])
        ->and(json_encode($entries))->not->toContain('manager')->not->toContain('onc-head')
        // The same rows to a site administrator name everyone.
        ->and(siteAudit('manager')->json('entries.1.actor.label'))->toBe('onc-head@example.com')
        ->and(siteAudit('manager')->json('entries.2.actor.label'))->toBe('you');
});

it('names the target of a refusal no more readily than an actor', function () {
    // Oncology's head asks for cardiology's staff member: refused, and the
    // row belongs to cardiology. Cardiology's admin may see who was refused
    // (their own member), but not who asked.
    expect(fn () => admin()->addMember(Site::actor('onc-head'), $this->cardiology, 'user:staff', Level::Admin))->toThrow(EscalationDenied::class);
    // Cardiology's head reaches for oncology's: refused, no territory of its own.
    expect(fn () => admin()->updateUser(Site::actor('head'), 'onc-head', ['name' => 'X']))->toThrow(EscalationDenied::class);

    $entries = territoryAudit('head', $this->cardiology)->json('entries');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['action'])->toBe('refused.add_member')
        ->and($entries[0]['actor'])->toBe(['label' => 'someone outside your territory', 'id' => null])
        ->and($entries[0]['target'])->toBe(['label' => 'staff@example.com', 'id' => 'staff'])
        ->and($entries[0]['after']['invariant'])->toBe(EscalationDenied::TERRITORY_NOT_ADMINISTERED);

    // Oncology's head sees neither row: one is cardiology's, the other nobody's.
    expect(territoryAudit('onc-head', $this->oncology)->json('entries'))->toBe([]);

    // The site-wide log has both, and names everyone.
    $all = siteAudit('manager')->json('entries');
    expect(array_column($all, 'action'))->toBe(['refused.update_user', 'refused.add_member'])
        ->and($all[0]['territory'])->toBeNull()
        ->and($all[0]['actor']['label'])->toBe('head@example.com')
        ->and($all[0]['target']['label'])->toBe('onc-head@example.com')
        ->and($all[1]['actor']['label'])->toBe('onc-head@example.com');
});
