<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Illuminate\Contracts\Auth\Access\Gate;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;

/**
 * Enforcement point: Statamic's own user screens, reached by direct URL or
 * bulk action rather than through the listing.
 *
 * A Gate::before callback for abilities whose subject is a user. For a
 * confined actor: a user they cannot see is refused everything; a user they
 * can see but may not change — a super user, or one who is also in a
 * territory the actor does not administer — is refused everything but
 * `view`. Themselves, they may always reach; what they may do to themselves
 * is Statamic's decision, as before.
 */
final class GuardsUserAbilities
{
    public function __construct(
        private readonly Gate $gate,
        private readonly UserVisibility $visibility,
    ) {}

    public function register(): void
    {
        $visibility = $this->visibility;

        $this->gate->before(function ($authed, string $ability, array $arguments) use ($visibility): ?bool {
            $actor = User::fromUser($authed);
            $ids = $visibility->ids($actor);

            if ($ids === null) {
                return null;
            }

            foreach ($arguments as $target) {
                if (! $target instanceof UserContract) {
                    continue;
                }

                if ((string) $target->id() === (string) $actor->id()) {
                    continue;
                }

                if (! in_array((string) $target->id(), $ids, true)) {
                    return false;
                }

                if ($ability !== 'view' && ! $visibility->mayChange($actor, $target)) {
                    return false;
                }
            }

            return null;
        });
    }
}
