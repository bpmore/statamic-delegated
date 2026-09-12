<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Illuminate\Testing\TestResponse;
use Statamic\Facades\Nav;

/**
 * Phase 3.2.f: the navigation tree is authorised, entry-derived data of
 * outside-linked branches is redacted, and a branch cannot be linked to an
 * entry outside the territory. Defined once, run under both drivers.
 */
final class NavScoping
{
    public static function setUp(): void
    {
        AbilityScoping::setUp();

        Nav::make()->handle('main')->title('Main')->save();
        Nav::find('main')->makeTree('default', [
            ['id' => 'n-card', 'entry' => 'card'],
            ['id' => 'n-onc', 'entry' => 'onc', 'children' => [['id' => 'n-chemo', 'entry' => 'chemo']]],
            ['id' => 'n-draft', 'entry' => 'oncdraft', 'title' => 'Coming Soon'],
            ['id' => 'n-url', 'title' => 'External', 'url' => 'https://example.org'],
        ])->save();
    }

    public static function scenarios(): void
    {
        it('refuses the nav tree to a user without permission on the nav', function () {
            Site::role('cp-only', ['access cp']);
            Site::user('lurker', roles: ['cp-only']);

            expect(NavScoping::tree('lurker')->status())->toBe(403);
        });

        it('keeps every branch but redacts what an outside branch reveals about its entry', function () {
            $r = NavScoping::tree('card-editor');

            expect($r->status())->toBe(200);

            $byId = collect(TreeScoping::flatten($r->json('pages')))->keyBy('id');

            expect($byId->keys()->all())->toEqualCanonicalizing(['n-card', 'n-onc', 'n-chemo', 'n-draft', 'n-url']);

            // Inside: untouched.
            expect($byId['n-card']['entry_title'])->toBe('Cardiology')
                ->and($byId['n-card']['edit_url'])->not->toBeNull()
                ->and($byId['n-card']['url'])->toBe('/cardiology');

            // Outside, published: the URL is public anyway; nothing else is.
            expect($byId['n-onc']['delegated_redacted'])->toBeTrue()
                ->and($byId['n-onc']['entry_title'])->toBeNull()
                ->and($byId['n-onc']['edit_url'])->toBeNull()
                ->and($byId['n-onc']['status'])->toBeNull()
                ->and($byId['n-onc']['url'])->toBe('/oncology')
                ->and($byId['n-chemo']['entry_title'])->toBeNull();

            // Outside, a draft: not even the URL, but the branch's own title stays.
            expect($byId['n-draft']['entry_title'])->toBeNull()
                ->and($byId['n-draft']['url'])->toBeNull()
                ->and($byId['n-draft']['title'])->toBe('Coming Soon');

            // A URL branch is nobody's content.
            expect($byId['n-url']['url'])->toBe('https://example.org')
                ->and($byId['n-url']['title'])->toBe('External');
        });

        it('shows a super user everything', function () {
            $byId = collect(TreeScoping::flatten(NavScoping::tree('root')->json('pages')))->keyBy('id');

            expect($byId['n-onc']['entry_title'])->toBe('Oncology')
                ->and($byId['n-draft']['entry_title'])->toBe('Oncology Secret Draft')
                ->and($byId['n-draft']['url'])->not->toBeNull()
                ->and(isset($byId['n-onc']['delegated_redacted']))->toBeFalse();
        });

        it('refuses to link a new branch to an entry outside the territory, and saves nothing', function () {
            $r = NavScoping::save('card-editor', data: [
                'n-new' => ['new' => true, 'entry' => 'oncdraft', 'values' => ['title' => null, 'url' => null], 'localizedFields' => []],
            ], pages: NavScoping::pages([['id' => 'n-new', 'children' => []]]));

            expect($r->status())->toBe(403)
                ->and(NavScoping::stored())->toHaveCount(4)
                ->and(collect(NavScoping::stored())->pluck('id')->all())->not->toContain('n-new');
        });

        it('refuses to re-point an existing branch at an entry outside the territory', function () {
            $r = NavScoping::save('card-editor', data: [
                'n-card' => ['entry' => 'onc', 'values' => ['title' => null, 'url' => null], 'localizedFields' => []],
            ], pages: NavScoping::pages());

            expect($r->status())->toBe(403)
                ->and(NavScoping::stored()[0]['entry'])->toBe('card');
        });

        it('lets a member link a new branch to their own entry', function () {
            $r = NavScoping::save('card-editor', data: [
                'n-new' => ['new' => true, 'entry' => 'trials', 'values' => ['title' => null, 'url' => null], 'localizedFields' => []],
            ], pages: NavScoping::pages([['id' => 'n-new', 'children' => []]]));

            expect($r->status())->toBe(200)
                ->and(collect(NavScoping::stored())->pluck('entry')->filter()->all())->toContain('trials');
        });

        it('lets a member re-title and move a branch that links outside, which is nav work', function () {
            // The nav's own permission governs its structure; the branch still
            // points where it pointed, and the entry behind it stays closed.
            $r = NavScoping::save('card-editor', data: [
                'n-onc' => ['entry' => 'onc', 'values' => ['title' => 'Cancer Care', 'url' => null], 'localizedFields' => ['title']],
            ], pages: [
                ['id' => 'n-card', 'children' => [['id' => 'n-onc', 'children' => [['id' => 'n-chemo', 'children' => []]]]]],
                ['id' => 'n-draft', 'children' => []],
                ['id' => 'n-url', 'children' => []],
            ]);

            expect($r->status())->toBe(200);

            $stored = NavScoping::stored();

            expect($stored[0]['children'][0]['id'])->toBe('n-onc')
                ->and($stored[0]['children'][0]['title'])->toBe('Cancer Care')
                ->and($stored[0]['children'][0]['entry'])->toBe('onc');
        });

        it('does not apply to a super user', function () {
            $r = NavScoping::save('root', data: [
                'n-new' => ['new' => true, 'entry' => 'oncdraft', 'values' => ['title' => null, 'url' => null], 'localizedFields' => []],
            ], pages: NavScoping::pages([['id' => 'n-new', 'children' => []]]));

            expect($r->status())->toBe(200);
        });
    }

    public static function tree(string $userId): TestResponse
    {
        return AbilityScoping::as($userId)->getJson('/cp/navigation/main/tree?site=default');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $pages
     */
    public static function save(string $userId, array $data, array $pages): TestResponse
    {
        return AbilityScoping::as($userId)->patchJson('/cp/navigation/main/tree', ['site' => 'default', 'data' => $data, 'pages' => $pages]);
    }

    /** The stored branches in editor shape, plus any extra top-level ones. */
    public static function pages(array $extra = []): array
    {
        return array_merge([
            ['id' => 'n-card', 'children' => []],
            ['id' => 'n-onc', 'children' => [['id' => 'n-chemo', 'children' => []]]],
            ['id' => 'n-draft', 'children' => []],
            ['id' => 'n-url', 'children' => []],
        ], $extra);
    }

    /** @return list<array<string, mixed>> */
    public static function stored(): array
    {
        return Nav::find('main')->in('default')->tree();
    }
}
