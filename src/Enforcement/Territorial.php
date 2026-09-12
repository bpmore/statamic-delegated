<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Bpmore\StatamicDelegated\Resolution\Subjects;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;

/**
 * The single-entry question: may this user reach this entry at all?
 *
 * Asked by the Gate for every entry ability, so the edit screen, update,
 * publish, preview, revisions, the relationship picker's data endpoint, the
 * command palette and the built-in bulk actions all get the same answer —
 * and the same answer as the listing, because both come from the resolver.
 */
final class Territorial
{
    public function __construct(private readonly TerritoryResolver $resolver) {}

    public function allows(?User $user, Entry $entry): bool
    {
        if (Exempt::user($user)) {
            return true;
        }

        $containing = $this->resolver->containing(Facts::of($entry));

        if ($containing === []) {
            // Site default: no territory owns it, so no department reaches it.
            return false;
        }

        return $this->resolver->membershipFor(Subjects::of($user))->coversAny($containing);
    }
}
