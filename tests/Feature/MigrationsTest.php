<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The column lists of every index on a table, primary key included, so a test
 * can say "these columns are indexed" without caring what the index is called.
 *
 * @return list<list<string>>
 */
function indexedColumns(string $table): array
{
    return array_values(array_map(
        fn (array $index): array => $index['columns'],
        Schema::getIndexes($table),
    ));
}

/**
 * The column lists of every unique index on a table.
 *
 * @return list<list<string>>
 */
function uniqueColumns(string $table): array
{
    return array_values(array_map(
        fn (array $index): array => $index['columns'],
        array_filter(Schema::getIndexes($table), fn (array $index): bool => $index['unique'] && ! $index['primary']),
    ));
}

function insertTerritory(array $overrides = []): int
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

function insertAssignment(int $territoryId, array $overrides = []): int
{
    return (int) DB::table('delegated_assignments')->insertGetId(array_merge([
        'territory_id' => $territoryId,
        'subject_type' => 'group',
        'subject_id' => 'cardiology-editors',
        'level' => 'member',
        'granted_by' => 'user-1',
        'granted_at' => now(),
    ], $overrides));
}

it('creates the three tables spec §6 names', function () {
    expect(Schema::hasTable('delegated_territories'))->toBeTrue()
        ->and(Schema::hasTable('delegated_assignments'))->toBeTrue()
        ->and(Schema::hasTable('delegated_audit'))->toBeTrue();
});

it('gives each table the columns spec §6 names', function () {
    expect(Schema::getColumnListing('delegated_territories'))->toEqualCanonicalizing([
        'id', 'name', 'slug', 'site', 'scope_type', 'scope_ref', 'parent_id', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('delegated_assignments'))->toEqualCanonicalizing([
        'id', 'territory_id', 'subject_type', 'subject_id', 'level', 'granted_by', 'granted_at',
    ])->and(Schema::getColumnListing('delegated_audit'))->toEqualCanonicalizing([
        'id', 'actor_id', 'action', 'target_type', 'target_id', 'territory_id', 'before', 'after', 'created_at',
    ]);
});

it('allows one territory per scope per site, and one slug per site', function () {
    expect(uniqueColumns('delegated_territories'))
        ->toContain(['site', 'slug'])
        ->toContain(['site', 'scope_type', 'scope_ref']);

    insertTerritory();

    // Same branch on another site is fine.
    insertTerritory(['site' => 'french']);

    // Same branch on the same site under another name is the ambiguity the
    // index exists to prevent.
    expect(fn () => insertTerritory(['slug' => 'heart', 'name' => 'Heart']))
        ->toThrow(QueryException::class);

    // And the same slug pointing somewhere else.
    expect(fn () => insertTerritory(['scope_ref' => '/heart']))
        ->toThrow(QueryException::class);
});

it('assigns a subject to a territory at most once (spec §6 unique)', function () {
    expect(uniqueColumns('delegated_assignments'))
        ->toContain(['territory_id', 'subject_type', 'subject_id']);

    $territory = insertTerritory();
    insertAssignment($territory);

    expect(fn () => insertAssignment($territory, ['level' => 'admin']))
        ->toThrow(QueryException::class);

    // A user with the same id as a group is a different subject.
    insertAssignment($territory, ['subject_type' => 'user']);

    expect(DB::table('delegated_assignments')->count())->toBe(2);
});

it('indexes the hot path: which territories a subject is in', function () {
    // Spec §6: resolution runs per row on every listing render.
    expect(indexedColumns('delegated_assignments'))->toContain(['subject_type', 'subject_id']);
});

it('deletes assignments with their territory', function () {
    $territory = insertTerritory();
    insertAssignment($territory);
    insertAssignment($territory, ['subject_id' => 'cardiology-admins', 'level' => 'admin']);

    DB::table('delegated_territories')->where('id', $territory)->delete();

    expect(DB::table('delegated_assignments')->count())->toBe(0);
});

it('makes a child territory a root when its parent is deleted', function () {
    $parent = insertTerritory();
    $child = insertTerritory([
        'name' => 'Clinical Trials', 'slug' => 'clinical-trials',
        'scope_ref' => '/cardiology/clinical-trials', 'parent_id' => $parent,
    ]);

    DB::table('delegated_territories')->where('id', $parent)->delete();

    $row = DB::table('delegated_territories')->find($child);

    expect($row)->not->toBeNull('the child territory was deleted with its parent')
        ->and($row->parent_id)->toBeNull();
});

it('rejects a parent that is not a territory', function () {
    expect(fn () => insertTerritory(['parent_id' => 999]))
        ->toThrow(QueryException::class);
});

it('keeps an audit row after the territory it describes is gone', function () {
    // Append-only: the audit table has no foreign key to cascade, and no
    // nullOnDelete to rewrite a row that is supposed to be immutable.
    $territory = insertTerritory();

    DB::table('delegated_audit')->insert([
        'actor_id' => 'user-1',
        'action' => 'territory.deleted',
        'target_type' => 'territory',
        'target_id' => (string) $territory,
        'territory_id' => $territory,
        'before' => json_encode(['slug' => 'cardiology']),
        'after' => null,
        'created_at' => now(),
    ]);

    DB::table('delegated_territories')->where('id', $territory)->delete();

    $row = DB::table('delegated_audit')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->territory_id)->toBe($territory)
        ->and(json_decode($row->before, true))->toBe(['slug' => 'cardiology']);
});

it('has no updated_at on the audit table, because nothing updates it', function () {
    expect(Schema::hasColumn('delegated_audit', 'updated_at'))->toBeFalse();
});

it('indexes the audit log for the per-territory view and by target', function () {
    $indexes = indexedColumns('delegated_audit');

    expect($indexes)->toContain(['territory_id', 'created_at'])
        ->and($indexes)->toContain(['target_type', 'target_id'])
        ->and($indexes)->toContain(['actor_id']);
});

it('lets the audit log record an actor-less change and a change with no before or after', function () {
    $columns = collect(Schema::getColumns('delegated_audit'))->keyBy('name');

    foreach (['actor_id', 'target_id', 'territory_id', 'before', 'after'] as $nullable) {
        expect($columns[$nullable]['nullable'])->toBeTrue("$nullable should be nullable");
    }
});

it('lets an assignment outlive the user who granted it', function () {
    $columns = collect(Schema::getColumns('delegated_assignments'))->keyBy('name');

    expect($columns['granted_by']['nullable'])->toBeTrue();
});

it('rolls back cleanly', function () {
    $this->artisan('migrate:rollback')->assertSuccessful();

    expect(Schema::hasTable('delegated_territories'))->toBeFalse()
        ->and(Schema::hasTable('delegated_assignments'))->toBeFalse()
        ->and(Schema::hasTable('delegated_audit'))->toBeFalse();
});
