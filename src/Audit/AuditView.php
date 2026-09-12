<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Audit;

use Bpmore\StatamicDelegated\Administration\Actor;
use Bpmore\StatamicDelegated\Administration\Visibility;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

/**
 * The audit log as a particular viewer may see it.
 *
 * A territory admin's log is their territories' rows (spec §11: "does a
 * territory admin see the audit log for their own territory? Probably
 * yes, scoped"). The rows are theirs, but an *actor* in them may not be:
 * a site administrator who reshaped the territory, say. Such an actor is
 * shown as "someone outside your territory", never by name or id — the
 * same rule as invariant 6. The *target* of one of the territory's own
 * rows was in the territory when the row was written (that is what the
 * territory id on the row means), so they are named even if they have
 * since left; the target of a refusal may be anyone, so they are not.
 *
 * The site-wide log is read behind `manage territories`, and names everyone.
 */
final class AuditView
{
    public function __construct(
        private readonly AuditLog $log,
        private readonly Visibility $visibility,
        private readonly TerritoryResolver $resolver,
    ) {}

    /**
     * @param  list<int>  $territoryIds
     * @return list<array<string, mixed>>
     */
    public function forTerritories(Actor $viewer, array $territoryIds, int $limit = 100): array
    {
        $visible = $viewer->isSuper ? null : array_flip($this->visibility->visibleIds($viewer));

        return $this->present($viewer, $this->log->forTerritories($territoryIds, $limit), $visible);
    }

    /** @return list<array<string, mixed>> */
    public function everything(Actor $viewer, int $limit = 200): array
    {
        return $this->present($viewer, $this->log->latest($limit), null);
    }

    /**
     * @param  list<AuditEntry>  $entries
     * @param  array<string, true>|null  $visible  null when the viewer sees everyone
     * @return list<array<string, mixed>>
     */
    private function present(Actor $viewer, array $entries, ?array $visible): array
    {
        $map = $this->resolver->map();

        return array_map(fn (AuditEntry $e): array => [
            'id' => $e->id,
            'at' => $e->createdAt->format(DATE_ATOM),
            'action' => $e->action,
            'actor' => $this->person($e->actorId, $viewer, $visible),
            'target' => match ($e->targetType) {
                'user' => $this->person($e->targetId, $viewer, str_starts_with($e->action, 'refused.') ? $visible : null),
                'group' => ['label' => UserGroup::find((string) $e->targetId)?->title() ?? (string) $e->targetId, 'id' => $e->targetId],
                'territory' => ['label' => $map->find((int) $e->targetId)?->name ?? ($e->before['name'] ?? $e->after['name'] ?? "territory {$e->targetId}"), 'id' => $e->targetId],
                default => ['label' => (string) $e->targetId, 'id' => $e->targetId],
            },
            'territory' => $e->territoryId === null ? null : ['id' => $e->territoryId, 'name' => $map->find($e->territoryId)?->name ?? ($e->before['name'] ?? "territory {$e->territoryId}")],
            'before' => $e->before,
            'after' => $e->after,
        ], $entries);
    }

    /**
     * @param  array<string, true>|null  $visible  null when the viewer sees everyone
     * @return array{label: string, id: string|null}
     */
    private function person(?string $id, Actor $viewer, ?array $visible): array
    {
        if ($id === null) {
            return ['label' => 'the system', 'id' => null];
        }

        if ($id === $viewer->userId) {
            return ['label' => 'you', 'id' => $id];
        }

        if ($visible !== null && ! isset($visible[$id])) {
            return ['label' => 'someone outside your territory', 'id' => null];
        }

        $user = User::find($id);

        if ($user === null) {
            return ['label' => 'a user who no longer exists', 'id' => $id];
        }

        $name = (string) $user->name();
        $email = (string) $user->email();

        return ['label' => $name === '' || $name === $email ? $email : "{$name} ({$email})", 'id' => $id];
    }
}
