<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Resolution;

use Bpmore\StatamicDelegated\Territories\Assignment;
use Statamic\Contracts\Auth\User;

/**
 * The subjects a Statamic user's membership is resolved from: themselves,
 * and every group they are in. Read fresh each time, never cached, so a
 * group change Statamic makes is seen at once (Phase 1.4).
 */
final class Subjects
{
    /** @return list<string> */
    public static function of(User $user): array
    {
        $subjects = [Assignment::subject('user', (string) $user->id())];

        foreach ($user->groups() as $group) {
            $subjects[] = Assignment::subject('group', (string) $group->handle());
        }

        return $subjects;
    }
}
