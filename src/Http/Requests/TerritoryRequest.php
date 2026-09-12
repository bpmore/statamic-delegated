<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Http\Requests;

use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Territories\Normalise;
use Bpmore\StatamicDelegated\Territories\ScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A territory as a site administrator creates or edits it. Everything the
 * database would refuse is refused here first with a message, and the
 * scope reference is normalised so the stored row compares the way the
 * resolver compares.
 */
final class TerritoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's `can:manage territories` middleware decides who; this
        // only makes sure a missing territory is a 404 before it is a 422.
        if ($this->route('territory') !== null && app(TerritoryResolver::class)->map()->find((int) $this->route('territory')) === null) {
            throw new NotFoundHttpException('No territory with id '.$this->route('territory'));
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('territory');
        $site = (string) $this->input('site');

        return [
            'name' => ['required', 'string', 'max:128'],
            'slug' => [
                'required', 'string', 'max:128', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('delegated_territories', 'slug')->where('site', $site)->ignore($id),
            ],
            'site' => ['required', 'string', Rule::in(Site::all()->map->handle()->all())],
            'scope_type' => ['required', Rule::enum(ScopeType::class)],
            'scope_ref' => ['required', 'string', 'max:512'],
            'parent_id' => ['nullable', 'integer', Rule::exists('delegated_territories', 'id')],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('scope_type') && $this->filled('scope_ref') && ($type = ScopeType::tryFrom((string) $this->input('scope_type'))) !== null) {
            $this->merge(['scope_ref' => Normalise::ref($type, (string) $this->input('scope_ref'))]);
        }

        if ($this->input('parent_id') === '' || $this->input('parent_id') === 0) {
            $this->merge(['parent_id' => null]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->validateScopeRef($v);
            $this->validateUniqueScope($v);
            $this->validateParent($v);
        });
    }

    private function validateScopeRef(Validator $v): void
    {
        $type = ScopeType::from((string) $this->input('scope_type'));
        $ref = (string) $this->input('scope_ref');

        match ($type) {
            ScopeType::StructureBranch => str_starts_with($ref, '/') || $v->errors()->add('scope_ref', 'A branch is a URI starting with /.'),
            ScopeType::Collection => Collection::findByHandle($ref) !== null || $v->errors()->add('scope_ref', "There is no collection [{$ref}]."),
            ScopeType::TaxonomyTerm => $this->termExists($ref) || $v->errors()->add('scope_ref', "A term is written taxonomy::slug, and [{$ref}] is not a term that exists."),
        };
    }

    private function termExists(string $ref): bool
    {
        if (! str_contains($ref, '::')) {
            return false;
        }

        [$taxonomy, $slug] = explode('::', $ref, 2);

        return Taxonomy::findByHandle($taxonomy) !== null && Term::find($ref) !== null && $slug !== '';
    }

    private function validateUniqueScope(Validator $v): void
    {
        $existing = app(TerritoryResolver::class)->map()->all();
        $id = $this->route('territory') !== null ? (int) $this->route('territory') : null;

        foreach ($existing as $territory) {
            if ($territory->id !== $id
                && $territory->site === $this->input('site')
                && $territory->scopeType->value === $this->input('scope_type')
                && $territory->scopeRef === $this->input('scope_ref')) {
                $v->errors()->add('scope_ref', "Territory [{$territory->name}] already covers this scope on this site. Assign more groups to it instead.");

                return;
            }
        }
    }

    private function validateParent(Validator $v): void
    {
        $parentId = $this->input('parent_id');

        if ($parentId === null) {
            return;
        }

        $map = app(TerritoryResolver::class)->map();
        $parent = $map->find((int) $parentId);
        $id = $this->route('territory') !== null ? (int) $this->route('territory') : null;

        if ($parent === null) {
            return; // the exists rule already said so
        }

        if ($parent->site !== $this->input('site')) {
            $v->errors()->add('parent_id', 'A territory and its parent must be on the same site.');
        }

        if ($id === null) {
            return;
        }

        if ($parent->id === $id) {
            $v->errors()->add('parent_id', 'A territory cannot be its own parent.');

            return;
        }

        $self = $map->find($id);

        if ($self !== null && in_array($parent->id, array_map(fn ($t) => $t->id, $map->descendants($self)), true)) {
            $v->errors()->add('parent_id', 'A territory cannot be placed under one of its own descendants.');
        }
    }
}
