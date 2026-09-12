<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Administration;

/**
 * The Statamic permissions a territory admin can never hand out, whoever
 * holds them (invariant 4). Each one is a way to manage roles, users or
 * groups — or, for the last, to bypass the entry policy altogether — and any
 * of them in the hands of a delegated admin's appointee ends the territory
 * model.
 */
final class ManagementPermissions
{
    public const ALL = [
        'super',
        'edit roles',
        'edit user groups',
        'edit users',
        'create users',
        'delete users',
        'change passwords',
        'assign roles',
        'assign user groups',
        'impersonate users',
        // EntryPolicy::before() returns true for this one (Phase 0.1).
        'configure collections',
    ];

    /**
     * @param  iterable<string>  $permissions
     */
    public static function anyIn(iterable $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (in_array($permission, self::ALL, true)) {
                return true;
            }
        }

        return false;
    }
}
