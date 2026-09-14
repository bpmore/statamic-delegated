<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Administration;

use Bpmore\StatamicDelegated\Territories\Level;

/**
 * What a territory admin can do: add and remove members within their own
 * territory, and nothing else (spec §4). Every method either does the whole
 * thing or throws EscalationDenied before writing anything.
 *
 * There is deliberately no way to create or modify a role here. That is
 * Statamic's `edit roles` permission, and a territory admin does not get it
 * through Delegated.
 *
 * This is the contract the six Phase 2 invariants are written against. It
 * has no implementation until they all fail.
 */
interface TerritoryAdministration
{
    /**
     * Put a subject into a territory the actor administers, optionally giving
     * a user roles — only ever roles the actor holds.
     *
     * @param  string  $subject  "user:<id>" or "group:<id>"
     * @param  list<string>  $roles  role handles to assign to a user subject
     *
     * @throws EscalationDenied
     */
    public function addMember(Actor $actor, int $territoryId, string $subject, Level $level, array $roles = []): void;

    /**
     * @param  string  $subject  "user:<id>" or "group:<id>"
     *
     * @throws EscalationDenied
     */
    public function removeMember(Actor $actor, int $territoryId, string $subject): void;

    /**
     * Set a visible user's roles, among the roles the actor holds: those the
     * actor holds are replaced by the given handles; any the user already
     * has that the actor does not hold stay as they are.
     *
     * @param  list<string>  $roles
     *
     * @throws EscalationDenied
     */
    public function setRoles(Actor $actor, string $userId, array $roles): void;

    /**
     * Edit a visible user's profile fields.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws EscalationDenied
     */
    public function updateUser(Actor $actor, string $userId, array $attributes): void;

    /**
     * @throws EscalationDenied
     */
    public function deleteUser(Actor $actor, string $userId): void;

    /**
     * Change a territory the actor administers: its name, or the membership
     * of a *child* territory. Never its own scope, parent or level.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws EscalationDenied
     */
    public function updateTerritory(Actor $actor, int $territoryId, array $changes): void;

    /**
     * The users the actor may see and act on: those with an assignment, own
     * or via a group, overlapping a territory the actor administers.
     *
     * @return list<string> user ids
     */
    public function visibleUsers(Actor $actor): array;
}
