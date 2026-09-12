<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Http\Controllers;

use Bpmore\StatamicDelegated\Administration\Actor;
use Bpmore\StatamicDelegated\Administration\Actors;
use Bpmore\StatamicDelegated\Administration\EscalationDenied;
use Bpmore\StatamicDelegated\Administration\ManagementPermissions;
use Bpmore\StatamicDelegated\Administration\TerritoryAdministration;
use Bpmore\StatamicDelegated\Administration\Visibility;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Territories\Territory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Territory admin self-service (Phase 4.2): the screen a department head
 * uses to manage their own people, and nothing else.
 *
 * Every write goes through TerritoryAdministration, so the six escalation
 * invariants (Phase 2) decide, not this controller. A refusal is a 403 that
 * names the invariant; a bad request is a 422. Who may open the screen at
 * all: anyone who administers at least one territory, and super users.
 */
final class MyTerritoriesController extends CpController
{
    public function __construct(
        Request $request,
        private readonly TerritoryResolver $resolver,
        private readonly TerritoryAdministration $administration,
    ) {
        parent::__construct($request);
    }

    public function index(Request $request): Response|JsonResponse
    {
        $actor = $this->actor();

        $data = [
            'territories' => $this->territories($actor),
            'grantableRoles' => $this->grantableRoles($actor),
            'levels' => [
                ['value' => Level::Member->value, 'label' => 'Member', 'hint' => 'Works on the content.'],
                ['value' => Level::Admin->value, 'label' => 'Admin', 'hint' => 'Also adds and removes members. Nothing else.'],
            ],
            'urls' => ['index' => cp_route('delegated.my-territories.index')],
        ];

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('delegated/MyTerritories', $data);
    }

    public function update(Request $request, string $territory): JsonResponse
    {
        $actor = $this->actor();
        $id = $this->territoryId($territory);

        $data = $request->validate(['name' => ['required', 'string', 'max:128']]);

        return $this->attempt(fn () => $this->administration->updateTerritory($actor, $id, ['name' => $data['name']]), $actor);
    }

