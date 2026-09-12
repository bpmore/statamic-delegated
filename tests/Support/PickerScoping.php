<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Bpmore\StatamicDelegated\Fieldtypes\Entries;
use Facades\Statamic\Fields\FieldtypeRepository as Fieldtype;
use Illuminate\Testing\TestResponse;

/**
 * Phase 3.2.g: the relationship picker lists only entries inside the
 * user's territories. Defined once, run under both drivers.
 */
final class PickerScoping
{
    public static function scenarios(): void
    {
        it('replaces the core entries fieldtype under the same handle', function () {
            expect(Fieldtype::find('entries'))->toBeInstanceOf(Entries::class);
        });

        it('offers a member only the entries in their territory, with the right total', function () {
            $r = PickerScoping::pick('card-editor', ['collections' => ['pages']]);

            expect($r->status())->toBe(200)
                ->and(PickerScoping::ids($r))->toEqualCanonicalizing(Content::CARDIOLOGY)
                ->and($r->json('meta.total'))->toBe(3);
        });

        it('offers a user with permission but no territory nothing', function () {
            $r = PickerScoping::pick('nobody', ['collections' => ['pages']]);

            expect($r->status())->toBe(200)
                ->and($r->json('data'))->toBe([])
                ->and($r->json('meta.total'))->toBe(0);
        });

        it('scopes a picker over every collection to the union of the user\'s territories', function () {
            $term = Site::territory('cardiology-news', 'departments::cardiology', scopeType: 'taxonomy_term');
            Site::assign($term, 'user:card-editor');

            $r = PickerScoping::pick('card-editor', []);

            expect(PickerScoping::ids($r))->toEqualCanonicalizing(array_merge(Content::CARDIOLOGY, ['n1', 'n3']));
        });

        it('paginates the scoped set', function () {
            $p1 = PickerScoping::pick('card-editor', ['collections' => ['pages']], ['perPage' => 2, 'page' => 1, 'sort' => 'title', 'order' => 'asc']);
            $p2 = PickerScoping::pick('card-editor', ['collections' => ['pages']], ['perPage' => 2, 'page' => 2, 'sort' => 'title', 'order' => 'asc']);

            expect(count($p1->json('data')))->toBe(2)
                ->and(count($p2->json('data')))->toBe(1)
                ->and($p1->json('meta.total'))->toBe(3)
                ->and(array_merge(PickerScoping::ids($p1), PickerScoping::ids($p2)))->toEqualCanonicalizing(Content::CARDIOLOGY);
        });

        it('scopes the picker\'s search, with and without a search index', function () {
            expect(PickerScoping::ids(PickerScoping::pick('card-editor', ['collections' => ['pages']], ['search' => 'cardiology'])))
                ->toEqualCanonicalizing(Content::CARDIOLOGY);

            Content::indexPages();

            expect(PickerScoping::ids(PickerScoping::pick('card-editor', ['collections' => ['pages']], ['search' => 'cardiology'])))
                ->toEqualCanonicalizing(Content::CARDIOLOGY);
        });

        it('does not restrict a super user', function () {
            expect(PickerScoping::ids(PickerScoping::pick('root', ['collections' => ['pages']])))->toEqualCanonicalizing(Content::PAGES);
        });
    }

    /**
     * @param  array<string, mixed>  $config  the field's config, as the picker sends it
     * @param  array<string, mixed>  $query
     */
    public static function pick(string $userId, array $config, array $query = []): TestResponse
    {
        $query = array_merge(['site' => 'default'], $query, ['config' => base64_encode(json_encode(['type' => 'entries'] + $config))]);

        return AbilityScoping::as($userId)->getJson('/cp/fieldtypes/relationship?'.http_build_query($query));
    }

    /** @return list<string> */
    public static function ids(TestResponse $response): array
    {
        expect($response->status())->toBe(200, 'picker did not return 200: '.($response->exception?->getMessage() ?? substr((string) $response->getContent(), 0, 200)));

        return collect($response->json('data'))->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }
}
