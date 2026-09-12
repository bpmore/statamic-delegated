<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Closure;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Enforcement point: saving the structure tree, and reordering a linear
 * collection (Phase 0.1, "Tree update" and "Flat reorder").
 *
 * Statamic authorises both on `reorder {collection} entries` and then
 * replaces the stored tree with whatever was submitted. For a territory
 * user the submission is their pruned view; TreeMerge checks it against
 * that view and folds it into the whole tree, and the controller receives
 * the merged tree in place of the request's. Anything the merge refuses
 * refuses the whole request, before anything is written.
 */
final class GuardsStructureTreeUpdate
{
    private const TREE_ROUTE = 'statamic.cp.collections.tree.update';

    private const REORDER_ROUTE = 'statamic.cp.collections.entries.reorder';

    public function __construct(private readonly Territorial $territorial) {}

    public function handle(Request $request, Closure $next)
    {
        $route = $request->route()?->getName();

        if ($route !== self::TREE_ROUTE && $route !== self::REORDER_ROUTE) {
            return $next($request);
        }

        $user = User::current();

        if (Exempt::user($user)) {
            return $next($request);
        }

        $isInside = fn (string $id): bool => ($entry = Entry::find($id)) !== null && $this->territorial->allows($user, $entry);

        if ($route === self::REORDER_ROUTE) {
            foreach ((array) $request->input('ids', []) as $id) {
                if (! $isInside((string) $id)) {
                    abort(403, "Entry [{$id}] is outside your territory");
                }
            }

            return $next($request);
        }

        $collection = $request->route('collection');
        $site = (string) ($request->input('site') ?? Site::selected()->handle());
        $stored = $collection->structure()?->in($site)?->tree() ?? [];

        try {
            $merged = (new TreeMerge($isInside))->merge(
                $stored,
                (array) $request->input('pages', []),
                array_map('strval', (array) $request->input('deletedEntries', [])),
            );
        } catch (TreeMergeRefused $e) {
            abort(403, $e->getMessage());
        }

        $request->merge(['pages' => $this->toEditorShape($merged)]);

        return $next($request);
    }

    /**
     * The controller reads id/title/url/children; the stored shape has
     * entry/title/url/children.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function toEditorShape(array $nodes): array
    {
        return array_map(fn (array $node): array => [
            'id' => $node['entry'] ?? null,
            'title' => $node['title'] ?? null,
            'url' => $node['url'] ?? null,
            'children' => $this->toEditorShape($node['children'] ?? []),
        ], $nodes);
    }
}
