<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Storage;

use Bpmore\StatamicDelegated\Audit\AuditLog;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Territories\Normalise;
use Bpmore\StatamicDelegated\Territories\ScopeType;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * The only place delegated_territories and delegated_assignments are
 * written. Every write invalidates the resolver, so nothing that reads
 * through it can be stale by so much as one request — and every write is
 * recorded in the audit log with what it replaced, so being the only
 * writer is what makes the log complete.
 */
final class TerritoryStore
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly TerritoryResolver $resolver,
        private readonly AuditLog $audit,
        private readonly ?string $connection = null,
    ) {}

    /**
     * @param  array<string, mixed>  $columns  name, slug, site, scope_type, scope_ref, parent_id
     * @return int the new territory's id
     */
    public function createTerritory(array $columns, ?string $actorId = null): int
    {
        $columns['scope_ref'] = Normalise::ref(ScopeType::from((string) $columns['scope_type']), (string) $columns['scope_ref']);

        $id = (int) $this->table('delegated_territories')->insertGetId($columns + ['created_at' => now(), 'updated_at' => now()]);

        $this->audit->record('territory.created', $actorId, 'territory', (string) $id, $id, null, $this->territoryRow($id));
        $this->resolver->invalidate();

        return $id;
    }

    /**
     * Assignments go with it (cascade); children become roots (null on
     * delete). The audit log keeps the record — including who was in it.
     */
    public function deleteTerritory(int $id, ?string $actorId = null): void
    {
        $before = $this->territoryRow($id);

        if ($before === null) {
            return;
        }

        $before['assignments'] = $this->table('delegated_assignments')->where('territory_id', $id)->get()
            ->map(fn (object $a): string => "{$a->subject_type}:{$a->subject_id} ({$a->level})")->values()->all();

        $this->table('delegated_territories')->where('id', $id)->delete();

        $this->audit->record('territory.deleted', $actorId, 'territory', (string) $id, $id, $before, null);
        $this->resolver->invalidate();
    }

    /**
     * @param  array<string, mixed>  $columns  name, slug, site, scope_type, scope_ref, parent_id
     */
    public function updateTerritory(int $id, array $columns, ?string $actorId = null): void
    {
        if (isset($columns['scope_ref'])) {
            $type = ScopeType::from((string) ($columns['scope_type'] ?? $this->table('delegated_territories')->where('id', $id)->value('scope_type')));
            $columns['scope_ref'] = Normalise::ref($type, (string) $columns['scope_ref']);
        }

        $before = $this->territoryRow($id);

        $this->table('delegated_territories')->where('id', $id)->update($columns + ['updated_at' => now()]);

        $after = $this->territoryRow($id);
        $changed = array_keys(array_filter($after ?? [], fn ($value, string $key): bool => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $this->audit->record('territory.updated', $actorId, 'territory', (string) $id, $id,
                array_intersect_key($before ?? [], array_flip($changed)), array_intersect_key($after ?? [], array_flip($changed)));
        }

        $this->resolver->invalidate();
    }

    public function putAssignment(int $territoryId, string $subjectType, string $subjectId, Level $level, ?string $grantedBy): void
    {
        $key = ['territory_id' => $territoryId, 'subject_type' => $subjectType, 'subject_id' => $subjectId];
        $existing = $this->table('delegated_assignments')->where($key)->value('level');

        $this->table('delegated_assignments')->updateOrInsert($key, ['level' => $level->value, 'granted_by' => $grantedBy, 'granted_at' => now()]);

        if ($existing !== $level->value) {
            $this->audit->record(
                $existing === null ? 'assignment.granted' : 'assignment.changed',
                $grantedBy, $subjectType, $subjectId, $territoryId,
                $existing === null ? null : ['level' => $existing],
                ['level' => $level->value],
            );
        }

        $this->resolver->invalidate();
    }

    public function deleteAssignment(int $territoryId, string $subjectType, string $subjectId, ?string $actorId = null): void
    {
        $key = ['territory_id' => $territoryId, 'subject_type' => $subjectType, 'subject_id' => $subjectId];
        $existing = $this->table('delegated_assignments')->where($key)->value('level');

        if ($existing === null) {
            return;
        }

        $this->table('delegated_assignments')->where($key)->delete();

        $this->audit->record('assignment.revoked', $actorId, $subjectType, $subjectId, $territoryId, ['level' => $existing], null);
        $this->resolver->invalidate();
    }

    /** When a user is deleted, so is every assignment that named them. */
    public function deleteAssignmentsForSubject(string $subjectType, string $subjectId, ?string $actorId = null): void
    {
        foreach ($this->table('delegated_assignments')->where(['subject_type' => $subjectType, 'subject_id' => $subjectId])->get() as $row) {
            $this->audit->record('assignment.revoked', $actorId, $subjectType, $subjectId, (int) $row->territory_id, ['level' => $row->level, 'because' => 'user deleted'], null);
        }

        $this->table('delegated_assignments')
            ->where(['subject_type' => $subjectType, 'subject_id' => $subjectId])
            ->delete();

        $this->resolver->invalidate();
    }

    /** @return array<string, mixed>|null */
    private function territoryRow(int $id): ?array
    {
        $row = $this->table('delegated_territories')->find($id);

        return $row === null ? null : [
            'name' => $row->name, 'slug' => $row->slug, 'site' => $row->site,
            'scope_type' => $row->scope_type, 'scope_ref' => $row->scope_ref,
            'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
        ];
    }

    private function table(string $name): Builder
    {
        return $this->db->connection($this->connection)->table($name);
    }
}
