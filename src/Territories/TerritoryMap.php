<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Territories;

/**
 * Every territory and assignment on the site, indexed for the two questions
 * resolution answers: which territories contain a piece of content, and which
 * territories a set of subjects belongs to.
 *
 * Built once and held for the request (spec §6: resolution runs per row on
 * every listing render). Pure: no framework, no clock, no I/O. The source
 * that builds it and the cache that keeps it are in Resolution\.
 */
final class TerritoryMap
{
    /** @var array<int, Territory> */
    private array $byId = [];

    /** @var array<string, list<Territory>> site => territories, most specific first */
    private array $bySite = [];

    /** @var array<int, list<int>> parent id => child ids */
    private array $children = [];

    /** @var array<string, list<Assignment>> subject => assignments */
    private array $bySubject = [];

    /**
     * @param  list<Territory>  $territories
     * @param  list<Assignment>  $assignments
     */
    public function __construct(array $territories, array $assignments = [])
    {
        foreach ($territories as $territory) {
            $this->byId[$territory->id] = $territory;
            $this->bySite[$territory->site][] = $territory;
        }

        foreach ($this->bySite as &$list) {
            usort($list, fn (Territory $a, Territory $b): int => $a->specificity() <=> $b->specificity());
        }
        unset($list);

        foreach ($this->byId as $territory) {
            // A parent that does not exist is treated as no parent: the
            // territory is a root. The doctor command reports the dangling id.
            if ($territory->parentId !== null && isset($this->byId[$territory->parentId])) {
                $this->children[$territory->parentId][] = $territory->id;
            }
        }

        foreach ($assignments as $assignment) {
            // An assignment to a territory that does not exist cannot grant
            // anything. Dropped here rather than trusted.
            if (isset($this->byId[$assignment->territoryId])) {
                $this->bySubject[$assignment->subject][] = $assignment;
            }
        }
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function find(int $id): ?Territory
    {
        return $this->byId[$id] ?? null;
    }

    /** @return list<Territory> */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function isEmpty(): bool
    {
        return $this->byId === [];
    }

    // ------------------------------------------------------------ content

    /**
     * Every territory whose scope covers the content, most specific first.
     * Empty means the content belongs to the site default: no territory, and
     * so nobody but a super user.
     *
     * @return list<Territory>
     */
    public function containing(ContentFacts $facts): array
    {
        return array_values(array_filter(
            $this->bySite[$facts->site] ?? [],
            fn (Territory $territory): bool => $territory->contains($facts),
        ));
    }

    /**
     * The one territory that owns the content: the most specific of those
     * containing it. Spec §4's "walk up until it finds an owning territory"
     * — walking up a branch is the same as taking the deepest matching prefix.
     */
    public function owner(ContentFacts $facts): ?Territory
    {
        return $this->containing($facts)[0] ?? null;
    }

    // ------------------------------------------------------------ hierarchy

    /**
     * The territory's parent chain, nearest first. Stops if it meets a
     * territory twice, so a cycle in the data cannot hang a request.
     *
     * @return list<Territory>
     */
    public function ancestors(Territory $territory): array
    {
        $seen = [$territory->id => true];
        $chain = [];

        while ($territory->parentId !== null && ($parent = $this->find($territory->parentId)) !== null) {
            if (isset($seen[$parent->id])) {
                break;
            }
            $seen[$parent->id] = true;
            $chain[] = $territory = $parent;
        }

        return $chain;
    }

    /**
     * Everything below the territory, depth first. Same cycle guard.
     *
     * @return list<Territory>
     */
    public function descendants(Territory $territory): array
    {
        $out = [];
        $seen = [$territory->id => true];
        $stack = $this->children[$territory->id] ?? [];

        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $this->byId[$id];
            foreach ($this->children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ subjects

    /**
     * Which territories the subjects are in, and at what level.
     *
     * Membership is the union over every subject (a user in two departments
     * is in both — spec §11), and flows down the hierarchy: whoever is in a
     * territory is in its descendants at the same level, so a department's
     * admin administers its sub-units. A level never flows up. Where two
     * routes reach the same territory the higher level wins.
     *
     * @param  list<string>  $subjects  "user:<id>" and "group:<id>" strings
     */
    public function membershipFor(array $subjects): Membership
    {
        $levels = [];

        $grant = function (int $territoryId, Level $level) use (&$levels): void {
            $levels[$territoryId] = isset($levels[$territoryId])
                ? Level::highest($levels[$territoryId], $level)
                : $level;
        };

        foreach (array_unique($subjects) as $subject) {
            foreach ($this->bySubject[$subject] ?? [] as $assignment) {
                $grant($assignment->territoryId, $assignment->level);

                foreach ($this->descendants($this->byId[$assignment->territoryId]) as $descendant) {
                    $grant($descendant->id, $assignment->level);
                }
            }
        }

        return new Membership($levels);
    }
}
