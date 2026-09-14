<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Scopes;

use Bpmore\StatamicDelegated\Enforcement\UserVisibility;
use Statamic\Facades\User;
use Statamic\Query\Scopes\Filter;

/**
 * Enforcement point: the users listing (Phase 0.1's users-listing surface,
 * flagged under invariant 4).
 *
 * A listing filter, applied through Statamic's own filter mechanism, that
 * confines the users query to the ids UserVisibility allows. It is never
 * shown in the filter menu and takes no values — it decides from the
 * signed-in user, so a crafted request cannot widen it — and it is put on
 * every users-listing request by ScopesUsersListing, so it cannot be left
 * off either.
 */
class VisibleUsers extends Filter
{
    protected static $handle = 'delegated_visible_users';

    public function visibleTo($key)
    {
        return false;
    }

    public function fieldItems()
    {
        return [];
    }

    public function apply($query, $values)
    {
        $ids = app(UserVisibility::class)->ids(User::current());

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
    }

    public function badge($values)
    {
        return null;
    }
}
