<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Illuminate\Contracts\Auth\Access\Gate;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

/**
 * Enforcement point: the policy layer (Phase 0.1, every surface marked ✅).
 *
 * A Gate::before callback that denies any ability whose subject is an entry
 * outside the user's territories, and otherwise says nothing — Statamic's
 * EntryPolicy then decides as it always did. Denying here rather than in a
 * policy subclass means every ability is covered, including ones an addon
 * or the site adds later, and a site that binds its own EntryPolicy is not
 * fought with.
 *
 * Gate::before runs ahead of the policy's own before(), so the super-user
 * and `configure collections` bypasses are honoured explicitly (Exempt)
 * rather than relied on.
 */
final class GuardsEntryAbilities
{
    public function __construct(
        private readonly Gate $gate,
        private readonly Territorial $territorial,
    ) {}

    public function register(): void
    {
        $territorial = $this->territorial;

        $this->gate->before(function ($user, string $ability, array $arguments) use ($territorial): ?bool {
            foreach ($arguments as $argument) {
                if ($argument instanceof Entry && ! $territorial->allows(User::fromUser($user), $argument)) {
                    return false;
                }
            }

            return null;
        });
    }
}
