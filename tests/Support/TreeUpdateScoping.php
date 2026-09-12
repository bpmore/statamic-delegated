<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Illuminate\Testing\TestResponse;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Phase 3.2.e: saving the tree from a pruned view folds back into the whole
 * tree; a move outside the territory, or a delete outside it, refuses the
 * whole request. Defined once, run under both drivers.
 */
final class TreeUpdateScoping
{
    /** Called from the test file's beforeEach (see ListingScoping::setUp for why not from here). */
    public static function setUp(): void
    {
        AbilityScoping::setUp();

        // A second child under cardiology so there is something to reorder.
        Entry::make()->id('clinics')->collection('pages')->slug('clinics')->published(true)->data(['title' => 'Cardiology Clinics'])->save();
        Collection::findByHandle('pages')->structure()->in('default')->tree([
            ['entry' => 'home'],
            ['entry' => 'card', 'children' => [['entry' => 'trials', 'children' => [['entry' => 'phase2']]], ['entry' => 'clinics']]],
            ['entry' => 'onc', 'children' => [['entry' => 'chemo'], ['entry' => 'oncdraft']]],
            ['entry' => 'alumni'],
            ['entry' => 'stray'],
        ])->save();
    }

    public static function scenarios(): void
    {
        it('saves a reorder within the territory and leaves the rest of the tree exactly as it was', function () {
            $r = TreeUpdateScoping::save('card-editor', [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [['id' => 'clinics', 'children' => []], ['id' => 'trials', 'children' => [['id' => 'phase2', 'children' => []]]]]],
            ]);

            expect($r->status())->toBe(200);

            $tree = TreeUpdateScoping::stored();

            expect(array_column($tree, 'entry'))->toBe(['home', 'card', 'onc', 'alumni', 'stray'])
                ->and(array_column($tree[1]['children'], 'entry'))->toBe(['clinics', 'trials'])
                ->and($tree[2])->toBe(['entry' => 'onc', 'children' => [['entry' => 'chemo'], ['entry' => 'oncdraft']]])
                ->and(Entry::find('chemo')->uri())->toBe('/oncology/chemo');
        });

