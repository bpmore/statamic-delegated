<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Http\Controllers;

use Bpmore\StatamicDelegated\Http\Requests\TerritoryRequest;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Storage\TerritoryStore;
use Bpmore\StatamicDelegated\Territories\ScopeType;
use Bpmore\StatamicDelegated\Territories\Territory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Site administration of territories (Phase 4.1): the screen a super user,
 * or a holder of `manage territories`, uses to draw the map. The Phase 2
 * invariants constrain territory admins, not this screen; it writes
 * through the store directly, and the store invalidates the resolver.
 */
final class TerritoriesController extends CpController
{
    public function __construct(
        Request $request,
        private readonly TerritoryResolver $resolver,
        private readonly TerritoryStore $store,
    ) {
        parent::__construct($request);
    }

    public function index(Request $request): Response|JsonResponse
    {
        $data = [
            'territories' => $this->territories(),
            'sites' => Site::all()->map(fn ($site) => ['handle' => $site->handle(), 'name' => $site->name()])->values()->all(),
            'scopeTypes' => [
                ['value' => ScopeType::StructureBranch->value, 'label' => 'Structure branch', 'hint' => 'Everything under a URI, such as /cardiology'],
                ['value' => ScopeType::Collection->value, 'label' => 'Collection', 'hint' => 'An entire collection'],
                ['value' => ScopeType::TaxonomyTerm->value, 'label' => 'Taxonomy term', 'hint' => 'Everything tagged with a term, written taxonomy::slug'],
            ],
            'collections' => Collection::all()->map(fn ($c) => ['handle' => $c->handle(), 'title' => $c->title()])->values()->all(),
            'taxonomies' => Taxonomy::all()->map(fn ($t) => ['handle' => $t->handle(), 'title' => $t->title()])->values()->all(),
            'users' => User::all()->map(fn ($u) => ['id' => (string) $u->id(), 'label' => trim(($u->name() ?? '').' <'.$u->email().'>'), 'super' => $u->isSuper()])->sortBy('label')->values()->all(),
            'groups' => UserGroup::all()->map(fn ($g) => ['handle' => $g->handle(), 'title' => $g->title()])->sortBy('title')->values()->all(),
            'urls' => [
                'store' => cp_route('delegated.territories.store'),
                'index' => cp_route('delegated.territories.index'),
                'audit' => cp_route('delegated.audit.index'),
            ],
        ];

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('delegated/Territories', $data);
    }

    public function store(TerritoryRequest $request): JsonResponse
    {
        $id = $this->store->createTerritory($request->safe()->only(['name', 'slug', 'site', 'scope_type', 'scope_ref', 'parent_id']), (string) User::current()->id());

        return response()->json(['territory' => $this->territory($this->find($id)), 'territories' => $this->territories()], 201);
    }

    public function update(TerritoryRequest $request, string $territory): JsonResponse
    {
        $existing = $this->find((int) $territory);

        $this->store->updateTerritory($existing->id, $request->safe()->only(['name', 'slug', 'site', 'scope_type', 'scope_ref', 'parent_id']), (string) User::current()->id());

        return response()->json(['territory' => $this->territory($this->find($existing->id)), 'territories' => $this->territories()]);
    }

    public function destroy(string $territory): JsonResponse
    {
        $existing = $this->find((int) $territory);

        $this->store->deleteTerritory($existing->id, (string) User::current()->id());

        return response()->json(['territories' => $this->territories()]);
    }

    private function find(int $id): Territory
    {
        return $this->resolver->map()->find($id) ?? throw new NotFoundHttpException("No territory with id {$id}");
    }

    /** @return list<array<string, mixed>> */
    private function territories(): array
    {
        $map = $this->resolver->map();

        return collect($map->all())
            ->sortBy(fn (Territory $t) => [$t->site, $t->scopeType->precedence(), $t->scopeRef])
            ->map(fn (Territory $t) => $this->territory($t))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function territory(Territory $t): array
    {
        $map = $this->resolver->map();
        $parent = $t->parentId !== null ? $map->find($t->parentId) : null;

        $assignments = collect($map->subjectsIn([$t->id]))
            ->map(function (string $subject) use ($map, $t): array {
                [$type, $id] = explode(':', $subject, 2);
                $level = collect($map->assignmentsFor([$subject]))->firstWhere('territoryId', $t->id)?->level->value;
                $label = $type === 'user'
                    ? (($u = User::find($id)) ? trim(($u->name() ?? '').' <'.$u->email().'>') : "{$id} (deleted)")
                    : (($g = UserGroup::find($id))?->title() ?? "{$id} (deleted)");

                return ['subject' => $subject, 'type' => $type, 'id' => $id, 'label' => $label, 'level' => $level];
            })
            ->sortBy([['type', 'asc'], ['label', 'asc']])
            ->values()
            ->all();

        return [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'site' => $t->site,
            'scope_type' => $t->scopeType->value,
            'scope_ref' => $t->scopeRef,
            'parent_id' => $t->parentId,
            'parent_name' => $parent?->name,
            'assignments' => $assignments,
            'urls' => [
                'update' => cp_route('delegated.territories.update', $t->id),
                'destroy' => cp_route('delegated.territories.destroy', $t->id),
                'assignments' => cp_route('delegated.territories.assignments.store', $t->id),
            ],
        ];
    }
}
