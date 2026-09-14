<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Contracts\Structures\Nav;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav as NavFacade;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Enforcement point: navigation editing (Phase 0.1, "Navigation tree" GET
 * and update).
 *
 * A navigation is public site structure, so its branches are not hidden.
 * What a branch reveals *about the entry behind it* is: reading the tree
 * requires `view` on the nav (the endpoint had no check at all), and a
 * branch linked to an entry outside the user's territories comes back with
 * everything entry-derived redacted — the title, status, blueprint and edit
 * URL, and the URL itself unless the entry is published and so already
 * public. Saving the tree may not link a new branch, or re-point an existing
 * one, to an entry outside the territory. Moving and re-titling branches is
 * nav work, governed by the nav's own permission, and is left alone.
 */
final class GuardsNavigationTree
{
    private const INDEX_ROUTE = 'statamic.cp.navigation.tree.index';

    private const UPDATE_ROUTE = 'statamic.cp.navigation.tree.update';

    public function __construct(
        private readonly Gate $gate,
        private readonly Territorial $territorial,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $route = $request->route()?->getName();

        if ($route !== self::INDEX_ROUTE && $route !== self::UPDATE_ROUTE) {
            return $next($request);
        }

        $user = User::current();

        if (Exempt::user($user)) {
            return $next($request);
        }

        $nav = $this->nav($request);

        if ($nav === null) {
            return $next($request);
        }

        $site = (string) ($request->input('site') ?? Site::selected()->handle());

        if ($route === self::UPDATE_ROUTE) {
            $this->refuseOutsideLinks($request, $nav, $site, $user);

            return $next($request);
        }

        $this->gate->authorize('view', $nav->in($site));

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $data['pages'] = $this->redact($data['pages'] ?? [], $user);
            $response->setData($data);
        }

        return $response;
    }

    private function nav(Request $request): ?Nav
    {
        $param = $request->route('navigation') ?? $request->route('nav') ?? $request->route('handle');

        return $param instanceof Nav ? $param : NavFacade::find((string) $param);
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function redact(array $pages, $user): array
    {
        foreach ($pages as &$page) {
            $page['children'] = $this->redact($page['children'] ?? [], $user);

            if (($id = $page['entry'] ?? null) === null) {
                continue;
            }

            $entry = Entry::find($id);

            if ($entry !== null && $this->territorial->allows($user, $entry)) {
                continue;
            }

            $page['entry_title'] = null;
            $page['entry_blueprint'] = null;
            $page['edit_url'] = null;
            $page['status'] = null;
            $page['redirect'] = null;
            $page['collection'] = null;
            $page['can_delete'] = false;
            $page['url'] = $entry !== null && $entry->published() ? $page['url'] : null;
            $page['delegated_redacted'] = true;
        }

        return $pages;
    }

    private function refuseOutsideLinks(Request $request, Nav $nav, string $site, $user): void
    {
        $stored = $this->flatten($nav->in($site)?->tree() ?? []);

        foreach ((array) $request->input('data', []) as $id => $branch) {
            $target = $branch['entry'] ?? null;

            if ($target === null || $target === '') {
                continue;
            }

            $isNew = (bool) ($branch['new'] ?? false);
            $unchanged = ! $isNew && (($stored[(string) $id] ?? null) === (string) $target);

            if ($unchanged) {
                continue;
            }

            $entry = Entry::find($target);

            if ($entry === null || ! $this->territorial->allows($user, $entry)) {
                abort(403, "Entry [{$target}] is outside your territory and cannot be linked");
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $branches
     * @return array<string, string|null> branch id => linked entry id
     */
    private function flatten(array $branches): array
    {
        $out = [];

        foreach ($branches as $branch) {
            if (isset($branch['id'])) {
                $out[(string) $branch['id']] = isset($branch['entry']) ? (string) $branch['entry'] : null;
            }
            $out += $this->flatten($branch['children'] ?? []);
        }

        return $out;
    }
}
