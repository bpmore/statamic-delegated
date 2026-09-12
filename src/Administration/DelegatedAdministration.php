<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Administration;

use Bpmore\StatamicDelegated\Audit\AuditLog;
use Bpmore\StatamicDelegated\Resolution\Subjects;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Storage\TerritoryStore;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Territories\Membership;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

/**
 * Delegated administration against Statamic's users, roles and groups and
 * Delegated's own tables.
 *
 * Every public method follows the same shape: resolve, check every invariant
 * that applies, and only then write. A refusal is an exception thrown before
 * the first write, so a refused request leaves nothing behind. Super users
 * skip the invariants (spec §8) but not the sanity checks.
 *
 * The invariants (spec §4), by the private method that enforces each:
 *   1. cannot grant a role they do not hold ........... requireRolesGrantable
 *   2. cannot add to a territory not administered ...... requireAdministers
 *   3. cannot edit, demote or delete a super user ...... requireNotSuper
 *   4. cannot create or modify roles ................... requireRolesGrantable, requireProfileAttributes
 *   5. cannot widen their own territory ................ requireTerritoryChangesAllowed
 *   6. cannot see users with no overlapping assignment . requireWhollyWithin, visibleUsers
 */
final class DelegatedAdministration implements TerritoryAdministration
{
    /** Keys updateUser accepts from nobody but a super user. */
    private const RESERVED_ATTRIBUTES = ['id', 'password', 'password_hash', 'remember_token', 'preferences'];

    private const RESERVED_PREFIXES = ['two_factor', 'passkey'];

    public function __construct(
        private readonly TerritoryResolver $resolver,
        private readonly TerritoryStore $store,
        private readonly Visibility $visibility,
        private readonly AuditLog $audit,
    ) {}

    public function addMember(Actor $actor, int $territoryId, string $subject, Level $level, array $roles = []): void
    {
        [$type, $id] = $this->parseSubject($subject);
        $user = $type === 'user' ? $this->requireUser($id) : null;

        if ($type === 'group') {
            if ($roles !== []) {
                throw new InvalidArgumentException('Roles are granted to users, not groups');
            }
            $this->requireGroup($id);
        }

        if (! $actor->isSuper) {
            $this->recordingRefusals($actor, 'add_member', $territoryId, $subject, function () use ($actor, $territoryId, $user, $roles): void {
                $this->requireAdministers($actor, $territoryId);
                if ($user !== null) {
                    $this->requireNotSuper($user);
                }
                $this->requireRolesGrantable($actor, $roles);
            });
        } else {
            $this->requireTerritory($territoryId);
            $this->requireRolesExist($roles);
        }

        $this->store->putAssignment($territoryId, $type, $id, $level, $actor->userId);

        if ($user !== null && $roles !== []) {
            $before = $user->explicitRoles()->map->handle()->values()->all();

            foreach ($roles as $role) {
                $user->assignRole($role);
            }
            $user->save();

            $this->audit->record('user.roles_set', $actor->userId, 'user', $id, $territoryId,
                ['roles' => $before], ['roles' => $user->explicitRoles()->map->handle()->values()->all()]);
        }
    }

    public function removeMember(Actor $actor, int $territoryId, string $subject): void
    {
        [$type, $id] = $this->parseSubject($subject);

        if (! $actor->isSuper) {
            $this->recordingRefusals($actor, 'remove_member', $territoryId, $subject, function () use ($actor, $territoryId, $type, $id): void {
                $this->requireAdministers($actor, $territoryId);
                if ($type === 'user' && ($user = User::find($id)) !== null) {
                    $this->requireNotSuper($user);
                }
            });
        } else {
            $this->requireTerritory($territoryId);
        }

        $this->store->deleteAssignment($territoryId, $type, $id, $actor->userId);
    }

    public function setRoles(Actor $actor, string $userId, array $roles): void
    {
        $user = $this->requireUser($userId);
        $roles = array_values(array_unique($roles));

        if (! $actor->isSuper) {
            $this->recordingRefusals($actor, 'set_roles', null, 'user:'.$userId, function () use ($actor, $user, $roles): void {
                $this->requireNotSuper($user);
                $this->requireWhollyWithin($actor, $user);
                $this->requireRolesGrantable($actor, $roles);
            });

            // The actor decides only among the roles they hold. A role the
            // user already has that the actor does not hold is neither
            // granted nor taken away: keeping it is not granting it, and
            // dropping it would be a demotion the actor has no standing for.
            $kept = array_values(array_filter(
                $user->explicitRoles()->map->handle()->values()->all(),
                fn (string $handle): bool => ! $actor->holdsRole($handle),
            ));

            $roles = array_values(array_unique(array_merge($kept, $roles)));
        } else {
            $this->requireRolesExist($roles);
        }

        $before = $user->explicitRoles()->map->handle()->values()->all();

        $user->explicitRoles($roles)->save();

        $this->recordForUser($actor, 'user.roles_set', $user, ['roles' => $before], ['roles' => $roles]);
    }

