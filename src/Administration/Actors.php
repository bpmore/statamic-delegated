<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Administration;

use Bpmore\StatamicDelegated\Resolution\Subjects;
use Statamic\Contracts\Auth\User;

/** Builds the Actor a Statamic user is, the way the invariants define it. */
final class Actors
{
    public static function from(User $user): Actor
    {
        return new Actor(
            userId: (string) $user->id(),
            isSuper: $user->isSuper(),
            roles: $user->roles()->map->handle()->values()->all(),
            subjects: Subjects::of($user),
        );
    }
}
