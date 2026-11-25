<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Enforcement;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/** Check the destination before core creates or returns a localization. */
final class GuardsEntryLocalization
{
    public function __construct(
        private readonly Gate $gate,
        private readonly Placement $placement,
        private readonly Territorial $territorial,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if ($request->route()?->getName() !== 'statamic.cp.collections.entries.localize') {
            return $next($request);
        }

        $user = User::current();
        if (Exempt::user($user)) {
            return $next($request);
        }

        $entry = $request->route('entry');
        $this->gate->authorize('edit', $entry);
        $collection = $entry->collection();
        $validated = $request->validate(['site' => ['required', 'string', Rule::in($collection->sites()->all())]]);
        $site = Site::get($validated['site']);
        abort_unless($site, 422);

        if ($existing = $entry->in($site->handle())) {
            $this->gate->authorize('edit', $existing);
            $allowed = true;
        } else {
            $this->gate->authorize('create', [Entry::class, $collection, $site]);
            // Match Entry::makeLocalization's after-save placement: use the
            // source parent's counterpart, or the destination tree's root.
            $parent = $entry->parent()?->in($site->handle());
            try {
                $facts = $this->placement->facts(
                    $collection,
                    $site,
                    $parent?->id(),
                    $entry->slug(),
                    $entry->values()->all(),
                );
                $allowed = $this->territorial->allowsFacts($user, $facts);
            } catch (InvalidArgumentException) {
                $allowed = false;
            }
        }

        abort_unless($allowed, 403, 'This localization is outside your territory.');

        // Keep core's source-entry authorization and localization behavior.
        return $next($request);
    }
}
