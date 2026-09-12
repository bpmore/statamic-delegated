<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Audit;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * The append-only record of who changed what in Delegated (spec §6).
 *
 * Append-only is this class's shape, not a database constraint: it can
 * write a row and read rows, and nothing else. There is no update and no
 * delete, and the table has no foreign key that could cascade one (Phase
 * 1.3). A row outlives the territory, assignment or user it describes.
 *
 * Refusals are recorded as well as changes: an action a territory admin was
 * refused under an invariant is worth knowing about, and is the one kind of
 * row that describes something that did *not* happen.
 */
final class AuditLog
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, ?string $actorId, string $targetType, ?string $targetId, ?int $territoryId, ?array $before = null, ?array $after = null): void
    {
        $this->table()->insert([
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'territory_id' => $territoryId,
            'before' => $before === null ? null : json_encode($before),
            'after' => $after === null ? null : json_encode($after),
            'created_at' => now(),
        ]);
    }

    /**
     * The log of the given territories, newest first. Rows with no
     * territory — a change to a user in no territory, a refusal with no
     * territory in it — are not any territory's and are not returned here.
     *
     * @param  list<int>  $territoryIds
     * @return list<AuditEntry>
     */
    public function forTerritories(array $territoryIds, int $limit = 100): array
    {
        if ($territoryIds === []) {
            return [];
        }

        return $this->table()
            ->whereIn('territory_id', $territoryIds)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(AuditEntry::fromRow(...))
            ->all();
    }

    /**
     * Everything, newest first. For a site administrator.
     *
     * @return list<AuditEntry>
     */
    public function latest(int $limit = 100): array
    {
        return $this->table()
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(AuditEntry::fromRow(...))
            ->all();
    }

    private function table(): Builder
    {
        return $this->db->connection($this->connection)->table('delegated_audit');
    }
}
