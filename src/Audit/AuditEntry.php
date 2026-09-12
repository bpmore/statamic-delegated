<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Audit;

use DateTimeImmutable;

/** One row of delegated_audit, read back. Immutable, like the row. */
final class AuditEntry
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $actorId,
        public readonly string $action,
        public readonly string $targetType,
        public readonly ?string $targetId,
        public readonly ?int $territoryId,
        public readonly ?array $before,
        public readonly ?array $after,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            actorId: $row->actor_id === null ? null : (string) $row->actor_id,
            action: (string) $row->action,
            targetType: (string) $row->target_type,
            targetId: $row->target_id === null ? null : (string) $row->target_id,
            territoryId: $row->territory_id === null ? null : (int) $row->territory_id,
            before: $row->before === null ? null : json_decode((string) $row->before, true),
            after: $row->after === null ? null : json_decode((string) $row->after, true),
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }
}
