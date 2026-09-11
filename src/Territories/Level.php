<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Territories;

/**
 * What an assignment lets a subject do in a territory (spec §4). A member
 * works on the content; an admin also manages the members, and nothing else.
 */
enum Level: string
{
    case Member = 'member';
    case Admin = 'admin';

    public function outranks(Level $other): bool
    {
        return $this === self::Admin && $other === self::Member;
    }

    public static function highest(Level $a, Level $b): Level
    {
        return $b->outranks($a) ? $b : $a;
    }
}
