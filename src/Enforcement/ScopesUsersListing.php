<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Bpmore\StatamicDelegated\Scopes\VisibleUsers;
use Closure;
use Illuminate\Http\Request;
use Statamic\Facades\User;

/**
 * Puts the VisibleUsers filter on every users-listing request from a
 * confined user. The listing reads its filters from a base64 JSON
 * parameter, decoded by the controller's FilteredRequest after this
 * middleware has run, so the parameter is rewritten here.
 */
final class ScopesUsersListing
{
    private const ROUTE = 'statamic.cp.users.index';

    public function __construct(private readonly UserVisibility $visibility) {}

    public function handle(Request $request, Closure $next)
    {
        if ($request->route()?->getName() !== self::ROUTE || ! $this->visibility->confines(User::current())) {
            return $next($request);
        }

        $filters = (array) json_decode(base64_decode((string) $request->input('filters', '')) ?: '[]', true);
        $filters[VisibleUsers::handle()] = ['on' => true];

        $request->merge(['filters' => base64_encode(json_encode($filters))]);

        return $next($request);
    }
}
