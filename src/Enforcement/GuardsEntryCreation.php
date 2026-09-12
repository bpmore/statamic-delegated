<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Statamic\Facades\User;

/**
 * Enforcement point: creating an entry (Phase 0.1, "create under a parent").
 *
 * Middleware on the authenticated control-panel group, acting only on the
 * two routes that place a new entry: the store request (`_parent`) and the
 * create screen (`parent`). The `create` ability cannot see where the entry
 * will go, so this asks Placement where it would land and refuses if that
 * is outside the user's territories — including the root of a structured
 * collection, which belongs to no department.
 */
final class GuardsEntryCreation
{
    private const ROUTES = [
        'statamic.cp.collections.entries.store' => '_parent',
        'statamic.cp.collections.entries.create' => 'parent',
    ];

    public function __construct(
        private readonly Placement $placement,
        private readonly Territorial $territorial,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $parentKey = self::ROUTES[$request->route()?->getName() ?? ''] ?? null;

        if ($parentKey === null) {
            return $next($request);
        }

        $user = User::current();

        if (Exempt::user($user)) {
            return $next($request);
        }

        try {
            $facts = $this->placement->facts(
                $request->route('collection'),
                $request->route('site'),
                $request->input($parentKey) !== null ? (string) $request->input($parentKey) : null,
                $request->input('slug') !== null ? (string) $request->input('slug') : null,
                $request->all(),
            );
        } catch (InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }

        if (! $this->territorial->allowsFacts($user, $facts)) {
            abort(403, 'This entry would be created outside your territory.');
        }

        return $next($request);
    }
}
