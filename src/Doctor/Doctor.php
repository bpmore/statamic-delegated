<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Doctor;

use Bpmore\StatamicDelegated\Administration\ManagementPermissions;
use Bpmore\StatamicDelegated\Enforcement\Facts;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Territories\ScopeType;
use Bpmore\StatamicDelegated\Territories\Territory;
use Bpmore\StatamicDelegated\Territories\TerritoryMap;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

/**
 * The checks behind `delegated:doctor`: everything that can drift between
 * Delegated's tables and the site they describe, and the two configuration
 * states that quietly defeat the territory model. Reads only. Each check
 * yields at most one Finding, with a line per affected thing.
 *
 * The problems are the ones that mean content is reachable by the wrong
 * people or by nobody:
 *
 *  - a territory whose scope no longer exists (spec §11: an entry whose
 *    territory is deleted falls back to the site default — here it is the
 *    territory that has outlived its branch, collection or term);
 *  - a non-super user holding `configure collections`, for whom
 *    EntryPolicy::before() answers yes to everything (Phase 0.1).
 *
 * The rest are warnings and notes: assignments to people who are gone,
 * dangling parents, entries outside their collection's stored tree (the
 * duplicate-a-top-level-page wart from Phase 3.2.b), territory admins who
 * hold a permission that lets them out of their territory by another door,
 * and the counts of content that belongs to no territory.
 */
final class Doctor
{
    public function __construct(private readonly TerritoryResolver $resolver) {}

    /** @return list<Finding> */
    public function diagnose(): array
    {
        $map = $this->resolver->map();

        return array_values(array_filter([
            $this->territoriesWithMissingScope($map),
            $this->territoriesWithMissingParent($map),
            $this->assignmentsToMissingSubjects($map),
            $this->territoriesWithNobody($map),
            $this->configureCollectionsHolders(),
            $this->adminsWithManagementPermissions($map),
            $this->entriesOutsideTheirTree(),
            $this->treesNamingMissingEntries(),
            $this->entriesNobodyOwns($map),
        ]));
    }

    // ------------------------------------------------------------ territories

    private function territoriesWithMissingScope(TerritoryMap $map): ?Finding
    {
        $lines = [];

        foreach ($map->all() as $t) {
            $reason = match (true) {
                Site::get($t->site) === null => "site '{$t->site}' does not exist",
                $t->scopeType === ScopeType::Collection && Collection::find($t->scopeRef) === null => "collection '{$t->scopeRef}' does not exist",
                $t->scopeType === ScopeType::TaxonomyTerm => $this->missingTerm($t->scopeRef),
                $t->scopeType === ScopeType::StructureBranch && $t->scopeRef !== '/' && Entry::findByUri($t->scopeRef, $t->site) === null => "nothing is at {$t->scopeRef} in site '{$t->site}'",
                default => null,
            };

            if ($reason !== null) {
                $lines[] = self::name($t).": {$reason}. Its members can reach nothing; its content, if any, belongs to nobody.";
            }
        }

        return $lines === [] ? null : Finding::problem('territory-scope-missing', 'Territories whose scope no longer exists', $lines);
    }

    private function missingTerm(string $ref): ?string
    {
        [$taxonomy, $slug] = array_pad(explode('::', $ref, 2), 2, '');

        if (Taxonomy::find($taxonomy) === null) {
            return "taxonomy '{$taxonomy}' does not exist";
        }

        return Term::find($ref) === null ? "term '{$slug}' does not exist in taxonomy '{$taxonomy}'" : null;
    }

    private function territoriesWithMissingParent(TerritoryMap $map): ?Finding
    {
        $lines = [];

        foreach ($map->all() as $t) {
            if ($t->parentId !== null && $map->find($t->parentId) === null) {
                $lines[] = self::name($t)." names parent #{$t->parentId}, which does not exist. It is treated as a top-level territory.";
            }
        }

        return $lines === [] ? null : Finding::warning('territory-parent-missing', 'Territories whose parent no longer exists', $lines);
    }

