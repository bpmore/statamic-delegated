<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Bpmore\StatamicDelegated\Resolution\Subjects;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Scoping\TerritoryScope;
use Statamic\Facades\User;
use Statamic\Hooks\CP\EntriesIndexQuery;

/**
 * Enforcement point: the control panel entry listing (Phase 0.1, surface 1)
 * and its search path, both of which run through Statamic's official
 * EntriesIndexQuery hook before pagination.
 *
 * Registered once at boot. The closure is rebound by Hookable to the hook
 * instance, so it closes over what it needs rather than using $this.
 */
final class ScopesEntryListings
{
    public function __construct(
        private readonly TerritoryResolver $resolver,
        private readonly TerritoryScope $scope,
    ) {}

    public function register(): void
    {
        $resolver = $this->resolver;
        $scope = $this->scope;

        EntriesIndexQuery::hook('query', function ($payload, $next) use ($resolver, $scope) {
            $user = User::current();

            if (! Exempt::user($user)) {
                $scope->apply($payload->query, $resolver->territoriesFor(Subjects::of($user)));
            }

            return $next($payload);
        });
    }
}
