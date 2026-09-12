<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Phase 3.2.d: the structure tree is authorised, and pruned to the user's
 * territories. Defined once, run under both drivers.
 */
final class TreeScoping
{
    public static function scenarios(): void
    {
        it('refuses the tree to a user without view permission on the collection', function () {
            // The core gap: any control-panel user could enumerate the site.
            Site::role('cp-only', ['access cp']);
            Site::user('lurker', roles: ['cp-only']);

            expect(TreeScoping::tree('lurker')->status())->toBe(403);
        });

        it('shows a member only their branch, with the root as a placeholder', function () {
            $r = TreeScoping::tree('card-editor');

            expect($r->status())->toBe(200);

            $pages = $r->json('pages');
            $flat = TreeScoping::flatten($pages);

            expect(array_column($flat, 'entry'))->toEqualCanonicalizing(['home', 'card', 'trials', 'phase2'])
                ->and(TreeScoping::titles($flat))->not->toContain('Oncology')
                ->and(TreeScoping::titles($flat))->not->toContain('Oncology Secret Draft')
                ->and(TreeScoping::titles($flat))->not->toContain('Cardiology Alumni')
                ->and(TreeScoping::titles($flat))->not->toContain('Stray Orphan');

            // The root is a placeholder: its id, and nothing to read or open.
            $root = $pages[0];
            expect($root['entry'])->toBe('home')
                ->and($root['delegated_redacted'])->toBeTrue()
                ->and($root['entry_title'])->toBeNull()
                ->and($root['edit_url'])->toBeNull()
                ->and($root['url'])->toBeNull()
                ->and($root['can_delete'])->toBeFalse();

            // The branch is whole.
            $card = $pages[1];
            expect($card['entry'])->toBe('card')
                ->and($card['entry_title'])->toBe('Cardiology')
                ->and($card['edit_url'])->not->toBeNull()
                ->and($card['children'][0]['entry'])->toBe('trials')
                ->and($card['children'][0]['children'][0]['entry'])->toBe('phase2');
        });

        it('keeps an outside ancestor as a placeholder above an inside sub-branch', function () {
            $trials = Site::territory('trials', '/cardiology/trials', parentId: 1);
            Site::user('trials-only', roles: ['editor']);
            Site::assign($trials, 'user:trials-only');

            $pages = TreeScoping::tree('trials-only')->json('pages');

            expect(array_column(TreeScoping::flatten($pages), 'entry'))->toEqualCanonicalizing(['home', 'card', 'trials', 'phase2']);

            $card = $pages[1];
            expect($card['entry'])->toBe('card')
                ->and($card['delegated_redacted'])->toBeTrue()
                ->and($card['entry_title'])->toBeNull()
                ->and($card['edit_url'])->toBeNull()
                ->and($card['title'])->toBe('Cardiology')
                ->and($card['children'][0]['entry'])->toBe('trials')
                ->and($card['children'][0]['entry_title'])->toBe('Cardiology Trials')
                ->and($card['children'][0]['edit_url'])->not->toBeNull();
        });

        it('shows a user with permission but no territory in this collection only the root placeholder', function () {
            $all = Site::territory('all-news', 'news', scopeType: 'collection');
            Site::user('newsroom', roles: ['editor']);
            Site::assign($all, 'user:newsroom');

            $pages = TreeScoping::tree('newsroom')->json('pages');

            expect($pages)->toHaveCount(1)
                ->and($pages[0]['entry'])->toBe('home')
                ->and($pages[0]['delegated_redacted'])->toBeTrue()
                ->and($pages[0]['children'])->toBe([]);
        });

        it('shows a super user the whole tree, untouched', function () {
            $flat = TreeScoping::flatten(TreeScoping::tree('root')->json('pages'));

            // Nine, not eight: the entry never placed in the tree is re-attached
            // at the root on read, and a super user sees it there.
            expect(array_column($flat, 'entry'))->toEqualCanonicalizing(Content::PAGES)
                ->and(array_filter($flat, fn (array $p): bool => ! empty($p['delegated_redacted'])))->toBe([]);
        });
    }

    public static function tree(string $userId): TestResponse
    {
        return AbilityScoping::as($userId)->getJson('/cp/collections/pages/tree?site=default');
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $pages): array
    {
        $out = [];

        foreach ($pages as $page) {
            $out[] = $page;
            $out = array_merge($out, self::flatten($page['children'] ?? []));
        }

        return $out;
    }

    /** @return list<string> */
    public static function titles(array $flat): array
    {
        return array_values(array_filter(array_map(fn (array $p): ?string => $p['entry_title'] ?? $p['title'] ?? null, $flat)));
    }
}
