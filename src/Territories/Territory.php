<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Territories;

/**
 * A territory as the resolver sees it: a scope, a site, and a place in the
 * hierarchy. Immutable; built from a delegated_territories row.
 */
final class Territory
{
    public readonly string $scopeRef;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $site,
        public readonly ScopeType $scopeType,
        string $scopeRef,
        public readonly ?int $parentId = null,
    ) {
        $this->scopeRef = Normalise::ref($scopeType, $scopeRef);
    }

    /**
     * Whether this territory's scope covers the content. Site-bound: a
     * territory never reaches across sites, whatever its scope.
     */
    public function contains(ContentFacts $facts): bool
    {
        if ($facts->site !== $this->site) {
            return false;
        }

        return match ($this->scopeType) {
            ScopeType::StructureBranch => $this->containsUri($facts->uri),
            ScopeType::Collection => $facts->collection === $this->scopeRef,
            ScopeType::TaxonomyTerm => in_array($this->scopeRef, $facts->terms, true),
        };
    }

    private function containsUri(?string $uri): bool
    {
        // An orphan — not in the tree, no URI — is under no branch. It belongs
        // to nobody rather than to everybody (Phase 0.1: the duplicate-entry
        // exfiltration path relies on the opposite reading).
        if ($uri === null) {
            return false;
        }

        if ($this->scopeRef === '/') {
            return true;
        }

        // The branch itself, or anything below it. Never a sibling that
        // happens to share the prefix: /cardiology does not cover
        // /cardiology-alumni.
        return $uri === $this->scopeRef || str_starts_with($uri, $this->scopeRef.'/');
    }

    /**
     * How specific this territory is, for ordering when several contain the
     * same content. Type first (see ScopeType::precedence), then the deeper
     * branch, then the id so the order is stable.
     *
     * @return array{int, int, int}
     */
    public function specificity(): array
    {
        $depth = $this->scopeType === ScopeType::StructureBranch && $this->scopeRef !== '/'
            ? substr_count($this->scopeRef, '/')
            : 0;

        return [$this->scopeType->precedence(), -$depth, $this->id];
    }
}