    public function updateUser(Actor $actor, string $userId, array $attributes): void
    {
        $user = $this->requireUser($userId);

        if (! $actor->isSuper) {
            $this->recordingRefusals($actor, 'update_user', null, 'user:'.$userId, function () use ($actor, $user, $attributes): void {
                $this->requireNotSuper($user);
                $this->requireWhollyWithin($actor, $user);
                $this->requireProfileAttributes($user, $attributes);
            });
        }

        $before = [];
        foreach (array_keys($attributes) as $key) {
            $before[$key] = $key === 'email' ? $user->email() : ($key === 'super' ? $user->isSuper() : $user->get($key));
        }

        foreach ($attributes as $key => $value) {
            if ($key === 'email') {
                $user->email((string) $value);
            } elseif ($key === 'super') {
                $value ? $user->makeSuper() : $user->removeSuper();
            } else {
                $user->set($key, $value);
            }
        }

        $user->save();

        $this->recordForUser($actor, 'user.updated', $user, $before, $attributes);
    }

    public function deleteUser(Actor $actor, string $userId): void
    {
        $user = $this->requireUser($userId);

        if (! $actor->isSuper) {
            $this->recordingRefusals($actor, 'delete_user', null, 'user:'.$userId, function () use ($actor, $user): void {
                $this->requireNotSuper($user);
                $this->requireWhollyWithin($actor, $user);
            });
        }

        // Record while the user still exists, so the row can say who they were.
        $this->recordForUser($actor, 'user.deleted', $user, ['email' => $user->email(), 'name' => $user->name()], null);

        $user->delete();

        $this->store->deleteAssignmentsForSubject('user', $userId, $actor->userId);
    }

    public function updateTerritory(Actor $actor, int $territoryId, array $changes): void
    {
        if (! $actor->isSuper) {
            $this->recordingRefusals($actor, 'update_territory', $territoryId, 'territory:'.$territoryId, function () use ($actor, $territoryId, $changes): void {
                $this->requireAdministers($actor, $territoryId);
                $this->requireTerritoryChangesAllowed($territoryId, $changes);
            });
        } else {
            $this->requireTerritory($territoryId);
            foreach (array_keys($changes) as $key) {
                if (! in_array($key, ['name', 'slug', 'site', 'scope_type', 'scope_ref', 'parent_id'], true)) {
                    throw new InvalidArgumentException("Unknown territory attribute '{$key}'");
                }
            }
        }

        $this->store->updateTerritory($territoryId, $changes, $actor->userId);
    }

    public function visibleUsers(Actor $actor): array
    {
        return $this->visibility->visibleIds($actor);
    }

    // ------------------------------------------------------------ invariants

    /** Invariant 2. Also refuses a territory that does not exist. */
    private function requireAdministers(Actor $actor, int $territoryId): void
    {
        if ($this->resolver->map()->find($territoryId) === null || ! $this->membership($actor)->isAdminOf($territoryId)) {
            throw EscalationDenied::territoryNotAdministered($territoryId);
        }
    }

    /** Invariant 3. */
    private function requireNotSuper(UserContract $user): void
    {
        if ($user->isSuper()) {
            throw EscalationDenied::superUser($user->id());
        }
    }

    /**
     * Invariants 1 and 4. Every role must exist, be held by the actor, and
     * carry no management permission — checked on the role as it is now.
     *
     * @param  list<string>  $roles
     */
    private function requireRolesGrantable(Actor $actor, array $roles): void
    {
        if ($roles === []) {
            return;
        }

        $missing = array_values(array_filter($roles, fn (string $handle): bool => Role::find($handle) === null));
        $notHeld = $actor->rolesNotHeld($roles);

        if ($missing !== [] || $notHeld !== []) {
            throw EscalationDenied::roleNotHeld(array_values(array_unique(array_merge($notHeld, $missing))));
        }

        foreach ($roles as $handle) {
            if (ManagementPermissions::anyIn(Role::find($handle)->permissions())) {
                throw EscalationDenied::roleManagement();
            }
        }
    }

