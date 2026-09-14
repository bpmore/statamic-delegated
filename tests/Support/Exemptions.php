<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Bpmore\StatamicDelegated\Enforcement\Exempt;
use Bpmore\StatamicDelegated\Enforcement\UserVisibility;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Phase 3.3: who Delegated does not restrict, pinned as a rule and swept
 * across every surface. Defined once, run under both drivers.
 *
 * The case that matters most is a super user who is *also* assigned to a
 * territory — a site owner who happens to be listed as head of a
 * department. Membership must never narrow what the exemption grants.
 */
final class Exemptions
{
    public static function setUp(): void
    {
        AbilityScoping::setUp();

        // A super user listed in cardiology like any member, and one holding
        // configure collections listed there too.
        Site::user('owner', roles: ['editor'], super: true);
        Site::assign(1, 'user:owner');

        Site::role('configurer', ['access cp', 'configure collections', 'view pages entries', 'view news entries']);
        Site::user('configurer', roles: ['configurer']);
        Site::assign(1, 'user:configurer');
    }

    public static function scenarios(): void
    {
        it('states the content exemption rule: super users, configure collections holders, and no user', function () {
            expect(Exempt::user(User::find('root')))->toBeTrue()
                ->and(Exempt::user(User::find('owner')))->toBeTrue()
                ->and(Exempt::user(User::find('configurer')))->toBeTrue()
                ->and(Exempt::user(null))->toBeTrue()
                ->and(Exempt::user(User::find('card-editor')))->toBeFalse()
                ->and(Exempt::user(User::find('nobody')))->toBeFalse();
        });

        it('states the users-screens rule: super users and people in no territory are not confined', function () {
            $visibility = app(UserVisibility::class);

            expect($visibility->ids(User::find('root')))->toBeNull()
                ->and($visibility->ids(User::find('owner')))->toBeNull()
                ->and($visibility->ids(User::find('nobody')))->toBeNull()
                ->and($visibility->ids(null))->toBeNull()
                // Holding configure collections is about content, not people:
                // listed in a territory, this user is confined like any member.
                ->and($visibility->ids(User::find('configurer')))->not->toBeNull()
                ->and($visibility->ids(User::find('card-editor')))->not->toBeNull();
        });

        it('lets a super user assigned to a territory see and do everything, on every surface', function () {
            foreach (['owner', 'configurer'] as $exempt) {
                $user = User::find($exempt);

                foreach (Content::PAGES as $id) {
                    expect($user->can('view', Entry::find($id)))->toBeTrue("{$exempt} view {$id}")
                        ->and($user->can('delete', Entry::find($id)))->toBeTrue("{$exempt} delete {$id}");
                }

                $listing = AbilityScoping::as($exempt)->getJson('/cp/collections/pages/entries');
                expect(collect($listing->json('data'))->pluck('id')->all())->toEqualCanonicalizing(Content::PAGES, "{$exempt} listing");

                $tree = AbilityScoping::as($exempt)->getJson('/cp/collections/pages/tree?site=default');
                $flat = TreeScoping::flatten($tree->json('pages'));
                expect(array_column($flat, 'entry'))->toEqualCanonicalizing(Content::PAGES, "{$exempt} tree")
                    ->and(array_filter($flat, fn (array $p): bool => ! empty($p['delegated_redacted'])))->toBe([]);

                $picker = PickerScoping::pick($exempt, ['collections' => ['pages']]);
                expect(PickerScoping::ids($picker))->toEqualCanonicalizing(Content::PAGES, "{$exempt} picker");

                expect(AbilityScoping::as($exempt)->get('/cp/edit/onc')->status())->toBe(302, "{$exempt} oracle")
                    ->and(AbilityScoping::as($exempt)->getJson('/cp/collections/pages/entries/onc')->status())->toBe(200, "{$exempt} edit");
            }
        });

        it('lets a super user assigned to a territory create anywhere and move anything', function () {
            $r = CreationScoping::store('owner', 'pages', ['title' => 'Owner Page', 'slug' => 'owner-page', '_parent' => 'onc']);
            expect($r->status())->toBe(200);
            $created = (string) $r->json('data.id');
            expect(Entry::find($created)->uri())->toBe('/oncology/owner-page');

            $r = AbilityScoping::as('owner')->patchJson('/cp/collections/pages/tree', ['site' => 'default', 'pages' => [
                ['id' => 'home', 'children' => []],
                ['id' => 'onc', 'children' => [
                    ['id' => 'chemo', 'children' => []], ['id' => 'oncdraft', 'children' => []], ['id' => $created, 'children' => []],
                    ['id' => 'card', 'children' => [['id' => 'trials', 'children' => [['id' => 'phase2', 'children' => []]]]]],
                ]],
                ['id' => 'alumni', 'children' => []],
                ['id' => 'stray', 'children' => []],
            ]]);
            expect($r->status())->toBe(200)
                ->and(Entry::find('card')->fresh()->uri())->toBe('/oncology/cardiology')
                ->and(Entry::find($created)->fresh()->uri())->toBe('/oncology/owner-page');
        });

        it('keeps the exemption from leaking to an ordinary member of the same territory', function () {
            // Control: the members alongside the exempt users are as confined
            // as ever. The exemption is about the person, not the territory.
            expect(User::find('card-editor')->can('view', Entry::find('onc')))->toBeFalse()
                ->and(collect(AbilityScoping::as('card-editor')->getJson('/cp/collections/pages/entries')->json('data'))->pluck('id')->all())
                ->toEqualCanonicalizing(Content::CARDIOLOGY);
        });
    }
}
