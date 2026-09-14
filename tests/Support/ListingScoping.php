<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Bpmore\StatamicDelegated\Scoping\TerritoryScope;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Territories\ScopeType;
use Bpmore\StatamicDelegated\Territories\Territory;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * The listing-scoping scenarios, defined once and run under each driver's
 * test case (tests/Feature/Scoping for the Stache, tests/Feature/Eloquent
 * for the Eloquent driver). A scenario that passes on one driver and not
 * the other is exactly the bug SECURITY.md asks to be told about.
 */
final class ListingScoping
{
    /**
     * What every scenario needs. Called from the test file's own beforeEach:
     * Pest attaches beforeEach() to the file that calls it, and it() to the
     * test file, so a beforeEach registered from here would never run.
     */
    public static function setUp(): void
    {
        Content::build();
        Site::role('viewer', ['access cp', 'view pages entries', 'view news entries']);
    }

    public static function scenarios(): void
    {
        it('shows a member of /cardiology exactly the cardiology branch, with the right total', function () {
            $t = Site::territory('cardiology', '/cardiology');
            Site::user('card-editor', roles: ['viewer']);
            Site::assign($t, 'user:card-editor');

            $r = ListingScoping::listing('card-editor', 'pages');

            expect(ListingScoping::ids($r))->toEqualCanonicalizing(Content::CARDIOLOGY)
                ->and($r->json('meta.total'))->toBe(3);
        });

        it('paginates the scoped set, not the whole collection', function () {
            $t = Site::territory('cardiology', '/cardiology');
            Site::user('card-editor', roles: ['viewer']);
            Site::assign($t, 'user:card-editor');

            $p1 = ListingScoping::listing('card-editor', 'pages', ['perPage' => 2, 'page' => 1, 'sort' => 'title', 'order' => 'asc']);
            $p2 = ListingScoping::listing('card-editor', 'pages', ['perPage' => 2, 'page' => 2, 'sort' => 'title', 'order' => 'asc']);

            expect(count($p1->json('data')))->toBe(2)
                ->and(count($p2->json('data')))->toBe(1)
                ->and($p1->json('meta.total'))->toBe(3)
                ->and($p2->json('meta.total'))->toBe(3)
                ->and(array_merge(ListingScoping::ids($p1), ListingScoping::ids($p2)))->toEqualCanonicalizing(Content::CARDIOLOGY);
        });

        it('shows a user with view permission but no territory nothing at all', function () {
            // The failure that would be easiest to ship: no territories
            // compiling to no predicate, which on the Stache means everything.
            Site::user('nobody', roles: ['viewer']);

            $r = ListingScoping::listing('nobody', 'pages');

            expect($r->status())->toBe(200)
                ->and($r->json('data'))->toBe([])
                ->and($r->json('meta.total'))->toBe(0)
                ->and(ListingScoping::listing('nobody', 'news')->json('meta.total'))->toBe(0);
        });

        it('shows a member of two departments the union, including a draft', function () {
            $c = Site::territory('cardiology', '/cardiology');
            $o = Site::territory('oncology', '/oncology');
            Site::user('dual', roles: ['viewer']);
            Site::assign($c, 'user:dual');
            Site::assign($o, 'user:dual');

            $r = ListingScoping::listing('dual', 'pages');

            expect(ListingScoping::ids($r))->toEqualCanonicalizing(array_merge(Content::CARDIOLOGY, Content::ONCOLOGY))
                ->and($r->json('meta.total'))->toBe(6);
        });

        it('never shows the sibling that shares the prefix, the root-level stray, or the home page', function () {
            $t = Site::territory('cardiology', '/cardiology');
            Site::user('card-editor', roles: ['viewer']);
            Site::assign($t, 'user:card-editor');

            $ids = ListingScoping::ids(ListingScoping::listing('card-editor', 'pages'));

            expect($ids)->not->toContain('alumni')
                ->and($ids)->not->toContain('stray')
                ->and($ids)->not->toContain('home');
        });

        it('scopes a taxonomy-term territory to the tagged entries only', function () {
            $t = Site::territory('cardiology-news', 'departments::cardiology', scopeType: 'taxonomy_term');
            Site::user('tagger', roles: ['viewer']);
            Site::assign($t, 'user:tagger');

            $r = ListingScoping::listing('tagger', 'news');

            expect(ListingScoping::ids($r))->toEqualCanonicalizing(['n1', 'n3'])
                ->and($r->json('meta.total'))->toBe(2)
                ->and(ListingScoping::listing('tagger', 'pages')->json('meta.total'))->toBe(0);
        });

        it('scopes a collection territory to that collection and nothing else', function () {
            $t = Site::territory('all-news', 'news', scopeType: 'collection');
            Site::user('newsroom', roles: ['viewer']);
            Site::assign($t, 'user:newsroom');

            expect(ListingScoping::ids(ListingScoping::listing('newsroom', 'news')))->toEqualCanonicalizing(Content::NEWS)
                ->and(ListingScoping::listing('newsroom', 'pages')->json('meta.total'))->toBe(0);
        });

        it('combines a branch and a term territory without collapsing either', function () {
            // The naive form collapsed on the Stache and failed open on
            // Eloquent (Phase 0.3). Both departments' content, and only that.
            $b = Site::territory('oncology', '/oncology');
            $t = Site::territory('cardiology-news', 'departments::cardiology', scopeType: 'taxonomy_term');
            Site::user('mixed', roles: ['viewer']);
            Site::assign($b, 'user:mixed');
            Site::assign($t, 'user:mixed');

            expect(ListingScoping::ids(ListingScoping::listing('mixed', 'pages')))->toEqualCanonicalizing(Content::ONCOLOGY)
                ->and(ListingScoping::ids(ListingScoping::listing('mixed', 'news')))->toEqualCanonicalizing(['n1', 'n3']);
        });

        it('gives a root-branch territory the whole site, the root-level stray included, on both drivers', function () {
            // An entry never placed in the tree is re-attached at the root on
            // read, so it is a root-level page on both drivers: inside a root
            // territory, inside no department.
            $t = Site::territory('site', '/');
            Site::user('sitewide', roles: ['viewer']);
            Site::assign($t, 'user:sitewide');

            expect(ListingScoping::ids(ListingScoping::listing('sitewide', 'pages')))->toEqualCanonicalizing(Content::PAGES)
                ->and(ListingScoping::ids(ListingScoping::listing('sitewide', 'news')))->toEqualCanonicalizing(Content::NEWS);
        });

        it('scopes the listing\'s search path the same way', function () {
            Content::indexPages();
            $t = Site::territory('cardiology', '/cardiology');
            Site::user('card-editor', roles: ['viewer']);
            Site::assign($t, 'user:card-editor');

            $r = ListingScoping::listing('card-editor', 'pages', ['search' => 'cardiology']);

            expect(ListingScoping::ids($r))->toEqualCanonicalizing(Content::CARDIOLOGY)
                ->and(ListingScoping::ids($r))->not->toContain('alumni');
        });

        it('does not restrict a super user, or a holder of configure collections', function () {
            Site::user('root', roles: ['viewer'], super: true);
            Site::role('configurer', ['access cp', 'configure collections']);
            Site::user('configurer', roles: ['configurer']);

            expect(ListingScoping::ids(ListingScoping::listing('root', 'pages')))->toEqualCanonicalizing(Content::PAGES)
                ->and(ListingScoping::ids(ListingScoping::listing('configurer', 'pages')))->toEqualCanonicalizing(Content::PAGES);
        });

        it('compiles an empty territory set to nothing, never to everything', function () {
            $query = Entry::query()->where('collection', 'pages');
            app(TerritoryScope::class)->apply($query, []);

            expect($query->count())->toBe(0)
                ->and($query->get())->toHaveCount(0);
        });

        it('compiles each scope directly on the entry query', function () {
            $scope = app(TerritoryScope::class);

            $branch = Entry::query();
            $scope->apply($branch, [new Territory(1, 'C', 'c', 'default', ScopeType::StructureBranch, '/Cardiology/')]);
            expect($branch->get()->map->id()->all())->toEqualCanonicalizing(Content::CARDIOLOGY);

            $collection = Entry::query();
            $scope->apply($collection, [new Territory(2, 'N', 'n', 'default', ScopeType::Collection, 'news')]);
            expect($collection->get()->map->id()->all())->toEqualCanonicalizing(Content::NEWS);

            $term = Entry::query();
            $scope->apply($term, [new Territory(3, 'T', 't', 'default', ScopeType::TaxonomyTerm, 'departments::oncology')]);
            expect($term->get()->map->id()->all())->toEqualCanonicalizing(['n2', 'n3']);

            $other = Entry::query();
            $scope->apply($other, [new Territory(4, 'F', 'f', 'french', ScopeType::StructureBranch, '/cardiology')]);
            expect($other->count())->toBe(0);
        });
    }

    /** @param  array<string, mixed>  $query */
    public static function listing(string $userId, string $collection, array $query = []): TestResponse
    {
        return test()->actingAs(User::find($userId))
            ->getJson("/cp/collections/{$collection}/entries".($query ? '?'.http_build_query($query) : ''));
    }

    /** @return list<string> */
    public static function ids(TestResponse $response): array
    {
        $why = $response->exception !== null
            ? get_class($response->exception).': '.$response->exception->getMessage().' @ '.$response->exception->getFile().':'.$response->exception->getLine()
            : substr((string) $response->getContent(), 0, 200);

        expect($response->status())->toBe(200, "listing did not return 200: {$why}");

        return collect($response->json('data'))->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }
}
