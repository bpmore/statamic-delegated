<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Resolution;

use Bpmore\StatamicDelegated\Territories\ContentFacts;
use Bpmore\StatamicDelegated\Territories\Membership;
use Bpmore\StatamicDelegated\Territories\Territory;
use Bpmore\StatamicDelegated\Territories\TerritoryMap;
use Illuminate\Contracts\Cache\Repository as Cache;
use Statamic\Facades\Blink;

/**
 * The one place that answers "which territory owns this" and "which
 * territories is this user in". The policy layer and the listing-query
 * compiler both ask here, so they cannot disagree (Phase 0 conclusion).
 *
 * Caching (spec §6): the whole map — every territory and assignment — is
 * built once and cached under a version stamp. Any write to either table
 * calls invalidate(), which bumps the stamp; every cached copy anywhere then
 * misses on its next read. Within a request the map is memoised in Blink, so
 * a listing of a thousand rows loads it once. Membership is computed from
 * the map per call: it is a hash lookup per subject, and computing it means
 * a user's groups — which Statamic edits without telling us — are always
 * read fresh rather than cached against a stale list.
 */
final class TerritoryResolver
{
    private const VERSION_KEY = 'delegated.territories.version';

    private const BLINK_STORE = 'delegated';

    public function __construct(
        private readonly TerritorySource $source,
        private readonly Cache $cache,
        private readonly ?int $ttl = 3600,
    ) {}

    public function map(): TerritoryMap
    {
        return Blink::store(self::BLINK_STORE)->once('map', fn (): TerritoryMap => $this->cached());
    }

    /** @return list<Territory> */
    public function containing(ContentFacts $facts): array
    {
        return $this->map()->containing($facts);
    }

    public function owner(ContentFacts $facts): ?Territory
    {
        return $this->map()->owner($facts);
    }

    /**
     * @param  list<string>  $subjects  "user:<id>" and "group:<id>" strings
     */
    public function membershipFor(array $subjects): Membership
    {
        return $this->map()->membershipFor($subjects);
    }

    /**
     * Forget every cached copy of the map, everywhere. Called after any write
     * to a territory or an assignment. Cheap: one counter, no scanning.
     */
    public function invalidate(): void
    {
        $this->cache->forever(self::VERSION_KEY, $this->version() + 1);

        Blink::store(self::BLINK_STORE)->flush();
    }

    public function version(): int
    {
        return (int) $this->cache->get(self::VERSION_KEY, 0);
    }

    private function cached(): TerritoryMap
    {
        $key = 'delegated.territories.map.v'.$this->version();
        $build = fn (): TerritoryMap => new TerritoryMap($this->source->territories(), $this->source->assignments());

        return $this->ttl === null
            ? $this->cache->rememberForever($key, $build)
            : $this->cache->remember($key, $this->ttl, $build);
    }
}
