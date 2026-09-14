<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Bpmore\StatamicDelegated\Territories\ContentFacts;
use InvalidArgumentException;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Routing\UrlBuilder;
use Statamic\Facades\Entry;
use Statamic\Sites\Site;
use Statamic\Support\Str;

/**
 * Where an entry that does not exist yet would land, as ContentFacts.
 *
 * The `create` ability carries no parent (Phase 0.1), so the store request
 * has to be read instead: its `_parent`, slug and values decide the URI and
 * terms the new entry will have, and therefore which territory it will be
 * in. The URI is computed the way EntriesController::entryUri computes it
 * for its own uniqueness check, so the two agree.
 */
final class Placement
{
    public function __construct(private readonly UrlBuilder $urls) {}

    /**
     * @param  array<string, mixed>  $values  the submitted entry values
     *
     * @throws InvalidArgumentException when the named parent is not in the tree
     */
    public function facts(Collection $collection, Site $site, ?string $parentId, ?string $slug, array $values): ContentFacts
    {
        $slug = $slug !== null && $slug !== '' ? $slug : Str::slug((string) ($values['title'] ?? 'untitled'));

        $entry = Entry::make()->collection($collection)->locale($site->handle())->slug($slug)->data($values);

        return new ContentFacts(
            site: $site->handle(),
            collection: $collection->handle(),
            uri: $this->uri($collection, $site, $parentId, $entry),
            terms: Facts::termsFrom($collection, fn (string $handle) => $values[$handle] ?? null),
        );
    }

    private function uri(Collection $collection, Site $site, ?string $parentId, $entry): ?string
    {
        if (! $entry->route()) {
            return null;
        }

        $tree = $collection->structure()?->in($site->handle());

        if ($tree === null) {
            // Not structured: the route alone decides, as for any entry.
            return $entry->uri();
        }

        $parent = null;

        if ($parentId !== null && $parentId !== '') {
            // A parent that is not in the tree is not "no parent". Statamic
            // would throw on append; refusing here says so before any check.
            $parent = $tree->find($parentId)
                ?? throw new InvalidArgumentException("Parent [{$parentId}] is not in the tree");

            if ($parent->isRoot()) {
                $parent = null;
            }
        }

        return $this->urls
            ->content($entry)
            ->merge([
                'parent_uri' => $parent?->uri(),
                'slug' => $entry->slug(),
                'depth' => $parent ? $parent->depth() + 1 : 1,
                'is_root' => false,
            ])
            ->build($entry->route());
    }
}