    public function addMember(Request $request, string $territory): JsonResponse
    {
        $actor = $this->actor();
        $id = $this->territoryId($territory);

        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['user', 'group'])],
            // A user is named by email — the admin knows the colleague's
            // address, and is not handed a list of everyone on the site.
            'subject' => ['required', 'string', 'max:191'],
            'level' => ['required', Rule::enum(Level::class)],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string'],
        ]);

        $subjectId = $data['subject_type'] === 'user'
            ? User::findByEmail($data['subject'])?->id()
            : (UserGroup::find($data['subject']) !== null ? $data['subject'] : null);

        if ($subjectId === null) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => ['subject' => [
                $data['subject_type'] === 'user' ? 'No user has that email address.' : 'There is no group with that handle.',
            ]]], 422);
        }

        return $this->attempt(fn () => $this->administration->addMember(
            $actor, $id, $data['subject_type'].':'.$subjectId, Level::from($data['level']), array_values($data['roles'] ?? []),
        ), $actor, 201);
    }

    public function removeMember(Request $request, string $territory): JsonResponse
    {
        $actor = $this->actor();
        $id = $this->territoryId($territory);

        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['user', 'group'])],
            'subject_id' => ['required', 'string', 'max:191'],
        ]);

        return $this->attempt(fn () => $this->administration->removeMember($actor, $id, $data['subject_type'].':'.$data['subject_id']), $actor);
    }

    public function setRoles(Request $request, string $territory, string $user): JsonResponse
    {
        $actor = $this->actor();
        $this->territoryId($territory);

        $data = $request->validate(['roles' => ['present', 'array'], 'roles.*' => ['string']]);

        return $this->attempt(fn () => $this->administration->setRoles($actor, $user, array_values($data['roles'])), $actor);
    }

    // ------------------------------------------------------------ helpers

    private function actor(): Actor
    {
        $actor = Actors::from(User::current());

        if (! $actor->isSuper && $this->resolver->membershipFor($actor->subjects)->administeredIds() === []) {
            throw new AccessDeniedHttpException('You do not administer any territory.');
        }

        return $actor;
    }

    private function territoryId(string $territory): int
    {
        $id = (int) $territory;

        if ($this->resolver->map()->find($id) === null) {
            throw new NotFoundHttpException("No territory with id {$id}");
        }

        return $id;
    }

    /** Runs a write, turning a refusal into a 403 that names the invariant. */
    private function attempt(callable $write, Actor $actor, int $status = 200): JsonResponse
    {
        try {
            $write();
        } catch (EscalationDenied $e) {
            return response()->json(['message' => $e->getMessage(), 'invariant' => $e->invariant], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['subject' => [$e->getMessage()]]], 422);
        }

        return response()->json(['territories' => $this->territories($actor)], $status);
    }

    /** @return list<array<string, mixed>> */
    private function territories(Actor $actor): array
    {
        $map = $this->resolver->map();
        $administered = $actor->isSuper
            ? array_map(fn (Territory $t): int => $t->id, $map->all())
            : $this->resolver->membershipFor($actor->subjects)->administeredIds();

        return collect($administered)
            ->map(fn (int $id): ?Territory => $map->find($id))
            ->filter()
            ->sortBy(fn (Territory $t) => [$t->site, $t->scopeRef])
            ->map(fn (Territory $t): array => $this->territory($t, $actor))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function territory(Territory $t, Actor $actor): array
    {
        $map = $this->resolver->map();
        $visible = array_flip($this->administration->visibleUsers($actor));
        $held = array_flip($actor->roles);

        $members = collect($map->subjectsIn([$t->id]))
            ->map(function (string $subject) use ($map, $t, $visible, $held, $actor): ?array {
                [$type, $id] = explode(':', $subject, 2);
                $level = collect($map->assignmentsFor([$subject]))->firstWhere('territoryId', $t->id)?->level->value;

                if ($type === 'group') {
                    $group = UserGroup::find($id);

                    return ['subject' => $subject, 'type' => 'group', 'id' => $id, 'label' => $group?->title() ?? $id, 'level' => $level, 'roles' => [], 'editable' => true];
                }

                $user = User::find($id);

                if ($user === null || ! isset($visible[$id])) {
                    return null;
                }

                // Roles the admin may change are their own; the rest are shown
                // locked. A super user, or a user shared with another
                // territory, cannot be changed at all (invariants 3 and 6).
                $roles = $user->explicitRoles()->map->handle()->values()->all();
                $editable = ! $user->isSuper() && ! $this->isShared($actor, $user);

                return [
                    'subject' => $subject,
                    'type' => 'user',
                    'id' => (string) $user->id(),
                    'label' => trim(($user->name() ?? '').' <'.$user->email().'>'),
                    'level' => $level,
                    'roles' => array_values(array_filter($roles, fn (string $r): bool => isset($held[$r]))),
                    'lockedRoles' => array_values(array_filter($roles, fn (string $r): bool => ! isset($held[$r]))),
                    'editable' => $editable,
                    'self' => (string) $user->id() === $actor->userId,
                ];
            })
            ->filter()
            ->sortBy([['type', 'asc'], ['label', 'asc']])
            ->values()
            ->all();

        return [
            'id' => $t->id,
            'name' => $t->name,
            'site' => $t->site,
            'scope_type' => $t->scopeType->value,
            'scope_ref' => $t->scopeRef,
            'members' => $members,
            'urls' => [
                'update' => cp_route('delegated.my-territories.update', $t->id),
                'members' => cp_route('delegated.my-territories.members.store', $t->id),
                'roles' => cp_route('delegated.my-territories.members.roles', [$t->id, '__user__']),
            ],
        ];
    }

    private function isShared(Actor $actor, \Statamic\Contracts\Auth\User $user): bool
    {
        if ($actor->isSuper) {
            return false;
        }

        $placement = app(Visibility::class)->placement($actor, $user);

        return $placement['outside'];
    }

    /** @return list<array{handle:string,title:string}> */
    private function grantableRoles(Actor $actor): array
    {
        $handles = $actor->isSuper ? Role::all()->map->handle()->values()->all() : $actor->roles;

        return collect($handles)
            ->map(fn (string $handle) => Role::find($handle))
            ->filter()
            ->reject(fn ($role): bool => ! $actor->isSuper && ManagementPermissions::anyIn($role->permissions()))
            ->map(fn ($role): array => ['handle' => $role->handle(), 'title' => $role->title()])
            ->sortBy('title')
            ->values()
            ->all();
    }
}
