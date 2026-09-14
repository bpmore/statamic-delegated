<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Bpmore\StatamicDelegated\Actions\DuplicateEntry;
use Statamic\Facades\Action;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Phase 3.2.b: the duplicate action asks whether the user may view the
 * source. Defined once, run under both drivers.
 */
final class DuplicateScoping
{
    public static function scenarios(): void
    {
        it('replaces the core duplicate action under the same handle', function () {
            expect(Action::get('duplicate_entry'))->toBeInstanceOf(DuplicateEntry::class);
        });

        it('does not offer duplicate on an entry outside the territory', function () {
            $outside = AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/actions/list', ['selections' => ['onc']]);
            $inside = AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/actions/list', ['selections' => ['trials']]);

            expect(collect($outside->json())->pluck('handle')->all())->not->toContain('duplicate_entry')
                ->and(collect($inside->json())->pluck('handle')->all())->toContain('duplicate_entry');
        });

        it('refuses to duplicate an entry outside the territory, creating nothing', function () {
            $before = Entry::query()->count();

            foreach (['list', 'form'] as $view) {
                $r = AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/actions', [
                    'action' => 'duplicate_entry', 'selections' => ['onc'], 'context' => ['view' => $view, 'collection' => 'pages'], 'values' => [],
                ]);

                expect($r->status())->toBe(403)
                    // The form-context response carries the item's values after
                    // a successful action. A refusal must carry nothing.
                    ->and($r->json('data'))->toBeNull();
            }

            expect(Entry::query()->count())->toBe($before);
        });

        it('refuses a mixed selection whole', function () {
            $before = Entry::query()->count();

            $r = AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/actions', [
                'action' => 'duplicate_entry', 'selections' => ['trials', 'onc'], 'context' => ['view' => 'list', 'collection' => 'pages'], 'values' => [],
            ]);

            expect($r->status())->toBe(403)
                ->and(Entry::query()->count())->toBe($before);
        });

        it('still duplicates an entry inside the territory, and the copy is inside it too', function () {
            $r = AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/actions', [
                'action' => 'duplicate_entry', 'selections' => ['trials'], 'context' => ['view' => 'list', 'collection' => 'pages'], 'values' => [],
            ]);

            expect($r->status())->toBe(200);

            $copy = Entry::query()->where('collection', 'pages')->get()->first(fn ($e) => $e->get('duplicated_from') === 'trials');

            expect($copy)->not->toBeNull()
                ->and($copy->uri())->toBe('/cardiology/trials-1')
                ->and(User::find('card-editor')->can('view', $copy))->toBeTrue();
        });

        it('does not apply to a super user', function () {
            $r = AbilityScoping::as('root')->postJson('/cp/collections/pages/entries/actions', [
                'action' => 'duplicate_entry', 'selections' => ['onc'], 'context' => ['view' => 'list', 'collection' => 'pages'], 'values' => [],
            ]);

            expect($r->status())->toBe(200)
                ->and(Entry::query()->where('collection', 'pages')->get()->first(fn ($e) => $e->get('duplicated_from') === 'onc'))->not->toBeNull();
        });
    }
}
