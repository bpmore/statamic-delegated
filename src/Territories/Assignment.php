<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Territories;

/**
 * A subject's place in a territory, from a delegated_assignments row.
 *
 * A subject is written "user:<id>" or "group:<id>" so the resolver can be
 * handed a plain list of them and never needs to know what a Statamic user
 * or group is.
 */
final class Assignment
{
    public function __construct(
        public readonly int $territoryId,
        public readonly string $subject,
        public readonly Level $level,
    ) {}

    public static function subject(string $type, string $id): string
    {
        return $type.':'.$id;
    }
}
