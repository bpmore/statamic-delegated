<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Scoping;

use Bpmore\StatamicDelegated\Territories\ScopeType;
use Bpmore\StatamicDelegated\Territories\Territory;
use Statamic\Contracts\Query\Builder as EntryQueryBuilder;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Query\EloquentQueryBuilder;

/**
 * Scopes an entry query to a set of territories *before it runs* — the
 * whole of spec §5's "scope the query, do not filter the results".
 *
 * The shape is the one the Phase 0.3 spike proved on both drivers: one
 * nested OR-group, one member per territory, and never `where('collection')`
 * or `whereTaxonomy()` inside it, because the Stache builder silently drops
 * both there (and Eloquent drops the taxonomy one, failing open). Each
 * member is bound to its territory's site, so a user's territories on one
 * site never reach another.
 *
 * Works on the three builders the control panel hands us: the Stache
 * builder, the Eloquent driver's builder, and the search-index builder the
 * listing switches to when a collection has a search index. One token
 * differs between them — the column that names an entry's collection — and
 * that is the only place this class asks which builder it has.
 */
final class TerritoryScope
{
    /**
     * Constrain the query to entries inside any of the territories. An empty
     * set means *nothing* — never everything.
     *
     * @param  list<Territory>  $territories
     */
    public function apply(EntryQueryBuilder $query, array $territories): void
    {
        if ($territories === []) {
            // Not whereIn('collection', []): on the Stache builder an empty
            // collection set means "all collections".
            $query->whereIn('id', []);

            return;
        }

        $collectionColumn = $this->collectionColumn($query);

        $query->where(function ($group) use ($territories, $collectionColumn): void {
            foreach ($territories as $territory) {
                $group->orWhere(function ($member) use ($territory, $collectionColumn): void {
                    $member->where('site', $territory->site);

                    match ($territory->scopeType) {
                        ScopeType::StructureBranch => $this->branch($member, $territory->scopeRef),
                        ScopeType::Collection => $member->where($collectionColumn, $territory->scopeRef),
                        ScopeType::TaxonomyTerm => $member->whereIn('id', $this->entryIdsTagged($territory)),
                    };
                });
            }
        });
    }

    private function branch(EntryQueryBuilder $query, string $uri): void
    {
        if ($uri === '/') {
            // The whole site: no URI predicate at all. An entry missing from
            // the tree is a root-level page (Territory::containsUri), and an
            // entry with no URI at all belongs to a root territory too.
            return;
        }

        // The branch itself, or anything below it — never a sibling that
        // shares the prefix. `like` is case-insensitive on every driver;
        // `=` is not on all of them, but scope_ref and stored URIs are both
        // normalised to lower case (Phase 0.3), so it does not matter here.
        $query->where(function ($q) use ($uri): void {
            $q->where('uri', $uri)->orWhere('uri', 'like', $uri.'/%');
        });
    }

    /**
     * A term territory as an id list. `whereTaxonomy()` cannot be used inside
     * the OR-group (both drivers drop it there), and `whereJsonContains` on
     * the taxonomy's field is not the same test: the Stache's version
     * rejects a non-array value, while Statamic's own association wraps and
     * slugs whatever a `max_items: 1` field stored. Resolving through
     * `whereTaxonomy` at the top level keeps this in step with the policy
     * layer, which reads the same association (Enforcement\Facts). The list
     * is resolved once per request per territory; term territories are for
     * sites that are not tree-shaped, and are few.
     *
     * @return list<string>
     */
    private function entryIdsTagged(Territory $territory): array
    {
        return Blink::store('delegated')->once('term-ids:'.$territory->id, function () use ($territory): array {
            return Entry::query()
                ->where('site', $territory->site)
                ->whereTaxonomy($territory->scopeRef)
                ->get(['id'])
                ->map(fn ($entry): string => (string) $entry->id())
                ->all();
        });
    }

    /**
     * The one driver-specific token. Eloquent stores the collection handle in
     * a `collection` column. The Stache builder intercepts `collection` as a
     * scan-set selector rather than a clause, but indexes `collectionHandle`;
     * the search builder resolves `collectionHandle` through the entry's
     * method of that name.
     */
    private function collectionColumn(EntryQueryBuilder $query): string
    {
        return $query instanceof EloquentQueryBuilder ? 'collection' : 'collectionHandle';
    }
}
