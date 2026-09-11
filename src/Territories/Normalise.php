<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Territories;

/**
 * One spelling for every reference, applied both when a territory is stored
 * and when content is compared against it.
 *
 * The Phase 0.3 spike found the two drivers disagree about case: the Stache
 * lower-cases both sides of `=`, SQLite compares `=` exactly and LIKE
 * case-insensitively, MySQL depends on collation. Comparing anything that has
 * not been through here is a bug.
 */
final class Normalise
{
    /** A branch URI: lower-case, one leading slash, no trailing slash, root is "/". */
    public static function uri(string $uri): string
    {
        $uri = '/'.trim(mb_strtolower(trim($uri)), '/');

        return $uri === '//' ? '/' : $uri;
    }

    /** A collection handle. */
    public static function collection(string $handle): string
    {
        return mb_strtolower(trim($handle));
    }

    /** A term reference in Statamic's "taxonomy::slug" form. */
    public static function term(string $term): string
    {
        return mb_strtolower(trim($term));
    }

    public static function ref(ScopeType $type, string $ref): string
    {
        return match ($type) {
            ScopeType::StructureBranch => self::uri($ref),
            ScopeType::Collection => self::collection($ref),
            ScopeType::TaxonomyTerm => self::term($ref),
        };
    }
}
