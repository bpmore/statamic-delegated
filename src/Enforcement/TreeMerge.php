<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

/**
 * Merges a territory user's edited view of a structure tree back into the
 * whole stored tree, refusing anything that reaches outside the territory.
 *
 * The control panel's tree editor sends back the entire tree it was given
 * and Statamic replaces the stored tree with it. A territory user was given
 * a pruned tree (PrunesStructureTree): their entries whole, outside entries
 * dropped, outside ancestors and the root as placeholders. Saving that as-is
 * would throw away everything they could not see. So the submitted tree is
 * checked against the pruned view and folded into the stored tree:
 *
 *  - every id submitted must be one the user was shown, and every id shown
 *    must be submitted or deleted — nothing appears, nothing quietly goes;
 *  - a placeholder keeps the parent it had: outside entries do not move;
 *  - an inside entry may move only under another inside entry, or stay under
 *    the parent it had — never up to the root or under a placeholder, which
 *    is how content would leave the territory;
 *  - a deleted id must be inside, and so must every stored descendant of it,
 *    because Statamic deletes descendants with it;
 *  - dropped entries keep their place and their subtrees; among the slots
 *    the user's own entries occupied, their submitted order is used.
 *
 * Pure: trees in, tree out, membership asked through a callable.
 */
final class TreeMerge
{
    /** @var array<string, array<string, mixed>> id => stored node */
    private array $stored = [];

    /** @var array<string, string|null> id => stored parent id (null at root level) */
    private array $storedParent = [];

    /** @var array<string, bool> id => inside */
    private array $inside = [];

    /** @var array<string, bool> ids the user was shown: inside, or placeholder */
    private array $shown = [];

    /** @var array<string, bool> */
    private array $deleted = [];

    /** @var array<string, bool> */
    private array $submitted = [];

    /**
     * @param  callable(string): bool  $isInside  whether an entry id is inside the user's territories
     */
    public function __construct(private readonly mixed $isInside) {}

    /**
     * @param  list<array<string, mixed>>  $stored  Statamic's stored shape: entry/children
     * @param  list<array<string, mixed>>  $submitted  the editor's shape: id/children
     * @param  list<string>  $deleted  ids the request asks to delete
     * @return list<array<string, mixed>> the merged tree in the stored shape
     *
     * @throws TreeMergeRefused
     */
    public function merge(array $stored, array $submitted, array $deleted = []): array
    {
        $this->stored = $this->storedParent = $this->inside = $this->shown = $this->submitted = [];
        $this->deleted = array_fill_keys(array_map('strval', $deleted), true);

        $this->indexStored($stored, null);

        foreach ($this->deleted as $id => $_) {
            if (! isset($this->stored[$id])) {
                throw TreeMergeRefused::unknown($id);
            }
            if (! $this->inside[$id]) {
                throw TreeMergeRefused::deleteOutside($id);
            }
            foreach ($this->descendantIds($this->stored[$id]) as $descendant) {
                if (! $this->inside[$descendant]) {
                    throw TreeMergeRefused::deleteOutside($descendant);
                }
            }
        }

        $this->checkSubmitted($submitted, null);

        foreach ($this->shown as $id => $_) {
            if (! isset($this->submitted[$id]) && ! isset($this->deleted[$id])) {
                throw TreeMergeRefused::missing($id);
            }
        }

        return $this->rebuild($stored, $submitted);
    }

    // ------------------------------------------------------------ indexing

    /** @param  list<array<string, mixed>>  $nodes */
    private function indexStored(array $nodes, ?string $parent): void
    {
        foreach ($nodes as $node) {
            $id = $this->idOf($node);

            if ($id === null) {
                continue;
            }

            $this->stored[$id] = $node;
            $this->storedParent[$id] = $parent;
            $this->inside[$id] = ($this->isInside)($id);

            $this->indexStored($node['children'] ?? [], $id);
        }

        // Second pass at this level, now that descendants are classified: a
        // node is shown if inside, or if anything below it is inside, or if
        // it is the root of the tree (first node at the top level).
        foreach ($nodes as $index => $node) {
            $id = $this->idOf($node);

            if ($id === null) {
                continue;
            }

            if ($this->inside[$id] || $this->hasInsideDescendant($node) || ($parent === null && $index === 0)) {
                $this->shown[$id] = true;
            }
        }
    }

    /** @param  array<string, mixed>  $node */
    private function hasInsideDescendant(array $node): bool
    {
        foreach ($this->descendantIds($node) as $id) {
            if ($this->inside[$id]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function descendantIds(array $node): array
    {
        $ids = [];

        foreach ($node['children'] ?? [] as $child) {
            if (($id = $this->idOf($child)) !== null) {
                $ids[] = $id;
                $ids = array_merge($ids, $this->descendantIds($child));
            }
        }

        return $ids;
    }

    // ------------------------------------------------------------ checking

    /** @param  list<array<string, mixed>>  $nodes */
    private function checkSubmitted(array $nodes, ?string $parent): void
    {
        foreach ($nodes as $node) {
            $id = $this->idOf($node);

            if ($id === null) {
                throw TreeMergeRefused::unknown('(text page)');
            }

            if (! isset($this->stored[$id])) {
                throw TreeMergeRefused::unknown($id);
            }

            if (! isset($this->shown[$id])) {
                throw TreeMergeRefused::outside($id);
            }

            if (isset($this->submitted[$id])) {
                throw TreeMergeRefused::duplicate($id);
            }

            if (isset($this->deleted[$id])) {
                throw TreeMergeRefused::deletedAndKept($id);
            }

            $this->submitted[$id] = true;

            $storedParent = $this->storedParent[$id];
            $parentIsInside = $parent !== null && $this->inside[$parent];

            if ($this->inside[$id]) {
                if ($parent !== $storedParent && ! $parentIsInside) {
                    throw TreeMergeRefused::moveOutside($id);
                }
            } elseif ($parent !== $storedParent) {
                throw TreeMergeRefused::placeholderMoved($id);
            }

            $this->checkSubmitted($node['children'] ?? [], $id);
        }
    }

    // ------------------------------------------------------------ rebuilding

    /**
     * @param  list<array<string, mixed>>  $storedSiblings
     * @param  list<array<string, mixed>>  $submittedSiblings
     * @return list<array<string, mixed>>
     */
    private function rebuild(array $storedSiblings, array $submittedSiblings): array
    {
        $result = [];
        $queue = array_values($submittedSiblings);

        foreach ($storedSiblings as $node) {
            $id = $this->idOf($node);

            if ($id === null || ! isset($this->shown[$id])) {
                // Dropped: not the user's to see, so exactly as stored.
                $result[] = $node;

                continue;
            }

            // A slot the user could see. Fill it with their next node, whatever
            // it is; a slot with nothing left for it (a node deleted, or moved
            // elsewhere) simply closes up.
            if ($queue !== []) {
                $result[] = $this->rebuildNode(array_shift($queue));
            }
        }

        // Nodes moved in from elsewhere in the tree.
        foreach ($queue as $node) {
            $result[] = $this->rebuildNode($node);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function rebuildNode(array $submitted): array
    {
        $id = $this->idOf($submitted);
        $stored = $this->stored[$id];
        $children = $this->rebuild($stored['children'] ?? [], $submitted['children'] ?? []);

        $node = ['entry' => $id];

        if ($children !== []) {
            $node['children'] = $children;
        }

        return $node;
    }

    /** @param  array<string, mixed>  $node */
    private function idOf(array $node): ?string
    {
        $id = $node['entry'] ?? $node['id'] ?? null;

        return $id === null || $id === '' ? null : (string) $id;
    }
}