        it('moves a page under another page inside the territory, and its URI follows', function () {
            $r = TreeUpdateScoping::save('card-editor', [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [['id' => 'trials', 'children' => []], ['id' => 'clinics', 'children' => [['id' => 'phase2', 'children' => []]]]]],
            ]);

            expect($r->status())->toBe(200)
                ->and(Entry::find('phase2')->fresh()->uri())->toBe('/cardiology/clinics/phase-2')
                ->and(array_column(TreeUpdateScoping::stored(), 'entry'))->toBe(['home', 'card', 'onc', 'alumni', 'stray']);
        });

        it('refuses to move a page to the root, and changes nothing', function () {
            $before = TreeUpdateScoping::stored();

            $r = TreeUpdateScoping::save('card-editor', [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [['id' => 'clinics', 'children' => []]]],
                ['id' => 'trials', 'children' => [['id' => 'phase2', 'children' => []]]],
            ]);

            expect($r->status())->toBe(403)
                ->and(TreeUpdateScoping::stored())->toBe($before)
                ->and(Entry::find('trials')->fresh()->uri())->toBe('/cardiology/trials');
        });

        it('refuses a submission that names a page outside the territory', function () {
            $before = TreeUpdateScoping::stored();

            // Pulling oncology under cardiology: the Phase 0.1 escalation.
            $r = TreeUpdateScoping::save('card-editor', [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [
                    ['id' => 'trials', 'children' => [['id' => 'phase2', 'children' => []]]],
                    ['id' => 'clinics', 'children' => []],
                    ['id' => 'onc', 'children' => [['id' => 'chemo', 'children' => []], ['id' => 'oncdraft', 'children' => []]]],
                ]],
            ]);

            expect($r->status())->toBe(403)
                ->and(TreeUpdateScoping::stored())->toBe($before)
                ->and(Entry::find('onc')->fresh()->uri())->toBe('/oncology');
        });

        it('refuses to drop a page from the tree without deleting it', function () {
            $before = TreeUpdateScoping::stored();

            $r = TreeUpdateScoping::save('card-editor', [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [['id' => 'trials', 'children' => []], ['id' => 'clinics', 'children' => []]]],
            ]);

            expect($r->status())->toBe(403)
                ->and(TreeUpdateScoping::stored())->toBe($before);
        });

        it('deletes a page inside the territory, and refuses one outside it', function () {
            $r = TreeUpdateScoping::save('card-editor', [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [['id' => 'trials', 'children' => []], ['id' => 'clinics', 'children' => []]]],
            ], deleted: ['phase2']);

            expect($r->status())->toBe(200)
                ->and(Entry::find('phase2'))->toBeNull()
                ->and(array_column(TreeUpdateScoping::stored(), 'entry'))->toBe(['home', 'card', 'onc', 'alumni', 'stray']);

            $r = TreeUpdateScoping::save('card-editor', [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [['id' => 'trials', 'children' => []], ['id' => 'clinics', 'children' => []]]],
            ], deleted: ['chemo']);

            expect($r->status())->toBe(403)
                ->and(Entry::find('chemo'))->not->toBeNull();
        });

        it('keeps a sub-branch member inside their sub-branch', function () {
            $trials = Site::territory('trials', '/cardiology/trials', parentId: 1);
            Site::user('trials-only', roles: ['editor']);
            Site::assign($trials, 'user:trials-only');

            // Their view: home (placeholder), card (placeholder) > trials > phase2.
            // Moving trials out from under card is refused.
            $r = TreeUpdateScoping::save('trials-only', [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => []],
                ['id' => 'trials', 'children' => [['id' => 'phase2', 'children' => []]]],
            ]);

            expect($r->status())->toBe(403)
                ->and(Entry::find('trials')->fresh()->uri())->toBe('/cardiology/trials');

            // Reordering within trials is fine, and clinics (never shown) stays.
            Entry::make()->id('phase1')->collection('pages')->slug('phase-1')->published(true)->data(['title' => 'Phase 1'])->save();
            Collection::findByHandle('pages')->structure()->in('default')->tree(TreeUpdateScoping::withPhase1())->save();

            $r = TreeUpdateScoping::save('trials-only', [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [['id' => 'trials', 'children' => [['id' => 'phase1', 'children' => []], ['id' => 'phase2', 'children' => []]]]]],
            ]);

            expect($r->status())->toBe(200)
                ->and(array_column(TreeUpdateScoping::stored()[1]['children'], 'entry'))->toBe(['trials', 'clinics'])
                ->and(array_column(TreeUpdateScoping::stored()[1]['children'][0]['children'], 'entry'))->toBe(['phase1', 'phase2']);
        });

        it('does not apply to a super user', function () {
            $r = TreeUpdateScoping::save('root', [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [
                    ['id' => 'trials', 'children' => [['id' => 'phase2', 'children' => []]]],
                    ['id' => 'clinics', 'children' => []],
                    ['id' => 'onc', 'children' => [['id' => 'chemo', 'children' => []], ['id' => 'oncdraft', 'children' => []]]],
                ]],
                ['id' => 'alumni', 'children' => []],
                ['id' => 'stray', 'children' => []],
            ]);

            expect($r->status())->toBe(200)
                ->and(Entry::find('onc')->fresh()->uri())->toBe('/cardiology/oncology');
        });

        it('refuses a flat reorder that names an entry outside the territory', function () {
            Collection::make('notices')->title('Notices')->routes('notices/{slug}')->structureContents(['max_depth' => 1])->save();
            foreach (['a', 'b', 'c'] as $id) {
                Entry::make()->id("notice-{$id}")->collection('notices')->slug("notice-{$id}")->published(true)->data(['title' => strtoupper($id)])->save();
            }
            Site::role('noticeboard', ['access cp', 'view notices entries', 'reorder notices entries']);
            $all = Site::territory('all-notices', 'notices', scopeType: 'collection');
            Site::user('noticeboard', roles: ['noticeboard']);
            Site::assign($all, 'user:noticeboard');

            $payload = ['ids' => ['notice-b', 'notice-a', 'notice-c'], 'page' => 1, 'perPage' => 3, 'site' => 'default'];

            // A collection territory covers the whole collection: allowed.
            expect(AbilityScoping::as('noticeboard')->postJson('/cp/collections/notices/entries/reorder', $payload)->status())->toBe(200)
                ->and(array_column(Collection::findByHandle('notices')->structure()->in('default')->tree(), 'entry'))->toBe(['notice-b', 'notice-a', 'notice-c']);

            // A user with the permission but no claim on these entries: refused.
            Site::role('reorderer', ['access cp', 'view notices entries', 'reorder notices entries', 'view pages entries']);
            Site::user('card-reorderer', roles: ['reorderer']);
            Site::assign(1, 'user:card-reorderer');

            expect(AbilityScoping::as('card-reorderer')->postJson('/cp/collections/notices/entries/reorder', $payload)->status())->toBe(403);
        });
    }

    /** @param  list<array<string, mixed>>  $pages */
    public static function save(string $userId, array $pages, array $deleted = []): TestResponse
    {
        $payload = ['site' => 'default', 'pages' => $pages];

        if ($deleted !== []) {
            $payload['deletedEntries'] = $deleted;
        }

        return AbilityScoping::as($userId)->patchJson('/cp/collections/pages/tree', $payload);
    }

    /** @return list<array<string, mixed>> */
    public static function stored(): array
    {
        return Collection::findByHandle('pages')->structure()->in('default')->tree();
    }

    /** @return list<array<string, mixed>> */
    public static function withPhase1(): array
    {
        return [
            ['entry' => 'home'],
            ['entry' => 'card', 'children' => [['entry' => 'trials', 'children' => [['entry' => 'phase2'], ['entry' => 'phase1']]], ['entry' => 'clinics']]],
            ['entry' => 'onc', 'children' => [['entry' => 'chemo'], ['entry' => 'oncdraft']]],
            ['entry' => 'alumni'],
            ['entry' => 'stray'],
        ];
    }
}
