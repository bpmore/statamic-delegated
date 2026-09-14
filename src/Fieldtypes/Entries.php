<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Fieldtypes;

use Bpmore\StatamicDelegated\Enforcement\Exempt;
use Bpmore\StatamicDelegated\Resolution\Subjects;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Scoping\TerritoryScope;
use Statamic\Facades\User;
use Statamic\Fieldtypes\Entries as CoreEntries;

/**
 * Enforcement point: the relationship picker (Phase 0.1, "Relationship /
 * entries picker" — the second listing surface, unreached by any hook).
 *
 * The picker's index runs Entry::query() from inside the `entries`
 * fieldtype, filtered only to collections the user may view. This subclass
 * registers under the same handle — a later registration replaces the core
 * binding, so every `entries` field, Bard link and Link fieldtype picker
 * resolves to it — and scopes that query with the same TerritoryScope the
 * entry listing uses, before it runs. The picker's `data` endpoint was
 * already closed by the Gate (Phase 3.2.a).
 */
class Entries extends CoreEntries
{
    protected function getIndexQuery($request)
    {
        $query = parent::getIndexQuery($request);

        $user = User::current();

        if (! Exempt::user($user)) {
            app(TerritoryScope::class)->apply(
                $query,
                app(TerritoryResolver::class)->territoriesFor(Subjects::of($user)),
            );
        }

        return $query;
    }
}
