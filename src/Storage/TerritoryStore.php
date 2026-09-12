<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Storage;

use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Territories\Normalise;
use Bpmore\StatamicDelegated\Territories\ScopeType;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * The only place delegated_territories and delegated_assignments are
 * written. Every write invalidates the resolver, so nothing that reads
 * through it can be stale by so much as one request.
 */
final class TerritoryStore
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly TerritoryResolver $resolver,
        private readonly ?string $connection = null,
    ) {}

    /**
     * @param  array<string, mixed>  $columns  name, slug, site, scope_type, scope_ref, parent_id
     */
    public function updateTerritory(int $id, array $columns): void
    {
        if (isset($columns['scope_ref'])) {
            $type = ScopeType::from((string) ($columns['scope_type'] ?? $this->table('delegated_territories')->where('id', $id)->value('scope_type')));
            $columns['scope_ref'] = Normalise::ref($type, (string) $columns['scope_ref']);
        }

        $this->table('delegated_territories')->where('id', $id)->update($columns + ['updated_at' => now()]);

        $this->resolver->invalidate();
    }

    public function putAssignment(int $territoryId, string $subjectType, string $subjectId, Level $level, ?string $grantedBy): void
    {
        $this->table('delegated_assignments')->updateOrInsert(
            ['territory_id' => $territoryId, 'subject_type' => $subjectType, 'subject_id' => $subjectId],
            ['level' => $level->value, 'granted_by' => $grantedBy, 'granted_at' => now()],
        );

        $this->resolver->invalidate();
    }

    public function deleteAssignment(int $territoryId, string $subjectType, string $subjectId): void
    {
        $this->table('delegated_assignments')
            ->where(['territory_id' => $territoryId, 'subject_type' => $subjectType, 'subject_id' => $subjectId])
            ->delete();

        $this->resolver->invalidate();
    }

    /** When a user is deleted, so is every assignment that named them. */
    public function deleteAssignmentsForSubject(string $subjectType, string $subjectId): void
    {
        $this->table('delegated_assignments')
            ->where(['subject_type' => $subjectType, 'subject_id' => $subjectId])
            ->delete();

        $this->resolver->invalidate();
    }

    private function table(string $name): Builder
    {
        return $this->db->connection($this->connection)->table($name);
    }
}
