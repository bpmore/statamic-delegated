<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Support\Str;

/**
 * Enforcement point: the structure tree (Phase 0.1, "Structure / tree view").
 *
 * Statamic's tree endpoint has no authorisation at all and returns every
 * entry's id, title, slug, URL and status to any control-panel user. This
 * middleware, on the two tree routes, first requires `view` on the
 * collection — the check the collection's own page already makes — and
 * then, for a user with territories, prunes the JSON: an entry inside the
 * territory is kept whole; one outside is dropped; one outside that sits
 * *above* an inside entry (or is the tree's root) stays as a redacted
 * placeholder, so the tree keeps its shape and a move within the territory
 * can be merged back into it (Phase 3.2.e). A placeholder carries the id
 * the tree needs and nothing a person could read.
 */
final class PrunesStructureTree
{
    private const ROUTE = 'statamic.cp.collections.tree.index';

    public function __construct(
        private readonly Gate $gate,
        private readonly Territorial $territorial,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if ($request->route()?->getName() !== self::ROUTE) {
            return $next($request);
        }

        $user = User::current();
        $collection = $request->route('collection');

        if (Exempt::user($user)) {
            return $next($request);
        }

        $this->gate->authorize('view', $collection);

        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);
        $data['pages'] = $this->prune($data['pages'] ?? [], $user, isTopLevel: true, expectsRoot: (bool) $collection->structure()?->expectsRoot());

        return $response->setData($data);
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function prune(array $pages, $user, bool $isTopLevel, bool $expectsRoot): array
    {
        $kept = [];

        foreach ($pages as $index => $page) {
            $children = $this->prune($page['children'] ?? [], $user, false, $expectsRoot);
            $inside = $this->inside($page, $user);
            $isRoot = $isTopLevel && $expectsRoot && $index === 0;

            if ($inside) {
                $page['children'] = $children;
                $kept[] = $page;
            } elseif ($children !== [] || $isRoot) {
                $kept[] = $this->redact($page, $children);
            }
        }

        return $kept;
    }

    /** @param  array<string, mixed>  $page */
    private function inside(array $page, $user): bool
    {
        $id = $page['entry'] ?? null;

        if ($id === null) {
            // A text or URL branch is not content and belongs to nobody.
            return false;
        }

        $entry = Entry::find($id);

        return $entry !== null && $this->territorial->allows($user, $entry);
    }

    /**
     * The shape the tree needs, and nothing a person could read. The title
     * is the URL's last segment, which the user already knows from their
     * own entries' URLs.
     *
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function redact(array $page, array $children): array
    {
        $segment = Str::afterLast(rtrim((string) ($page['url'] ?? ''), '/'), '/');

        return [
            'id' => $page['id'] ?? null,
            'entry' => $page['entry'] ?? null,
            'title' => $segment !== '' ? Str::title(str_replace('-', ' ', $segment)) : null,
            'entry_title' => null,
            'entry_blueprint' => null,
            'url' => null,
            'edit_url' => null,
            'can_delete' => false,
            'slug' => null,
            'status' => null,
            'redirect' => null,
            'collection' => null,
            'children' => $children,
            'delegated_redacted' => true,
        ];
    }
}
