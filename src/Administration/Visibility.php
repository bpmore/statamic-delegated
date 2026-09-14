<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Administration;

use Bpmore\StatamicDelegated\Resolution\Subjects;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

/**
 * Invariant 6 as a service: which users an actor may see, and which they
 * may change. Used by DelegatedAdministration for its own operations and
 * by the enforcement points that scope Statamic's users screens, so the two
 * cannot disagree.
 *
 * Seeing: any assignment, own or through a group, to a territory the actor
 * administers or a descendant of it — never an ancestor. Changing: every
 * assignment inside; a user who is also somewhere the actor does not
 * administer is visible but read-only, because roles and emails are global.
 */
final class Visibility
{
    public function __construct(private readonly TerritoryResolver $resolver) {}

    /**
     * The users the actor may see. A super user may see everyone.
     *
     * @return list<string>
     */
    public function visibleIds(Actor $actor): array
    {
        if ($actor->isSuper) {
            return User::all()->map->id()->values()->all();
        }

        $administered = $this->resolver->membershipFor($actor->subjects)->administeredIds();

        if ($administered === []) {
            return [];
        }

        $ids = [];

        foreach ($this->resolver->map()->subjectsIn($administered) as $subject) {
            [$type, $id] = explode(':', $subject, 2);

            if ($type === 'user') {
                if (User::find($id) !== null) {
                    $ids[$id] = true;
                }
            } elseif (($group = UserGroup::find($id)) !== null) {
                foreach ($group->queryUsers()->get() as $user) {
                    $ids[(string) $user->id()] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Where the user's assignments fall relative to what the actor
     * administers: inside any, and outside any.
     *
     * @return array{inside: bool, outside: bool}
     */
    public function placement(Actor $actor, UserContract $user): array
    {
        $administered = array_flip($this->resolver->membershipFor($actor->subjects)->administeredIds());
        $inside = $outside = false;

        foreach ($this->resolver->map()->assignmentsFor(Subjects::of($user)) as $assignment) {
            isset($administered[$assignment->territoryId]) ? $inside = true : $outside = true;
        }

        return ['inside' => $inside, 'outside' => $outside];
    }
}
