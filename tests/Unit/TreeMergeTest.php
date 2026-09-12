<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Enforcement\TreeMerge;
use Bpmore\StatamicDelegated\Enforcement\TreeMergeRefused;

// Pure: no application. The stored tree is Statamic's shape (entry/children),
// the submitted one is the editor's (id/children).

function stored(): array
{
    return [
        ['entry' => 'home'],
        ['entry' => 'card', 'children' => [
            ['entry' => 'trials', 'children' => [['entry' => 'phase2']]],
            ['entry' => 'clinics'],
        ]],
        ['entry' => 'onc', 'children' => [['entry' => 'chemo'], ['entry' => 'oncdraft']]],
        ['entry' => 'alumni'],
        ['entry' => 'stray'],
    ];
}

function cardiology(): TreeMerge
{
    return new TreeMerge(fn (string $id): bool => in_array($id, ['card', 'trials', 'phase2', 'clinics'], true));
}

function n(string $id, array $children = []): array
{
    return ['id' => $id, 'children' => $children];
}

it('folds an unchanged pruned view back into the whole tree unchanged', function () {
    $merged = cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('trials', [n('phase2')]), n('clinics')]),
    ]);

    expect($merged)->toBe(stored());
});

it('applies a reorder among the user\'s own children and touches nothing else', function () {
    $merged = cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('clinics'), n('trials', [n('phase2')])]),
    ]);

    expect($merged[1])->toBe(['entry' => 'card', 'children' => [
        ['entry' => 'clinics'],
        ['entry' => 'trials', 'children' => [['entry' => 'phase2']]],
    ]])
        ->and(array_slice($merged, 2))->toBe(array_slice(stored(), 2))
        ->and($merged[0])->toBe(['entry' => 'home']);
});

it('lets an inside page move under another inside page', function () {
    $merged = cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('trials'), n('clinics', [n('phase2')])]),
    ]);

    expect($merged[1])->toBe(['entry' => 'card', 'children' => [
        ['entry' => 'trials'],
        ['entry' => 'clinics', 'children' => [['entry' => 'phase2']]],
    ]]);
});

it('refuses to move an inside page up to the root, which is outside the territory', function () {
    expect(fn () => cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('clinics')]),
        n('trials', [n('phase2')]),
    ]))->toThrow(TreeMergeRefused::class, 'can only be moved within your territory');
});

it('refuses a page the user was never shown', function () {
    expect(fn () => cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('trials', [n('phase2')]), n('clinics'), n('chemo')]),
    ]))->toThrow(TreeMergeRefused::class, 'outside your territory');

    expect(fn () => cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('trials', [n('phase2')]), n('clinics')]),
        n('onc'),
    ]))->toThrow(TreeMergeRefused::class, 'outside your territory');
});

it('refuses an id that is not in the tree at all', function () {
    expect(fn () => cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('trials', [n('phase2')]), n('clinics'), n('ghost')]),
    ]))->toThrow(TreeMergeRefused::class, 'not in this tree');
});

it('refuses to leave a page out without deleting it', function () {
    // Statamic would re-attach it at the root: a move outside by omission.
    expect(fn () => cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('trials'), n('clinics')]),
    ]))->toThrow(TreeMergeRefused::class, 'left out');
});

it('refuses to move a placeholder', function () {
    $trialsOnly = new TreeMerge(fn (string $id): bool => in_array($id, ['trials', 'phase2'], true));

    // The user sees: home (placeholder), card (placeholder) > trials > phase2.
    // Dropping the card placeholder puts trials at the root — a move outside,
    // caught before the missing placeholder is.
    expect(fn () => $trialsOnly->merge(stored(), [
        n('home'),
        n('trials', [n('phase2')]),
    ]))->toThrow(TreeMergeRefused::class, 'can only be moved within your territory');

    expect(fn () => $trialsOnly->merge(stored(), [
        n('home', [n('card', [n('trials', [n('phase2')])])]),
    ]))->toThrow(TreeMergeRefused::class, 'cannot be moved');
});

it('keeps a placeholder\'s dropped children while applying the user\'s reorder below it', function () {
    $trialsOnly = new TreeMerge(fn (string $id): bool => in_array($id, ['trials', 'phase2'], true));

    $merged = $trialsOnly->merge(stored(), [
        n('home'),
        n('card', [n('trials', [n('phase2')])]),
    ]);

    // clinics, which the user never saw, is still under card.
    expect($merged[1])->toBe(['entry' => 'card', 'children' => [
        ['entry' => 'trials', 'children' => [['entry' => 'phase2']]],
        ['entry' => 'clinics'],
    ]]);
});

it('deletes an inside page and refuses to delete an outside one, or one with outside descendants', function () {
    $merged = cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('trials'), n('clinics')]),
    ], deleted: ['phase2']);

    expect($merged[1])->toBe(['entry' => 'card', 'children' => [['entry' => 'trials'], ['entry' => 'clinics']]]);

    expect(fn () => cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('trials', [n('phase2')]), n('clinics')]),
    ], deleted: ['onc']))->toThrow(TreeMergeRefused::class, 'cannot be deleted');

    // A term-shaped territory: card is inside, its child clinics is not.
    $patchy = new TreeMerge(fn (string $id): bool => in_array($id, ['card', 'trials', 'phase2'], true));

    expect(fn () => $patchy->merge(stored(), [n('home')], deleted: ['card']))
        ->toThrow(TreeMergeRefused::class, 'cannot be deleted');
});

it('refuses a page that is both deleted and kept, and one that appears twice', function () {
    expect(fn () => cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('trials', [n('phase2')]), n('clinics')]),
    ], deleted: ['clinics']))->toThrow(TreeMergeRefused::class, 'both deleted and kept');

    expect(fn () => cardiology()->merge(stored(), [
        n('home'),
        n('card', [n('trials', [n('phase2')]), n('clinics'), n('clinics')]),
    ]))->toThrow(TreeMergeRefused::class, 'appears twice');
});

it('permutes the user\'s pages among their own root-level slots without moving dropped siblings', function () {
    $two = new TreeMerge(fn (string $id): bool => in_array($id, ['card', 'trials', 'phase2', 'clinics', 'alumni'], true));

    // Shown: home (placeholder), card, alumni. The user swaps card and alumni.
    $merged = $two->merge(stored(), [
        n('home'),
        n('alumni'),
        n('card', [n('trials', [n('phase2')]), n('clinics')]),
    ]);

    expect(array_column($merged, 'entry'))->toBe(['home', 'alumni', 'onc', 'card', 'stray'])
        ->and($merged[2])->toBe(stored()[2]);
});