    /**
     * Invariants 3 and 4 as they apply to profile edits: the super flag,
     * roles and groups have their own rules, and credentials are nobody's
     * to set on someone else's behalf.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function requireProfileAttributes(UserContract $user, array $attributes): void
    {
        foreach (array_keys($attributes) as $key) {
            if ($key === 'super') {
                throw EscalationDenied::promotion($user->id());
            }
            if ($key === 'roles' || $key === 'groups') {
                throw EscalationDenied::roleManagement();
            }
            if (in_array($key, self::RESERVED_ATTRIBUTES, true)) {
                throw EscalationDenied::reservedAttribute($user->id(), $key);
            }
            foreach (self::RESERVED_PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    throw EscalationDenied::reservedAttribute($user->id(), $key);
                }
            }
        }
    }

    /**
     * Invariant 5. A name is the only thing an admin may change; the scope
     * — type, reference, parent, site — is immutable to them, and so is
     * anything this code does not recognise.
     *
     * @param  array<string, mixed>  $changes
     */
    private function requireTerritoryChangesAllowed(int $territoryId, array $changes): void
    {
        foreach (array_keys($changes) as $key) {
            if (! in_array($key, ['name', 'slug'], true)) {
                throw EscalationDenied::ownTerritory($territoryId, $key);
            }
        }
    }

    /**
     * Invariant 6, for changes. The user must have an assignment overlapping
     * a territory the actor administers (visible), and no assignment that
     * does not (not shared) — because what would be changed is global.
     */
    private function requireWhollyWithin(Actor $actor, UserContract $user): void
    {
        ['inside' => $inside, 'outside' => $outside] = $this->visibility->placement($actor, $user);

        if (! $inside) {
            throw EscalationDenied::userNotVisible($user->id());
        }

        if ($outside) {
            throw EscalationDenied::userShared($user->id());
        }
    }

    // ------------------------------------------------------------ audit

    /**
     * Runs the invariant checks for an operation; a refusal is recorded —
     * which invariant, against what — and rethrown. The one kind of audit
     * row that describes something that did not happen.
     */
    private function recordingRefusals(Actor $actor, string $operation, ?int $territoryId, string $target, callable $checks): void
    {
        try {
            $checks();
        } catch (EscalationDenied $e) {
            [$type, $id] = array_pad(explode(':', $target, 2), 2, null);

            $this->audit->record("refused.{$operation}", $actor->userId, $type ?? 'unknown', $id, $territoryId, null, [
                'invariant' => $e->invariant,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * A change to a user belongs to every territory the user is in that the
     * actor administers — for a wholly-owned user, each of their territories;
     * for a super user acting, each territory the user is in at all.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordForUser(Actor $actor, string $action, UserContract $user, ?array $before, ?array $after): void
    {
        $territories = collect($this->resolver->map()->assignmentsFor(Subjects::of($user)))->map->territoryId->unique();

        if (! $actor->isSuper) {
            $administered = $this->membership($actor)->administeredIds();
            $territories = $territories->filter(fn (int $id): bool => in_array($id, $administered, true));
        }

        foreach ($territories->isEmpty() ? [null] : $territories->all() as $territoryId) {
            $this->audit->record($action, $actor->userId, 'user', (string) $user->id(), $territoryId, $before, $after);
        }
    }

    // ------------------------------------------------------------ helpers

    private function membership(Actor $actor): Membership
    {
        return $this->resolver->membershipFor($actor->subjects);
    }

    /** @return array{string, string} */
    private function parseSubject(string $subject): array
    {
        $parts = explode(':', $subject, 2);

        if (count($parts) !== 2 || ! in_array($parts[0], ['user', 'group'], true) || $parts[1] === '') {
            throw new InvalidArgumentException("Subject must be 'user:<id>' or 'group:<handle>', got '{$subject}'");
        }

        return [$parts[0], $parts[1]];
    }

    private function requireUser(string $id): UserContract
    {
        return User::find($id) ?? throw new InvalidArgumentException("No user with id '{$id}'");
    }

    private function requireGroup(string $handle): void
    {
        if (UserGroup::find($handle) === null) {
            throw new InvalidArgumentException("No user group with handle '{$handle}'");
        }
    }

    private function requireTerritory(int $territoryId): void
    {
        if ($this->resolver->map()->find($territoryId) === null) {
            throw new InvalidArgumentException("No territory with id {$territoryId}");
        }
    }

    /** @param  list<string>  $roles */
    private function requireRolesExist(array $roles): void
    {
        foreach ($roles as $handle) {
            if (Role::find($handle) === null) {
                throw new InvalidArgumentException("No role with handle '{$handle}'");
            }
        }
    }
}