    private function assignmentsToMissingSubjects(TerritoryMap $map): ?Finding
    {
        $ids = array_map(fn (Territory $t): int => $t->id, $map->all());
        $lines = [];

        foreach ($map->subjectsIn($ids) as $subject) {
            [$type, $id] = explode(':', $subject, 2);

            $exists = $type === 'user' ? User::find($id) !== null : UserGroup::find($id) !== null;

            if (! $exists) {
                $in = collect($map->assignmentsFor([$subject]))->map(fn ($a) => self::name($map->find($a->territoryId)))->implode(', ');
                $lines[] = "{$type} '{$id}' no longer exists but is assigned to {$in}. Revoke it from the Territories screen.";
            }
        }

        return $lines === [] ? null : Finding::warning('assignment-subject-missing', 'Assignments to users or groups that no longer exist', $lines);
    }

    private function territoriesWithNobody(TerritoryMap $map): ?Finding
    {
        $lines = [];

        foreach ($map->all() as $t) {
            // Inherited membership counts: a sub-territory of a staffed one is staffed.
            $chain = array_merge([$t->id], array_map(fn (Territory $a): int => $a->id, $map->ancestors($t)));

            if ($map->subjectsIn($chain) === []) {
                $lines[] = self::name($t).' has no members. Only site administrators can reach its content.';
            }
        }

        return $lines === [] ? null : Finding::note('territory-empty', 'Territories with nobody assigned', $lines);
    }

    // ------------------------------------------------------------ people

    private function configureCollectionsHolders(): ?Finding
    {
        $lines = [];

        foreach (User::all() as $user) {
            if (! $user->isSuper() && $user->hasPermission('configure collections')) {
                $lines[] = self::person($user).' holds `configure collections` (through '.$this->rolesGranting($user, 'configure collections').'). Statamic exempts them from every entry check, so no territory applies to them.';
            }
        }

        return $lines === [] ? null : Finding::problem('configure-collections-holder', 'Non-super users who bypass every entry check', $lines);
    }

    private function adminsWithManagementPermissions(TerritoryMap $map): ?Finding
    {
        $lines = [];

        foreach ($this->territoryAdmins($map) as $id => $territories) {
            $user = User::find($id);

            if ($user === null || $user->isSuper()) {
                continue;
            }

            $held = array_values(array_filter(ManagementPermissions::ALL, fn (string $p): bool => $p !== 'super' && $p !== 'configure collections' && $user->hasPermission($p)));

            if ($held !== []) {
                $lines[] = self::person($user).' administers '.implode(', ', $territories).' and also holds `'.implode('`, `', $held)."` — Statamic's own screens let them reach users outside the territory.";
            }
        }

        return $lines === [] ? null : Finding::warning('territory-admin-with-management-permission', 'Territory admins who can leave their territory through Statamic\'s user screens', $lines);
    }

    /**
     * Every user who administers a territory, directly or through a group,
     * with the names of the territories.
     *
     * @return array<string, list<string>> user id => territory names
     */
    private function territoryAdmins(TerritoryMap $map): array
    {
        $admins = [];

        foreach ($map->all() as $t) {
            foreach ($map->subjectsIn([$t->id]) as $subject) {
                [$type, $id] = explode(':', $subject, 2);

                if (! collect($map->assignmentsFor([$subject]))->contains(fn ($a) => $a->territoryId === $t->id && $a->level === Level::Admin)) {
                    continue;
                }

                $users = $type === 'user'
                    ? [$id]
                    : (UserGroup::find($id)?->queryUsers()->get()->map(fn ($u) => (string) $u->id())->all() ?? []);

                foreach ($users as $userId) {
                    $admins[$userId][] = self::name($t);
                }
            }
        }

        return array_map(fn (array $names): array => array_values(array_unique($names)), $admins);
    }

    // ------------------------------------------------------------ entries

    private function entriesOutsideTheirTree(): ?Finding
    {
        $lines = [];

        foreach (Collection::all() as $collection) {
            if (! $collection->hasStructure()) {
                continue;
            }

            foreach ($collection->sites() as $site) {
                $tree = $collection->structure()->in($site);

                if ($tree === null) {
                    continue;
                }

                $inTree = array_flip($this->idsIn($tree->fileData()['tree'] ?? []));

                foreach ($collection->queryEntries()->where('site', $site)->get() as $entry) {
                    if (! isset($inTree[(string) $entry->id()])) {
                        $lines[] = "{$collection->handle()}/{$site}: entry '{$entry->id()}' (".($entry->get('title') ?? $entry->slug()).') is not in the stored tree. Statamic shows it at the root; below a root territory it belongs to nobody. Place or delete it.';
                    }
                }
            }
        }

        return $lines === [] ? null : Finding::warning('entry-outside-tree', 'Entries missing from their collection\'s stored tree', $lines);
    }

