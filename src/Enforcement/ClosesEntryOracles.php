<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Closure;
use Illuminate\Http\Request;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Data;
use Statamic\Facades\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Enforcement point: the two redirect routes that told any control-panel
 * user whether an entry id exists, and in which collection, before any
 * policy ran (Phase 0.1, "Direct URL to an entry ID").
 *
 *   GET cp/edit/{id}                                   -> 302 to the edit URL
 *   GET cp/collections/{collection}/entries/{entry}/{slug} -> 302 to the edit URL
 *
 * For a user Delegated confines, an entry outside their territories now
 * answers exactly as a non-existent one does: 404. Neither route is named,
 * so they are matched by their URI pattern.
 */
final class ClosesEntryOracles
{
    public function __construct(private readonly Territorial $territorial) {}

    public function handle(Request $request, Closure $next)
    {
        $uri = $request->route()?->uri() ?? '';
        $cp = trim((string) config('statamic.cp.route', 'cp'), '/');

        $entry = match (true) {
            $uri === "{$cp}/edit/{id}" => Data::find((string) $request->route('id')),
            $uri === "{$cp}/collections/{collection}/entries/{entry}/{slug}" => $request->route('entry'),
            default => null,
        };

        if ($entry instanceof Entry) {
            $user = User::current();

            if (! Exempt::user($user) && ! $this->territorial->allows($user, $entry)) {
                throw new NotFoundHttpException;
            }
        }

        return $next($request);
    }
}
