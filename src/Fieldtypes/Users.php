<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Fieldtypes;

use Bpmore\StatamicDelegated\Enforcement\UserVisibility;
use Statamic\Facades\User;
use Statamic\Fieldtypes\Users as CoreUsers;

/**
 * Enforcement point: the users relationship picker (invariant 6, the second
 * listing of users after the users index).
 *
 * ScopesUsersListing confines `statamic.cp.users.index`, but the picker
 * behind every `users` field reaches the same users through its own route
 * (`fieldtypes/relationship`) and its own `User::query()`, open to anyone
 * with `view users`. This subclass registers under the same handle — as
 * the `entries` fieldtype does — and confines that query to the ids
 * UserVisibility allows, in the one place the core fieldtype lets the
 * query be touched before it is paginated or run. It runs with or without
 * a users search index, since the core calls it on either query.
 */
class Users extends CoreUsers
{
    protected function applyIndexQueryScopes($query, $params)
    {
        parent::applyIndexQueryScopes($query, $params);

        $ids = app(UserVisibility::class)->ids(User::current());

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
    }
}
