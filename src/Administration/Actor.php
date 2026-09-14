<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Administration;

/**
 * The person performing a delegated-administration action, reduced to what
 * the invariants need to know: who they are, whether they are a super user,
 * which roles they hold (directly or through a group), and the subjects
 * their territory membership is resolved from.
 *
 * Plain values, built from a Statamic user by the framework side, so the
 * invariants can be tested without one.
 */
final class Actor
{
    /** @var list<string> */
    public readonly array $roles;

    /** @var list<string> */
    public readonly array $subjects;

    /**
     * @param  list<string>  $roles  handles of every role the actor holds, own and via groups
     * @param  list<string>  $subjects  "user:<id>" and "group:<id>"
     */
    public function __construct(
        public readonly string $userId,
        public readonly bool $isSuper,
        array $roles,
        array $subjects,
    ) {
        $this->roles = array_values(array_unique($roles));
        $this->subjects = array_values(array_unique($subjects));
    }

    public function holdsRole(string $handle): bool
    {
        return in_array($handle, $this->roles, true);
    }

    /**
     * The roles among the given handles that the actor does not hold.
     *
     * @param  list<string>  $handles
     * @return list<string>
     */
    public function rolesNotHeld(array $handles): array
    {
        return array_values(array_filter($handles, fn (string $handle): bool => ! $this->holdsRole($handle)));
    }
}
