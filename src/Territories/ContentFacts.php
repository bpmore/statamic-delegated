<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Territories;

/**
 * What resolution needs to know about a piece of content, and nothing else.
 *
 * Plain values rather than an Entry so the resolver can be tested without a
 * booted Statamic, and so the same facts can be built from an Entry, from a
 * query row, or from a request that names a parent the entry does not yet
 * have (the create-under-parent case from Phase 0.1).
 */
final class ContentFacts
{
    public readonly string $site;

    public readonly string $collection;

    /** Null for an entry that is not in its collection's tree (an orphan). */
    public readonly ?string $uri;

    /** @var list<string> Term references, "taxonomy::slug". */
    public readonly array $terms;

    /**
     * @param  list<string>  $terms
     */
    public function __construct(string $site, string $collection, ?string $uri, array $terms = [])
    {
        $this->site = $site;
        $this->collection = Normalise::collection($collection);
        $this->uri = $uri === null ? null : Normalise::uri($uri);
        $this->terms = array_values(array_unique(array_map(Normalise::term(...), $terms)));
    }
}
