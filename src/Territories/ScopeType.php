<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Territories;

/**
 * The three kinds of territory (spec §4). The backing values are what the
 * database stores in delegated_territories.scope_type.
 */
enum ScopeType: string
{
    // Everything under a branch of a structured collection. The primary case.
    case StructureBranch = 'structure_branch';

    // An entire collection, for a team that owns a content type.
    case Collection = 'collection';

    // Everything tagged with a term, for a site that is not tree-shaped.
    case TaxonomyTerm = 'taxonomy_term';

    /**
     * Where this kind sits when more than one territory contains the same
     * content. Lower wins. A branch beats a term because the tree is where
     * content *lives*; a term is a field an editor could change to hand
     * content to another department. A collection is the coarsest of all.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::StructureBranch => 0,
            self::TaxonomyTerm => 1,
            self::Collection => 2,
        };
    }
}
