<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Bpmore\StatamicDelegated\Territories\ContentFacts;
use Statamic\Contracts\Entries\Entry;
use Statamic\Support\Arr;
use Statamic\Support\Str;

/**
 * An entry, as the resolver needs to see it. This is the only place an
 * Entry is turned into ContentFacts, so the policy layer and the listing
 * predicate cannot drift apart:
 *
 *  - uri is Entry::uri(), which is what the listing's `uri` clause compares
 *    against on both drivers (the Stache index, the Eloquent column);
 *  - terms are read the way Statamic associates entries with terms for
 *    `whereTaxonomy()`: the value under the *taxonomy's* handle, wrapped and
 *    slugged (Stache\Stores\TaxonomyTermsStore::sync).
 */
final class Facts
{
    public static function of(Entry $entry): ContentFacts
    {
        $terms = [];

        foreach ($entry->collection()->taxonomies() as $taxonomy) {
            $handle = $taxonomy->handle();

            foreach (Arr::wrap($entry->value($handle)) as $value) {
                if (is_string($value) && $value !== '') {
                    $terms[] = $handle.'::'.Str::slug($value);
                }
            }
        }

        return new ContentFacts(
            site: (string) $entry->locale(),
            collection: (string) $entry->collectionHandle(),
            uri: $entry->uri(),
            terms: $terms,
        );
    }
}
