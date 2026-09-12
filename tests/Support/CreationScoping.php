<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Illuminate\Testing\TestResponse;
use Statamic\Facades\Entry;

/**
 * Phase 3.2.c: creating an entry is refused unless the place it would land
 * — its would-be URI, collection and terms — is inside the user's
 * territories. Defined once, run under both drivers.
 */
final class CreationScoping
{
    public static function scenarios(): void
    {
        it('lets a member create under a parent inside their territory', function () {
            $r = CreationScoping::store('card-editor', 'pages', ['title' => 'New Clinic', 'slug' => 'new-clinic', '_parent' => 'card']);

            expect($r->status())->toBe(200);

            $entry = Entry::find($r->json('data.id'));

            expect($entry)->not->toBeNull()
                ->and($entry->uri())->toBe('/cardiology/new-clinic');
        });

        it('refuses to create under a parent outside the territory, and creates nothing', function () {
            $before = Entry::query()->count();

            $r = CreationScoping::store('card-editor', 'pages', ['title' => 'Sneaky', 'slug' => 'sneaky', '_parent' => 'onc']);

            expect($r->status())->toBe(403)
                ->and(Entry::query()->count())->toBe($before)
                ->and(Entry::findByUri('/oncology/sneaky', 'default'))->toBeNull();
        });

        it('refuses to create at the root of the tree, which belongs to no department', function () {
            $before = Entry::query()->count();

            foreach ([[], ['_parent' => ''], ['_parent' => 'home']] as $parent) {
                $r = CreationScoping::store('card-editor', 'pages', ['title' => 'Top Level', 'slug' => 'top-level'] + $parent);

                expect($r->status())->toBe(403);
            }

            expect(Entry::query()->count())->toBe($before);
        });

        it('lets a root-territory member create at the root', function () {
            $root = Site::territory('site', '/');
            Site::user('sitewide', roles: ['editor']);
            Site::assign($root, 'user:sitewide');

            $r = CreationScoping::store('sitewide', 'pages', ['title' => 'Top Level', 'slug' => 'top-level', '_parent' => 'home']);

            expect($r->status())->toBe(200)
                ->and(Entry::find($r->json('data.id'))->uri())->toBe('/top-level');
        });

        it('refuses a parent that is not in the tree rather than treating it as no parent', function () {
            $before = Entry::query()->count();

            $r = CreationScoping::store('card-editor', 'pages', ['title' => 'Ghost', 'slug' => 'ghost', '_parent' => 'no-such-page']);

            expect($r->status())->toBe(403)
                ->and(Entry::query()->count())->toBe($before);
        });

        it('confines a sub-territory admin to their own branch, not the parent branch', function () {
            $trials = Site::territory('trials', '/cardiology/trials', parentId: 1);
            Site::user('trials-head', roles: ['editor']);
            Site::assign($trials, 'user:trials-head', 'admin');

            expect(CreationScoping::store('trials-head', 'pages', ['title' => 'A', 'slug' => 'a', '_parent' => 'card'])->status())->toBe(403)
                ->and(CreationScoping::store('trials-head', 'pages', ['title' => 'B', 'slug' => 'b', '_parent' => 'trials'])->status())->toBe(200);
        });

        it('judges a flat collection by its route and its terms', function () {
            // A branch member has no claim on news.
            expect(CreationScoping::store('card-editor', 'news', ['title' => 'X', 'slug' => 'x'])->status())->toBe(403);

            // A collection territory covers all of it.
            $all = Site::territory('all-news', 'news', scopeType: 'collection');
            Site::user('newsroom', roles: ['editor']);
            Site::assign($all, 'user:newsroom');

            expect(CreationScoping::store('newsroom', 'news', ['title' => 'Y', 'slug' => 'y'])->status())->toBe(200);

            // A term territory covers only what is tagged with the term —
            // read from the submitted values, since the entry does not exist.
            $term = Site::territory('cardiology-news', 'departments::cardiology', scopeType: 'taxonomy_term');
            Site::user('tagger', roles: ['editor']);
            Site::assign($term, 'user:tagger');

            expect(CreationScoping::store('tagger', 'news', ['title' => 'Untagged', 'slug' => 'untagged'])->status())->toBe(403)
                ->and(CreationScoping::store('tagger', 'news', ['title' => 'Other', 'slug' => 'other', 'departments' => ['oncology']])->status())->toBe(403)
                ->and(CreationScoping::store('tagger', 'news', ['title' => 'Ours', 'slug' => 'ours', 'departments' => ['cardiology']])->status())->toBe(200);
        });

        it('refuses the create screen for a parent outside the territory', function () {
            expect(AbilityScoping::as('card-editor')->getJson('/cp/collections/pages/entries/create/default?parent=onc')->status())->toBe(403)
                ->and(AbilityScoping::as('card-editor')->getJson('/cp/collections/pages/entries/create/default?parent=card')->status())->toBe(200);
        });

        it('does not apply to a super user', function () {
            $r = CreationScoping::store('root', 'pages', ['title' => 'Anywhere', 'slug' => 'anywhere', '_parent' => 'onc']);

            expect($r->status())->toBe(200)
                ->and(Entry::find($r->json('data.id'))->uri())->toBe('/oncology/anywhere');
        });
    }

    /** @param  array<string, mixed>  $payload */
    public static function store(string $userId, string $collection, array $payload): TestResponse
    {
        return AbilityScoping::as($userId)->postJson("/cp/collections/{$collection}/entries/default", $payload + ['published' => true]);
    }
}
