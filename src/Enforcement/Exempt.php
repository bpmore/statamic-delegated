<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Statamic\Contracts\Auth\User;

/**
 * Who Delegated does not restrict, and why.
 *
 * Super users bypass everything by design (spec §8), and Statamic's own
 * EntryPolicy::before() also returns true for anyone holding `configure
 * collections` (Phase 0.1). Restricting the listing for a user the policy
 * then lets open anything would be theatre, so Delegated treats the two
 * alike and says so in the README and the licence.
 */
final class Exempt
{
    public static function user(?User $user): bool
    {
        if ($user === null) {
            // No user — a console command, a queue job — is not a control
            // panel user being restricted. The hooks only fire in the CP.
            return true;
        }

        return $user->isSuper() || $user->hasPermission('configure collections');
    }
}
