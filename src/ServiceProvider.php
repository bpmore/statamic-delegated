<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    // The product is "Delegated" everywhere a person sees it — views, config,
    // commands — never the repository name.
    protected $viewNamespace = 'delegated';

    public function bootAddon(): void
    {
        // Nothing yet. Enforcement is deliberately absent until the Phase 2
        // escalation invariants exist as tests (see PROGRESS.md).
    }
}