    /**
     * The other direction: a stored tree naming an entry that is not in the
     * collection. Statamic drops the reference silently on read, and every
     * branch below it with it — a territory rooted there covers nothing.
     */
    private function treesNamingMissingEntries(): ?Finding
    {
        $lines = [];

        foreach (Collection::all() as $collection) {
            if (! $collection->hasStructure()) {
                continue;
            }

            foreach ($collection->sites() as $site) {
                $tree = $collection->structure()->in($site);

                if ($tree === null) {
                    continue;
                }

                $known = array_flip($collection->queryEntries()->where('site', $site)->get()->map(fn (EntryContract $e): string => (string) $e->id())->all());

                foreach ($this->idsIn($tree->fileData()['tree'] ?? []) as $id) {
                    if (! isset($known[$id])) {
                        $lines[] = "{$collection->handle()}/{$site}: the stored tree names entry '{$id}', which is not in the collection. Statamic drops it, and everything under it, on read.";
                    }
                }
            }
        }

        return $lines === [] ? null : Finding::warning('tree-names-missing-entry', 'Stored trees naming entries that do not exist', $lines);
    }

    private function entriesNobodyOwns(TerritoryMap $map): ?Finding
    {
        $lines = [];

        foreach (Site::all() as $site) {
            $handle = $site->handle();

            if (! collect($map->all())->contains(fn (Territory $t): bool => $t->site === $handle)) {
                $lines[] = "site '{$handle}' has no territories: everyone with Statamic's permissions reaches everything in it.";

                continue;
            }

            foreach (Collection::all() as $collection) {
                if (! in_array($handle, $collection->sites()->all(), true)) {
                    continue;
                }

                $unowned = $collection->queryEntries()->where('site', $handle)->get()
                    ->filter(fn (EntryContract $e): bool => $map->owner(Facts::of($e)) === null);

                if ($unowned->isNotEmpty()) {
                    $lines[] = "{$collection->handle()}/{$handle}: {$unowned->count()} of {$this->countIn($collection, $handle)} entries belong to no territory (site administrators only): ".$this->sample($unowned).'.';
                }
            }
        }

        return $lines === [] ? null : Finding::note('entry-unowned', 'Content that belongs to no territory', $lines);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  list<array<string, mixed>>  $tree
     * @return list<string>
     */
    private function idsIn(array $tree): array
    {
        $ids = [];

        foreach ($tree as $node) {
            if (isset($node['entry'])) {
                $ids[] = (string) $node['entry'];
            }
            $ids = array_merge($ids, $this->idsIn($node['children'] ?? []));
        }

        return $ids;
    }

    private function countIn(CollectionContract $collection, string $site): int
    {
        return $collection->queryEntries()->where('site', $site)->count();
    }

    /** @param  \Illuminate\Support\Collection<int, EntryContract>  $entries */
    private function sample(\Illuminate\Support\Collection $entries): string
    {
        $shown = $entries->take(5)->map(fn (EntryContract $e): string => $e->uri() ?? (string) $e->id())->implode(', ');

        return $entries->count() > 5 ? $shown.', …' : $shown;
    }

    private function rolesGranting(UserContract $user, string $permission): string
    {
        $roles = $user->roles()->filter(fn ($r) => $r->hasPermission($permission))->map->handle()->values()->all();

        return $roles === [] ? 'a group' : 'role '.implode(', ', $roles);
    }

    private static function name(?Territory $t): string
    {
        return $t === null ? 'a territory that does not exist' : "'{$t->name}' (#{$t->id}, {$t->scopeType->value} {$t->scopeRef} in {$t->site})";
    }

    private static function person(UserContract $user): string
    {
        return "user '{$user->id()}' ({$user->email()})";
    }
}
