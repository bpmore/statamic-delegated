<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Http\Controllers;

use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Storage\TerritoryStore;
use Bpmore\StatamicDelegated\Territories\Level;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Site administration of a territory's assignments (Phase 4.1): who is in
 * it and at what level, as a super user sets it up. Territory admins get
 * their own, invariant-constrained way to do this in Phase 4.2.
 */
final class AssignmentsController extends CpController
{
    public function __construct(
        Request $request,
        private readonly TerritoryResolver $resolver,
        private readonly TerritoryStore $store,
    ) {
        parent::__construct($request);
    }

    public function store(Request $request, string $territory): JsonResponse
    {
        $id = $this->territoryId($territory);

        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['user', 'group'])],
            'subject_id' => ['required', 'string', 'max:191'],
            'level' => ['required', Rule::enum(Level::class)],
        ]);

        $exists = $data['subject_type'] === 'user'
            ? User::find($data['subject_id']) !== null
            : UserGroup::find($data['subject_id']) !== null;

        if (! $exists) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => ['subject_id' => ["There is no {$data['subject_type']} [{$data['subject_id']}]."]]], 422);
        }

        $this->store->putAssignment($id, $data['subject_type'], $data['subject_id'], Level::from($data['level']), (string) User::current()->id());

        return response()->json(['saved' => true], 201);
    }

    public function destroy(Request $request, string $territory): JsonResponse
    {
        $id = $this->territoryId($territory);

        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['user', 'group'])],
            'subject_id' => ['required', 'string', 'max:191'],
        ]);

        $this->store->deleteAssignment($id, $data['subject_type'], $data['subject_id']);

        return response()->json(['saved' => true]);
    }

    private function territoryId(string $territory): int
    {
        $id = (int) $territory;

        if ($this->resolver->map()->find($id) === null) {
            throw new NotFoundHttpException("No territory with id {$id}");
        }

        return $id;
    }
}
