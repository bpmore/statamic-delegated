<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\Content;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Statamic\Eloquent\Entries\EntryQueryBuilder;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

uses(RefreshDatabase::class);

it('really is running entries on the Eloquent driver', function () {
    // Guards the whole directory: if the driver silently failed to take
    // over, every scenario here would be a second copy of the flat-file run.
    Content::build();

    expect(get_class(Entry::query()))->toBe(EntryQueryBuilder::class)
        ->and(DB::table('entries')->count())->toBe(13)
        ->and(DB::table('entries')->where('id', 'phase2')->value('uri'))->toBe('/cardiology/trials/phase-2')
        // An entry never placed in the tree is re-attached at the root on
        // read (CollectionStructure::validateTree), so it is a root-level
        // page — on this driver, in the stored column too.
        ->and(DB::table('entries')->where('id', 'stray')->value('uri'))->toBe('/stray')
        ->and(Entry::find('stray')->uri())->toBe('/stray');
});

it('keeps an entry\'s last URI in the column after it is dropped from the stored tree, until it is saved again', function () {
    // Statamic's own behaviour, recorded because a territory predicate
    // reads that column. The tree re-attaches the entry at the root on read
    // (it is now /phase-2 in the tree), but this driver's column — and
    // Entry::uri(), which reads it — keep the old value until a save. The
    // control panel cannot produce this state (its tree editor has no
    // "remove without deleting"), and the old URI was inside the old
    // territory, so it is not a cross-territory exposure.
    Content::build();
    $tree = Collection::findByHandle('pages')->structure()->in('default');
    $tree->tree([
        ['entry' => 'home'],
        ['entry' => 'card', 'children' => [['entry' => 'trials']]],
        ['entry' => 'onc', 'children' => [['entry' => 'chemo'], ['entry' => 'oncdraft']]],
        ['entry' => 'alumni'],
    ])->save();

    expect(DB::table('entries')->where('id', 'phase2')->value('uri'))->toBe('/cardiology/trials/phase-2');

    Entry::find('phase2')->save();

    expect(DB::table('entries')->where('id', 'phase2')->value('uri'))->toBe('/phase-2');
});
