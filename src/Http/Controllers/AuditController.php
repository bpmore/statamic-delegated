<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Http\Controllers;

use Bpmore\StatamicDelegated\Administration\Actors;
use Bpmore\StatamicDelegated\Audit\AuditView;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The audit log, read two ways: site-wide by a site administrator, per territory by its admin. */
final class AuditController extends CpController
{
    public function __construct(
        Request $request,
        private readonly AuditView $view,
        private readonly TerritoryResolver $resolver,
    ) {
        parent::__construct($request);
    }

    /** Everything. Behind `manage territories` at the route. */
    public function all(): JsonResponse
    {
        return response()->json(['entries' => $this->view->everything(Actors::from(User::current()))]);
    }

    /** One territory, for someone who administers it. */
    public function territory(string $territory): JsonResponse
    {
        $actor = Actors::from(User::current());
        $id = (int) $territory;

        if ($this->resolver->map()->find($id) === null) {
            throw new NotFoundHttpException("No territory with id {$id}");
        }

        if (! $actor->isSuper && ! $this->resolver->membershipFor($actor->subjects)->isAdminOf($id)) {
            throw new AccessDeniedHttpException('You do not administer this territory.');
        }

        return response()->json(['entries' => $this->view->forTerritories($actor, [$id])]);
    }
}
