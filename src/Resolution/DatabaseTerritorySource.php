<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Resolution;

use Bpmore\StatamicDelegated\Territories\Assignment;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Territories\ScopeType;
use Bpmore\StatamicDelegated\Territories\Territory;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Reads delegated_territories and delegated_assignments. Two queries, no
 * joins: the tables are small (hundreds of rows on a large site) and the
 * indexing happens in TerritoryMap.
 */
final class DatabaseTerritorySource implements TerritorySource
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
    ) {}

    public function territories(): array
    {
        return $this->db->connection($this->connection)
            ->table('delegated_territories')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): Territory => new Territory(
                id: (int) $row->id,
                name: (string) $row->name,
                slug: (string) $row->slug,
                site: (string) $row->site,
                scopeType: ScopeType::from((string) $row->scope_type),
                scopeRef: (string) $row->scope_ref,
                parentId: $row->parent_id === null ? null : (int) $row->parent_id,
            ))
            ->all();
    }

    public function assignments(): array
    {
        return $this->db->connection($this->connection)
            ->table('delegated_assignments')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): Assignment => new Assignment(
                territoryId: (int) $row->territory_id,
                subject: Assignment::subject((string) $row->subject_type, (string) $row->subject_id),
                level: Level::from((string) $row->level),
            ))
            ->all();
    }
}
