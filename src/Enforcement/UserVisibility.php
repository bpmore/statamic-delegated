<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Bpmore\StatamicDelegated\Administration\Actors;
use Bpmore\StatamicDelegated\Administration\Visibility;
use Bpmore\StatamicDelegated\Resolution\Subjects;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\Blink;

/**
 * Whether Delegated confines a signed-in user's view of *other users*, and
 * to whom.
 *
 * Confined: anyone who belongs to a territory. Their view of Statamic's own
 * users screens is invariant 6's — the users they administer, plus
 * themselves. Not confined: super users, and people in no territory at all,
 * whose user management is Statamic's business under Statamic's own
 * permissions. This is deliberately narrower than Exempt::user(), which is
 * about content: a site needs to be able to have a non-super user
 * administrator, and it does so by keeping them out of every territory.
 */
final class UserVisibility
{
    public function __construct(
        private readonly TerritoryResolver $resolver,
        private readonly Visibility $visibility,
    ) {}

    /**
     * The ids the user may see, or null when Delegated does not confine
     * them. Memoised per request: the users listing asks once per row.
     *
     * @return list<string>|null
     */
    public function ids(?User $user): ?array
    {
        if ($user === null || $user->isSuper()) {
            return null;
        }

        return Blink::store('delegated')->once('visible-users:'.$user->id(), function () use ($user): ?array {
            if ($this->resolver->membershipFor(Subjects::of($user))->isEmpty()) {
                return null;
            }

            $ids = $this->visibility->visibleIds(Actors::from($user));
            $ids[] = (string) $user->id();

            return array_values(array_unique($ids));
        });
    }

    public function confines(?User $user): bool
    {
        return $this->ids($user) !== null;
    }

    /** May the user change (not just see) this other user? */
    public function mayChange(User $actor, User $target): bool
    {
        if ($target->isSuper()) {
            return false;
        }

        ['inside' => $inside, 'outside' => $outside] = $this->visibility->placement(Actors::from($actor), $target);

        return $inside && ! $outside;
    }
}
