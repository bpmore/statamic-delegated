<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Bpmore\StatamicDelegated\Enforcement\Facts;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Search;
use Statamic\Facades\User;

/**
 * The policy-layer scenarios (Phase 3.2.a): every entry ability, and every
 * control-panel surface that asks the Gate about an entry, refuses an entry
 * outside the user's territories. Defined once, run under both drivers.
 *
 * Each surface gets a denial and a control, because a surface that refuses
 * everything would pass a denial test for the wrong reason.
 */
final class AbilityScoping
{
    public static function setUp(): void
    {
        Content::build();

        Site::role('editor', [
            'access cp',
            'view pages entries', 'edit pages entries', 'create pages entries', 'publish pages entries', 'delete pages entries',
            'view news entries', 'edit news entries', 'create news entries',
            'view main nav',
        ]);

        $cardiology = Site::territory('cardiology', '/cardiology');
        Site::user('card-editor', roles: ['editor']);
        Site::assign($cardiology, 'user:card-editor');

        Site::user('nobody', roles: ['editor']);
        Site::user('root', roles: ['editor'], super: true);
    }

    public static function scenarios(): void
    {
        it('reads an entry\'s facts the way the listing reads them', function () {
            $facts = Facts::of(Entry::find('n3'));

            expect($facts->site)->toBe('default')
                ->and($facts->collection)->toBe('news')
                ->and($facts->uri)->toBe('/news/both-news')
                ->and($facts->terms)->toEqualCanonicalizing(['departments::cardiology', 'departments::oncology'])
                ->and(Facts::of(Entry::find('phase2'))->uri)->toBe('/cardiology/trials/phase-2')
                ->and(Facts::of(Entry::find('n4'))->terms)->toBe([]);
        });

        it('denies every ability on an entry outside the territory, and allows them inside', function (string $ability) {
            $user = User::find('card-editor');

            foreach (Content::CARDIOLOGY as $id) {
                expect($user->can($ability, Entry::find($id)))->toBeTrue("{$ability} on {$id} should be allowed");
            }

            foreach (array_merge(Content::ONCOLOGY, ['home', 'alumni', 'stray']) as $id) {
                expect($user->can($ability, Entry::find($id)))->toBeFalse("{$ability} on {$id} should be denied");
            }
        })->with(['view', 'edit', 'update', 'delete', 'publish']);

        it('denies everything to a user with the permissions but no territory', function () {
            $user = User::find('nobody');

            foreach (Content::PAGES as $id) {
                expect($user->can('view', Entry::find($id)))->toBeFalse("view on {$id} should be denied");
            }
        });

        it('honours a term territory and a collection territory in single-entry checks', function () {
            $term = Site::territory('cardiology-news', 'departments::cardiology', scopeType: 'taxonomy_term');
            Site::user('tagger', roles: ['editor']);
            Site::assign($term, 'user:tagger');
            $tagger = User::find('tagger');

            expect($tagger->can('view', Entry::find('n1')))->toBeTrue()
                ->and($tagger->can('view', Entry::find('n3')))->toBeTrue()
                ->and($tagger->can('view', Entry::find('n2')))->toBeFalse()
                ->and($tagger->can('view', Entry::find('n4')))->toBeFalse()
                ->and($tagger->can('view', Entry::find('card')))->toBeFalse();

            $all = Site::territory('all-news', 'news', scopeType: 'collection');
            Site::user('newsroom', roles: ['editor']);
            Site::assign($all, 'user:newsroom');
            $newsroom = User::find('newsroom');

            expect($newsroom->can('view', Entry::find('n4')))->toBeTrue()
                ->and($newsroom->can('view', Entry::find('card')))->toBeFalse();
        });

        it('gives a root territory the root-level stray, and nobody else', function () {
            $root = Site::territory('site', '/');
            Site::user('sitewide', roles: ['editor']);
            Site::assign($root, 'user:sitewide');

            expect(User::find('sitewide')->can('view', Entry::find('stray')))->toBeTrue()
                ->and(User::find('card-editor')->can('view', Entry::find('stray')))->toBeFalse();
        });

        it('does not restrict a super user or a holder of configure collections', function () {
            Site::role('configurer', ['access cp', 'configure collections']);
            Site::user('configurer', roles: ['configurer']);

            foreach (Content::PAGES as $id) {
                expect(User::find('root')->can('delete', Entry::find($id)))->toBeTrue()
                    ->and(User::find('configurer')->can('delete', Entry::find($id)))->toBeTrue();
            }
        });

        it('still lets Statamic\'s own permissions deny inside the territory', function () {
            // The Gate says nothing about an entry inside the territory; the
            // policy decides as before. No publish permission, no publish.
            Site::role('viewer', ['access cp', 'view pages entries']);
            Site::user('card-viewer', roles: ['viewer']);
            Site::assign(1, 'user:card-viewer');

            expect(User::find('card-viewer')->can('view', Entry::find('card')))->toBeTrue()
                ->and(User::find('card-viewer')->can('edit', Entry::find('card')))->toBeFalse()
                ->and(User::find('card-viewer')->can('publish', Entry::find('card')))->toBeFalse();
        });

        // ---------------------------------------------------------- surfaces

        it('refuses the edit screen and a direct URL for an entry outside the territory', function () {
            expect(AbilityScoping::as('card-editor')->getJson('/cp/collections/pages/entries/onc')->status())->toBe(403)
                ->and(AbilityScoping::as('card-editor')->getJson('/cp/collections/pages/entries/oncdraft')->status())->toBe(403)
                ->and(AbilityScoping::as('card-editor')->getJson('/cp/collections/pages/entries/stray')->status())->toBe(403)
                ->and(AbilityScoping::as('card-editor')->getJson('/cp/collections/pages/entries/card')->status())->toBe(200);
        });

        it('refuses to update an entry outside the territory, and leaves it unchanged', function () {
            $r = AbilityScoping::as('card-editor')->patchJson('/cp/collections/pages/entries/onc', ['title' => 'Hijacked', 'slug' => 'oncology', 'published' => true]);

            expect($r->status())->toBe(403)
                ->and(Entry::find('onc')->value('title'))->toBe('Oncology');

            $ok = AbilityScoping::as('card-editor')->patchJson('/cp/collections/pages/entries/card', ['title' => 'Cardiology Dept', 'slug' => 'cardiology', 'published' => true]);

            expect($ok->status())->toBe(200)
                ->and(Entry::find('card')->value('title'))->toBe('Cardiology Dept');
        });

        it('refuses to publish or unpublish an entry outside the territory', function () {
            expect(AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/oncdraft/publish', [])->status())->toBe(403)
                ->and(Entry::find('oncdraft')->published())->toBeFalse()
                ->and(AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/onc/unpublish', [])->status())->toBe(403)
                ->and(Entry::find('onc')->published())->toBeTrue()
                ->and(AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/card/unpublish', [])->status())->toBe(200)
                ->and(Entry::find('card')->published())->toBeFalse();
        });

        it('refuses a live preview of an entry outside the territory', function () {
            expect(AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/onc/preview', ['preview' => ['title' => 'x']])->status())->toBe(403)
                ->and(AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/card/preview', ['preview' => ['title' => 'x']])->status())->toBe(200);
        });

        it('refuses the revision history of an entry outside the territory', function () {
            config(['statamic.revisions.enabled' => true]);
            Collection::findByHandle('pages')->revisionsEnabled(true)->save();

            expect(AbilityScoping::as('card-editor')->getJson('/cp/collections/pages/entries/onc/revisions')->status())->toBe(403)
                ->and(AbilityScoping::as('card-editor')->getJson('/cp/collections/pages/entries/card/revisions')->status())->toBe(200);
        });

        it('refuses the navigation page editor for a branch linked to an entry outside the territory', function () {
            Nav::make()->handle('main')->title('Main')->save();
            Nav::find('main')->makeTree('default', [
                ['id' => 'n-onc', 'entry' => 'onc'],
                ['id' => 'n-card', 'entry' => 'card'],
            ])->save();

            expect(AbilityScoping::as('card-editor')->getJson('/cp/navigation/main/pages/n-onc/edit?site=default')->status())->toBe(403)
                ->and(AbilityScoping::as('card-editor')->getJson('/cp/navigation/main/pages/n-card/edit?site=default')->status())->toBe(200);
        });

        it('marks an entry outside the territory invalid in the relationship picker\'s data endpoint', function () {
            $config = base64_encode(json_encode(['type' => 'entries', 'collections' => ['pages']]));

            $r = AbilityScoping::as('card-editor')->postJson('/cp/fieldtypes/relationship/data', ['config' => $config, 'selections' => ['onc', 'card']]);

            $byId = collect($r->json('data'))->keyBy('id');

            expect($r->status())->toBe(200)
                ->and($byId['onc']['invalid'] ?? false)->toBeTrue()
                ->and($byId['card']['invalid'] ?? false)->toBeFalse()
                ->and($byId['card']['title'])->toBe('Cardiology');
        });

        it('keeps entries outside the territory out of the command palette', function () {
            Search::index('cp')->update();

            $texts = fn (TestResponse $r): array => collect($r->json())->pluck('text')->all();

            expect($texts(AbilityScoping::as('card-editor')->getJson('/cp/command-palette/search?q=Oncology')))->toBe([])
                ->and($texts(AbilityScoping::as('card-editor')->getJson('/cp/command-palette/search?q=Cardiology')))
                ->toEqualCanonicalizing(['Cardiology', 'Cardiology Trials', 'Cardiology Trials Phase 2'])
                ->and($texts(AbilityScoping::as('root')->getJson('/cp/command-palette/search?q=Oncology')))->not->toBe([]);
        });

        it('neither offers nor runs delete and unpublish on an entry outside the territory', function () {
            $offered = AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/actions/list', ['selections' => ['onc']]);
            $handles = collect($offered->json())->pluck('handle')->all();

            expect($handles)->not->toContain('delete')
                ->and($handles)->not->toContain('unpublish');

            $run = AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/actions', [
                'action' => 'delete', 'selections' => ['onc'], 'context' => ['view' => 'list', 'collection' => 'pages'], 'values' => [],
            ]);

            expect($run->status())->toBe(403)
                ->and(Entry::find('onc'))->not->toBeNull();

            // A mixed selection is refused whole.
            $mixed = AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/actions', [
                'action' => 'delete', 'selections' => ['card', 'onc'], 'context' => ['view' => 'list', 'collection' => 'pages'], 'values' => [],
            ]);

            expect($mixed->status())->toBe(403)
                ->and(Entry::find('card'))->not->toBeNull();

            // And inside the territory the action is offered and runs.
            $ok = AbilityScoping::as('card-editor')->postJson('/cp/collections/pages/entries/actions', [
                'action' => 'delete', 'selections' => ['phase2'], 'context' => ['view' => 'list', 'collection' => 'pages'], 'values' => [],
            ]);

            expect($ok->status())->toBe(200)
                ->and(Entry::find('phase2'))->toBeNull();
        });
    }

    /** The running test case, signed in as the user. Pest's generated class is not a TestCase subtype, hence the loose return type. */
    public static function as(string $userId): object
    {
        return test()->actingAs(User::find($userId));
    }
}
